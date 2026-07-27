# Roteiro do vídeo de demonstração

Hackathon FIAP X · limite de **10 minutos**

O enunciado exige três coisas: **documentação**, **arquitetura escolhida** e **o
projeto funcionando**. Aqui elas não são três capítulos separados — a arquitetura
é narrada por cima da tela funcionando, e o diagrama entra depois só para
confirmar o que o espectador já viu acontecer.

Total: 9 min 30 s.

---

## Antes de apertar o rec

```bash
cd c:/Users/kaue_/OneDrive/Documentos/projeto_fiap_x
docker compose up -d
docker compose exec api php artisan migrate --force

bash scripts/preparar-demo.sh   # gera demo/*.mp4 — só na primeira vez
bash scripts/reset-demo.sh      # zera tudo entre as tomadas
```

| Aba / janela | Endereço |
|---|---|
| **Interface** | http://localhost:8080 — onde a demonstração acontece |
| Terminal (Git Bash) | na raiz do projeto |
| Editor | `legacy/main.go` e `docs/architecture/arquitetura.md` |
| RabbitMQ | http://localhost:15672 (`fiapx` / `fiapx`) → aba *Queues* |
| Mailpit | http://localhost:8025 |
| Grafana | http://localhost:3001 (`admin` / `admin`) |
| Pasta `demo/` | no explorador, ao lado do navegador, para arrastar os vídeos |

O ambiente já está zerado: **nenhuma conta cadastrada**, para você poder mostrar
o cadastro ao vivo.

---

## Roteiro

### 1 · O problema — 0:00 a 0:45

Abra [`legacy/main.go`](../legacy/main.go) na linha 117:

```go
result := processVideo(videoPath, timestamp)   // trava aqui até o ffmpeg terminar
c.JSON(200, result)
```

**Diga:** *"Este é o projeto que foi apresentado aos investidores. Ele funciona,
mas processa o vídeo dentro da requisição HTTP: o usuário espera o ffmpeg terminar
e o servidor fica preso nisso. Não tem banco, não tem fila, não tem autenticação, e
o estado é descoberto listando arquivos no disco. Era isso que precisava mudar."*

> Sem se demorar: o objetivo é só estabelecer o ponto de partida.

---

### 2 · A solução, funcionando — 0:45 a 2:15

**Esta é a parte que substitui a explicação teórica.** Abra
**http://localhost:8080** e narre a arquitetura enquanto usa o sistema.

1. **Crie a conta** ao vivo.

   **Diga:** *"Primeiro requisito: o sistema é protegido por usuário e senha. A
   senha vai em bcrypt para o banco e o login devolve um token JWT, que é exigido
   em todas as rotas de vídeo."*

2. **Arraste `demo/paisagem.mp4`** para a área de envio.

   **Diga enquanto a barra sobe:** *"Quando eu solto o arquivo aqui, a API faz três
   coisas e devolve na hora: grava o vídeo no storage de objetos, registra o pedido
   no banco como pendente e publica uma mensagem na fila. Ela não processa nada —
   responde 202 e libera a conexão."*

3. **A linha aparece como "Na fila" e vira "Pronto" sozinha.**

   **Diga:** *"Quem processa é um segundo serviço, escrito em Go, que consome essa
   fila, roda o ffmpeg, monta o ZIP e devolve o resultado ao storage. Ele avisa o
   fim por outra mensagem, e é isso que faz esta tela mudar sozinha — sem eu
   recarregar nada."*

4. Aponte para os **10 frames**.

   **Diga:** *"O vídeo tem 10 segundos e rendeu 10 frames: um por segundo, a mesma
   extração do projeto original — agora fora do caminho da requisição."*

---

### 3 · A documentação — 2:15 a 3:15

Agora que o espectador **viu o caminho acontecer**, o diagrama confirma em vez de
introduzir. Por isso este bloco é rápido.

Abra [`docs/architecture/arquitetura.md`](architecture/arquitetura.md):

- **Diagrama de componentes** — *"É esse desenho que acabamos de percorrer: API,
  fila, worker, storage e banco."*
- **Diagrama de sequência** — *"O mesmo fluxo, passo a passo, do upload ao
  download."*
- Role até o **modelo de dados** — *"Com a justificativa do PostgreSQL e dos
  índices."*

Mostre a pasta e cite as decisões:

```bash
ls docs/architecture/
```

**Diga:** *"Cinco ADRs registram as decisões e o porquê: processamento assíncrono
por fila, worker em Go separado da API, storage de objetos no lugar do disco local,
JWT com segredo compartilhado e a política de retentativas com dead letter queue.
Tem também o contrato OpenAPI e o script de criação do banco."*

> Um item de cada, sem ler o conteúdo. O objetivo é evidenciar que a documentação
> existe e é específica, não percorrê-la.

---

### 4 · A fila segura o pico — 3:15 a 5:45 · **cena principal**

Prova os dois requisitos mais difíceis: processar vários vídeos ao mesmo tempo e
não perder requisição em pico. **Derrube os workers de propósito.**

```bash
docker compose stop worker
```

