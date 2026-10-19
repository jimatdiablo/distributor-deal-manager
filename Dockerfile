FROM php:8.3-cli-alpine

WORKDIR /app

RUN apk add --no-cache curl-dev \
    && docker-php-ext-install pdo_mysql curl

COPY . /app
RUN chmod +x /app/docker/app-entrypoint.sh

EXPOSE 8080

ENTRYPOINT ["/app/docker/app-entrypoint.sh"]
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]
