# Imagem de desenvolvimento da API: PHP-FPM com as extensoes do projeto
# e o driver de cobertura (pcov) para rodar a suite de testes.

FROM php:8.4-fpm-alpine

RUN apk add --no-cache \
        postgresql-dev \
        libzip-dev \
        linux-headers \
        $PHPIZE_DEPS \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        pgsql \
        bcmath \
        sockets \
        zip \
        pcntl \
        opcache \
    # pcov e muito mais rapido que o xdebug para medir cobertura no CI.
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Usuario nao-root com o mesmo uid do php-fpm padrao, evitando arquivos
# criados como root no volume montado.
RUN addgroup -g 1000 -S fiapx \
    && adduser -u 1000 -S -G fiapx fiapx \
    && chown -R fiapx:fiapx /var/www/html

COPY --chown=fiapx:fiapx . .

USER fiapx

RUN composer install --no-interaction --prefer-dist --no-progress

EXPOSE 9000

CMD ["php-fpm"]
