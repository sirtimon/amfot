#!/bin/sh
set -eu
cd /var/www/html
php /opt/amfot/check-runtime.php --worker
exec php artisan queue:work database --queue=default --sleep=3 --tries=3 \
  --timeout="${QUEUE_WORKER_TIMEOUT:-60}" --max-time=3600 --memory=256
