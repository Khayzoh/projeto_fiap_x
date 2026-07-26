# Documentação de arquitetura — FIAP X

Hackathon · POSTECH 13SOAT · Fase 5

---

## 1. Visão de componentes

```mermaid
flowchart TB
    U([Usuário])

    subgraph borda [Borda]
        NG[nginx / Ingress<br/>proxy + limite de 512 MB]
    end

    subgraph aplicacao [Aplicação — sem estado, escalável]
        API[API Laravel<br/>PHP 8.4]
        CONS[Consumer de status<br/>artisan fiapx:consume-status]
        WK[Worker Go 1.23<br/>ffmpeg + zip]
    end

    subgraph dados [Dados e mensageria]
        PG[(PostgreSQL 16<br/>users · videos)]
        RD[(Redis 7<br/>cache · sessão)]
        MQ{{RabbitMQ 3.13<br/>fiapx.events · DLQ}}
        S3[(MinIO / S3<br/>bucket fiapx-videos)]
    end

    subgraph apoio [Apoio]
        ML[Mailpit / SMTP]
        PR[Prometheus]
        GF[Grafana]
    end

    U --> NG --> API
    API --> PG & RD & S3
    API -- publica --> MQ
    MQ -- video.uploaded --> WK
    WK --> S3
    WK -- video.completed<br/>video.failed --> MQ
    MQ -- fila video.status --> CONS
    CONS --> PG
    CONS --> ML
    U -. link assinado .-> S3

    PR -. /api/metrics .-> API
    PR -. :9100/metrics .-> WK
    PR -. :15692 .-> MQ
    GF --> PR
```

### Responsabilidade de cada serviço

| Serviço | Responsabilidade | Escala por |
|---|---|---|
| **API Laravel** | Autenticar, receber o vídeo, registrar o pedido, listar status, assinar o download | Tráfego HTTP (HPA por CPU/memória) |
| **Worker Go** | Baixar, extrair frames, compactar, devolver o ZIP e anunciar o desfecho | Tamanho da fila (HPA por CPU) |
| **Consumer de status** | Aplicar os eventos de resultado no banco e notificar falhas | Uma réplica basta |

---

## 2. Fluxo de sucesso

```mermaid
sequenceDiagram
    autonumber
    participant U as Usuário
    participant A as API Laravel
    participant S as MinIO / S3
    participant D as PostgreSQL
    participant Q as RabbitMQ
    participant W as Worker Go
    participant C as Consumer de status

    U->>A: POST /api/videos (multipart + JWT)
    A->>A: valida token, extensão e mimetype
    A->>S: grava videos/{id}/original.mp4
    A->>D: INSERT video (PENDING)
    A->>Q: publica video.uploaded (persistente)
    Note over A,D: publicação dentro da transação:<br/>broker fora do ar ⇒ nada é confirmado
    A-->>U: 202 Accepted + id

    Q->>W: entrega o job (prefetch, ack manual)
    W->>S: baixa o vídeo
    W->>W: ffmpeg fps=1 → PNGs
    W->>W: compacta em ZIP
    W->>S: grava outputs/{id}/frames.zip
    W->>Q: publica video.completed
    W->>Q: ack da mensagem original

    Q->>C: entrega o evento
    C->>D: UPDATE status = COMPLETED, frame_count

    U->>A: GET /api/videos/{id}/download
    A->>S: gera URL assinada (15 min)
    A-->>U: download_url
    U->>S: baixa o ZIP direto do storage
```

## 3. Fluxo de falha

```mermaid
sequenceDiagram
    autonumber
    participant Q as RabbitMQ
    participant W as Worker Go
    participant C as Consumer de status
    participant D as PostgreSQL
    participant M as SMTP
    participant U as Usuário

    Q->>W: entrega o job (x-attempt = 0)
    W->>W: ffmpeg falha (arquivo corrompido)
    W->>Q: republica com x-attempt = 1
    Note over W,Q: erro transitório enquanto<br/>houver tentativas restantes

    Q->>W: entrega (x-attempt = 1)
    W->>Q: republica com x-attempt = 2

    Q->>W: entrega (x-attempt = 2 — última)
    W->>W: promove a erro permanente
    W->>Q: publica video.failed
    W->>Q: Nack(requeue = false)
    Q->>Q: mensagem vai para video.processing.dlq

    Q->>C: entrega video.failed
    C->>D: UPDATE status = FAILED, attempts = 3
    C->>M: envia notificação
    M-->>U: e-mail "Falha no processamento"
```

---

## 4. Topologia de mensageria

```mermaid
flowchart LR
    API[API] -->|video.uploaded| EX{{fiapx.events<br/>topic · durable}}
    WK[Worker] -->|video.completed<br/>video.failed| EX

    EX -->|video.uploaded| QP[[video.processing<br/>durable]]
    EX -->|video.completed<br/>video.failed| QS[[video.status<br/>durable]]

    QP -->|consome| WK
    QS -->|consome| CS[Consumer de status]

    QP -.->|Nack requeue=false| DLX{{fiapx.dlx}}
    DLX --> DLQ[[video.processing.dlq]]
```

| Objeto | Tipo | Papel |
|---|---|---|
| `fiapx.events` | exchange topic durável | Ponto único de publicação |
| `video.processing` | fila durável | Jobs aguardando o worker |
| `video.status` | fila durável | Resultados aguardando a API |
| `fiapx.dlx` + `video.processing.dlq` | dead letter | Isola o que falhou em definitivo |

