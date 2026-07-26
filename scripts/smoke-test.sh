#!/usr/bin/env bash
#
# Teste de fumaca do ambiente completo: exercita o caminho feliz e o caminho
# de erro contra a stack real (API, broker, worker, storage e notificacao).
#
# Uso:
#   docker compose up -d --build
#   docker compose exec -T api php artisan migrate --force
#   bash scripts/smoke-test.sh
#
set -euo pipefail

BASE_URL="${BASE_URL:-http://localhost:8080/api}"
MAILPIT_URL="${MAILPIT_URL:-http://localhost:8025}"
EMAIL="smoke-$(date +%s)@fiapx.test"
SENHA="senhaSegura1"
# Diretorio relativo em vez de mktemp: no Git Bash do Windows o curl e o
# docker sao binarios nativos e nao entendem caminhos POSIX como /tmp/....
TMP_DIR=".smoke-tmp"
TIMEOUT_PROCESSAMENTO="${TIMEOUT_PROCESSAMENTO:-120}"

rm -rf "$TMP_DIR" && mkdir -p "$TMP_DIR"
trap 'rm -rf "$TMP_DIR"' EXIT

passo()  { echo ""; echo "==> $*"; }
ok()     { echo "    OK: $*"; }
falhar() { echo "    FALHOU: $*" >&2; exit 1; }

# -----------------------------------------------------------------------------
passo "1/8 Verificando disponibilidade da API"
curl -fsS "${BASE_URL}/health" > /dev/null || falhar "a API nao respondeu"
ok "API no ar"

# -----------------------------------------------------------------------------
passo "2/8 Cadastrando usuario"
TOKEN=$(curl -fsS -X POST "${BASE_URL}/auth/register" \
    -H 'Content-Type: application/json' \
    -d "{\"name\":\"Smoke Test\",\"email\":\"${EMAIL}\",\"password\":\"${SENHA}\",\"password_confirmation\":\"${SENHA}\"}" \
    | grep -o '"token":"[^"]*"' | cut -d'"' -f4)

[ -n "$TOKEN" ] || falhar "nao recebeu token no cadastro"
ok "token emitido"

# -----------------------------------------------------------------------------
passo "3/8 Confirmando que rota protegida exige autenticacao"
CODIGO=$(curl -s -o /dev/null -w '%{http_code}' "${BASE_URL}/videos")
[ "$CODIGO" = "401" ] || falhar "esperava 401 sem token, veio ${CODIGO}"
ok "acesso sem token bloqueado"

# -----------------------------------------------------------------------------
passo "4/8 Gerando videos de teste"
# O ffmpeg vive na imagem do worker: nao e preciso instala-lo no runner.
docker compose exec -T worker sh -c '
    ffmpeg -hide_banner -loglevel error -f lavfi \
        -i testsrc=duration=4:size=320x240:rate=10 \
        -pix_fmt yuv420p -y /tmp/fiapx/smoke.mp4
' || falhar "nao foi possivel gerar o video de teste"

CID=$(docker compose ps -q worker | head -1)
docker cp "${CID}:/tmp/fiapx/smoke.mp4" "${TMP_DIR}/smoke.mp4"
# Header MP4 valido com dados truncados: passa na validacao e quebra no ffmpeg.
head -c 1500 "${TMP_DIR}/smoke.mp4" > "${TMP_DIR}/corrompido.mp4"
ok "video de 4s e amostra corrompida prontos"

# -----------------------------------------------------------------------------
passo "5/8 Enviando 3 videos simultaneos"
for i in 1 2 3; do
    curl -fsS -X POST "${BASE_URL}/videos" \
        -H "Authorization: Bearer ${TOKEN}" \
        -F "video=@${TMP_DIR}/smoke.mp4;type=video/mp4" > "${TMP_DIR}/upload-${i}.json" &
done
wait

for i in 1 2 3; do
    grep -q '"status":"PENDING"' "${TMP_DIR}/upload-${i}.json" \
        || falhar "upload ${i} nao foi aceito"
done
ok "3 uploads aceitos com 202"

