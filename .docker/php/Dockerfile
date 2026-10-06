FROM dunglas/frankenphp:1-php8.5-alpine

# OPcache is not listed: since PHP 8.5 it is part of PHP itself and always loaded
RUN install-php-extensions \
    apcu \
    intl \
    pdo_pgsql

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
