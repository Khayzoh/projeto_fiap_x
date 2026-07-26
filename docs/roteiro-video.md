# Roteiro do vídeo de demonstração

Hackathon FIAP X · limite de **10 minutos**

O enunciado exige três coisas no vídeo: **documentação**, **arquitetura escolhida**
e **o projeto funcionando**. O roteiro abaixo cobre as três em ~9 minutos, deixando
folga para imprevistos.

---

## Antes de gravar

### 1. Suba o ambiente e deixe estabilizar

```bash
cd c:/Users/kaue_/OneDrive/Documentos/projeto_fiap_x
docker compose up -d
docker compose exec api php artisan migrate --force
```

### 2. Prepare os arquivos e zere o estado

```bash
bash scripts/preparar-demo.sh   # gera demo/*.mp4 (só precisa na primeira vez)
bash scripts/reset-demo.sh      # limpa banco, storage, filas, e-mails e logs
```

> Rode `reset-demo.sh` entre cada tomada. Ele deixa tudo zerado **sem derrubar
> os containers**, então você não perde tempo esperando a stack subir de novo.

### 3. Deixe aberto antes de começar

| Aba / janela | Endereço |
|---|---|
| Terminal (Git Bash) | na raiz do projeto |
| Editor | `legacy/main.go` e `docs/architecture/arquitetura.md` |
| Navegador — RabbitMQ | http://localhost:15672 (`fiapx` / `fiapx`) |
| Navegador — Mailpit | http://localhost:8025 |
| Navegador — Grafana | http://localhost:3001 (`admin` / `admin`) |
| Navegador — MinIO | http://localhost:9001 (`fiapxadmin` / `fiapxadmin123`) |

### 4. Verifique que está tudo de pé

```bash
docker compose ps
curl -s http://localhost:8080/api/health
```

---

## Roteiro

### Bloco 1 — O problema (0:00 – 1:00)

Abra [`legacy/main.go`](../legacy/main.go) e mostre a linha onde o processamento
acontece dentro do request:

```go
result := processVideo(videoPath, timestamp)   // linha 117
c.JSON(200, result)
```

**Diga:** *"Este é o projeto que foi apresentado aos investidores. Funciona, mas
processa o vídeo dentro da requisição HTTP: o usuário fica esperando o ffmpeg
terminar, e o servidor fica preso nisso. Não tem banco, não tem fila, não tem
autenticação, e o estado é descoberto listando arquivos no disco com Glob."*

Mostre também o comentário do [`legacy/Dockerfile`](../legacy/Dockerfile):
`# DOCKERFILE SIMPLES (sem boas práticas - propositalmente!)`.

---

### Bloco 2 — A arquitetura (1:00 – 2:30)

Abra [`docs/architecture/arquitetura.md`](architecture/arquitetura.md) no diagrama
de componentes e depois no de sequência.

**Pontos a destacar:**

- A API **só recebe** o vídeo e responde `202`. Quem processa é o worker.
- O que separa os dois é a **fila** — é ela que absorve o pico e garante que
  nenhuma requisição se perca.
- **Por que duas linguagens:** a API é I/O curto e reaproveita o ecossistema
  Laravel das fases anteriores; o worker é CPU longo e herda o código de
  extração do projeto base, agora em Clean Architecture.
- Cite as 5 ADRs como registro das decisões.

Mostre rapidamente a estrutura do worker:

```bash
ls worker/internal/domain worker/internal/usecase worker/internal/adapter
```

**Diga:** *"O domínio não importa nada de infraestrutura. ffmpeg, MinIO e RabbitMQ
são adaptadores atrás de interfaces — é isso que permite testar o caso de uso
inteiro sem subir nada."*

---

### Bloco 3 — Autenticação (2:30 – 3:15)

```bash
API=http://localhost:8080/api

# Cadastro
curl -s -X POST $API/auth/register -H 'Content-Type: application/json' \
  -d '{"name":"Kaue Ruiz","email":"kaue@fiapx.com","password":"senhaSegura1","password_confirmation":"senhaSegura1"}' \
  | python -m json.tool
```

```bash
# Rota protegida sem token
curl -s $API/videos | python -m json.tool
```

Deve responder `{"message": "Token de autenticacao ausente."}`.

