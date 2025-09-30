FROM php:8.2-cli

ENV DEBIAN_FRONTEND=noninteractive

# Install OS deps and PHP build deps
RUN apt-get update \
  && apt-get install -y --no-install-recommends \
       git curl unzip libzip-dev zlib1g-dev libicu-dev libgmp-dev libcurl4-openssl-dev \
       software-properties-common ca-certificates \
  && docker-php-ext-configure zip \
  && docker-php-ext-install -j"$(nproc)" sockets gmp mbstring intl xml curl pdo pdo_sqlite fileinfo zip \
  && rm -rf /var/lib/apt/lists/*

# Install composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /app
COPY composer.json composer.lock* /app/

# Install PHP deps for your repo (no-dev for production)
RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader

# Copy the rest of your repo
COPY . /app

CMD ["php", "-v"]
