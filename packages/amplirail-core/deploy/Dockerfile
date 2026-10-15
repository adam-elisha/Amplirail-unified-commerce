# AmpliRail application image (Laravel 12, PHP 8.3, Octane with RoadRunner/Swoole)
FROM php:8.3-cli-bookworm AS base
RUN apt-get update && apt-get install -y --no-install-recommends git unzip libpq-dev libzip-dev \
 && docker-php-ext-install pdo_pgsql zip opcache pcntl \
 && pecl install redis swoole && docker-php-ext-enable redis swoole \
 && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --prefer-dist --no-interaction
COPY . .
RUN composer dump-autoload --optimize && php artisan event:cache && php artisan route:cache && php artisan view:cache
RUN useradd -u 10001 -m amplirail && chown -R amplirail /app/storage /app/bootstrap/cache
USER amplirail
EXPOSE 8000
HEALTHCHECK --interval=15s --timeout=3s CMD php -r "exit(@file_get_contents('http://127.0.0.1:8000/health')===false?1:0);"
CMD ["php", "artisan", "octane:start", "--server=swoole", "--host=0.0.0.0", "--port=8000"]
