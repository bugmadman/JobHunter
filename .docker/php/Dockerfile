FROM dunglas/frankenphp:1-php8.5-alpine

# OPcache is not listed: since PHP 8.5 it is part of PHP itself and always loaded
RUN install-php-extensions \
    apcu \
    intl \
    pdo_pgsql

# Sources are relative to the build context, the repository root. conf.d is parsed alphabetically:
# the zz- prefix puts this file after the docker-php-ext-*.ini ones, so its values take precedence
# --chmod: COPY keeps the host file's mode, and PHP silently skips an ini file the app user can't read
COPY --chmod=0644 .docker/php/php.ini ${PHP_INI_DIR}/conf.d/zz-app.ini

COPY --from=composer/composer:2-bin /composer /usr/local/bin/composer

# Pass the host user's name and UID, so that files created in the bind-mounted project belong to that user
ARG USER=app
ARG USERID=1000

# /config and /data are the image's XDG config and data dirs: Caddy and Composer keep their state there
RUN set -eux; \
    adduser -D -u "${USERID}" "${USER}"; \
    chown -R "${USER}:${USER}" /app /config /data

USER ${USER}

WORKDIR /app
