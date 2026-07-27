#!/usr/bin/env bash
#
# Prepara o ambiente completo a partir de um clone limpo.
#
#   bash scripts/setup.sh
#
# Gera as chaves, sobe os containers, espera as dependências ficarem prontas,
# aplica as migrations e confirma que a aplicação responde. Idempotente: rodar
# de novo não sobrescreve um .env existente.
#
set -euo pipefail

cd "$(dirname "$0")/.."

passo()  { echo ""; echo "==> $*"; }
ok()     { echo "    OK: $*"; }
aviso()  { echo "    !  $*"; }
falhar() { echo "    FALHOU: $*" >&2; exit 1; }

# -----------------------------------------------------------------------------
passo "1/7 Verificando os pré-requisitos"

command -v docker > /dev/null || falhar "Docker não encontrado. Instale o Docker Desktop e tente de novo."
docker compose version > /dev/null 2>&1 || falhar "Docker Compose v2 não encontrado."
docker info > /dev/null 2>&1 || falhar "O Docker está instalado mas não está em execução. Abra o Docker Desktop."
ok "Docker em execução"

# -----------------------------------------------------------------------------
passo "2/7 Preparando a configuração"

if [ -f .env ]; then
    aviso ".env já existe — mantido como está"
else
    cp .env.example .env

    # As chaves precisam ser geradas: APP_KEY exige exatamente 32 bytes, e uma
    # chave inválida faz o Laravel falhar com "Unsupported cipher or incorrect
    # key length" — erro que não sugere a causa.
    APP_KEY="base64:$(head -c 32 /dev/urandom | base64 | tr -d '\n')"
    JWT_SECRET="$(head -c 48 /dev/urandom | base64 | tr -d '\n')"

    # O separador do sed é | porque as chaves em base64 contêm barras.
    sed -i.bak "s|^APP_KEY=.*|APP_KEY=${APP_KEY}|" .env
    sed -i.bak "s|^JWT_SECRET=.*|JWT_SECRET=${JWT_SECRET}|" .env
    rm -f .env.bak

    ok ".env criado com APP_KEY e JWT_SECRET novos"
fi

# -----------------------------------------------------------------------------
passo "3/7 Construindo as imagens"
echo "    (a primeira execução baixa as imagens e compila o worker; pode levar alguns minutos)"

docker compose build

ok "imagens construídas"

# -----------------------------------------------------------------------------
passo "4/7 Instalando as dependências da API"

# O Dockerfile roda composer install durante o build, mas o compose monta ./api
# sobre /var/www/html e o vendor/ da imagem fica encoberto pelo diretório do
# host — que num clone novo não existe, já que vendor/ não é versionado. Sem
# este passo a API sobe e responde 500 no autoload.
if [ -f api/vendor/autoload.php ]; then
    aviso "dependências já instaladas — pulando"
else
    docker compose run --rm --no-deps api \
        composer install --no-interaction --prefer-dist --no-progress
    ok "dependências instaladas em api/vendor"
fi

# -----------------------------------------------------------------------------
passo "5/7 Subindo os containers"

docker compose up -d

ok "containers no ar"

# -----------------------------------------------------------------------------
passo "6/7 Aguardando as dependências"

for tentativa in $(seq 1 60); do
    if docker compose exec -T postgres pg_isready -U fiapx -d fiapx > /dev/null 2>&1; then
        ok "banco de dados pronto"
        break
    fi
    [ "$tentativa" = "60" ] && falhar "o PostgreSQL não ficou pronto a tempo"
    sleep 2
done

for tentativa in $(seq 1 60); do
    if curl -fsS http://localhost:8080/api/health > /dev/null 2>&1; then
        ok "API respondendo"
        break
    fi
    [ "$tentativa" = "60" ] && {
        docker compose logs --tail 40
        falhar "a API não respondeu a tempo"
    }
    sleep 2
done

# -----------------------------------------------------------------------------
passo "7/7 Criando as tabelas"

docker compose exec -T api php artisan migrate --force > /dev/null
ok "migrations aplicadas"

# -----------------------------------------------------------------------------
cat <<'FIM'

================================================================
 Ambiente pronto.

   Interface    http://localhost:8080     crie a conta na tela
   RabbitMQ     http://localhost:15672    fiapx / fiapx
   MinIO        http://localhost:9001     fiapxadmin / fiapxadmin123
   Mailpit      http://localhost:8025
   Prometheus   http://localhost:9090
   Grafana      http://localhost:3001     admin / admin

 Para conferir que tudo funciona de ponta a ponta:

   bash scripts/smoke-test.sh

================================================================
FIM
