#!/usr/bin/env bash
#
# Prepara os arquivos usados na demonstracao.
#
# Gera videos sinteticos com o ffmpeg que ja existe na imagem do worker,
# evitando depender de arquivos grandes versionados no repositorio.
#
# Uso:
#   bash scripts/preparar-demo.sh
#
set -euo pipefail

DESTINO="${DESTINO:-demo}"

echo "==> Gerando videos de demonstracao em ${DESTINO}/"
mkdir -p "$DESTINO"

docker compose exec -T worker sh -c '
    set -e
    # Durações diferentes para evidenciar a contagem de frames a 1 fps.
    ffmpeg -hide_banner -loglevel error -f lavfi \
        -i testsrc=duration=10:size=640x480:rate=24 \
        -pix_fmt yuv420p -y /tmp/fiapx/paisagem.mp4

    ffmpeg -hide_banner -loglevel error -f lavfi \
        -i testsrc2=duration=15:size=640x480:rate=24 \
        -pix_fmt yuv420p -y /tmp/fiapx/viagem.mp4

    ffmpeg -hide_banner -loglevel error -f lavfi \
        -i smptebars=duration=8:size=640x480:rate=24 \
        -pix_fmt yuv420p -y /tmp/fiapx/aniversario.mp4
'

CID="$(docker compose ps -q worker | head -1)"
for nome in paisagem viagem aniversario; do
    docker cp "${CID}:/tmp/fiapx/${nome}.mp4" "${DESTINO}/${nome}.mp4" > /dev/null
    echo "    ${nome}.mp4"
done

# Header MP4 valido com os dados truncados: passa na validacao da API e
# quebra no ffmpeg, o que demonstra o caminho de erro e a notificacao.
head -c 1500 "${DESTINO}/aniversario.mp4" > "${DESTINO}/video-corrompido.mp4"
echo "    video-corrompido.mp4 (para demonstrar o tratamento de falha)"

echo ""
echo "Pronto. Duracoes: paisagem 10s, viagem 15s, aniversario 8s"
echo "A 1 frame por segundo, devem render 10, 15 e 8 frames."
