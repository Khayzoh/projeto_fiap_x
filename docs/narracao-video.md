# Narração do vídeo — texto para ler

Hackathon FIAP X · POSTECH 13SOAT

Este é o **texto falado**, do começo ao fim. O que está entre colchetes é ação na
tela, não se lê. O guia de operação — comandos, ordem das janelas, plano B — está
em [`roteiro-video.md`](roteiro-video.md); use os dois lado a lado.

**Antes de gravar:** troque `[SEU NOME]` pelo seu nome. Se for apresentar em grupo,
ajuste a abertura.

> **Sobre o tempo.** São ~1.000 palavras: cerca de 7 minutos de fala em ritmo natural.
> Os ~2 min 30 s restantes são as pausas do sistema — cadastro, upload, as três
> tentativas do vídeo corrompido, os testes rodando. Fecha em 9 min 30 s.
>
> **Dica:** rode os testes uma vez **antes** de gravar. O `go test` baixa o ffmpeg na
> primeira execução e demora bem mais; na segunda já está em cache. Sem isso, o bloco
> de qualidade vira tempo morto e o vídeo estoura.

---

## Abertura · 0:00

> Olá. Meu nome é **[SEU NOME]**, e este é o Hackathon da fase 5 da POSTECH,
> turma 13SOAT.
>
> O desafio era pegar o sistema de processamento de vídeos da FIAP X — aquele
> apresentado aos investidores — e transformá-lo numa aplicação que aguenta operação
> real.

*[AÇÃO: abrir `legacy/main.go`, linha 117]*

> Este é o projeto original. Repare no que acontece aqui: o `processVideo` é chamado
> **dentro** da requisição HTTP. O usuário sobe o vídeo e fica esperando o ffmpeg
> terminar, com o servidor preso nesse processamento.
>
> Fora isso: não tem banco, não tem fila, não tem autenticação, e o estado dos
> arquivos é descoberto listando o disco. Se duas pessoas subirem vídeo ao mesmo
> tempo, uma espera a outra. Se o processo cair no meio, o trabalho se perde.

---

## A solução funcionando · 0:45

*[AÇÃO: abrir a interface em localhost:8080]*

> Esta é a nova versão. Vou criar uma conta.

*[AÇÃO: criar a conta ao vivo]*

> Primeiro requisito: o sistema é protegido por usuário e senha. A senha vai
> criptografada para o banco, e o login devolve um token exigido em todas as rotas.
>
> Agora o envio.

*[AÇÃO: arrastar `demo/paisagem.mp4`]*

> Aqui está a diferença central do projeto. Quando eu solto o arquivo, a aplicação
> faz três coisas e responde **na hora**: guarda o vídeo, registra o pedido como
> pendente e coloca numa fila. Ela não processa nada — libera a conexão na hora.
>
> Quem processa é outro serviço, rodando separado. Ele pega da fila, extrai os
> frames, monta o ZIP e devolve o resultado. Quando termina, avisa.

*[AÇÃO: apontar a linha virando "Pronto"]*

> É esse aviso que faz a tela mudar sozinha — eu não recarreguei a página.
>
> O vídeo tem 10 segundos e gerou 10 frames: um por segundo, a mesma extração do
> projeto original, agora fora do caminho da requisição.

---

## As escolhas · 2:00

*[AÇÃO: abrir `docs/architecture/arquitetura.md` no diagrama de componentes]*

> Esse caminho que acabamos de percorrer está documentado aqui. E deixa eu explicar
> por que cada peça é o que é.
>
> São duas linguagens, e isso foi de propósito. A API é em **Laravel**, que é o que eu
> já vinha usando nas fases anteriores do curso — autenticação, mensageria, manifestos
> e pipeline vieram prontos de lá. Já o worker é em **Go**, que é a linguagem do
> projeto original: processar vídeo é trabalho pesado de processador, e o Go gera um
> binário pequeno, que sobe rápido. Isso importa na hora de criar réplicas sob carga.
>
> No meio dos dois está o **RabbitMQ**. É ele que segura o pico e garante que nada se
> perde, com as filas gravadas em disco e uma fila separada para o que falha em
> definitivo.
>
> Os dados ficam no **PostgreSQL**, porque o modelo é relacional e precisa de
> integridade entre usuário e vídeo. O **Redis** cuida do cache. E os vídeos e os ZIPs
> vão para um storage de objetos compatível com **S3** — aqui local, o **MinIO**. É
> isso que permite ter várias instâncias ao mesmo tempo: nenhum arquivo fica preso ao
> disco de uma máquina.
>
> Tudo roda em **Docker**, com manifestos de **Kubernetes** e autoescalonamento.
> **Prometheus** e **Grafana** mostram o que está acontecendo, e o **GitHub Actions**
> cuida dos testes e do deploy.

*[AÇÃO: mostrar a pasta `docs/architecture/`]*

