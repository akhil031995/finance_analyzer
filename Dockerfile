# Personal Finance Tracker — PHP (Slim) + SQLite, served by Apache
FROM php:8.3-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip libzip-dev libsqlite3-dev \
 && docker-php-ext-install pdo pdo_sqlite zip \
 && a2enmod rewrite headers \
 && apt-get clean && rm -rf /var/lib/apt/lists/*

# Serve from /public (Slim front controller)
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
      /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
 && printf '<Directory /var/www/html/public>\n    AllowOverride All\n    Require all granted\n</Directory>\n' \
      > /etc/apache2/conf-enabled/zz-finance.conf

# Allow large statement uploads
RUN { \
      echo 'upload_max_filesize=25M'; \
      echo 'post_max_size=30M'; \
      echo 'memory_limit=256M'; \
      echo 'max_execution_time=300'; \
    } > /usr/local/etc/php/conf.d/finance.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html

# Dependencies are installed OUTSIDE the image build (bin/deps.sh) because
# this LAN's DNS is too slow for Composer inside BuildKit (resolv.conf is
# read-only there). vendor/ is COPY'd in below — the build is fully offline.
COPY . .
RUN test -f vendor/autoload.php \
      || { echo >&2 "vendor/ missing — run ./bin/deps.sh first"; exit 1; } \
 && mkdir -p data storage/uploads \
 && chown -R www-data:www-data data storage

EXPOSE 80
