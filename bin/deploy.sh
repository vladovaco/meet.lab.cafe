#!/usr/bin/env bash
# Nasadenie na serveri (spúšťa GitHub Actions cez SSH, dá sa spustiť aj ručne):
#   bash bin/deploy.sh
# Stiahne vetvu main, nainštaluje závislosti, aktualizuje DB schému. .env a storage/ sa nemenia.
set -euo pipefail
cd "$(dirname "$0")/.."
PHP="${PHP_BIN:-php}"
BRANCH="${DEPLOY_BRANCH:-main}"

echo "== Git: $BRANCH"
git fetch --quiet origin "$BRANCH"
git checkout --quiet -B "$BRANCH" "origin/$BRANCH"
git reset --quiet --hard "origin/$BRANCH"

echo "== Composer"
if command -v composer >/dev/null 2>&1; then
  COMPOSER="composer"
else
  [ -f composer.phar ] || curl -sS https://getcomposer.org/installer | "$PHP" -- --quiet
  COMPOSER="$PHP composer.phar"
fi
$COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts 2>&1 | tail -n 3

echo "== Databáza (schéma) a adresáre"
mkdir -p storage/audio storage/logs
if [ -f .env ]; then
  "$PHP" bin/install.php
else
  echo "Chýba .env – schéma sa neaplikovala. Skopírujte .env.example na .env a spustite: $PHP bin/install.php --email=... --password=..."
fi

echo "== Nasadené: $(git rev-parse --short HEAD) ($(git log -1 --format=%s))"
