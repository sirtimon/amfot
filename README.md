# AMFOT: инфраструктура LMS

Docker runtime и инструкции для **https://lms.idcg.tj** (Laravel 9 / PHP 8.2 / MySQL 8.4).

**Репозиторий и GHCR содержат только инфраструктуру.** Приложение поставляется отдельно архивом, база — SQL-дампом. Исходники Laravel, vendor, .env, ключи, дампы и загрузки остаются на сервере. Не прикладывайте их к issues, PR, Actions artifacts или GitHub Releases.

- [Установка и переход с прежней конфигурации](deploy/README.md).
- [Mini howto: остановка, перезапуск, обновление и проверка](docs/operations.md).
- [Пароль администратора и смена пароля БД](docs/passwords.md).
- [Backup и восстановление](docs/backup-restore.md).
- [SSH, DNS, очередь и диагностика](docs/troubleshooting.md).

Runtime ghcr.io/sirtimon/amfot-php включает PHP FPM, Composer, cron, Java и pdftk-java; приложение в образ не копируется. Общий bind mount PHP-контейнеров: /opt/amfot/app. Пользовательские файлы лежат в /opt/amfot/app/storage; отдельного mount /opt/amfot/storage нет.

## Сборка и публикация

PR проверяется workflow Validate infrastructure: граница допустимых файлов, Compose, shell/PHP, сборка runtime и Nginx. Реальное приложение и БД для CI не требуются.

Workflow Publish AMFOT PHP runtime запускается вручную или по тегу v*, публикует только runtime с SHA-тегом и версией для релизных тегов. Используется встроенный GITHUB_TOKEN, приложение не скачивается. Для private GHCR нужен доступ на чтение пакета на сервере.

После изменения Dockerfile дождитесь успешной проверки и публикации, затем закрепите конкретный образ через PHP_IMAGE в серверном .env. **Новая инфраструктура требует runtime с pcntl; старый v1.0.0 нужно проверить или заменить новым SHA-тегом.** Compose pull не собирает новую версию.

На тестовом сервере 1 ноября 2026 проверены HTTPS, страница входа, CSS/JS, БД и статус миграций. Обнаружены включённый debug и несовпадение queue timeout/retry_after. Новые изменения требуют отдельной приёмки на тестовом сервере; CI не проверяет бизнес-сценарии.

## Автоматический релиз runtime

Изменение runtime-version.txt в main запускает проверки и публикацию runtime, затем создаёт GitHub Release с этим тегом. Для следующего релиза укажите новую версию vX.Y.Z; существующие теги не перезаписываются. Публикация также доступна по обычному git-тегу v*; ручной dispatch создаёт только SHA-тег. В релиз не загружаются приложение, дамп или серверные файлы. Права contents:write используются только publish job для создания релизного тега; секреты сервера workflow не получает.

Backend Nginx передаёт PHP HTTPS=on/SERVER_PORT=443, поскольку этот deployment обслуживается через внешний TLS proxy. HTTP-порт backend должен быть доступен только доверенному proxy.
