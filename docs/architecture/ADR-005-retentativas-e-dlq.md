# ADR-005 — Retentativas com classificação de erro e DLQ

- **Status:** aceito
- **Data:** 2026-07-26

## Contexto

Nem toda falha no processamento merece o mesmo tratamento. Um vídeo corrompido
vai falhar exatamente igual na centésima tentativa; o MinIO fora do ar por trinta
segundos, não. Tratar os dois casos da mesma forma leva a um de dois extremos:

- reenfileirar sempre → vídeo inválido gira em laço infinito, ocupando workers;
- descartar sempre → uma instabilidade momentânea perde o trabalho do usuário.

## Decisão

Classificar os erros no domínio, e deixar o consumidor agir de acordo.

**Erro permanente** (`domain.Permanent`) — job malformado, vídeo sem frames,
extração que falhou depois de esgotar as tentativas. O consumidor faz
`Nack(requeue: false)`, e a mensagem vai para a **dead letter queue**
`video.processing.dlq`. O usuário é notificado por e-mail.

**Erro transitório** — falha de download, de upload ou de publicação. A mensagem
é republicada com o header `x-attempt` incrementado. Ao atingir `WORKER_MAX_RETRY`
(padrão: 3), a próxima falha é promovida a permanente.

A classificação atravessa o encadeamento de erros: `IsPermanent` usa `errors.As`,
então continua funcionando mesmo depois de o erro ser embrulhado por `fmt.Errorf`
nas camadas superiores.

## Consequências

**Positivas**

- Instabilidade de infraestrutura é absorvida sem intervenção humana.
- Vídeo inválido sai do fluxo em no máximo 3 tentativas, sem ocupar capacidade.
- A DLQ preserva a mensagem original para investigação, em vez de descartá-la.
- O usuário é avisado uma única vez, no desfecho definitivo.

**Negativas**

- Republicar em vez de usar `Nack(requeue: true)` faz a mensagem ir para o fim da
  fila, alterando a ordem de processamento — aceitável, já que os jobs são
  independentes entre si.
- A DLQ precisa de monitoramento: mensagens acumuladas ali são invisíveis se
  ninguém observar.

## Verificação

O comportamento é coberto por testes unitários em
[`process_video_test.go`](../../worker/internal/usecase/process_video_test.go)
— incluindo a diferença entre falhar na primeira tentativa e na última — e
validado contra a stack real pelo `scripts/smoke-test.sh`, que confirma o vídeo
em `FAILED`, o e-mail enviado e a mensagem parada na DLQ.
