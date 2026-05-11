FROM php:8.3-cli-alpine

WORKDIR /app

RUN docker-php-ext-install pdo_mysql

COPY . /app

EXPOSE 8080

CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]
