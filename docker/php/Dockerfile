# ─── Drupal PHP-FPM Dockerfile ────────────────────────────────────────────────
# Base: PHP 8.3 FPM on Debian Bookworm (slim)
FROM php:8.3-fpm-bookworm

# ─── System dependencies ───────────────────────────────────────────────────────
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    curl \
    unzip \
    zip \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libwebp-dev \
    libpq-dev \
    libxml2-dev \
    libzip-dev \
    libonig-dev \
    libicu-dev \
    libgmp-dev \
    mariadb-client \
    postgresql-client \
    && rm -rf /var/lib/apt/lists/*

# ─── PHP Extensions required by Drupal ────────────────────────────────────────
RUN docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
        --with-webp \
    && docker-php-ext-install -j$(nproc) \
        gd \
        pdo \
        pdo_pgsql \
        pgsql \
        opcache \
        zip \
        xml \
        dom \
        simplexml \
        intl \
        mbstring \
        exif \
        bcmath \
        gmp

# ─── PHP configuration ─────────────────────────────────────────────────────────
COPY php.ini /usr/local/etc/php/conf.d/drupal.ini

# ─── Composer ─────────────────────────────────────────────────────────────────
COPY --from=composer:2.9 /usr/bin/composer /usr/bin/composer

# ─── Working directory ─────────────────────────────────────────────────────────
WORKDIR /var/www/html

# ─── Non-root user for security ───────────────────────────────────────────────
RUN useradd -m -u 1000 -s /bin/bash drupal_user \
    && chown -R drupal_user:drupal_user /var/www/html

USER drupal_user

EXPOSE 9000
CMD ["php-fpm"]
