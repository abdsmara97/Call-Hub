#!/usr/bin/env bash
#
# Deploy the Oak Tree Venture Hub.
#
# Order matters in two places:
#   - caches are rebuilt AFTER the new code is in place, never before;
#   - horizon:terminate runs LAST, so workers pick up the new code rather than
#     continuing to run the old copy they hold in memory.
#
# Usage: sudo -u www-data ./deploy/deploy.sh

set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/oaktree-hub}"

cd "$APP_DIR"

echo "==> Maintenance mode"
# The secret lets an administrator browse the site while it is down.
php artisan down --render="errors::503" --secret="${DEPLOY_SECRET:-deploy-bypass}" || true

cleanup() {
    echo "==> Bringing the app back up"
    php artisan up || true
}
trap cleanup EXIT

echo "==> Pulling code"
git pull --ff-only

echo "==> PHP dependencies"
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

echo "==> Front-end build"
npm ci
npm run build

echo "==> Database"
php artisan migrate --force

echo "==> Caches"
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

echo "==> Storage symlink"
php artisan storage:link || true

echo "==> Restarting the socket server"
sudo supervisorctl restart oaktree-reverb

echo "==> Cycling queue workers onto the new code"
php artisan horizon:terminate

echo "==> Done"
