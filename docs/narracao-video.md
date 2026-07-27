# Narração do vídeo — texto para ler

Hackathon FIAP X · POSTECH 13SOAT

Este é o **texto falado**, do começo ao fim. O que está entre colchetes é ação na
tela, não se lê. O guia de operação — comandos, ordem das janelas, plano B — está
em [`roteiro-video.md`](roteiro-video.md); use os dois lado a lado.

**Antes de gravar:** troque `[SEU NOME]` pelo seu nome. Se for apresentar em grupo,
ajuste a abertura.

> **Sobre o tempo.** São ~900 palavras: cerca de 6 min 30 s de fala em ritmo natural.
> Os ~3 minutos restantes são as pausas do sistema — cadastro, upload, processamento,
> testes rodando. Fecha em 9 min 30 s, com folga sobre o limite de 10.
>
> **Dica:** rode os testes uma vez **antes** de gravar. O `go test` baixa o ffmpeg na
> primeira execução e demora bem mais; na segunda vez já está em cache.

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

> Primeiro requisito: o sistema é protegido por usuário e senha. A senha é gravada
> com bcrypt, e o login devolve um token JWT exigido em todas as rotas de vídeo.
>
> Agora o envio.

*[AÇÃO: arrastar `demo/paisagem.mp4`]*

> Aqui está a diferença central do projeto. Quando eu solto o arquivo, a API faz três
> coisas e responde **na hora**: grava o vídeo no storage de objetos, registra o
> pedido no banco como pendente, e publica uma mensagem numa fila. Ela não processa
> nada — devolve 202 e libera a conexão.
>
> Quem processa é um segundo serviço, escrito em Go, rodando separado. Ele consome a
> fila, executa o ffmpeg, monta o ZIP e devolve o resultado ao storage. Quando
> termina, avisa por outra mensagem.

*[AÇÃO: apontar a linha virando "Pronto"]*

> É esse aviso que faz a tela mudar sozinha — eu não recarreguei a página.
>
> O vídeo tem 10 segundos e gerou 10 frames: um por segundo, a mesma extração do
> projeto original, agora fora do caminho da requisição.

---

## A documentação · 2:15

*[AÇÃO: abrir `docs/architecture/arquitetura.md`]*

> Esse caminho está documentado aqui. Este é o diagrama de componentes: API, fila,
> worker, storage e banco — as peças que vocês acabaram de ver funcionando.

*[AÇÃO: rolar até o diagrama de sequência e depois o modelo de dados]*

> Este é o fluxo passo a passo, do upload ao download. E aqui o modelo de dados, com
> a justificativa do PostgreSQL e dos índices.

*[AÇÃO: mostrar a pasta `docs/architecture/`]*

> Cinco ADRs registram as decisões e o porquê de cada uma: o processamento assíncrono
> por fila, o worker em Go separado da API, o storage de objetos no lugar do disco
> local, a autenticação com JWT e a política de retentativas com dead letter queue.
> Tem também o contrato OpenAPI e o script de criação do banco.

---

## A fila segura o pico · 3:15

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

## Download · 5:45

*[AÇÃO: clicar em "Baixar .zip" e abrir o arquivo]*

> Aqui estão os frames extraídos, um para cada segundo do vídeo.
>
> A API não serve esse arquivo: ela assina um link temporário de quinze minutos e o
> navegador baixa direto do storage. Se o download passasse pela aplicação, cada
> usuário baixando prenderia um processo do servidor.

---

## Erro e notificação · 6:15

> Falta mostrar o que acontece quando dá errado. Este arquivo tem cabeçalho de MP4
> válido, então passa na validação, mas os dados estão corrompidos.

*[AÇÃO: arrastar `demo/video-corrompido.mp4` e aguardar]*

> O sistema tenta três vezes antes de desistir. Falhou — e a tela mostra o motivo
> exato do erro para o usuário.

*[AÇÃO: abrir o Mailpit]*

> E aqui está o último requisito funcional: o usuário foi notificado por e-mail. Uma
> única vez, no desfecho definitivo, não a cada tentativa.

*[AÇÃO: rodar o comando das filas]*

> A mensagem que falhou ficou isolada nesta fila separada, a dead letter queue. O
> sistema distingue erro permanente de temporário: se o storage estiver fora do ar,
> ele reenfileira e tenta de novo; se o vídeo está corrompido, ele sai do fluxo depois
> de três tentativas e não fica ocupando worker para sempre.

---

## Qualidade e operação · 7:30

*[AÇÃO: rodar os testes da API e do worker]*

> São 63 testes na API, com quase 87% de cobertura, e 29 testes no worker — 90% no
> domínio e 100% na configuração.

*[AÇÃO: abrir o Grafana ou Prometheus]*

> Estas são as métricas que o sistema publica, e os números batem com o que acabamos
> de fazer: os processamentos que deram certo, as tentativas repetidas e a falha
> definitiva do vídeo corrompido.

*[AÇÃO: mostrar os logs do worker]*

> Os dois serviços emitem log em JSON, com um identificador de correlação que liga a
> requisição na API ao processamento no worker.

*[AÇÃO: abrir `.github/workflows/ci.yml`]*

> E a pipeline roda build, testes, cobertura mínima de 80%, um teste de integração
> ponta a ponta contra o ambiente completo, validação dos manifestos do Kubernetes e
> varredura de vulnerabilidades.

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
