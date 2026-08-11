# Fronote — image applicative reproductible
# Base : PHP 8.2 + Apache (mpm_prefork), Debian.
FROM php:8.2-apache

# --- Dépendances système nécessaires à la compilation des extensions PHP ---
# libzip      -> ext-zip
# libsodium   -> ext-sodium
# libicu      -> ext-intl
# libpng/jpeg/freetype -> ext-gd
# unzip/git   -> composer (récupération des paquets)
RUN apt-get update && apt-get install -y --no-install-recommends \
        libzip-dev \
        libsodium-dev \
        libicu-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        unzip \
        git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        zip \
        sodium \
        intl \
        gd \
        opcache \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# --- Modules Apache : réécriture d'URL + en-têtes ---
RUN a2enmod rewrite headers

# --- Configuration PHP personnalisée (opcache + erreurs masquées en prod) ---
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-fronote.ini

# --- DocumentRoot + AllowOverride All (.htaccess actifs) ---
ENV APACHE_DOCUMENT_ROOT=/var/www/html
RUN set -eux; \
    printf '%s\n' \
        '<Directory /var/www/html>' \
        '    Options Indexes FollowSymLinks' \
        '    AllowOverride All' \
        '    Require all granted' \
        '</Directory>' \
        > /etc/apache2/conf-available/fronote-docroot.conf \
    && a2enconf fronote-docroot

# --- Composer (binaire copié depuis l'image officielle) ---
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# --- Installation des dépendances PHP en couche dédiée (cache build) ---
# Installation STRICTE : un échec de composer doit casser le build (pas de vendor/
# incomplet embarqué silencieusement dans l'image).
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist --optimize-autoloader

# --- Copie du code applicatif ---
COPY . /var/www/html

# --- Permissions : Apache (www-data) doit pouvoir écrire dans les dossiers runtime ---
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80

CMD ["apache2-foreground"]
