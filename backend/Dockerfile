# Debian 13 "trixie" (security support until ~2028-08, LTS to ~2030) replaces Debian 11 bullseye (EOL,
# packages only on archive.debian.org). The old bullseye pin worked around a dev-host containerd
# "exec format error" with bookworm images; that no longer reproduces (bookworm/trixie images run).
# Still PHP 8.2: its security support ENDS 2026-12-31. PHP 8.3/8.4 run the app, but there
# http_response_code() refuses to change the code after output, which breaks the CLI harness of
# admin_authz_test.php / middleware_test.php (they echo before calling controllers); PHP 8.5 also
# deprecates curl_close()/finfo_close(). Move to php:8.4-fpm-trixie once those tests buffer output.
FROM php:8.2-fpm-trixie

RUN docker-php-ext-install pdo pdo_mysql

# poppler-utils: pdfinfo + pdftoppm for the secure reader (private PDF -> page JPEGs).
# prlimit (util-linux, already in the base image) caps their memory/CPU (see PdfPageRenderer).
RUN apt-get update \
    && apt-get install -y --no-install-recommends poppler-utils \
    && rm -rf /var/lib/apt/lists/*

COPY php/uploads.ini /usr/local/etc/php/conf.d/uploads.ini

WORKDIR /var/www/html
