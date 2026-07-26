# ADR-003 — Storage de objetos em vez de disco local

- **Status:** aceito
- **Data:** 2026-07-26

## Contexto

O projeto base gravava tudo em `./uploads`, `./temp` e `./outputs`, no disco do
processo. Isso amarra o sistema a uma única instância: se houver duas, o vídeo
enviado para a instância A não existe para a instância B, e o ZIP produzido em
uma não pode ser baixado pela outra.

Em Kubernetes o problema é ainda mais direto — o disco do pod é efêmero e
desaparece a cada reinício ou reescalonamento.

## Decisão

Todo artefato binário vive em um storage de objetos compatível com S3
(**MinIO** no ambiente local, **S3** em nuvem), com chaves determinísticas
derivadas do id do vídeo:

```
videos/{video_id}/original.{ext}    vídeo enviado pelo usuário
outputs/{video_id}/frames.zip       resultado do processamento
```

O disco local do worker é usado apenas como área de trabalho transitória, em um
diretório por job, removido ao final da execução (`defer os.RemoveAll`).

O download **não passa pela API**: ela devolve uma URL assinada com validade de
15 minutos e o cliente busca o arquivo direto no storage. Transferir centenas de
megabytes através do PHP-FPM ocuparia um worker inteiro por download.

## Consequências

**Positivas**

- API e worker ficam sem estado e podem escalar horizontalmente sem coordenação.
- Reprocessar um vídeo sobrescreve a mesma chave: a operação é idempotente.
- A banda de download não consome capacidade da aplicação.
- Em nuvem, troca-se o endpoint por S3 sem alterar uma linha de código.

**Negativas**

- Mais um componente de infraestrutura no ambiente local.
- O worker precisa baixar o vídeo antes de processar, o que adiciona latência
  proporcional ao tamanho do arquivo.

## Detalhe de implementação

A URL assinada precisa apontar para um host que o navegador do usuário resolva.
Dentro da rede do Docker o MinIO atende em `minio:9000`, que não existe fora dos
containers. Por isso existem dois discos configurados sobre o mesmo bucket:
`s3` para as operações internas e `s3_public` apenas para assinar os links.
