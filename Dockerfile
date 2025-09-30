# Dockerfile
FROM php:8.4-cli

ENV DEBIAN_FRONTEND=noninteractive

# Install OS build deps + libraries required for php extensions
RUN apt-get update && apt-get install -y --no-install-recommends \
    build-essential autoconf pkg-config ca-certificates gnupg wget curl \
    git unzip \
    libzip-dev zlib1g-dev libicu-dev libgmp-dev libcurl4-openssl-dev \
    libxml2-dev libonig-dev libsqlite3-dev libmagic-dev \
    software-properties-common \
  && docker-php-ext-configure zip \
  && docker-php-ext-install -j"$(nproc)" \
        sockets gmp mbstring intl xml curl pdo pdo_sqlite fileinfo zip \
  && rm -rf /var/lib/apt/lists/*

# composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /app
COPY composer.json composer.lock* /app/
RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader || true

COPY . /app

CMD ["php", "-v"]

