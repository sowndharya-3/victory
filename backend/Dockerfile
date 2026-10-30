# Pinned to bullseye (not the current bookworm-based php:8.2-fpm) because
# this dev host's Docker containerd snapshotter cannot run the bookworm
# image at all — every binary in it fails with "exec format error" despite
# correct amd64/linux metadata and a verified-clean re-pull (isolated via
# testing alpine/hello-world, which run fine on the same daemon). Same PHP
# 8.2, same extensions. Local dev only — do not ship bullseye to production
# without checking it still receives security patches.
FROM php:8.2-fpm-bullseye

RUN docker-php-ext-install pdo pdo_mysql

# poppler-utils: pdfinfo + pdftoppm for the secure reader (private PDF -> page JPEGs).
# bullseye is EOL: its security pool now lives only on archive.debian.org (deb.debian.org
# still serves the index but 404s the .debs). Another reason not to ship this image.
RUN sed -i 's#http://deb.debian.org/debian-security#http://archive.debian.org/debian-security#' /etc/apt/sources.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends poppler-utils \
    && rm -rf /var/lib/apt/lists/*

COPY php/uploads.ini /usr/local/etc/php/conf.d/uploads.ini

WORKDIR /var/www/html
