FROM debian:bookworm-slim as base

ENV PHP_CONF_DATE_TIMEZONE=UTC \
    PHP_CONF_MAX_EXECUTION_TIME=60 \
    PHP_CONF_MEMORY_LIMIT=512M \
    PHP_CONF_OPCACHE_VALIDATE_TIMESTAMP=0 \
    PHP_CONF_MAX_INPUT_VARS=1000 \
    PHP_CONF_UPLOAD_LIMIT=40M \
    PHP_CONF_MAX_POST_SIZE=40M

# Minimum PHP patch release the build must produce. PHP < 8.3.36 is vulnerable
# to PHP2Shell (CVE-2026-109618 / CVE-2026-109619, unauthenticated RCE).
# Declared before the apt install so that bumping it invalidates the apt layer
# cache and forces a fresh package download.
ARG PHP_MIN_VERSION=8.3.36

RUN echo 'APT::Install-Recommends "0" ; APT::Install-Suggests "0" ;' > /etc/apt/apt.conf.d/01-no-recommended && \
    echo 'path-exclude=/usr/share/man/*' > /etc/dpkg/dpkg.cfg.d/path_exclusions && \
    echo 'path-exclude=/usr/share/doc/*' >> /etc/dpkg/dpkg.cfg.d/path_exclusions && \
    apt-get update && \
    apt-get --yes install apt-transport-https ca-certificates curl wget &&\
    wget -O /etc/apt/trusted.gpg.d/php.gpg https://packages.sury.org/php/apt.gpg &&\
    sh -c 'echo "deb https://packages.sury.org/php/ bookworm main" > /etc/apt/sources.list.d/php.list' &&\
    apt-get update && \
    apt-get --yes install imagemagick \
        libmagickcore-6.q16-6-extra \
        ghostscript \
        php8.3-fpm \
        php8.3-cli \
        php8.3-intl \
        php8.3-opcache \
        php8.3-mysql \
        php8.3-zip \
        php8.3-xml \
        php8.3-gd \
        php8.3-grpc \
        php8.3-curl \
        php8.3-mbstring \
        php8.3-bcmath \
        php8.3-imagick \
        php8.3-apcu \
        php8.3-exif \
        php8.3-memcached \
        openssh-client \
        aspell \
        aspell-en aspell-es aspell-de aspell-fr && \
    apt-get clean && \
    rm -rf /var/lib/apt/lists/* && \
    ln -s /usr/sbin/php-fpm8.3 /usr/local/sbin/php-fpm && \
    usermod --uid 1000 www-data && groupmod --gid 1000 www-data && \
    mkdir /srv/pim && \
    sed -i "s#listen = /run/php/php8.3-fpm.sock#listen = 9000#g" /etc/php/8.3/fpm/pool.d/www.conf && \
    mkdir -p /run/php

COPY docker/build/akeneo.ini /etc/php/8.3/cli/conf.d/99-akeneo.ini
COPY docker/build/akeneo.ini /etc/php/8.3/fpm/conf.d/99-akeneo.ini

COPY docker/build/check-php-version.sh /usr/local/bin/check-php-version
# sed strips CRLF in case the script was checked out with Windows line endings.
RUN sed -i 's/\r$//' /usr/local/bin/check-php-version && \
    chmod +x /usr/local/bin/check-php-version && \
    check-php-version "$PHP_MIN_VERSION"

FROM base as dev

ARG PHP_MIN_VERSION

ENV PHP_CONF_OPCACHE_VALIDATE_TIMESTAMP=1
ENV COMPOSER_MEMORY_LIMIT=4G

RUN apt-get update && \
    apt-get --yes install gnupg &&\
    sh -c 'wget -q -O - https://packages.blackfire.io/gpg.key |APT_KEY_DONT_WARN_ON_DANGEROUS_USAGE=DontWarn apt-key add -' &&\
    sh -c 'echo "deb http://packages.blackfire.io/debian any main" >  /etc/apt/sources.list.d/blackfire.list' &&\
    apt-get update && \
    apt-get --yes install \
        blackfire \
        blackfire-php \
        curl \
        default-mysql-client \
        git \
        php8.3-xdebug \
        procps \
        unzip &&\
    apt-get clean && \
    rm -rf /var/lib/apt/lists/*

COPY docker/build/xdebug.ini /etc/php/8.3/cli/conf.d/99-akeneo-xdebug.ini
COPY docker/build/xdebug.ini /etc/php/8.3/fpm/conf.d/99-akeneo-xdebug.ini
COPY docker/build/blackfire.ini /etc/php/8.3/cli/conf.d/99-akeneo-blackfire.ini
COPY docker/build/blackfire.ini /etc/php/8.3/fpm/conf.d/99-akeneo-blackfire.ini

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
RUN chmod +x /usr/local/bin/composer

RUN mkdir -p /var/www/.composer && chown www-data:www-data /var/www/.composer
RUN mkdir -p /var/www/.cache && chown www-data:www-data /var/www/.cache

VOLUME /srv/pim

# Re-check after the dev-stage packages (xdebug, blackfire) are installed, so the
# final image is guaranteed to ship a patched PHP.
RUN check-php-version "$PHP_MIN_VERSION"
