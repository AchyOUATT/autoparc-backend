FROM php:8.4-cli

# Extensions PHP nécessaires pour Laravel
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libpq-dev \
    zip \
    unzip \
    git \
    curl \
    && docker-php-ext-install \
        pdo \
        pdo_mysql \
        pdo_pgsql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# Dépendances d'abord (cache Docker optimisé)
COPY composer.json composer.lock ./
RUN composer install --optimize-autoloader --no-dev --no-scripts --no-interaction

# Code source
COPY . .

# Permissions Laravel
RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 8000

# Migrations puis démarrage du serveur
CMD php artisan config:cache \
    && php artisan route:cache \
    && php artisan migrate --force \
    && php artisan serve --host=0.0.0.0 --port=${PORT:-8000}
