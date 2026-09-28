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
# Every WebSocket on the hub drops here. Chat is durable, so clients reconnect
# and nobody notices — but calls are not.
#
#   * A call already connected SURVIVES: its audio and video are peer-to-peer
#     and do not pass through this server at all. Signalling reconnects behind
#     it, and a short restart is covered by the client's outbox.
#   * A call that is still ringing or still negotiating DIES, because those
#     stages need the socket.
#
# There is deliberately no drain step: with no calls table the server does not
# know a call is in progress, and teaching it would mean adding exactly the
# persistence this feature was scoped to avoid. Release outside working hours.
sudo supervisorctl restart oaktree-reverb

# LiveKit is deliberately NOT restarted here, and the asymmetry with Reverb above
# is the reason. A connected one-to-one call survives a Reverb bounce because its
# media never touches this server. Huddle media does flow through the SFU, so
# restarting it drops every participant mid-sentence, and no drain helps.
#
# Upgrade the SFU by hand, out of hours, with deploy/livekit/install-livekit.sh.
#
# Huddle state needs no drain for the same reason calls need none: it lives in
# the cache and in LiveKit's own memory, and `huddles:reconcile` rebuilds it from
# LiveKit within a minute of any restart.

echo "==> Cycling queue workers onto the new code"
php artisan horizon:terminate

echo "==> Done"
