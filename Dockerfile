FROM php:8.3-cli

RUN apt-get update && apt-get install -y libpq-dev
RUN docker-php-ext-install pdo_pgsql pgsql

WORKDIR /app

COPY . .

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "-t", "."]
