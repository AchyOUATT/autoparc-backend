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

# Les plafonds de depot de PHP.
#
# php:8.4-cli n'active aucun php.ini : les deux modeles livres restent
# php.ini-production et php.ini-development, non copies. Les defauts COMPILES
# s'appliquaient donc, et ils sont trop bas pour ce que l'application envoie.
# Mesure par HTTP reel avant ce fichier, POST /api/parts/1/media :
#
#     2 072 238 o (juste sous 2 MiB) -> 201
#     2 255 773 o (juste au-dessus)  -> 422 « The photos.0 failed to upload. »
#
# Soit : toute photo de telephone ordinaire refusee, avec un message qui ne
# parle pas de taille.
#
# Ce fichier pose TROIS directives — 9M, 24M et 192M — et le calcul de chacune
# est dans le fichier lui-meme. La redaction precedente n'en posait que deux,
# avec post_max_size a 92M et memory_limit laisse au defaut compile : POST
# /api/login, route NON AUTHENTIFIEE, rendait alors 500 AU CORPS VIDE des
# 33 554 428 o de corps urlencoded, en 15,76 s, par erreur fatale de memoire.
# Mesure par HTTP reel.
#
# Pose AVANT `COPY . .` pour que la couche reste en cache quand seul le code
# change — ce fichier bouge beaucoup moins souvent que l'application.
COPY docker/php/depot-de-photos.ini /usr/local/etc/php/conf.d/depot-de-photos.ini

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# Dépendances d'abord (cache Docker optimisé)
COPY composer.json composer.lock ./
RUN composer install --optimize-autoloader --no-dev --no-scripts --no-interaction

# Code source
COPY . .

