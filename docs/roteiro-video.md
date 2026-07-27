# Roteiro do vídeo de demonstração

Hackathon FIAP X · limite de **10 minutos**

O enunciado exige três coisas: **documentação**, **arquitetura escolhida** e **o
projeto funcionando**. O roteiro cobre as três em 9 min 30 s.

A demonstração acontece pela **interface web** (http://localhost:8080). O terminal
entra só onde ele prova algo que a tela não mostra: a fila do broker, as réplicas
do worker e os testes.

---

## Antes de apertar o rec

### 1. Suba o ambiente

```bash
cd c:/Users/kaue_/OneDrive/Documentos/projeto_fiap_x
docker compose up -d
docker compose exec api php artisan migrate --force
```

### 2. Prepare os arquivos e zere o estado

```bash
bash scripts/preparar-demo.sh   # gera demo/*.mp4 — só na primeira vez
bash scripts/reset-demo.sh      # limpa banco, storage, filas, e-mails e logs
```

> Rode `reset-demo.sh` entre cada tomada. Ele zera tudo **sem derrubar os
> containers**, então você não espera a stack subir de novo.

### 3. Deixe aberto

| Aba / janela | Endereço |
|---|---|
| **Interface** | http://localhost:8080 — a estrela da demonstração |
| Terminal (Git Bash) | na raiz do projeto |
| Editor | `legacy/main.go` e `docs/architecture/arquitetura.md` |
| RabbitMQ | http://localhost:15672 (`fiapx` / `fiapx`) → aba *Queues* |
| Mailpit | http://localhost:8025 |
| Grafana | http://localhost:3001 (`admin` / `admin`) |
| Pasta `demo/` | no explorador, para arrastar os vídeos para a tela |

> Deixe a pasta `demo/` visível ao lado do navegador. Arrastar os arquivos para
> a interface rende muito melhor em vídeo do que clicar em "escolher arquivos".

### 4. Confira

```bash
docker compose ps
curl -s http://localhost:8080/api/health
```

---

## Roteiro

### Bloco 1 — O problema (0:00 – 1:00)

Abra [`legacy/main.go`](../legacy/main.go) na linha 117:

```go
result := processVideo(videoPath, timestamp)   // trava aqui até o ffmpeg terminar
c.JSON(200, result)
```

**Diga:** *"Este é o projeto apresentado aos investidores. Funciona, mas processa
o vídeo dentro da requisição HTTP: o usuário espera o ffmpeg terminar e o servidor
fica preso nisso. Não tem banco, não tem fila, não tem autenticação, e o estado é
descoberto listando arquivos no disco com Glob."*

Mostre o comentário do [`legacy/Dockerfile`](../legacy/Dockerfile): *"sem boas
práticas — propositalmente"*.

---

### Bloco 2 — A arquitetura (1:00 – 2:15)

Abra [`docs/architecture/arquitetura.md`](architecture/arquitetura.md) no diagrama
de componentes e depois no de sequência.

- A API **só recebe** o vídeo e responde `202`. Quem processa é o worker.
- O que separa os dois é a **fila** — ela absorve o pico e garante que nada se perca.
- **Duas linguagens:** a API é I/O curto e reaproveita o ecossistema Laravel das
  fases anteriores; o worker é CPU longo e herda o código de extração do projeto base.
- Cite as cinco ADRs.

```bash
ls worker/internal/domain worker/internal/usecase worker/internal/adapter
```

**Diga:** *"O domínio não importa nada de infraestrutura. ffmpeg, MinIO e RabbitMQ
são adaptadores atrás de interfaces — por isso o caso de uso é testável sem subir nada."*

---

### Bloco 3 — Primeiro contato (2:15 – 3:15)

Abra **http://localhost:8080**.

1. Clique em **Criar conta** e cadastre-se ao vivo.
2. Já logado, **arraste `demo/paisagem.mp4`** da pasta para a área de envio.
3. A barra de progresso aparece, e o vídeo entra na lista como **Na fila**.
4. Em segundos ele vira **Pronto**, com **10 frames** — a tela atualiza sozinha.

**Diga:** *"O envio responde na hora: o vídeo entra na fila e a tela acompanha o
processamento sozinha. O arquivo tem 10 segundos e rendeu 10 frames — um por
segundo, como no projeto original."*

> Se quiser mostrar a proteção: clique em **Sair** e tente voltar. A tela de login
> reaparece, porque sem token a API responde 401 em qualquer rota de vídeo.

---

### Bloco 4 — A fila segura o pico (3:15 – 5:45) · cena principal

É o momento que prova os dois requisitos mais difíceis do enunciado. **Derrube os
workers de propósito.**

```bash
docker compose stop worker
```

**Diga:** *"Vou simular o pior caso: o serviço de processamento inteiro fora do ar
no momento em que chegam vários vídeos."*

Agora, **na interface**, arraste os **três vídeos de uma vez** — e repita, para dar
seis no total.

O que aparece na tela:
- todos aceitos, nenhum erro;
- todos com a etiqueta **Na fila**;
- o contador **Em andamento: 6**.

Vá ao **RabbitMQ → Queues** e mostre `video.processing` com **6 mensagens**.

**Diga:** *"Seis vídeos aceitos, nenhuma requisição perdida, e o trabalho está
guardado numa fila durável. Se o broker reiniciar agora, as mensagens continuam
lá — são persistentes."*

Suba **três** réplicas:

```bash
docker compose up -d --scale worker=3
```

**Volte para a interface sem recarregar.** Em segundos as linhas mudam sozinhas de
*Na fila* para *Pronto*, e o contador migra para **Prontos: 6**.

Confirme no terminal que a fila drenou e como o trabalho se dividiu:

```bash
docker compose exec rabbitmq rabbitmqctl list_queues name messages | grep video
docker compose logs worker --since 5m | grep 'processamento concluido' | awk '{print $1}' | sort | uniq -c
```

**Diga:** *"A fila zerou e o trabalho foi dividido entre as três réplicas
automaticamente. Escalar é só aumentar o número de réplicas — em Kubernetes o HPA
faz isso sozinho, de 2 a 20."*

---

### Bloco 5 — Download (5:45 – 6:15)

Na interface, clique em **Baixar .zip** em qualquer vídeo pronto. Abra o arquivo
baixado e mostre os PNGs.

**Diga:** *"A API não serve o arquivo: ela assina um link válido por 15 minutos e o
navegador baixa direto do storage. Transferir centenas de megabytes pelo PHP
ocuparia um processo inteiro por download."*

---

### Bloco 6 — Erro e notificação (6:15 – 7:30)

Arraste **`demo/video-corrompido.mp4`** para a interface.

**Diga:** *"Este arquivo tem cabeçalho de MP4 válido, então passa na validação, mas
os dados estão truncados: o ffmpeg vai falhar."*

Aguarde ~15 segundos — são as três tentativas. Na tela, a linha vira **Falhou**,
em vermelho, **com o motivo do erro visível**.

Abra o **Mailpit** (http://localhost:8025) e mostre o e-mail recebido.

**Diga:** *"Requisito de notificação em caso de erro: o usuário é avisado uma única
vez, no desfecho definitivo — não a cada tentativa."*

```bash
docker compose exec rabbitmq rabbitmqctl list_queues name messages | grep video
```

**Diga:** *"E a mensagem ficou isolada na dead letter queue. O sistema distingue
erro permanente de transitório: storage fora do ar reenfileira; vídeo corrompido
vai para a DLQ depois de três tentativas e não volta a ocupar worker."*

---

### Bloco 7 — Qualidade e observabilidade (7:30 – 9:00)

```bash
docker compose exec api vendor/bin/phpunit
```
*"63 testes na API, quase 87% de cobertura de linhas."*

```bash
docker run --rm -v "$PWD/worker:/src" -w /src golang:1.23-alpine \
  sh -c "apk add --no-cache ffmpeg >/dev/null && go test ./... -cover" | grep -v "no test files"
```
*"29 testes no worker: domínio 90%, caso de uso 86%, configuração 100%."*

**Grafana ou Prometheus** — consulte `fiapx_videos_processados_total` e mostre os
três resultados (`sucesso`, `falha_transitoria`, `falha_permanente`), que batem
exatamente com o que acabou de acontecer na demonstração.

```bash
docker compose logs worker --tail 3
docker compose logs api --tail 3
```
*"Logs em JSON nos dois serviços, com o mesmo correlation_id acompanhando o upload
da API até o worker."*

Abra [`.github/workflows/ci.yml`](../.github/workflows/ci.yml): jobs de worker, api,
integração ponta a ponta, validação dos manifestos e varredura de vulnerabilidades,
com gate de 80% de cobertura. Se já tiver feito push, mostre a aba **Actions** verde.

---

### Bloco 8 — Fechamento (9:00 – 9:30)

```bash
ls docs/architecture/
ls k8s/
head -30 database/schema.sql
```

**Diga:** *"Documentação de arquitetura com diagramas, cinco ADRs justificando as
decisões, script de criação do banco, contrato OpenAPI, manifestos Kubernetes com
autoescalonamento e pipeline de CI/CD. O projeto base continua no repositório, em
legacy, como referência do ponto de partida."*

---

## Plano B

Se algo travar ao vivo:

```bash
bash scripts/smoke-test.sh
```

Executa o fluxo completo — cadastro, bloqueio sem token, uploads simultâneos,
download, falha, e-mail e DLQ — imprimindo cada passo, em ~40 segundos.

## Comandos de emergência

| Situação | Comando |
|---|---|
| Estado inconsistente | `bash scripts/reset-demo.sh` |
| Um serviço caiu | `docker compose up -d` |
| Ver o que houve | `docker compose logs --tail 50` |
| Reconstruir do zero | `docker compose down -v && docker compose up -d --build` |
| Refazer as migrations | `docker compose exec api php artisan migrate:fresh --force` |

## Antes de enviar

- [ ] Vídeo com no máximo **10 minutos**
- [ ] Mostrou a **documentação** (arquitetura + ADRs)
- [ ] Explicou a **arquitetura escolhida** e a justificativa
- [ ] Demonstrou o **projeto funcionando**, incluindo o caminho de erro
- [ ] Upload no YouTube ou Vimeo (público ou **não listado**)
- [ ] PDF com link do repositório, link do vídeo e diagrama da arquitetura
