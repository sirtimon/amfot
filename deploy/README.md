# AMFOT — минимальный запуск на Debian / Docker

**Публичный адрес:** https://lms.idcg.tj. TLS завершается на **внешнем** reverse proxy, здесь Nginx слушает HTTP. В GitHub нет приложения, базы данных и пользовательских файлов.

## 1. Разместить инфраструктуру на сервере

```sh
git clone https://github.com/sirtimon/amfot.git /opt/amfot
cd /opt/amfot
cp deploy/.env.example .env
mkdir -p app storage
```

Положить **полный релиз Laravel** в `/opt/amfot/app` (файл `artisan` должен быть `/opt/amfot/app/artisan`, а PHP-классы Laravel — в `/opt/amfot/app/app`). Перенести **оригинальную папку storage** в `/opt/amfot/storage` (так, чтобы были `storage/app/public`, `storage/app/scorm`, `storage/framework`). **Не копировать пользовательские данные в GitHub.** SQL-дамп держать вне репозитория, например `/root/20261030_amfot.sql`.

В `/opt/amfot/.env` установить надёжные `MYSQL_ROOT_PASSWORD` и `MYSQL_PASSWORD`. В `/opt/amfot/app/.env` указать совпадающий `DB_PASSWORD` и параметры Laravel. Установить `HTTP_BIND` в адрес интерфейса Docker-сервера, доступный **только** reverse proxy. При проксировании на localhost оставить `127.0.0.1`. Если proxy на другом хосте — указать приватный IP Docker-хоста и ограничить порт 8080 firewall'ом.

## 2. Настроить Laravel

Используются два файла: `/opt/amfot/.env` для Compose (MYSQL_PASSWORD, HTTP_BIND и др.) и `/opt/amfot/app/.env` для Laravel (APP_URL, DB_HOST, QUEUE_CONNECTION и др.). Создайте `app/.env` из Laravel `.env.example` и заполните параметры:


```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://lms.idcg.tj
SESSION_SECURE_COOKIE=true
DB_CONNECTION=mysql
DB_HOST=db
DB_PORT=3306
DB_DATABASE=amfot
DB_USERNAME=amfot
DB_PASSWORD=THE_SAME_VALUE_AS_MYSQL_PASSWORD_IN_ROOT_ENV
QUEUE_CONNECTION=database
QUEUE_RETRY_AFTER=360
```

Сохранить остальные необходимые настройки из инструкции разработчиков (MAIL_*, Google Calendar OAuth, `APP_TZ` и т. д.). Laravel должен доверять заголовкам `X-Forwarded-Proto` **только от внешнего reverse proxy** (TrustProxies). Не публиковать оба `.env` в GitHub.

## 3. Запустить БД, установить зависимости, импортировать дамп

```sh
docker compose pull
docker compose up -d db
docker compose run --rm --no-deps init
docker compose run --rm --no-deps app composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
```

Composer выполняется на локальном сервере; исходники приложения не передаются в GHCR. При необходимости выдайте права на запись в `app/vendor`, `storage`, `app/bootstrap/cache` пользователю контейнера (`www-data`, UID 33).

Проверить структуру SQL до импорта (`CREATE DATABASE`, `USE`, `DEFINER`, кодировки). **Первый импорт выполнять только в пустую БД после резервной копии**. Если дамп содержит таблицы, например `migrations`, не запускать миграции вслепую:

```sh
docker compose exec -T db sh -c 'exec mysql -u root -p"$MYSQL_ROOT_PASSWORD" amfot' < /root/20261030_amfot.sql
docker compose run --rm --no-deps app php artisan migrate:status
```

Дамп был создан MySQL 8.0.46; здесь используется MySQL 8.4. Перед production обязательно проверить совместимость импорта и поведение приложения. При ошибках импорта не править дамп автоматически.

**APP_KEY:** создавайте новый только если нет зашифрованных старым ключом данных и не нужно сохранять их расшифровку; иначе запросите прежний ключ по защищённому каналу. При отсутствии ключа и подтверждённом отсутствии таких данных:

```sh
docker compose run --rm --no-deps app php artisan key:generate --force
```

Обновить кеш конфигурации:

```sh
docker compose run --rm --no-deps app php artisan config:clear
docker compose run --rm --no-deps app php artisan config:cache
```

Для Laravel 9 с closure routes не применять `route:cache`.

## 4. Запуск

```sh
docker compose up -d
docker compose ps
docker compose logs --tail=100 app nginx worker scheduler db
```

Сервис `init` автоматически создаёт относительные symlinks:
`public/storage -> ../storage/app/public` и `public/scorm -> ../storage/app/scorm`. Он **не удаляет** существующие обычные файлы/каталоги на месте ссылок.

На внешнем reverse proxy настройте `lms.idcg.tj` → `http://DOCKER_PRIVATE_IP:8080`, передавайте заголовки `Host`, `X-Forwarded-Proto: https`, `X-Forwarded-Port: 443`. Прямой публичный доступ к 8080 и MySQL не нужен.

## 5. Обновление runtime

```sh
cd /opt/amfot
docker compose pull
docker compose up -d
```

Сейчас используется проверенный SHA-тег `sha-56a4f4d`. После публикации проверенного `v1.0.0` можно заменить image tag в Compose. Пользовательские данные и БД не входят в runtime-образ. При обновлениях приложения выполняйте резервное копирование БД и storage отдельно.