**Diga:** *"Vou simular o pior caso: o serviço de processamento inteiro fora do ar
justamente quando chegam vários vídeos."*

Na interface, **arraste os três vídeos de uma vez — e repita**, para dar seis.

Na tela: todos aceitos, sem erro, todos **Na fila**, contador **Em andamento: 6**.

Vá ao **RabbitMQ → Queues**: `video.processing` com **6 mensagens acumuladas**.

**Diga:** *"Seis vídeos aceitos, nenhuma requisição perdida. O trabalho está numa
fila durável, com mensagens persistentes: se o broker reiniciar agora, elas
continuam lá."*

Suba três réplicas:

```bash
docker compose up -d --scale worker=3
```

**Volte para a interface sem recarregar.** As linhas mudam sozinhas para *Pronto*
e o contador migra para **Prontos: 6**.

```bash
docker compose exec rabbitmq rabbitmqctl list_queues name messages | grep video
docker compose logs worker --since 5m | grep 'processamento concluido' | awk '{print $1}' | sort | uniq -c
```

**Diga:** *"A fila zerou e os seis vídeos foram divididos entre as três réplicas
automaticamente. Escalar é aumentar o número de réplicas — em Kubernetes o HPA faz
isso sozinho, de 2 a 20."*

---

### 5 · Download — 5:45 a 6:15

Clique em **Baixar .zip**, abra o arquivo e mostre os PNGs.

**Diga:** *"A API não serve o arquivo: ela assina um link válido por 15 minutos e o
navegador baixa direto do storage. Passar centenas de megabytes pelo PHP ocuparia
um processo inteiro por download."*

---

### 6 · Erro e notificação — 6:15 a 7:30

Arraste **`demo/video-corrompido.mp4`**.

**Diga:** *"Este arquivo tem cabeçalho de MP4 válido, então passa na validação, mas
os dados estão truncados: o ffmpeg vai falhar."*

Aguarde ~15 s — são as três tentativas. A linha vira **Falhou**, com o motivo do
erro visível.

Abra o **Mailpit** e mostre o e-mail.

**Diga:** *"Último requisito funcional: em caso de erro o usuário é notificado —
uma única vez, no desfecho definitivo, não a cada tentativa."*

```bash
docker compose exec rabbitmq rabbitmqctl list_queues name messages | grep video
```

**Diga:** *"E a mensagem ficou isolada na dead letter queue. O sistema separa erro
permanente de transitório: storage fora do ar reenfileira; vídeo corrompido sai do
fluxo depois de três tentativas e não volta a ocupar worker."*

---

### 7 · Qualidade e operação — 7:30 a 9:00

```bash
docker compose exec api vendor/bin/phpunit
```
*"63 testes na API, quase 87% de cobertura de linhas."*

```bash
docker run --rm -v "$PWD/worker:/src" -w /src golang:1.23-alpine \
  sh -c "apk add --no-cache ffmpeg >/dev/null && go test ./... -cover" | grep -v "no test files"
```
*"29 testes no worker: domínio 90%, caso de uso 86%, configuração 100%."*

**Grafana ou Prometheus** — consulte `fiapx_videos_processados_total`: os três
resultados (`sucesso`, `falha_transitoria`, `falha_permanente`) batem com o que
acabou de acontecer na demonstração.

```bash
docker compose logs worker --tail 3
```
*"Logs em JSON nos dois serviços, com o mesmo correlation_id ligando a requisição
da API ao processamento no worker."*

Abra [`.github/workflows/ci.yml`](../.github/workflows/ci.yml): build, testes,
gate de 80% de cobertura, integração ponta a ponta contra a stack real, validação
dos manifestos Kubernetes e varredura de vulnerabilidades. Se já tiver feito push,
mostre a aba **Actions** verde.

---

### 8 · Fechamento — 9:00 a 9:30

```bash
ls k8s/
head -30 database/schema.sql
```

**Diga:** *"Manifestos Kubernetes com autoescalonamento, script de criação do banco,
documentação de arquitetura e pipeline de CI/CD — tudo versionado. E o projeto base
continua no repositório, na pasta legacy, como referência do ponto de partida."*

---

## Plano B

```bash
bash scripts/smoke-test.sh
```

Executa o fluxo completo em ~40 s, imprimindo cada passo: cadastro, bloqueio sem
token, uploads simultâneos, download, falha, e-mail e DLQ.

## Emergência

| Situação | Comando |
|---|---|
| Estado inconsistente | `bash scripts/reset-demo.sh` |
| Um serviço caiu | `docker compose up -d` |
| Ver o que houve | `docker compose logs --tail 50` |
| Reconstruir do zero | `docker compose down -v && docker compose up -d --build` |

## Antes de enviar

- [ ] Vídeo com no máximo **10 minutos**
- [ ] **Documentação** apresentada (bloco 3)
- [ ] **Arquitetura** explicada e justificada (blocos 2 e 3)
- [ ] **Projeto funcionando**, incluindo o caminho de erro
- [ ] Upload no YouTube ou Vimeo (público ou **não listado**)
- [ ] PDF com link do repositório, link do vídeo e diagrama da arquitetura
