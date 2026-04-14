# PHP-FPM for Drupal 11 (local development).
FROM php:8.3-fpm-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
    curl \
    git \
    unzip \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libzip-dev \
    libicu-dev \
    mariadb-client \
  && docker-php-ext-configure gd --with-freetype --with-jpeg \
  && docker-php-ext-install -j$(nproc) \
    gd \
    intl \
    opcache \
    pdo_mysql \
    zip \
  && apt-get clean && rm -rf /var/lib/apt/lists/*

# MailHog sendmail shim so PHP mail() reaches Mailhog SMTP.
RUN curl -fsSL https://github.com/mailhog/mhsendmail/releases/download/v0.2.0/mhsendmail_linux_amd64 -o /usr/local/bin/mhsendmail \
  && chmod +x /usr/local/bin/mhsendmail

COPY php.ini /usr/local/etc/php/conf.d/zz-mel.ini

WORKDIR /var/www/html