# -----------------------------------------------------------------------------
passo "6/8 Aguardando o processamento concluir"
for _ in $(seq 1 "$TIMEOUT_PROCESSAMENTO"); do
    CONCLUIDOS=$(curl -fsS "${BASE_URL}/videos?status=COMPLETED" \
        -H "Authorization: Bearer ${TOKEN}" | grep -o '"status":"COMPLETED"' | wc -l)
    [ "$CONCLUIDOS" -ge 3 ] && break
    sleep 1
done

[ "${CONCLUIDOS:-0}" -ge 3 ] || falhar "esperava 3 videos concluidos, veio ${CONCLUIDOS:-0}"
ok "3 videos processados"

# -----------------------------------------------------------------------------
passo "7/8 Baixando o ZIP dos frames"
VIDEO_ID=$(curl -fsS "${BASE_URL}/videos?status=COMPLETED" \
    -H "Authorization: Bearer ${TOKEN}" | grep -o '"id":"[^"]*"' | head -1 | cut -d'"' -f4)

# A API devolve a URL sem escapar as barras; o tr remove qualquer barra
# invertida residual. Usamos o octal \134 em vez de '\\' porque o segundo
# gera aviso de portabilidade e sofre conversao de caminho no Git Bash.
URL=$(curl -fsS "${BASE_URL}/videos/${VIDEO_ID}/download" \
    -H "Authorization: Bearer ${TOKEN}" | grep -o '"download_url":"[^"]*"' | cut -d'"' -f4 | tr -d '\134')

curl -fsS "$URL" -o "${TMP_DIR}/frames.zip" || falhar "download do ZIP falhou"
unzip -tq "${TMP_DIR}/frames.zip" > /dev/null || falhar "ZIP baixado esta corrompido"

FRAMES=$(unzip -l "${TMP_DIR}/frames.zip" | grep -c '\.png' || true)
# Video de 4s a 1 fps: o ffmpeg pode arredondar, entao exigimos ao menos 4.
[ "$FRAMES" -ge 4 ] || falhar "esperava ao menos 4 frames no ZIP, veio ${FRAMES}"
ok "ZIP integro com ${FRAMES} frames"

# -----------------------------------------------------------------------------
passo "8/8 Validando o tratamento de falha e a notificacao"
curl -fsS -X POST "${BASE_URL}/videos" \
    -H "Authorization: Bearer ${TOKEN}" \
    -F "video=@${TMP_DIR}/corrompido.mp4;type=video/mp4" > /dev/null

for _ in $(seq 1 "$TIMEOUT_PROCESSAMENTO"); do
    FALHOS=$(curl -fsS "${BASE_URL}/videos?status=FAILED" \
        -H "Authorization: Bearer ${TOKEN}" | grep -o '"status":"FAILED"' | wc -l)
    [ "$FALHOS" -ge 1 ] && break
    sleep 1
done

[ "${FALHOS:-0}" -ge 1 ] || falhar "o video corrompido deveria terminar em FAILED"
ok "video invalido marcado como FAILED apos as retentativas"

# A notificacao por e-mail e requisito explicito do desafio.
EMAILS=$(curl -fsS "${MAILPIT_URL}/api/v1/messages" | grep -o '"total":[0-9]*' | head -1 | cut -d':' -f2)
[ "${EMAILS:-0}" -ge 1 ] || falhar "nenhuma notificacao de erro foi enviada"
ok "notificacao de erro enviada (${EMAILS} na caixa)"

# A mensagem que falhou em definitivo precisa parar na DLQ, e nao voltar a fila.
DLQ=$(docker compose exec -T rabbitmq rabbitmqctl list_queues name messages 2>/dev/null \
    | awk '/video.processing.dlq/ {print $2}')
[ "${DLQ:-0}" -ge 1 ] || falhar "a mensagem com falha permanente nao chegou na DLQ"
ok "mensagem isolada na DLQ (${DLQ})"

echo ""
echo "================================================"
echo " Teste de fumaca concluido com sucesso"
echo "================================================"
