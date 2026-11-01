#!/bin/sh
set -eu
cd /var/www/html
test -f artisan || { echo 'ERROR: mount the separate application release in app/ first' >&2; exit 1; }
mkdir -p storage/app/public storage/app/scorm storage/app/google-calendar \
  storage/framework/cache/data storage/framework/sessions \
  storage/framework/views storage/logs bootstrap/cache

for name in storage scorm; do
  link="public/$name"
  if [ -e "$link" ] && [ ! -L "$link" ]; then
    echo "ERROR: $link is a real file/directory; refusing to replace it" >&2
    exit 1
  fi
done

ln -sfn ../storage/app/public public/storage
ln -sfn ../storage/app/scorm public/scorm

# Avoid changing ownership recursively on potentially large user uploads.
chown -R www-data:www-data storage/framework storage/logs bootstrap/cache
chown www-data:www-data storage storage/app storage/app/public storage/app/scorm storage/app/google-calendar
chmod u+rwX storage storage/app storage/app/public storage/app/scorm storage/app/google-calendar
chmod -R u+rwX storage/framework storage/logs bootstrap/cache
ls -l public/storage public/scorm
