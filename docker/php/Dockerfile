FROM php:7.4-apache AS base
MAINTAINER Misley Márton <misley.marton@webdream.hu>

COPY --from=composer /usr/bin/composer /usr/bin/composer

# configure and install extra dependencies
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        # gd extension dependencies
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        # imap dependency
        libc-client-dev \
        libkrb5-dev \
        # intl dependency
        libicu-dev \
        # zip extension dependency
        libzip-dev \
        unzip \
    && apt-get clean \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-configure imap --with-kerberos --with-imap-ssl \
    && docker-php-ext-configure intl \
    && docker-php-ext-install \
        bcmath \
        exif \
        gd \
        imap \
        intl \
        opcache \
        pdo_mysql \
        sockets \
        sysvsem \
        zip \
    # install & enable pecl packages
    && pecl install \
        apcu \
    && docker-php-ext-enable \
        apcu \
        exif \
    # disable default apache vhost
    && a2dissite 000-default.conf \
    # enable apache rewrite engine
    && a2enmod rewrite \
    # add directory for composer cache
    && mkdir /var/www/.composer \
    && chmod 0777 /var/www/.composer

FROM base AS dev
RUN pecl install xdebug
