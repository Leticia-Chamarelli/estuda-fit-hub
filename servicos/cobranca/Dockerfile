FROM php:8.3-cli-alpine

RUN docker-php-ext-install pdo_mysql

WORKDIR /servico
COPY . /servico

EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public", "public/index.php"]
