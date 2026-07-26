# ADR-002 — Worker em Go, API em Laravel

- **Status:** aceito
- **Data:** 2026-07-26

## Contexto

O projeto base é escrito em Go. As fases anteriores do curso (1 a 4) produziram
um ecossistema em Laravel: autenticação JWT, publisher/consumer AMQP, logs
estruturados em JSON, manifestos Kubernetes e pipelines de CI/CD já validados.

Era preciso escolher entre reescrever tudo em uma linguagem só ou combinar as duas.

## Decisão

Dividir por natureza da carga:

- **API em Laravel** — autenticação, upload, listagem de status e download.
  Trabalho de I/O curto, muita regra de aplicação e validação. Reaproveita
  diretamente o que foi construído nas fases anteriores.
- **Worker em Go** — download do vídeo, ffmpeg, empacotamento e upload do ZIP.
  Trabalho de CPU longo. Preserva a lógica do projeto base, agora estruturada em
  Clean Architecture, e entrega um binário estático de poucos megabytes.

A fronteira entre eles é o contrato de mensagens no RabbitMQ, não uma chamada
de função. Os dois lados evoluem separadamente desde que a topologia declarada
em `topology.go` e `config/fiapx.php` continue coerente.

## Consequências

**Positivas**

- Cada serviço usa a ferramenta adequada ao seu gargalo.
- A imagem do worker é pequena (`alpine` + binário + ffmpeg), e sobe rápido —
  o que importa quando o HPA precisa adicionar réplicas sob pressão.
- Concorrência no worker é nativa: goroutines com semáforo limitado ao prefetch.
- O código de extração do projeto base é preservado e evoluído, não descartado.

**Negativas**

- Duas stacks para manter, testar e construir no CI.
- O contrato de mensagens é acoplamento implícito entre linguagens: uma mudança
  no formato do payload precisa ser aplicada nos dois lados.
- Quem for manter o projeto precisa conhecer ambas.

## Mitigação

Os nomes de exchanges, filas e routing keys ficam centralizados em um único
lugar em cada linguagem, com comentário cruzado apontando para o par. O job de
integração do CI sobe a stack completa e falharia imediatamente se os contratos
divergissem.
