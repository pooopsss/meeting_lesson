FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
    postgresql-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    libwebp-dev \
    zlib-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install pdo pdo_pgsql gd

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

COPY composer.json composer.lock* ./
RUN composer install --no-interaction --optimize-autoloader

COPY . .

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

RUN { \
    echo 'upload_max_filesize=220M'; \
    echo 'post_max_size=220M'; \
    echo 'memory_limit=512M'; \
} > /usr/local/etc/php/conf.d/zz-meeting-files.ini

EXPOSE 9000

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php-fpm"]
