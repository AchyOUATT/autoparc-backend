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

# Migrations automatiques, référentiels, puis démarrage.
#
# Les données métier (véhicules, pièces, clients) restent seedées à la main une
# fois : `php artisan db:seed --force`. Les RÉFÉRENTIELS, eux, se rejouent à
# chaque démarrage, et c'est voulu.
#
# La raison est concrète. Le plan gratuit de Render n'ouvre pas de Shell : il
# n'existe aucun moyen de lancer une commande ponctuelle sur l'instance. Un
# référentiel enrichi après le premier déploiement — trois générations de Mazda3
# ajoutées au catalogue, les points du contrôle avant voyage — n'arrivait donc
# jamais en production. Un propriétaire de Mazda3 de 2014 s'est ainsi vu proposer
# la génération de 2018, la seule que la base connaissait : le correctif de
# décodage était en ligne, la donnée qu'il lui fallait non.
#
# Les deux seeders sont bâtis sur des upsert : les rejouer ne duplique rien et
# ne détruit rien. Ils sont volontairement hors de la chaîne bloquante — un
# référentiel qui échoue laisse l'application servir des données un peu datées,
# ce qui vaut mieux qu'une instance qui refuse de démarrer.
CMD php artisan config:cache \
    && php artisan route:cache \
    && (php artisan storage:link \
        || echo "ATTENTION : lien de stockage non cree, les photos locales seront en 404") \
    && php artisan migrate --force \
    && (php artisan db:seed --class=VehicleModelsSeeder --force \
        || echo "ATTENTION : referentiel des modeles non mis a jour") \
    && (php artisan db:seed --class=VehicleCheckItemsSeeder --force \
        || echo "ATTENTION : points de controle non mis a jour") \
    && php artisan serve --host=0.0.0.0 --port=${PORT:-8000}
