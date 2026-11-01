#!/bin/sh
# Run from the deployment checkout; safe output suitable for diagnostics.
set -eu
cd "$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)"
docker compose config --quiet
docker compose ps -a
docker compose exec -T --user www-data app php /opt/amfot/check-runtime.php
docker compose exec -T nginx nginx -t
curl --fail --silent --show-error --max-time 15 --output /dev/null \
  --write-out 'HTTPS login: %{http_code}; TLS verify: %{ssl_verify_result}\n' \
  https://lms.idcg.tj/login
