# Production image: nginx in front of php-fpm (the built-in php -S server is
# for local development only). The app has no Composer runtime dependencies.
FROM php:8.2-fpm-alpine

RUN apk add --no-cache nginx curl \
 && mkdir -p /run/nginx /var/www/html

COPY . /var/www/html
COPY docker/nginx.conf /etc/nginx/http.d/default.conf

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
  CMD curl -fsS http://127.0.0.1/health.php >/dev/null || exit 1

CMD ["sh", "-c", "php-fpm -D && nginx -g 'daemon off;'"]
