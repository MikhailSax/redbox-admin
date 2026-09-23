#!/usr/bin/env bash
#
# Deploys the current branch on the hosting. Run it over SSH from the project folder:
#
#     cd ~/www/sibiradm.ru && bin/deploy.sh
#
# PHP=/opt/php84/bin/php bin/deploy.sh — when the shell's "php" is not the version the site runs.
# Assets (public/build) are built here when npm is available, otherwise upload them from your machine
# (see docs/deploy-reg-ru.md).
set -euo pipefail

cd "$(dirname "$0")/.."
PHP=${PHP:-php}
CONSOLE="$PHP bin/console --env=prod --no-interaction"

echo "==> Обновляю код"
git pull --ff-only

echo "==> Зависимости PHP"
composer install --no-dev --optimize-autoloader --classmap-authoritative --no-interaction

if command -v npm >/dev/null 2>&1; then
    echo "==> Сборка фронтенда"
    npm ci
    npm run build
else
    echo "==> npm не найден: public/build берётся из того, что уже загружено"
fi

echo "==> Миграции базы"
$CONSOLE doctrine:migrations:migrate --allow-no-migration

echo "==> Кеш"
$CONSOLE cache:clear
$CONSOLE cache:warmup

# The web server and the console may run as different users; keep both able to write
chmod -R ug+rwX var public/uploads 2>/dev/null || true

echo "==> Готово: https://sibiradm.ru/admin"
