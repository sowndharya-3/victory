# Pinned to bullseye (not the current bookworm-based php:8.2-fpm) because
# this dev host's Docker containerd snapshotter cannot run the bookworm
# image at all — every binary in it fails with "exec format error" despite
# correct amd64/linux metadata and a verified-clean re-pull (isolated via
# testing alpine/hello-world, which run fine on the same daemon). Same PHP
# 8.2, same extensions. Local dev only — do not ship bullseye to production
# without checking it still receives security patches.
FROM php:8.2-fpm-bullseye

RUN docker-php-ext-install pdo pdo_mysql

WORKDIR /var/www/html
