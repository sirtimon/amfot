# Mini howto: пароли

Эти процедуры изменяют данные и выполняются оператором на сервере. Во время диагностики они не запускаются. Нужен backup, защищённый терминал без записи сеанса и парольный менеджер. Не публикуйте пароли, хеши, .env или SQL в GitHub.

## Сбросить пароль существующего администратора

~~~sh
cd /opt/amfot
docker compose exec --user www-data app php /opt/amfot/admin-password.php
~~~

Нужен интерактивный TTY: **не добавляйте -T**. Helper спрашивает email, дважды скрыто запрашивает пароль и требует подтверждения. Пароль не передаётся в аргументах или environment. Минимум 12 символов, максимум 72 байта.

Helper использует модель auth.providers.users.model и штатный Laravel Hash, принимает только существующего Voyager пользователя с ролью admin. Не создаёт аккаунт и не повышает права. При нестандартной модели/роли остановитесь и уточните схему у поставщика; не выполняйте UPDATE users SET password='...' вручную.

После сброса заменяется remember-token. **Уже активные сессии и API-токены не отзываются**: при компрометации нужна отдельная адресная процедура отзыва по session/token driver приложения. Глобальная очистка file sessions разлогинит всех пользователей, её не делать автоматически. Не меняйте APP_KEY ради сброса пароля.

## Изменить пароль пользователя БД amfot

Переменная MYSQL_PASSWORD и app/.env сами по себе **не меняют пароль существующего MySQL-аккаунта**. MYSQL_* используются для начального создания пустого volume. Используем временно два пароля MySQL 8.4, чтобы не потерять доступ при переключении.

1. Сделайте backup. Остановите приложение и исполнителей:

~~~sh
docker compose exec --user www-data app php artisan down
docker compose stop nginx worker scheduler app
docker compose exec -e MYSQL_HISTFILE=/dev/null db mysql --user=root --password
~~~

Root-пароль вводится скрыто на приглашении mysql; не добавляйте его после --password. В SQL-сессии проверьте имя и host аккаунта (не запрашивайте authentication_string):

~~~sql
SELECT User, Host FROM mysql.user WHERE User = 'amfot';
~~~

Ниже пример для host %. Подставьте фактический host. Сгенерируйте новый пароль в менеджере; для простого безопасного ввода SQL/dotenv можно использовать случайные 40+ hex-символов. Значение вводится только локально в SQL-клиенте, не в shell history:

~~~sql
ALTER USER 'amfot'@'%' IDENTIFIED BY 'REPLACE_WITH_NEW_RANDOM_PASSWORD' RETAIN CURRENT PASSWORD;
EXIT;
~~~

2. Редактором замените MYSQL_PASSWORD в /opt/amfot/.env и DB_PASSWORD в /opt/amfot/app/.env на **одно и то же новое значение**. Сохраните режимы доступа. Старый пароль пока продолжает работать.

3. Удалите старый кеш, создайте новый и проверьте новое подключение:

~~~sh
docker compose run --rm --no-deps --user www-data app php artisan config:clear
docker compose run --rm --no-deps --user www-data app php artisan config:cache
docker compose run --rm --no-deps --user www-data app php /opt/amfot/check-runtime.php
docker compose run --rm --no-deps --user www-data app php artisan up
docker compose up -d --force-recreate app worker scheduler nginx
sh deploy/check.sh
~~~

После перехода с env_file старые PHP-контейнеры обязательно пересоздаются. Probe после пересборки кеша создаёт новое DB-подключение; до удаления старого пароля убедитесь, что больше нет потребителей со старым секретом.

4. Завершите ротацию:

~~~sh
docker compose exec -e MYSQL_HISTFILE=/dev/null db mysql --user=root --password
~~~

~~~sql
ALTER USER 'amfot'@'%' DISCARD OLD PASSWORD;
EXIT;
~~~

5. Повторите check.sh после удаления старого пароля. При проблеме до DISCARD верните старые значения в два локальных .env, пересоберите кеш и пересоздайте PHP-сервисы. После DISCARD нужен новый ALTER USER через root, простое редактирование файлов доступ не вернёт.

Окружение уже работающего db-контейнера может пока содержать старый MYSQL_PASSWORD; MySQL его повторно не применит. Не пытайтесь менять пароль удалением volume. При следующем плановом пересоздании db окружение обновится; действующая учётная запись останется в volume.

## Изменить root-пароль БД

Это отдельная процедура. Приложение использует amfot, не root. Сначала проверьте доступ root и фактические Host для него. Через mysql --user=root --password с MYSQL_HISTFILE=/dev/null измените пароль нужного root-аккаунта посредством ALTER USER, затем обновите MYSQL_ROOT_PASSWORD в корневом .env.

Healthcheck и backup используют MYSQL_ROOT_PASSWORD из окружения db-контейнера. После изменения нужно в окно обслуживания остановить app/worker/scheduler/nginx, выполнить docker compose up -d --no-deps --force-recreate db, дождаться healthy, затем up -d app worker scheduler nginx и check.sh. Это не пересоздаёт volume и не меняет данные. До завершения храните открытый административный сеанс, не удаляйте volume при ошибке доступа.

Справка: [MySQL 8.4: dual passwords](https://dev.mysql.com/doc/refman/8.4/en/password-management.html#dual-password-support).
