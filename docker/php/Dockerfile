FROM php:8.2-fpm-bookworm

LABEL org.opencontainers.image.source="https://github.com/sirtimon/amfot" \
      org.opencontainers.image.description="PHP runtime for AMFOT (application sources not included)"

RUN apt-get update && apt-get install -y --no-install-recommends \
    git unzip ca-certificates cron pdftk-java default-jre-headless \
    libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    libonig-dev libxml2-dev libcurl4-openssl-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mbstring xml curl zip gd intl bcmath opcache exif pcntl \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY docker/php/production.ini /usr/local/etc/php/conf.d/99-amfot.ini
COPY docker/php/scheduler.cron /etc/cron.d/amfot

RUN chmod 0644 /etc/cron.d/amfot
WORKDIR /var/www/html
