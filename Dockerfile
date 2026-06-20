FROM php:8.3-apache

RUN apt-get update && apt-get install -y libpq-dev
RUN docker-php-ext-install pdo_pgsql pgsql

COPY . /var/www/html/