```bash
# Guarda o token para os próximos passos
TOKEN=$(curl -s -X POST $API/auth/login -H 'Content-Type: application/json' \
  -d '{"email":"kaue@fiapx.com","password":"senhaSegura1"}' \
  | grep -o '"token":"[^"]*"' | cut -d'"' -f4)
echo "${TOKEN:0:40}..."
```

**Diga:** *"Requisito de proteção por usuário e senha: bcrypt no banco, JWT HS256
para as chamadas. Sem token, 401."*

---

### Bloco 4 — A cena principal: a fila segura o pico (3:15 – 5:30)

Esta é a parte mais importante do vídeo. **Derrube os workers de propósito.**

```bash
docker compose stop worker
```

**Diga:** *"Vou simular o pior caso: o serviço de processamento inteiro fora do ar
no momento em que chegam vários vídeos."*

```bash
# 6 uploads simultâneos com o processamento fora do ar
for i in 1 2; do
  for v in paisagem viagem aniversario; do
    curl -s -X POST $API/videos -H "Authorization: Bearer $TOKEN" -F "video=@demo/$v.mp4" > /dev/null &
  done
done
wait
echo "6 uploads enviados"
```

```bash
# Nenhum foi recusado
curl -s "$API/videos" -H "Authorization: Bearer $TOKEN" | python -c "
import sys,json
from collections import Counter
d=json.load(sys.stdin)
print('total:', d['meta']['total'])
print(Counter(v['status'] for v in d['data']))"
```

Mostre `6 PENDING`. **Agora vá ao navegador, aba do RabbitMQ**, e mostre a fila
`video.processing` com **6 mensagens acumuladas**.

**Diga:** *"Seis vídeos aceitos, nenhuma requisição perdida, e o trabalho está
guardado numa fila durável. Se o broker reiniciar agora, as mensagens continuam
lá — são persistentes."*

Agora suba **três** réplicas:

```bash
docker compose up -d --scale worker=3
```

Aguarde uns 10 segundos e mostre:

```bash
# A fila drenou
docker compose exec rabbitmq rabbitmqctl list_queues name messages | grep video
```

```bash
# Todos concluídos, com a contagem de frames
curl -s "$API/videos" -H "Authorization: Bearer $TOKEN" | python -c "
import sys,json
for v in json.load(sys.stdin)['data']:
    print(f\"  {v['filename']:20s} {v['status']:11s} frames={v['frame_count']}\")"
```

**Diga:** *"paisagem tem 10 segundos e rendeu 10 frames, viagem tem 15 e rendeu 15,
aniversário tem 8 e rendeu 8 — um frame por segundo, como no projeto original."*

```bash
# Quem processou o quê — prova do paralelismo
docker compose logs worker --since 5m | grep 'processamento concluido' | awk '{print $1}' | sort | uniq -c
```

**Diga:** *"O trabalho foi dividido entre as três réplicas automaticamente. Escalar
é só aumentar o número de réplicas — em Kubernetes o HPA faz isso sozinho, de 2 a 20."*

---

### Bloco 5 — Download do resultado (5:30 – 6:15)

```bash
ID=$(curl -s "$API/videos?status=COMPLETED" -H "Authorization: Bearer $TOKEN" \
  | grep -o '"id":"[^"]*"' | head -1 | cut -d'"' -f4)

curl -s "$API/videos/$ID/download" -H "Authorization: Bearer $TOKEN" | python -m json.tool
```

**Diga:** *"A API não serve o arquivo: ela devolve um link assinado válido por 15
minutos, e o usuário baixa direto do storage. Transferir centenas de megabytes
pelo PHP ocuparia um processo inteiro por download."*

```bash
URL=$(curl -s "$API/videos/$ID/download" -H "Authorization: Bearer $TOKEN" \
  | grep -o '"download_url":"[^"]*"' | cut -d'"' -f4)
curl -s "$URL" -o demo/frames.zip
unzip -l demo/frames.zip | tail -5
```

Abra o ZIP no explorador de arquivos e mostre os PNGs.

---

### Bloco 6 — Tratamento de erro e notificação (6:15 – 7:30)

```bash
curl -s -X POST $API/videos -H "Authorization: Bearer $TOKEN" \
  -F "video=@demo/video-corrompido.mp4" | python -m json.tool
```

**Diga:** *"Este arquivo tem cabeçalho de MP4 válido, então passa na validação da
API, mas os dados estão truncados: o ffmpeg vai falhar."*

