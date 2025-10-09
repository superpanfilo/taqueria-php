# Usa imagen oficial de PHP con servidor Apache
FROM php:8.2-apache

# Copia el contenido del repositorio dentro del contenedor
COPY . /var/www/html/

# Habilita extensiones necesarias (pdo_pgsql para Postgres)
RUN docker-php-ext-install pdo pdo_pgsql

# Expón el puerto 80
EXPOSE 80
