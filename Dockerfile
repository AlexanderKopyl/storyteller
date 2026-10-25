# syntax=docker/dockerfile:1

# Single dev-targeted stage. R10: dev dependencies MUST be present, otherwise
# vendor/bin/{phpunit,phpstan,php-cs-fixer,deptrac} are missing and `make qa` cannot pass.
# A future production stage must not inherit this behaviour silently.
FROM php:8.5.12-fpm AS dev

ARG HOST_UID=1000
ARG HOST_GID=1000

# opcache is deliberately absent from docker-php-ext-install below: php:8.5 ships Zend
# OPcache built into the binary, so building it as a shared module fails with
# "cp: cannot stat 'modules/*'". It is still enabled - tuned in docker/php/php.ini.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libpq-dev \
        libicu-dev \
        libzip-dev \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql intl zip \
    && apt-get purge -y --auto-remove \
    && rm -rf /var/lib/apt/lists/*

# PCOV supplies the line-coverage driver. Without a driver PHPUnit does not fail - it
# prints "No code coverage driver available" and exits 0 - so a coverage threshold would
# be unverifiable rather than red. PCOV rather than Xdebug: it is coverage-only and much
# faster, and Xdebug silently produces nothing unless XDEBUG_MODE=coverage is also set.
# No extra build packages are needed: the official php images keep PHPIZE_DEPS installed.
RUN pecl install pcov \
    && docker-php-ext-enable pcov

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-storyteller.ini

# R9: a non-root user whose UID/GID match the host, so vendor/ and var/ written inside
# the container stay writable from macOS without sudo. GID 20 (staff) already exists in
# Debian, hence the tolerant groupadd.
RUN (groupadd -g "${HOST_GID}" app || true) \
    && useradd -u "${HOST_UID}" -g "${HOST_GID}" -m -s /bin/bash app

WORKDIR /app
USER app