Aguarde ~15 segundos (as três tentativas) e mostre:

```bash
curl -s "$API/videos?status=FAILED" -H "Authorization: Bearer $TOKEN" | python -c "
import sys,json
for v in json.load(sys.stdin)['data']:
    print(f\"  {v['filename']}  {v['status']}  tentativas={v['attempts']}\")
    print(f\"  motivo: {v['error_message'][:80]}\")"
```

**Vá ao Mailpit** (http://localhost:8025) e abra o e-mail recebido.

**Diga:** *"Requisito de notificação em caso de erro: o usuário é avisado uma única
vez, no desfecho definitivo — não a cada tentativa."*

Volte ao terminal:

```bash
docker compose exec rabbitmq rabbitmqctl list_queues name messages | grep video
```

**Diga:** *"E a mensagem ficou isolada na dead letter queue. O sistema distingue
erro permanente de erro transitório: storage fora do ar reenfileira, vídeo
corrompido vai para a DLQ depois de três tentativas e não volta a ocupar worker."*

---

### Bloco 7 — Qualidade e observabilidade (7:30 – 9:00)

**Testes** — mostre rodando ao vivo:

```bash
docker compose exec api vendor/bin/phpunit
```

*"55 testes na API, 86% de cobertura de linhas."*

```bash
docker run --rm -v "$PWD/worker:/src" -w /src golang:1.23-alpine \
  sh -c "apk add --no-cache ffmpeg >/dev/null && go test ./... -cover" | grep -v "no test files"
```

*"29 testes no worker: domínio 90%, caso de uso 86%, configuração 100%."*

**Observabilidade** — abra o Grafana (http://localhost:3001) ou o Prometheus
(http://localhost:9090) e consulte:

```
fiapx_videos_processados_total
```

Mostre os três resultados: `sucesso`, `falha_transitoria`, `falha_permanente` —
que batem exatamente com o que aconteceu na demonstração.

**Logs correlacionados:**

```bash
docker compose logs worker --tail 3
```

*"Logs em JSON com correlation_id, que acompanha o upload da API até o worker e
volta no evento de resultado."*

**CI/CD** — abra [`.github/workflows/ci.yml`](../.github/workflows/ci.yml) e mostre
os jobs: worker, api, integração ponta a ponta, validação dos manifestos e
varredura de vulnerabilidades. Mencione o gate de 80% de cobertura.

Se já tiver feito push, mostre a aba **Actions** do GitHub com a pipeline verde.

---

### Bloco 8 — Fechamento (9:00 – 9:30)

Mostre a estrutura do repositório e os entregáveis:

```bash
ls docs/architecture/    # ADRs e documento de arquitetura
ls k8s/                  # manifestos com HPA
cat database/schema.sql | head -30
```

**Diga:** *"Documentação de arquitetura com diagramas, cinco ADRs justificando as
decisões, script de criação do banco, contrato OpenAPI, manifestos Kubernetes com
autoescalonamento e pipeline de CI/CD. O projeto base continua no repositório, em
legacy, como referência do ponto de partida."*

---

## Plano B

Se algo travar durante a gravação, você tem uma saída rápida:

```bash
bash scripts/smoke-test.sh
```

Ele executa o fluxo completo — cadastro, bloqueio sem token, uploads simultâneos,
download do ZIP, falha, e-mail e DLQ — imprimindo cada passo. Serve como
demonstração completa em ~40 segundos.

---

## Comandos de emergência

| Situação | Comando |
|---|---|
| Algo ficou inconsistente | `bash scripts/reset-demo.sh` |
| Um serviço caiu | `docker compose up -d` |
| Ver o que houve de errado | `docker compose logs --tail 50` |
| Reconstruir tudo do zero | `docker compose down -v && docker compose up -d --build` |
| Refazer as migrations | `docker compose exec api php artisan migrate:fresh --force` |

---

## Checklist final antes de enviar

- [ ] Vídeo com no máximo **10 minutos**
- [ ] Mostrou a **documentação** (arquitetura + ADRs)
- [ ] Explicou a **arquitetura escolhida** e a justificativa
- [ ] Demonstrou o **projeto funcionando** de ponta a ponta
- [ ] Upload no YouTube ou Vimeo (público ou **não listado**)
- [ ] PDF de entrega com: link do repositório GitHub, link do vídeo e o
      diagrama da arquitetura
