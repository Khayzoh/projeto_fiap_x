# Build multi-stage: a imagem final leva apenas o binario e o ffmpeg.
# Contraste deliberado com legacy/Dockerfile, que rodava "go run" em producao
# carregando todo o toolchain Go na imagem final.

FROM golang:1.23-alpine AS builder

WORKDIR /src

# Camada de dependencias separada do codigo: alterar um .go nao invalida o cache do download.
COPY go.mod go.sum* ./
RUN go mod download

COPY . .

# Binario estatico, sem symbols e sem debug info.
RUN CGO_ENABLED=0 GOOS=linux go build \
    -trimpath \
    -ldflags="-s -w" \
    -o /out/worker ./cmd/worker


FROM alpine:3.20

# ffmpeg e a unica dependencia de runtime; tini garante que sinais cheguem ao processo.
RUN apk add --no-cache ffmpeg ca-certificates tini \
    && addgroup -S fiapx \
    && adduser -S -G fiapx -h /app fiapx

WORKDIR /app

COPY --from=builder /out/worker /usr/local/bin/worker

# Diretorio de trabalho dos jobs, com dono correto para o usuario nao-root.
RUN mkdir -p /tmp/fiapx && chown -R fiapx:fiapx /tmp/fiapx /app

USER fiapx

EXPOSE 9100

HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --retries=3 \
    CMD wget -qO- http://localhost:9100/health || exit 1

ENTRYPOINT ["/sbin/tini", "--"]
CMD ["worker"]
