FROM php:8.3-fpm-alpine

# ── Métadonnées ────────────────────────────────────────────────────────────────
LABEL maintainer="GoLivreur <lead.dev@golivreur.ci>"
LABEL description="PHP 8.3-FPM pour GoLivreur — Laravel 11"

# ── Dépendances système ────────────────────────────────────────────────────────
RUN apk add --no-cache \
    # Compilation des extensions PHP
    build-base \
    autoconf \
    # PostgreSQL
    libpq-dev \
    postgresql-dev \
    # Compression (pour les backups et uploads)
    zlib-dev \
    # Manipulation d'images (profils, photos)
    jpeg-dev \
    libpng-dev \
    freetype-dev \
    # Internationalisation
    icu-dev \
    icu-libs \
    # Utilitaires
    curl \
    git \
    unzip \
    nodejs \
    npm

# ── Extensions PHP ─────────────────────────────────────────────────────────────
RUN docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo \
        pdo_pgsql \      
        pgsql \
        gd \
        intl \
        zip \
        bcmath \
        pcntl \
        # opcache pour les performances en production
        opcache

# ── Extension Redis ────────────────────────────────────────────────────────────
RUN pecl install redis-6.0.2 \
    && docker-php-ext-enable redis

# ── Configuration PHP pour le développement ───────────────────────────────────
RUN mv "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

COPY docker/php/php-local.ini $PHP_INI_DIR/conf.d/99-golivreur.ini

# ── Composer ──────────────────────────────────────────────────────────────────
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

# ── Répertoire de travail ─────────────────────────────────────────────────────
WORKDIR /var/www/html

# ── Permissions ────────────────────────────────────────────────────────────────
RUN addgroup -g 1000 golivreur \
    && adduser -u 1000 -G golivreur -s /bin/sh -D golivreur \
    && chown -R golivreur:golivreur /var/www/html

USER golivreur

EXPOSE 9000
CMD ["php-fpm"]
