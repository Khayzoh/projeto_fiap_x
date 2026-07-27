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

# Limites de upload. Sem isto valem os padrões do PHP — post_max_size de 8M e
# upload_max_filesize de 2M — e qualquer vídeo real é recusado antes mesmo de
# chegar à aplicação, com um warning solto no corpo da resposta.
#
# post_max_size fica acima de upload_max_filesize para acomodar o overhead das
# fronteiras do multipart, e os dois abaixo do client_max_body_size do nginx.
RUN { \
        echo 'upload_max_filesize=512M'; \
        echo 'post_max_size=520M'; \
        echo 'memory_limit=256M'; \
        echo 'max_execution_time=300'; \
        echo 'max_input_time=300'; \
        # A saída desta API é JSON: um warning impresso no corpo quebra o
        # parser do cliente. Erros vão para o log; quem os exibe é o Laravel.
        echo 'display_errors=Off'; \
        echo 'log_errors=On'; \
    } > /usr/local/etc/php/conf.d/zz-fiapx.ini

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
