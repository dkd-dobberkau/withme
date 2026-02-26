FROM php:8.3-fpm AS builder
WORKDIR /var/www/html
RUN apt-get update && apt-get install -y unzip git && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-scripts

FROM php:8.3-fpm
RUN apt-get update && apt-get install -y libmaxminddb0 libmaxminddb-dev && rm -rf /var/lib/apt/lists/*
RUN docker-php-ext-install pdo pdo_mysql
RUN pecl install maxminddb && docker-php-ext-enable maxminddb
WORKDIR /var/www/html
COPY --from=builder /var/www/html/vendor ./vendor
COPY . .
RUN chown -R www-data:www-data /var/www/html
USER www-data
