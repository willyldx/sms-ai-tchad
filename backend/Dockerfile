FROM php:8.3-fpm-alpine

WORKDIR /var/www/html

RUN apk add --no-cache bash git unzip libzip-dev icu-dev oniguruma-dev \
    && docker-php-ext-install pdo pdo_mysql mbstring

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY . /var/www/html

RUN if [ -f composer.json ]; then composer install --no-dev --optimize-autoloader --no-interaction; fi

EXPOSE 9000

CMD ["php-fpm"]
