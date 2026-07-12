#!/usr/bin/env sh
# Installs PHP dependencies into ./vendor using the composer Docker image.
#
# Why not `composer install` inside `docker build`? BuildKit RUN steps inherit
# the host's resolv.conf read-only, and this LAN's DNS answers too slowly for
# Composer's 10s curl timeout. `docker run` accepts --dns, builds cannot.
# So: deps are installed here, and the Dockerfile COPYs vendor/ in (offline).
#
# Run this once after cloning and whenever composer.json changes, then
# `docker compose up -d --build`.
set -eu

cd "$(dirname "$0")/.."

docker run --rm \
    --dns 1.1.1.1 --dns 8.8.8.8 \
    -v "$PWD":/app -w /app \
    -u "$(id -u):$(id -g)" \
    -e COMPOSER_CACHE_DIR=/tmp/composer-cache \
    composer:2 \
    composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

echo "OK: vendor/ ready ($(ls vendor | wc -l) packages dirs). Now: docker compose up -d --build"
