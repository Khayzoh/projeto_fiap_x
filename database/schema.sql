-- =============================================================================
--  FIAP X - Sistema de Processamento de Videos
--  Script de criacao do banco de dados (PostgreSQL 16)
-- =============================================================================
--
--  Este arquivo e o DDL de referencia do sistema, extraido do schema real
--  gerado pelas migrations do Laravel (api/database/migrations).
--
--  Em desenvolvimento e em producao as tabelas sao criadas por:
--      php artisan migrate --force
--
--  Use este script quando precisar provisionar o banco fora do ciclo de
--  migrations (ex.: criacao manual, revisao de modelagem, auditoria).
--
--  Aplicacao:
--      psql -U fiapx -d fiapx -f database/schema.sql
-- =============================================================================

BEGIN;

-- -----------------------------------------------------------------------------
-- users - contas de acesso ao sistema
--
-- Atende ao requisito "o sistema deve ser protegido por usuario e senha".
-- A senha e sempre gravada como hash bcrypt; texto puro nunca toca o banco.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id                BIGSERIAL     PRIMARY KEY,
    name              VARCHAR(255)  NOT NULL,
    email             VARCHAR(255)  NOT NULL,
    email_verified_at TIMESTAMP(0)  WITHOUT TIME ZONE,
    password          VARCHAR(255)  NOT NULL,
    remember_token    VARCHAR(100),
    created_at        TIMESTAMP(0)  WITHOUT TIME ZONE,
    updated_at        TIMESTAMP(0)  WITHOUT TIME ZONE,

    CONSTRAINT users_email_unique UNIQUE (email)
);

-- -----------------------------------------------------------------------------
-- videos - um registro por video enviado, com o estado do processamento
--
-- A chave e um UUID gerado pela API no momento do upload. Esse mesmo id viaja
-- na mensagem AMQP ate o worker, nomeia os objetos no storage e volta nos
-- eventos de resultado, servindo como identificador unico de ponta a ponta.
--
-- Ciclo de vida do campo status:
--     PENDING -> PROCESSING -> COMPLETED
--                           -> FAILED
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS videos (
    id                UUID          PRIMARY KEY,
    user_id           BIGINT        NOT NULL,

    -- Nome informado pelo usuario, preservado apenas para exibicao.
    original_filename VARCHAR(255)  NOT NULL,
    -- Chave do video original no storage de objetos (S3/MinIO).
    object_key        VARCHAR(255)  NOT NULL,
    -- Chave do ZIP com os frames; so e preenchida ao concluir.
    zip_object_key    VARCHAR(255),

    status            VARCHAR(20)   NOT NULL DEFAULT 'PENDING',

    size_bytes        BIGINT        NOT NULL DEFAULT 0,
    zip_size_bytes    BIGINT,
    frame_count       INTEGER,
    -- Tentativas de processamento consumidas antes do desfecho.
    attempts          SMALLINT      NOT NULL DEFAULT 0,

    error_message     TEXT,
    -- Correlaciona os logs da API, do broker e do worker para um mesmo upload.
    correlation_id    UUID,
    processed_at      TIMESTAMP(0)  WITHOUT TIME ZONE,

    created_at        TIMESTAMP(0)  WITHOUT TIME ZONE,
    updated_at        TIMESTAMP(0)  WITHOUT TIME ZONE,

    CONSTRAINT videos_user_id_foreign
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,

    -- Barreira contra estado invalido vindo de um consumidor com bug.
    CONSTRAINT videos_status_check
        CHECK (status IN ('PENDING', 'PROCESSING', 'COMPLETED', 'FAILED'))
);

-- Consulta dominante: "meus videos, mais recentes primeiro" (GET /api/videos).
CREATE INDEX IF NOT EXISTS videos_user_id_created_at_index
    ON videos USING btree (user_id, created_at);

-- Suporta o filtro por status na listagem (GET /api/videos?status=...).
CREATE INDEX IF NOT EXISTS videos_user_id_status_index
    ON videos USING btree (user_id, status);

-- Busca por rastreio durante investigacao de incidente.
CREATE INDEX IF NOT EXISTS videos_correlation_id_index
    ON videos USING btree (correlation_id);

-- -----------------------------------------------------------------------------
-- Tabelas de infraestrutura do framework
--
-- Criadas pelas migrations padrao do Laravel. Ficam aqui para que o script
-- reproduza o banco por completo.
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS password_reset_tokens (
    email      VARCHAR(255) PRIMARY KEY,
    token      VARCHAR(255) NOT NULL,
    created_at TIMESTAMP(0) WITHOUT TIME ZONE
);

CREATE TABLE IF NOT EXISTS sessions (
    id            VARCHAR(255) PRIMARY KEY,
    user_id       BIGINT,
    ip_address    VARCHAR(45),
    user_agent    TEXT,
    payload       TEXT    NOT NULL,
    last_activity INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS sessions_user_id_index ON sessions USING btree (user_id);
CREATE INDEX IF NOT EXISTS sessions_last_activity_index ON sessions USING btree (last_activity);

CREATE TABLE IF NOT EXISTS cache (
    key        VARCHAR(255) PRIMARY KEY,
    value      TEXT    NOT NULL,
    expiration INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS cache_expiration_index ON cache USING btree (expiration);

CREATE TABLE IF NOT EXISTS cache_locks (
    key        VARCHAR(255) PRIMARY KEY,
    owner      VARCHAR(255) NOT NULL,
    expiration INTEGER      NOT NULL
);
CREATE INDEX IF NOT EXISTS cache_locks_expiration_index ON cache_locks USING btree (expiration);

CREATE TABLE IF NOT EXISTS jobs (
    id           BIGSERIAL    PRIMARY KEY,
    queue        VARCHAR(255) NOT NULL,
    payload      TEXT         NOT NULL,
    attempts     SMALLINT     NOT NULL,
    reserved_at  INTEGER,
    available_at INTEGER      NOT NULL,
    created_at   INTEGER      NOT NULL
);
CREATE INDEX IF NOT EXISTS jobs_queue_index ON jobs USING btree (queue);

CREATE TABLE IF NOT EXISTS job_batches (
    id             VARCHAR(255) PRIMARY KEY,
    name           VARCHAR(255) NOT NULL,
    total_jobs     INTEGER      NOT NULL,
    pending_jobs   INTEGER      NOT NULL,
    failed_jobs    INTEGER      NOT NULL,
    failed_job_ids TEXT         NOT NULL,
    options        TEXT,
    cancelled_at   INTEGER,
    created_at     INTEGER      NOT NULL,
    finished_at    INTEGER
);

CREATE TABLE IF NOT EXISTS failed_jobs (
    id         BIGSERIAL    PRIMARY KEY,
    uuid       VARCHAR(255) NOT NULL,
    connection TEXT         NOT NULL,
    queue      TEXT         NOT NULL,
    payload    TEXT         NOT NULL,
    exception  TEXT         NOT NULL,
    failed_at  TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT failed_jobs_uuid_unique UNIQUE (uuid)
);

CREATE TABLE IF NOT EXISTS migrations (
    id        SERIAL       PRIMARY KEY,
    migration VARCHAR(255) NOT NULL,
    batch     INTEGER      NOT NULL
);

COMMIT;
