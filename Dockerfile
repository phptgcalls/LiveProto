# Dockerfile (fixed)
FROM php:8.2-cli

ENV DEBIAN_FRONTEND=noninteractive

# Install build tools & dev libs required to compile PHP extensions.
# We keep this block separate so apt errors are easy to read.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
      build-essential autoconf pkg-config ca-certificates gnupg wget curl \
      git unzip lsb-release apt-transport-https \
      libzip-dev zlib1g-dev libicu-dev libgmp-dev libcurl4-openssl-dev \
      libxml2-dev libonig-dev libsqlite3-dev libmagic-dev; \
    rm -rf /var/lib/apt/lists/*

# Configure zip (if needed) and install extensions in small groups.
# Installing in small groups gives clearer compile errors.
RUN set -eux; \
    docker-php-ext-configure zip; \
    docker-php-ext-install -j"$(nproc)" zip; \
    docker-php-ext-install -j"$(nproc)" gmp; \
    docker-php-ext-install -j"$(nproc)" sockets; \
    docker-php-ext-install -j"$(nproc)" mbstring; \
    docker-php-ext-install -j"$(nproc)" intl; \
    docker-php-ext-install -j"$(nproc)" xml; \
    docker-php-ext-install -j"$(nproc)" curl; \
    docker-php-ext-install -j"$(nproc)" pdo pdo_sqlite; \
    docker-php-ext-install -j"$(nproc)" fileinfo

# Composer (stable)
RUN set -eux; \
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /app

# Install composer dependencies (if you want composer to run at build time)
COPY composer.json composer.lock* /app/
RUN set -eux; \
    composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader || true

COPY . /app

CMD ["php", "-v"]
