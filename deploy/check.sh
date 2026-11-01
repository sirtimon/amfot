#!/bin/sh
# Run from the deployment checkout; safe output suitable for diagnostics.
set -eu
cd "$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)"
docker compose config --quiet
docker compose ps -a
running=$(docker compose ps --services --status running)
for service in app nginx worker scheduler db; do
  if ! printf '%s\n' "$running" | grep -Fxq "$service"; then
    echo "FAIL service not running: $service" >&2
    exit 1
  fi
done
docker compose exec -T --user www-data app php /opt/amfot/check-runtime.php
docker compose exec -T nginx nginx -t
status=$(curl --fail --silent --show-error --max-time 15 --output /dev/null \
  --write-out '%{http_code}' https://lms.idcg.tj/login)
test "$status" = 200 || { echo "FAIL HTTPS login status: $status" >&2; exit 1; }
echo 'OK HTTPS login: 200; TLS certificate verified'
