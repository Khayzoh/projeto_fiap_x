# ADR-001 — Processamento assíncrono por fila

- **Status:** aceito
- **Data:** 2026-07-26

## Contexto

No projeto base, `handleVideoUpload` chamava `processVideo` dentro do próprio
request HTTP. O cliente ficava bloqueado durante toda a execução do ffmpeg, e o
processo do servidor ficava ocupado junto.

Isso viola dois requisitos explícitos do desafio: processar mais de um vídeo ao
mesmo tempo e não perder requisições em caso de pico. Com processamento síncrono,
a capacidade do sistema é o número de conexões HTTP simultâneas, e qualquer
reinício de processo perde o trabalho em andamento.

## Decisão

A API apenas **recebe** o vídeo, grava no storage de objetos, registra o pedido
no banco com status `PENDING` e publica uma mensagem `video.uploaded` no RabbitMQ.
Responde `202 Accepted` imediatamente.

Um serviço separado consome a fila e executa o processamento. Ao terminar,
publica `video.completed` ou `video.failed`, que um consumidor da API aplica no
banco e converte em notificação ao usuário.

A publicação acontece **dentro da transação** que grava o registro: se o broker
estiver indisponível, a transação não é confirmada e o cliente recebe erro, em
vez de ficar com um vídeo eternamente `PENDING` que ninguém processaria.

## Consequências

**Positivas**

- A capacidade de processamento passa a ser `réplicas × prefetch`, ajustável sem tocar no código.
- Um pico vira fila, não erro: mensagens persistentes sobrevivem ao restart do broker.
- API e processamento escalam de forma independente, por gargalos diferentes.
- Falha de um worker devolve o job à fila (ack manual), sem perda.

**Negativas**

- O cliente precisa consultar o status; não há resposta imediata com o resultado.
- Mais partes móveis: broker, worker e consumidor de status para operar e monitorar.
- Exige tratamento de idempotência, já que uma mensagem pode ser reentregue.

## Alternativas consideradas

- **Fila em banco de dados** (tabela `jobs` do Laravel): elimina o broker, mas
  faz polling, não oferece DLQ nativa e concentra carga no PostgreSQL.
- **Goroutine em background na própria API**: continua perdendo trabalho quando o
  processo morre e não distribui carga entre instâncias.
