FROM php:8.3-fpm-alpine

WORKDIR /var/www/html

RUN apk add --no-cache bash git unzip libzip-dev icu-dev oniguruma-dev \
    && docker-php-ext-install pdo pdo_mysql mbstring intl zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY . /var/www/html

RUN composer install --no-dev --optimize-autoloader --no-interaction

# Ensure all files are owned by www-data
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 775 storage bootstrap/cache

COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 9000

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]
