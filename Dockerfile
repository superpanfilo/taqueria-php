FROM php:8.2-apache

# Dependencias de Postgres para compilar pdo_pgsql
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

# Copia tu app
COPY . /var/www/html/

EXPOSE 80
