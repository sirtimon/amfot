# Установка AMFOT на Debian / Docker Compose

Команды выполняются в shell **сервера**, из /opt/amfot. Нужны Docker Engine, Compose v2, curl, git и административный доступ. TLS завершается на внешнем reverse proxy; этот Nginx слушает HTTP.

## 1. Инфраструктура и отдельный архив

~~~sh
git clone https://github.com/sirtimon/amfot.git /opt/amfot
cd /opt/amfot
cp deploy/.env.example .env
mkdir -p app
chmod 600 .env
~~~

Архив приложения и SQL доставляйте по защищённому каналу вне checkout, например в /srv/amfot-private/incoming. Проверьте контрольные суммы поставщика и состав архива (без абсолютных путей и ..). Перенесите полный релиз в /opt/amfot/app: artisan, composer.json, composer.lock, public, app, bootstrap, config и исходную storage с пользовательскими файлами. Не заменяйте storage пустой директорией.

Единственное расположение данных Laravel — /opt/amfot/app/storage. Если осталась отдельная /opt/amfot/storage, сначала сохраните и сверяйте её с данными релиза; автоматически ничего не удаляйте и не переносите поверх.

В корневом .env задайте разные случайные MYSQL_ROOT_PASSWORD и MYSQL_PASSWORD, HTTP bind и **проверенный опубликованный PHP_IMAGE**. Нужен runtime с pcntl: опубликуйте текущий Dockerfile перед развёртыванием этой версии. Не используйте latest.

HTTP_BIND=127.0.0.1 подходит только для proxy на этом же сервере. Для внешнего proxy укажите приватный IP сервера, ограничьте TCP 8080 адресом proxy с учётом правил Docker/DOCKER-USER. MySQL не публикуется. Имя сети internal не отключает исходящий интернет: он нужен почте и интеграциям.

## 2. Локальные настройки Laravel

Создайте app/.env из поставки, сохранив оригинальные APP_KEY, настройки почты и интеграций. Несекретные настройки:

~~~dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://lms.idcg.tj
SESSION_SECURE_COOKIE=true
DB_CONNECTION=mysql
DB_HOST=db
DB_PORT=3306
DB_DATABASE=amfot
DB_USERNAME=amfot
QUEUE_CONNECTION=database
~~~

DB_PASSWORD должен совпадать с MYSQL_PASSWORD корневого .env; вводите его локальным редактором, не в командной строке. Учитывайте синтаксис dotenv для пробелов, # и $. Не выводите оба файла и результат docker compose config без --quiet в отчёты.

PHP-контейнеры больше не получают env_file: app/.env. Laravel читает собственный bind-mounted файл либо кеш конфигурации. FPM, worker и cron используют один источник. Старые контейнеры нужно **пересоздать**: restart не удалит прежнее окружение.

Worker по умолчанию имеет timeout 60, меньше стандартного retry_after 90. Для длительных PDF/SCORM-задач настройте в **отдельно поставляемом приложении** config/queue.php: connections.database.retry_after должен быть больше максимального времени задания. Для timeout 300 подходит retry_after 360, а в корневом .env установите QUEUE_WORKER_TIMEOUT=300. Одного QUEUE_RETRY_AFTER=360 недостаточно, если PHP-конфиг его не читает. Проверьте также $timeout самих job-классов: он может переопределять CLI timeout. Исходники приложения в GitHub не переносить.

Laravel должен доверять forwarded-заголовкам только от настоящего proxy. На proxy передавайте Host, X-Forwarded-Proto: https, X-Forwarded-Port: 443 и корректный client IP; очищайте подставленные клиентом forwarded-заголовки. TrustProxies настраивается в отдельно поставляемом приложении.

## 3. Инициализация и импорт

~~~sh
docker compose config --quiet
docker compose pull
docker compose up -d db
docker compose run --rm --no-deps init
docker compose run --rm --no-deps app composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
docker compose run --rm --no-deps app composer check-platform-reqs --no-dev
~~~

Init создаёт рабочие каталоги и относительные ссылки public/storage → ../storage/app/public, public/scorm → ../storage/app/scorm. Обычные директории вместо ссылок не заменяет. Устанавливает владельца www-data (UID 33) рабочих директорий, **не меняет рекурсивно все загрузки**. Старые файлы с неподходящими правами исправляйте адресно после проверки; не используйте chmod -R 777.

App/.env должен читаться UID 33: например root:33, режим 640. Bootstrap/cache должен быть доступен на запись www-data. Composer устанавливает зависимости только на сервере, не в GitHub или образ.

SQL импортируется **только в пустую БД**, без работающих app/worker/scheduler. Проверьте CREATE DATABASE, USE, DEFINER, кодировки и совместимость MySQL 8.0 → 8.4. Существующие данные сначала [сохраните](../docs/backup-restore.md). Не исправляйте SQL автоматически.

~~~sh
docker compose exec -T db sh -c 'export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; exec mysql --user=root amfot' < /srv/amfot-private/incoming/database.sql
docker compose run --rm --no-deps --user www-data app php artisan migrate:status
~~~

Не выполняйте migrate:fresh, db:wipe или seed поверх импорта. Migrate --force допускается только для предусмотренных поставщиком новых миграций после backup.

Сохраняйте оригинальный APP_KEY: его замена может лишить доступа к зашифрованным данным. Key:generate --force допустим только для новой установки без зависимых от старого ключа данных.

~~~sh
docker compose run --rm --no-deps --user www-data app php artisan config:clear
docker compose run --rm --no-deps --user www-data app php artisan config:cache
docker compose run --rm --no-deps --user www-data app php /opt/amfot/check-runtime.php
~~~

Последняя команда должна завершиться кодом 0. Она выводит статусы и количества заданий без секретов. Устаревший кеш способен сохранять debug=true после изменения .env. Не используйте route:cache с closure routes.

## 4. Запуск и приёмка

~~~sh
docker compose up -d
sh deploy/check.sh
~~~

Nginx запускается после healthy app. PHP probe проверяет конфигурацию, расширения, БД и каталоги; /healthz проверяет только Nginx. Unhealthy сам по себе не перезапускает контейнер. Worker работает как www-data и проверяет настройки перед запуском. Scheduler запускает cron как root, задания — как www-data.

Внешний proxy направляет lms.idcg.tj на http://PRIVATE_IP:8080. Проверьте HTTPS, вход/выход, upload, SCORM, PDF, тестовое письмо и обработку очереди. У scheduler должны быть актуальные записи в app/storage/logs/scheduler.log.

## 5. Переход с предыдущего запуска

1. Сделайте backup, сохраните предыдущий infrastructure commit, runtime image/digest и настройки отдельно от GitHub.
2. Опубликуйте runtime с pcntl, закрепите его SHA-тег в корневом .env.
3. Отключите debug, включите secure cookie, выберите timeout/retry_after с учётом job overrides.
4. Выполните [обновление инфраструктуры](../docs/operations.md#обновить-инфраструктуру-и-runtime): оно пересоздаёт контейнеры и кеш с новым источником env.
5. Старые 8 failed jobs от 2023 года не удаляйте и не повторяйте автоматически.

Справка: [Laravel 9 queues](https://laravel.com/docs/9.x/queues), [Docker env precedence](https://docs.docker.com/compose/how-tos/environment-variables/envvars-precedence/).
