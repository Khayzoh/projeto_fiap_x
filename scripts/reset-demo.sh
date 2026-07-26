#!/usr/bin/env bash
#
# Devolve o ambiente ao estado inicial, sem derrubar os containers.
#
# Util entre tomadas da gravacao: limpa banco, storage, filas e caixa de
# e-mail, mas preserva o ambiente no ar (nao precisa esperar tudo subir).
#
# Uso:
#   bash scripts/reset-demo.sh
#
set -euo pipefail

echo "==> Limpando o banco de dados"
docker compose exec -T postgres psql -U fiapx -d fiapx -q \
    -c "TRUNCATE videos, users RESTART IDENTITY CASCADE;"
echo "    videos e users zerados"

echo "==> Limpando o storage de objetos"
docker compose exec -T minio sh -c '
    mc alias set local http://localhost:9000 "$MINIO_ROOT_USER" "$MINIO_ROOT_PASSWORD" > /dev/null
    mc rm --recursive --force "local/${STORAGE_BUCKET:-fiapx-videos}/videos"  > /dev/null 2>&1 || true
    mc rm --recursive --force "local/${STORAGE_BUCKET:-fiapx-videos}/outputs" > /dev/null 2>&1 || true
' || echo "    (bucket ja estava vazio)"
echo "    videos e ZIPs removidos"

echo "==> Purgando as filas"
for fila in video.processing video.status video.processing.dlq; do
    docker compose exec -T rabbitmq rabbitmqctl purge_queue "$fila" > /dev/null 2>&1 || true
done
echo "    filas e DLQ zeradas"

echo "==> Limpando a caixa de e-mail"
curl -fsS -X DELETE http://localhost:8025/api/v1/messages > /dev/null 2>&1 || true
echo "    Mailpit zerado"

# Recriar os workers zera os logs acumulados. Sem isso, a contagem de
# "quem processou o que" mistura execucoes anteriores.
#
# O rm antes do up e necessario para a numeracao voltar a worker-1: apenas
# reduzir a escala preserva os indices altos dos containers remanescentes.
echo "==> Recriando os workers (zera os logs)"
docker compose rm -sf worker > /dev/null 2>&1
docker compose up -d --scale worker=1 worker > /dev/null 2>&1
echo "    1 worker no ar (fiapx-worker-1), com log limpo"

echo ""
echo "Ambiente pronto para uma nova demonstracao."
