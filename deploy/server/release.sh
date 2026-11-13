#!/usr/bin/env bash
# Activates an uploaded release. Runs on the server as the environment's user (wingora-<env>), called by
# .github/workflows/deploy.yml. Usage: release.sh <development|staging|production> <release-dir>
set -euo pipefail

ENVIRONMENT=$1
RELEASE=$2
BASE=/srv/wingora/$ENVIRONMENT
KEEP_RELEASES=5

case "$ENVIRONMENT" in development|staging|production) ;; *) echo "unknown environment: $ENVIRONMENT" >&2; exit 2 ;; esac
[ "$(id -un)" = "wingora-$ENVIRONMENT" ] || { echo "must run as wingora-$ENVIRONMENT" >&2; exit 2; }
[ -f "$BASE/shared/.env" ] || { echo "$BASE/shared/.env is missing; run provision.sh first" >&2; exit 2; }

cd "$RELEASE/backend"
ln -sfn "$BASE/shared/.env" .env
rm -rf storage && ln -sfn "$BASE/shared/storage" storage

if [ "$ENVIRONMENT" = production ]; then
  sudo -n /usr/local/sbin/wingora-backup production
fi

php artisan migrate --force --no-interaction
# Reference data seeders are idempotent; they add new countries, currencies and rule templates shipped in a release.
php artisan db:seed --force --no-interaction
php artisan optimize

ln -sfn "$RELEASE" "$BASE/current.next"
mv -Tf "$BASE/current.next" "$BASE/current"

sudo -n /usr/bin/systemctl reload php8.4-fpm
php "$BASE/current/backend/artisan" queue:restart
sudo -n /usr/bin/systemctl restart "wingora-queue@$ENVIRONMENT"

find "$BASE/releases" -mindepth 1 -maxdepth 1 -type d -printf '%T@ %p\n' | sort -rn | tail -n +$((KEEP_RELEASES + 1)) | cut -d' ' -f2- | xargs -r rm -rf
echo "released $(basename "$RELEASE") to $ENVIRONMENT"
