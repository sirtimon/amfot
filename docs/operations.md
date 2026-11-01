# Mini howto: управление проектом

Команды выполняются на сервере из /opt/amfot. В Windows сначала подключитесь:

~~~powershell
ssh amfot-lms
~~~

## Проверить работоспособность

~~~sh
cd /opt/amfot
sh deploy/check.sh
docker compose ps -a
docker stats --no-stream
df -h /
free -h
~~~

Успех: probe без FAIL, HTTPS login 200, nginx/db/app healthy, worker/scheduler running. Init с Exited (0) — нормально. Пустая очередь не доказывает обработку заданий: проверьте контролируемое тестовое задание и пользовательские сценарии. Старые failed jobs не означают новую аварию; сравнивайте даты.

Безопасная проверка миграций:

~~~sh
docker compose exec --user www-data app php artisan migrate:status
~~~

Не прикладывайте полные inspect/config/logs к публичным issues: там могут оказаться секреты и пользовательские данные.

## Остановить и запустить

~~~sh
docker compose stop
docker compose start
~~~

Stop останавливает контейнеры, сохраняя БД и app/storage. Worker получает до 360 секунд для завершения. Start запускает существующие контейнеры, но не применяет новые настройки. Для первого запуска или новой конфигурации:

~~~sh
docker compose up -d
~~~

**Не используйте docker compose down -v:** это удалит том MySQL. Обычный down удалит контейнеры/сеть, сохранив named volume, но для краткой остановки достаточно stop.

## Перезапустить сервис

~~~sh
docker compose restart app
docker compose restart worker scheduler
sh deploy/check.sh
~~~

При пересоздании app его IP может измениться. Выполните docker compose restart nginx, если Nginx продолжает обращаться по старому IP. Плановый restart db прерывает подключения: делайте его только в согласованное окно.

Restart не обновляет образ и container environment. После изменения Compose используйте up -d --force-recreate для затронутых сервисов.

## Применить изменение app/.env

Не выводите файл в терминал. Правьте редактором на сервере. Чтобы worker и cron не работали с промежуточным конфигом:

~~~sh
docker compose exec --user www-data app php artisan down
docker compose stop nginx worker scheduler app
docker compose run --rm --no-deps --user www-data app php artisan config:clear
docker compose run --rm --no-deps --user www-data app php artisan config:cache
docker compose run --rm --no-deps --user www-data app php /opt/amfot/check-runtime.php
docker compose run --rm --no-deps --user www-data app php artisan up
docker compose up -d --force-recreate app worker scheduler nginx
sh deploy/check.sh
~~~

Остановка scheduler не прерывает его задания гарантированно без побочных эффектов: выбирайте окно без длительных задач. При неуспехе probe сначала исправьте конфигурацию, затем выполняйте artisan up. При down внешние запросы могут получать 502, поскольку Nginx остановлен.

## Обновить инфраструктуру и runtime

1. Сделайте [backup](backup-restore.md). Запишите предыдущий commit и точный runtime image/digest. Сохраните корневой .env и app/.env вне GitHub.
2. Убедитесь, что выбранный runtime опубликован, содержит pcntl и прошёл CI. Обновите PHP_IMAGE в корневом .env; не меняйте APP_KEY.
3. Введите maintenance и остановите исполнителей перед изменением bind-mounted инфраструктуры:

~~~sh
docker compose exec --user www-data app php artisan down
docker compose stop nginx worker scheduler app
git status --short
git pull --ff-only
docker compose config --quiet
docker compose pull app worker scheduler init nginx
docker compose run --rm --no-deps init
docker compose run --rm --no-deps --user www-data app php artisan config:clear
docker compose run --rm --no-deps --user www-data app php artisan config:cache
docker compose run --rm --no-deps --user www-data app php /opt/amfot/check-runtime.php
docker compose run --rm --no-deps --user www-data app php artisan up
docker compose up -d --force-recreate app worker scheduler nginx
sh deploy/check.sh
~~~

Если Git обнаружил локальные изменения инфраструктуры — разберите diff, не делайте reset --hard. Не обновляйте MySQL одновременно с runtime без отдельной проверки и backup. Не запускайте миграции только из-за обновления Docker-образа.

Если обновление остановилось, не снимайте maintenance до исправления или отката. Не удаляйте том БД.

## Обновить приложение из нового архива

Приложение не поступает из GitHub. Нужны релиз от поставщика и его инструкция миграций.

1. Сделайте согласованный backup БД, всего app и локальных настроек.
2. Распакуйте и проверьте архив вне checkout; убедитесь в совместимости PHP и наличии composer.lock.
3. Введите maintenance; остановите nginx/worker/scheduler/app. Сохраните прежний app отдельной директорией вне GitHub.
4. Разместите новую поставку в app, сохранив прежние app/.env, APP_KEY и app/storage. Не копируйте старый bootstrap/cache/config.php и не перетирайте uploads новым архивом.
5. Выполните init, composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction и composer check-platform-reqs --no-dev через compose run --rm --no-deps app.
6. Проверьте migrate:status. Только если поставщик предусматривает миграции — выполните php artisan migrate --force через одноразовый app в окно обслуживания.
7. Выполните config:clear/config:cache как www-data, probe, artisan up, затем up -d --force-recreate app worker scheduler nginx и check.sh.
8. Проверьте вход, SCORM, PDF, загрузки, письма и очередь. Оригинальный архив и дамп храните вне GitHub.

## Откат

Остановите исполнителей. Верните прежний infrastructure commit, PHP_IMAGE, поставку app и подходящие настройки из защищённой копии. Пересоберите конфиг и пересоздайте PHP/Nginx контейнеры. Сохраняйте согласованные данные storage.

Если новая поставка изменила схему или данные, возврат одного образа **не откатит БД**. Используйте проверенную процедуру [восстановления](backup-restore.md); не запускайте migrate:rollback вслепую. Не используйте Git для хранения копий приложения.