# Répertoires de travail de Laravel, puis permissions.
#
# Le mkdir n'est pas une précaution de style : .dockerignore exclut
# storage/framework/{views,cache,sessions} — à juste titre, pour ne pas
# embarquer les vues compilées du poste de développement. Mais l'exclusion
# vaut aussi pour les dossiers eux-mêmes, qui n'existent donc pas dans
# l'image.
#
# Conséquence, le 2026-10-16 : au démarrage, `php artisan config:cache`
# évalue le défaut de Laravel pour view.compiled, soit
# realpath(storage_path('framework/views')). Sur un dossier absent,
# realpath() rend `false` — et ce `false` part dans le cache de
# configuration. Le compilateur Blade recevait donc un chemin vide et levait
# « Please provide a valid cache path » sur toute vue : la page d'accueil,
# les pages d'erreur, et la route de santé /up, qui rend elle aussi une vue.
# L'API, qui ne sert que du JSON, n'était pas touchée — d'où une panne
# invisible côté application mobile.
#
# Les créer ici suffit, et reste correct quelle que soit l'évolution du
# .dockerignore.
RUN mkdir -p storage/framework/views \
             storage/framework/cache/data \
             storage/framework/sessions \
             storage/logs \
    && chown -R www-data:www-data storage bootstrap/cache \
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
#
# ── Rattrapage des aperçus d'images ────────────────────────────────────────
#
# `media:apercus` fabrique la variante 1200x630 que og:image doit servir. La
# génération à l'upload ne vaut que pour les photos à venir : sans ce
# rattrapage, chaque article DÉJÀ en base garde son original, que WhatsApp
# refuse dès quelques centaines de ko — c'est-à-dire que les pages de partage
# restent sans vignette pour tout le catalogue actuel, soit le cas courant. Et
# le plan gratuit de Render n'ouvrant pas de Shell, « lancer la commande à la
# main » n'est pas une solution : c'est la même contrainte qui a déjà forcé les
# référentiels ici.
#
# Ce commentaire annonçait « son original de 2 à 6 Mo ». C'était impossible :
# jusqu'à depot-de-photos.ini, cette image n'activait aucun php.ini et PHP
# appliquait upload_max_filesize=2M. Mesure par HTTP réel sous ce plafond,
# POST /api/parts/1/media : 2 072 238 o -> 201, et 2 255 773 o -> 422. Le stock
# à rattraper pèse donc au plus 2 Mo par photo, ce qui est déjà très au-delà de
# ce que WhatsApp accepte. Les dépôts à venir, eux, iront jusqu'à 8192 ko.
#
# LANCÉ APRÈS LE DÉMARRAGE, ET BORNÉ. Les deux tiennent à des mesures.
#
# Après le démarrage, parce qu'une variante coûte du temps. Mesure sur 20
# photos fabriquées pour le cas (4032x3024, 5,3 Mo en moyenne — soit ce que le
# dépôt accepte DEPUIS depot-de-photos.ini, et non ce qui est en base), disque
# local :
#
#     media:apercus sur 21 lignes -> 14,6 s au total, 20 variantes écrites
#     une variante seule -> 0,662 s, entrée 5 312 223 o, sortie 258 891 o (840x630)
#
# Soit ~0,66 s par photo ICI, sur un disque local et un processeur entier. En
# ligne, chaque photo fait en plus un aller-retour vers R2 et l'instance
# gratuite partage son processeur. Dans la chaîne bloquante, ce temps-là
# s'ajoute avant le premier octet servi, et Render marque le déploiement en
# échec si le service ne répond pas. La boucle `curl .../up` attend donc que
# l'application réponde — c'est la même sonde que Render interroge — avant de
# commencer, et le `&` final garde tout cela hors du chemin de démarrage.
#
# Borné, parce que la mémoire est la vraie limite.
#
# Mesures du pic de `VignetteApercu::generer()`, relevées à memory_limit=128M.
#
# CES PICS NE DÉPENDENT PAS DE memory_limit, et la rédaction précédente
# affirmait le contraire pour justifier de ne pas poser la directive : « poser
# memory_limit y rendrait faux tous les chiffres qui suivent sans les
# remesurer ». C'était une cause attribuée à tort. Ce que GD alloue dépend de
# l'image — `VignetteApercu::octetsRequis` n'a que trois termes, les pixels de
# la source, ceux de la cible et le poids du fichier. memory_limit ne change
# que le VERDICT du garde-fou, pas le besoin. Vérification à 16,00 Mpx : une
# entrée de 9 057 386 o donnait 102,0 Mo sous 128M, une entrée de 7 931 546 o
# donne 102 798 656 o sous 192M. depot-de-photos.ini pose donc désormais
# memory_limit=192M, et les chiffres ci-dessous restent valides :
#
# Le POIDS D'ENTRÉE est cité à côté de chaque pic, et ce n'est pas de la
# décoration : il est l'un des trois termes de ce que GD alloue
# (VignetteApercu::octetsRequis), et deux images de même taille en pixels mais
# de poids différents ne demandent pas la même mémoire. Un pic annoncé sans lui
# ne veut rien dire — c'est le défaut des deux rédactions précédentes.
#
#     3,00 Mpx  (2000x1500), entrée  1 697 851 o -> variante 840x630, pic  46,0 Mo, 0,278 s
#     12,19 Mpx (4032x3024), entrée  6 901 583 o -> variante 840x630, pic  88,0 Mo, 0,761 s
#     16,00 Mpx (4000x4000), entrée  9 057 386 o -> variante 630x630, pic 102,0 Mo, 1,002 s
#     20,00 Mpx (5000x4000), entrée 11 325 171 o -> renoncement,      pic  38,0 Mo, 0,037 s
#     27,00 Mpx (6000x4500), entrée 15 284 506 o -> renoncement,      pic  42,0 Mo, 0,036 s
#
# Deux rédactions ont été fausses ici. La première annonçait 104,0 Mo à
# 12,2 Mpx puis ~136 Mo à 16 Mpx « par simple proportion » : la proportion est
# fausse, le socle du processus (26 Mo) ne suit pas les pixels. La seconde
# donnait 82,0 et 96,0 Mo sans dire sur quelles entrées : les pics ci-dessus
# sont plus hauts de 6 Mo parce que les images pèsent plus lourd, ce qui est
# exactement l'information qui manquait.
#
# 102,0 Mo contre les 128M d'alors laissaient 26 Mo ; les 102 798 656 o
# remesurés contre les 192M que pose maintenant depot-de-photos.ini en laissent
# 98 527 936 o. Le garde-fou calculé de VignetteApercu tenait déjà le cas
# serré — mesure par HTTP réel à
# memory_limit=128M, dépôt d'un 4000x4000 de 7 923 762 o : 201, variante 630x630
# produite — et le relèvement se lit en base : sur 11 lignes à 7 931 546 o
# (16,00 Mpx) déposées par HTTP réel, les 10 envoyées en une requête sous 128M
# ont 0 aperçu, la 1 envoyée seule sous 192M en a 1.
#
# ── CE QUI ANNULE UNE VIGNETTE : LA RÉSOLUTION, PAS LE POIDS ──────────────
#
# La rédaction précédente l'attribuait au poids : « relever le plafond de 2 Mo
# à 8192 ko laisse entrer des images que GD décompresse entièrement ». La
# mesure désigne la résolution, et PIXELS_MAX (16 Mpx) est la borne qui tranche.
# Deux appels directs à `VignetteApercu::generer()`, memory_limit=192M :
#
#       946 688 o à 17,22 Mpx (4200x4100) -> RENONCEMENT,    pic  27 268 688 o, 0,119 s
#     5 214 309 o à  8,67 Mpx (3400x2550) -> variante 840x630, pic  81 155 544 o, 0,678 s
#     7 931 546 o à 16,00 Mpx (4000x4000) -> variante 630x630, pic 102 798 656 o, 1,406 s
#
# La plus LÉGÈRE des trois est la seule refusée, et la plus lourde produit sa
# variante : c'est le franchissement de 16 Mpx qui annule, pas le passage de 2 à
# 8 Mo. Relevé en base après les dépôts HTTP : 1 ligne à 946 688 o -> 0 aperçu,
# 3 lignes à 5 214 309 o -> 3 aperçus, 30 lignes à 2 310 061 o (16,00 Mpx) ->
# 30 aperçus.
#
# ── LE PIRE DÉPÔT QUE LE PRODUIT ACCEPTE, ET CE QU'IL BLOQUE ──────────────
#
# Dix photos en une requête, par HTTP réel, sous 9M / 24M / 192M :
#
#     dix x   706 508 o ( 3,15 Mpx,  7 066 612 o) -> 201,  8,76 s, 10 variantes
#     dix x 2 072 670 o ( 4,18 Mpx, 20 728 232 o) -> 201, 11,43 s, 10 variantes
#     dix x 2 310 061 o (16,00 Mpx, 23 102 142 o) -> 201, 21,57 s et 24,78 s, 10 variantes
#     dix x 6 739 621 o (12,19 Mpx, 67 397 742 o) -> 413, 57,73 s
#     dix x 7 931 546 o (16,00 Mpx, 79 316 992 o) -> 413, 77,21 s
#
# LE PIRE ACCEPTÉ EST LE TROISIÈME, et ce n'est pas le plus lourd : c'est celui
# qui tient dix images juste sous PIXELS_MAX dans les 24 Mo du plafond. 21,57 s
# et 24,78 s sur deux essais, dix variantes produites, pic 102 443 696 o.
#
# CE QUE CES SECONDES BLOQUENT, mesuré. La LECTURE du corps n'est pas bloquante
# — la boucle du serveur intercale les autres requêtes, et `GET /up` sondé en
# continu pendant un dépôt de 20 728 232 o a répondu entre 0,25 et 0,53 s tout
# du long. C'est la PHASE SCRIPT qui sérialise : sur le pire cas ci-dessus, une
# sonde `GET /up` a attendu 23,85 s. Pendant ce temps, personne d'autre n'est
# servi.
#
# ET IL RESTE UN PLAFOND DE TEMPS QUE CE LOT NE TOUCHE PAS. Mesure sous
# `php -n -S` — c'est-à-dire exactement l'image, qui n'active aucun php.ini :
# `PHP_SAPI=cli-server`, `max_execution_time=30`. Le pire dépôt accepté s'en
# approche à 21,57 s et 24,78 s sur ce poste, et le processeur de l'instance
# gratuite est partagé. Au-delà, c'est une erreur fatale, donc un 500. Ni
# post_max_size ni memory_limit ne bornent ce cas : une image juste sous
# PIXELS_MAX n'a pas besoin de peser lourd — mesure, 946 688 o suffisent pour
# 17,22 Mpx —, si bien que le coût GD d'une requête est fixé par
# MAX_PHOTOS x PIXELS_MAX et non par le poids du corps. Le borner demanderait un
# budget de variantes par REQUÊTE et non par image, ce qui n'est pas dans ce
# lot.
#
# Le MODE DE DÉFAILLANCE, lui, est toujours celui-ci : un
# dépassement de mémoire est une erreur FATALE, que le try/catch de la classe ne
# rattrape pas. VignetteApercu ne parie donc plus sur une borne en pixels
# calibrée pour un socle : elle CALCULE ce que la recompression va allouer et le
# compare à ce qui reste, à chaque appel. Un dépôt sous une limite serrée perd
# sa vignette et garde sa photo, au lieu de mourir.
#
# `-d memory_limit=256M` reste posé SUR CETTE COMMANDE SEULEMENT, et désormais
# pour une raison de débit et non de survie : le rattrapage enchaîne des
# centaines de photos, et plus de mémoire libre veut dire moins de
# renoncements.
#
# CE QUE CELA FAIT AVEC LES 192M DU WEB, parce que l'instance gratuite n'a que
# 512 Mo pour tout. Les deux PLAFONDS font 448 Mo, et c'est le chiffre qui
# inquiète. Les deux PICS mesurés — socle compris, `memory_get_peak_usage`
# comptant tout le processus — font 106 569 680 o pour la requête la plus
# gourmande et 102 798 656 o pour une variante de 16 Mpx en console, soit
# ~200 Mo des 512. `php artisan serve` est mono-processus : une seule requête à
# la fois, donc au plus un memory_limit côté web. La marge est dans les pics,
# pas dans les plafonds, et un dépassement se verrait en OOM de l'instance et
# non en 500.
#
# `--limit` par défaut à 60, soit ~40 s de travail à la cadence mesurée ici, et
# nettement plus en ligne. Le volume à rattraper, lui, n'est PAS mesurable
# d'ici : une base réaliste (`db:seed`) compte 80 véhicules, 200 pièces et
# 80 accessoires et AUCUN media, parce que les photos ne viennent que des dépôts
# réels. Le chiffre est donc choisi sur ce qu'on sait — le coût par photo —, pas
# sur le stock, et c'est pour cela qu'il est réglable plutôt que gravé : solder
# un stock plus gros se fait en relevant MEDIA_APERCUS_LIMIT depuis le tableau
# de bord de Render — ce qui déclenche justement un redémarrage. Même mécanisme
# que le plancher de version de l'application : un réglage qui se corrige sans
# reconstruire l'image, et sans le Shell que le plan gratuit n'offre pas.
#
# `--limit` est éprouvé : mesure, `--refaire --limit=5` sur 21 lignes en traite
# 5 et s'arrête, en 3,8 s.
#
# ── Elle avance d'un démarrage au suivant, et ce n'était pas le cas ────────
#
# Ce commentaire affirmait que « chaque démarrage reprend là où le précédent
# s'est arrêté ». C'était faux : la commande repartait du plus petit
# identifiant et ne marquait pas ses échecs. Mesure, cinq media dont les deux
# plus petits identifiants sont irréparables, `--limit=2` lancé trois fois :
#
#     avant : 3 démarrages -> « 0 variante(s) generee(s) », 0 ligne sur 5
#     après : démarrage 1 -> 0 générée, les 2 lignes mortes marquées (essai 1/3)
#             démarrage 2 -> 2 générées
#             démarrage 3 -> 1 générée, les 3 lignes saines sont faites
#
# En ligne, l'ancien comportement donnait : aucune vignette, jamais, et un
# journal de déploiement qui se lisait comme un déploiement sain. Le détail est
# dans GenererApercusMedia.
#
# Hors de la chaîne bloquante, et son échec n'arrête rien : une photo sans
# variante sert son original, ce qui donne un aperçu médiocre — pas de page en
# panne. La commande rend désormais le code 1 quand RIEN n'a pu être généré
# alors qu'il y avait à faire, ce qui donne enfin quelque chose à dire au `||`
# ci-dessous — avec un code 0 systématique, il ne s'allumait jamais.
CMD php artisan config:cache \
    && php artisan route:cache \
    && (php artisan storage:link \
        || echo "ATTENTION : lien de stockage non cree, les photos locales seront en 404") \
    && php artisan migrate --force \
    && (php artisan db:seed --class=VehicleModelsSeeder --force \
        || echo "ATTENTION : referentiel des modeles non mis a jour") \
    && (php artisan db:seed --class=TrimsSeeder --force \
        || echo "ATTENTION : referentiel des finitions non mis a jour") \
    && (php artisan db:seed --class=VehicleCheckItemsSeeder --force \
        || echo "ATTENTION : points de controle non mis a jour") \
    && (nohup sh -c ' \
          for _ in 1 2 3 4 5 6 7 8 9 10; do \
            curl -fsS -o /dev/null "http://127.0.0.1:'"${PORT:-8000}"'/up" && break; \
            sleep 3; \
          done; \
          php -d memory_limit=256M artisan media:apercus --limit="${MEDIA_APERCUS_LIMIT:-60}" \
            || echo "ATTENTION : aucune vignette generee par ce rattrapage. Les echecs sont marques, le prochain demarrage traitera de nouvelles lignes ; voir les lignes « Apercu : media abandonne » de ce journal" \
        ' >&2 & ) \
    && php artisan serve --host=0.0.0.0 --port=${PORT:-8000}