> Cada uma dessas decisões está registrada em uma ADR, com o motivo e as alternativas
> descartadas. Tem também o contrato da API e o script de criação do banco.

---

## A fila segura o pico · 4:00

> Agora a parte principal. O desafio pede duas coisas difíceis: processar vários
> vídeos ao mesmo tempo, e não perder nenhuma requisição em caso de pico.
>
> Vou provar as duas simulando o pior cenário: derrubando o serviço de processamento
> inteiro.

*[AÇÃO: `docker compose stop worker`]*

> Não há mais nenhum worker de pé. E eu vou mandar seis vídeos.

*[AÇÃO: arrastar os três vídeos, duas vezes]*

> Os seis foram aceitos. Nenhum erro, nenhuma requisição recusada.

*[AÇÃO: abrir o RabbitMQ, aba Queues]*

> E estão todos aqui: seis mensagens acumuladas numa fila durável, gravadas em disco.
> Se o broker reiniciar agora, elas continuam aqui.
>
> É isso que significa não perder requisição em pico: o excedente vira fila, não vira
> erro.
>
> Agora subo três réplicas do worker de uma vez.

*[AÇÃO: `docker compose up -d --scale worker=3`, voltar para a interface]*

> E sem eu tocar em nada, a tela vai atualizando: um por um, os vídeos saem da fila e
> ficam prontos.

*[AÇÃO: rodar os comandos de verificação]*

> A fila zerou, e os seis vídeos foram divididos entre as três réplicas
> automaticamente. Escalar é só aumentar o número de réplicas — em Kubernetes, o
> autoescalonamento faz isso sozinho, de duas a vinte, conforme a carga.

---

## Download · 6:15

*[AÇÃO: clicar em "Baixar .zip" e abrir o arquivo]*

> Aqui estão os frames extraídos, um para cada segundo do vídeo.
>
> A API não serve esse arquivo: ela assina um link temporário de quinze minutos e o
> navegador baixa direto do storage. Se o download passasse pela aplicação, cada
> usuário baixando prenderia um processo do servidor.

---

## Erro e notificação · 6:45

> Falta mostrar o que acontece quando dá errado. Este arquivo tem cabeçalho de MP4
> válido, então passa na validação, mas os dados estão corrompidos.

*[AÇÃO: arrastar `demo/video-corrompido.mp4` e aguardar]*

> O sistema tenta três vezes antes de desistir. Falhou — e a tela mostra o motivo
> exato do erro para o usuário.

*[AÇÃO: abrir o Mailpit]*

> E aqui está o último requisito funcional: o usuário foi notificado por e-mail. Uma
> única vez, no desfecho definitivo, não a cada tentativa.

*[AÇÃO: rodar o comando das filas]*

> E a mensagem ficou isolada nesta fila separada, a dead letter queue. O sistema
> distingue erro permanente de temporário: falha de infraestrutura ele tenta de novo;
> vídeo corrompido sai do fluxo e para de ocupar worker.

---

## Qualidade e operação · 8:00

*[AÇÃO: rodar os testes da API e do worker]*

> São 63 testes na API, com quase 87% de cobertura, e 29 testes no worker — 90% no
> domínio e 100% na configuração.

*[AÇÃO: abrir o Grafana ou Prometheus]*

> Estas são as métricas do sistema, e os números batem com o que acabamos de fazer:
> os processamentos que deram certo, as tentativas repetidas e a falha definitiva do
> vídeo corrompido.

*[AÇÃO: mostrar os logs do worker]*

> Os dois serviços emitem log em JSON, com um identificador que liga a requisição na
> API ao processamento no worker.

*[AÇÃO: abrir `.github/workflows/ci.yml`]*

> E a pipeline roda build, testes, cobertura mínima de 80%, um teste de integração
> contra o ambiente completo, validação dos manifestos e varredura de
> vulnerabilidades.

---

## Fechamento · 9:00

*[AÇÃO: mostrar `k8s/` e `database/schema.sql`]*

> Para fechar: o repositório tem os manifestos do Kubernetes com autoescalonamento, o
> script de criação do banco, a documentação de arquitetura e a pipeline completa.
>
> E o projeto original continua ali, na pasta `legacy` — porque a lógica de extração
> de frames dele não foi jogada fora: ela virou o núcleo do worker, agora assíncrono
> e escalável.
>
> Era isso. Obrigado!

---

## Se estourar o tempo

O bloco das escolhas é o mais longo (1 min 50 s) porque é ele que cobre a
"arquitetura escolhida" exigida no enunciado — evite cortar dali. Corte nesta ordem:

1. **Qualidade e operação** — fale só os números dos testes e mostre a pipeline, sem
   passar pelo Grafana e pelos logs.
2. **Download** — clique e mostre o ZIP, sem explicar o link assinado.
3. **Abertura** — vá direto ao `legacy/main.go` depois de se apresentar.

O bloco da fila e o de erro não devem ser cortados: são eles que provam os
requisitos funcionais do desafio.
