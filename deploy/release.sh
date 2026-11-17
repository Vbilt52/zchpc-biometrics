#!/usr/bin/env bash
# Run as root on the existing VM with the reviewed main commit as the argument.
set -euo pipefail
expected=${1:?Supply the reviewed main commit SHA}
[[ "$expected" =~ ^[a-f0-9]{40}$ ]] || exit 2
repo=/var/www/zchpc-biometrics
app="$repo/zchpc-dashboard"
run() { runuser -u user -- "$@"; }
exec 9>/run/lock/zchpc-release.lock
flock -n 9 || { echo 'Another deployment is running.'; exit 1; }
cd "$repo"
run git fetch origin main
test "$(run git rev-parse origin/main)" = "$expected"
run git -c core.filemode=false diff --quiet
run git -c core.filemode=false diff --cached --quiet
previous=$(run git rev-parse HEAD)
stamp=$(date -u +%Y%m%dT%H%M%SZ)
stage="/home/user/zchpc-transfer/release-$stamp"
backup="/home/user/zchpc-backups/release-$stamp"
install -d -o user -g www-data -m 750 "$backup"
run git worktree add --detach "$stage" "$expected"
cd "$stage/zchpc-dashboard"
# Fetch and validate dependencies before the maintenance window.
run composer install --no-dev --prefer-dist --no-interaction --no-scripts --optimize-autoloader
run composer check-platform-reqs --no-dev
maintenance=0
vendor_saved=0
finish() {
    result=$?
    trap - EXIT
    if [ "$result" -ne 0 ] && [ "$maintenance" -eq 1 ]; then
        echo 'Deployment failed; restoring the preceding code and dependencies.' >&2
        cd "$repo"
        run git -c core.filemode=false checkout --detach "$previous"
        if [ "$vendor_saved" -eq 1 ]; then
            if [ -d "$app/vendor" ]; then mv "$app/vendor" "$backup/failed-vendor"; fi
            mv "$backup/vendor" "$app/vendor"
        fi
        cd "$app"
        run php artisan optimize:clear
        run php artisan config:cache
        run php artisan view:cache
        run php artisan up
        systemctl restart zchpc-scheduler zchpc-queue
        systemctl reload php8.3-fpm
        echo 'Previous code restored. Database changes were preserved; review the release backup before any data recovery.' >&2
    fi
    exit "$result"
}
trap finish EXIT
cd "$app"
run php artisan down --retry=15
maintenance=1
systemctl stop zchpc-scheduler zchpc-queue
run python3 - "$backup/database.sqlite" <<'PY'
import sqlite3,sys,os
src=sqlite3.connect('database/database.sqlite')
dest=sqlite3.connect(sys.argv[1]);src.backup(dest);dest.close()
os.chmod(sys.argv[1],0o600)
print('Consistent database backup created.')
PY
install -m 600 "$app/.env" "$backup/environment"
printf '%s\n' "$previous" > "$backup/previous-commit"
cd "$repo"
run git -c core.filemode=false merge --ff-only "$expected"
mv "$app/vendor" "$backup/vendor"
vendor_saved=1
run cp -a "$stage/zchpc-dashboard/vendor" "$app/vendor"
cd "$app"
run composer dump-autoload --no-dev --optimize --no-interaction
run php artisan migrate --force
run php artisan config:cache
run php artisan route:cache
run php artisan view:cache
chown -R user:www-data "$app/bootstrap/cache"
find "$app/bootstrap/cache" -type f -exec chmod 664 {} +
run php artisan up
systemctl restart zchpc-scheduler zchpc-queue
systemctl reload php8.3-fpm
systemctl is-active zchpc-scheduler zchpc-queue
test "$(curl -sS -o /dev/null -w '%{http_code}' --max-time 20 https://biometrics.hpc.ac.zw/login)" = 200
maintenance=0
echo "DEPLOYED $expected"
echo "BACKUP $backup"
# Retain the staging checkout and previous vendor directory for recovery.
