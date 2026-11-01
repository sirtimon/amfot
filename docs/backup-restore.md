# Mini howto: backup и восстановление

Резервная копия включает БД, **весь app с storage**, APP_KEY/app/.env и корневой .env, точную версию инфраструктуры и PHP_IMAGE. Держите её вне checkout, с ограниченными правами, шифрованием и отдельной копией вне сервера. В GitHub не загружать.

## Сделать согласованную копию

Запускайте в shell сервера с достаточными правами. Для согласованности остановите источники записи; внешние интеграции также не должны писать в БД. Это окно недоступности.

~~~sh
cd /opt/amfot
docker compose exec --user www-data app php artisan down
docker compose stop nginx worker scheduler app
umask 077
backup="/srv/amfot-private/backups/$(date -u +%Y%m%dT%H%M%SZ)"
mkdir -p "$backup"
docker compose exec -T db sh -c 'export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; exec mysqldump --user=root --single-transaction --routines --events --triggers --no-tablespaces amfot' > "$backup/database.sql"
test -s "$backup/database.sql"
tar -C /opt/amfot -czf "$backup/application.tar.gz" app
cp /opt/amfot/.env "$backup/compose.env"
git rev-parse HEAD > "$backup/infrastructure-commit.txt"
docker compose images --format json > "$backup/images.json"
(cd "$backup" && sha256sum database.sql application.tar.gz compose.env images.json > SHA256SUMS)
~~~

Каждую команду выполняйте только после успеха предыдущей. При автоматизации используйте set -eu; неполный SQL при ошибке mysqldump не является backup. Пароль не попадает в argv mysqldump, но окружение доступно администратору хоста.

Если команды успешны:

~~~sh
docker compose run --rm --no-deps --user www-data app php artisan up
docker compose up -d app worker scheduler nginx
sh deploy/check.sh
~~~

Копия app содержит maintenance marker — после восстановления понадобится artisan up. Проверьте читаемость архива и SQL, перенесите зашифрованную копию на отдельное хранилище. Одних checksum недостаточно: регулярно выполняйте тестовое восстановление.

## Восстановить

**Сначала на отдельном тестовом сервере/изолированном экземпляре.** Отключите исходящую почту и внешние интеграции в восстановленной локальной конфигурации до запуска worker/scheduler. Проверяйте целевой сервер и путь; восстановление поверх текущей БД заменяет данные.

1. Сохраните текущее состояние отдельно. Проверьте контрольные суммы backup и версии MySQL/PHP.
2. Подготовьте отдельный infrastructure checkout и **новый пустой MySQL volume**, не удаляя старый. Compose имеет фиксированное name: amfot: для параллельного теста используйте docker compose -p amfot-restore-test и отдельный HTTP_PORT/HTTP_BIND. Все последующие compose-команды этого теста выполняйте с тем же -p.
3. Восстановите app и оба локальных env из согласованной копии в выбранный каталог. Не переносите их в GitHub. Сохраните APP_KEY. Укажите проверенный runtime и настройки нового DB-экземпляра.
4. Запустите только db, дождитесь healthy. В новый пустой экземпляр импортируйте SQL через mysql по процедуре установки. Не импортируйте дамп повторно в непустую рабочую БД.
5. Запустите init. Пересоздайте Laravel config:clear/config:cache как www-data. Выполните check-runtime.php, migrate:status, artisan up, затем запустите сервисы.
6. Проверьте данные, вход, SCORM, загрузки, PDF и очередь. HTTPS check.sh направлен на канонический домен: в изолированном тесте проверяйте именно его локальный порт/тестовый домен отдельно, не принимайте ответ рабочего сайта за успех восстановления.
7. Production переключайте только после приёмки. Старые данные храните до подтверждения; не используйте down -v для устранения ошибок импорта.