**Garantias contra perda em pico:** exchange e filas duráveis, mensagens
publicadas com `delivery_mode = persistent`, *publisher confirms* no worker,
`autoAck = false` no consumo e `Qos(prefetch)` limitando o despacho por réplica.

---

## 5. Modelo de dados

```mermaid
erDiagram
    USERS ||--o{ VIDEOS : envia

    USERS {
        bigserial id PK
        varchar   name
        varchar   email UK
        varchar   password "hash bcrypt"
        timestamp created_at
    }

    VIDEOS {
        uuid      id PK "gerado pela API, viaja na mensagem"
        bigint    user_id FK
        varchar   original_filename
        varchar   object_key "chave do vídeo no storage"
        varchar   zip_object_key "preenchido ao concluir"
        varchar   status "PENDING|PROCESSING|COMPLETED|FAILED"
        bigint    size_bytes
        bigint    zip_size_bytes
        integer   frame_count
        smallint  attempts
        text      error_message
        uuid      correlation_id "rastreio ponta a ponta"
        timestamp processed_at
        timestamp created_at
    }
```

### Justificativa das escolhas

**PostgreSQL** — o modelo é relacional e pequeno (dois agregados com uma relação
1:N), com necessidade de integridade referencial (`ON DELETE CASCADE`) e de
consultas por status. Também é o banco gerenciado disponível na maioria dos
provedores, com módulo Terraform já pronto das fases anteriores.

**UUID como chave de `videos`** — o identificador é gerado pela API antes de
qualquer escrita, viaja na mensagem AMQP, nomeia os objetos no storage e volta
nos eventos. Um `BIGSERIAL` exigiria gravar primeiro para só então conhecer a
chave, e exporia a contagem de vídeos do sistema na URL.

**Índices** — `(user_id, created_at)` cobre a consulta dominante (listagem do
usuário, mais recentes primeiro); `(user_id, status)` cobre o filtro por status;
`correlation_id` serve à investigação de incidentes.

**Redis** — cache e sessão. Não guarda estado de negócio: o `status` do vídeo é
lido do PostgreSQL, porque um cache desatualizado aqui mostraria "processando"
para um vídeo já pronto.

---

## 6. Observabilidade

| Sinal | Origem | Uso |
|---|---|---|
| `fiapx_videos_processados_total{resultado}` | Worker | Taxa de sucesso, retentativas e falhas permanentes |
| `fiapx_processamento_duracao_segundos` | Worker | Distribuição do tempo de processamento |
| `fiapx_videos_em_processamento` | Worker | Ocupação instantânea das réplicas |
| `fiapx_frames_por_video` | Worker | Perfil de carga dos vídeos recebidos |
| `fiapx_videos_total{status}` | API | Backlog visto pelo usuário |
| Filas e DLQ | RabbitMQ (`:15692`) | Acúmulo de fila — sinal para escalar |

**Logs estruturados em JSON** nos dois serviços (`slog` no Go, canal `stack` no
Laravel), com `correlation_id` propagado do header `X-Correlation-Id` da
requisição até o evento de resultado — o que permite reconstruir o caminho
completo de um upload através dos três serviços.

**Probes:** `/api/health` (liveness, sem dependências — banco fora do ar não deve
reiniciar o pod em laço) e `/api/ready` (readiness, verifica PostgreSQL e Redis).

---

## 7. Escalabilidade

| Dimensão | Mecanismo |
|---|---|
| Vazão de upload | HPA da API: 2 → 10 réplicas a 70% de CPU |
| Vazão de processamento | HPA do worker: 2 → 20 réplicas a 65% de CPU |
| Paralelismo por réplica | `WORKER_PREFETCH` jobs simultâneos, com semáforo do mesmo tamanho |
| Absorção de pico | Fila durável — o excedente vira backlog, não erro |
| Disponibilidade durante deploy | `maxUnavailable: 0` e PodDisruptionBudget na API |
| Encerramento sem perda | `terminationGracePeriodSeconds: 120` + ack manual |

O `scaleDown` do worker é deliberadamente lento (janela de 600s, 1 pod por vez):
derrubar uma réplica no meio de um ffmpeg custa reprocessar o vídeo inteiro.

**Evolução natural:** trocar o gatilho do HPA do worker de CPU para o tamanho da
fila `video.processing` via KEDA, que reage antes de a CPU subir.

---

## 8. Segurança

- Senhas em bcrypt; texto puro nunca é gravado nem devolvido nas respostas.
- Todas as rotas de vídeo exigem JWT válido, com o usuário confirmado no banco.
- Acesso restrito ao dono: recurso de outro usuário responde `404`.
- Upload validado por extensão **e** mimetype real, com limite de tamanho.
- Nome de arquivo enviado pelo usuário é normalizado com `filepath.Base` no
  worker, impedindo travessia de diretório.
- Throttle no login (5/min) e no cadastro (10/min).
- Links de download expiram em 15 minutos.
- Containers rodam com usuário não-root, e imagens de produção não carregam
  toolchain nem dependências de desenvolvimento.
- Segredos vêm de `Secret`/variáveis de ambiente, nunca do repositório.
