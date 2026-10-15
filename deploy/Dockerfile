# AmpliRail application image (Laravel 12, PHP 8.3). One image, several roles (see docker-compose.yml / ECS services):
#   api        php artisan octane:start (Swoole)       relay      php artisan amplirail:outbox:relay
#   worker     php artisan queue:work                  scheduler  php artisan schedule:work
FROM php:8.3-cli-bookworm AS base
RUN apt-get update && apt-get install -y --no-install-recommends git unzip libpq-dev libzip-dev libicu-dev \
 && docker-php-ext-install pdo_pgsql zip intl opcache pcntl bcmath \
 && pecl install redis swoole && docker-php-ext-enable redis swoole \
 && rm -rf /var/lib/apt/lists/* \
 && { echo 'opcache.enable_cli=1'; echo 'opcache.validate_timestamps=0'; echo 'expose_php=0'; echo 'memory_limit=512M'; } > /usr/local/etc/php/conf.d/amplirail.ini
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app

# Dependencies first (layer cache). The core package is a path repository, so copy it before composer install.
COPY composer.json composer.lock ./
COPY packages ./packages
RUN composer install --no-dev --no-scripts --prefer-dist --no-interaction --no-progress

COPY . .
RUN rm -f .env && composer dump-autoload --optimize --no-dev \
 && php artisan package:discover --ansi \
 && php artisan event:cache && php artisan route:cache && php artisan view:cache \
 && useradd -u 10001 -m amplirail && chown -R amplirail /app/storage /app/bootstrap/cache

USER amplirail
EXPOSE 8000
HEALTHCHECK --interval=15s --timeout=3s CMD php -r "exit(@file_get_contents('http://127.0.0.1:8000/health')===false?1:0);"
# config:cache runs at start so environment variables injected by ECS are captured.
CMD ["sh", "-c", "php artisan config:cache && php artisan octane:start --server=swoole --host=0.0.0.0 --port=8000"]
