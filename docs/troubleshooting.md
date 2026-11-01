# Mini howto: диагностика

## SSH и DNS из Windows

~~~powershell
nslookup lms.corp.mavsim.com
nslookup lms.corp.mavsim.com 192.168.2.20
Resolve-DnsName lms.corp.mavsim.com
Test-NetConnection lms.corp.mavsim.com -Port 22
ssh -o BatchMode=yes amfot-lms "hostname && uptime && docker ps"
~~~

192.168.2.20 — DNS и шлюз интерфейса, проверенного в этом чате; в другой сети адрес может отличаться. BatchMode запрещает интерактивный запрос пароля. При ошибке ключа проверяйте агент и доступ оператора; приватные ключи не выводить. Не отключайте проверку host key.

Если в обычном Windows-терминале всё работает, а в терминале Codex нет DNS/сети или Resolve-DnsName отвечает «Отказано в доступе», сначала проверьте ограничения песочницы. В этом чате исходный SSH заработал после одобрения запуска конкретной команды вне песочницы. Не меняйте системный DNS, hosts и VPN ради обхода ограничений; запросите разрешение на нужную диагностическую команду.

## Compose не разбирает YAML

~~~sh
docker compose config --quiet
~~~

Проверьте отступы volumes у init и nginx. В прежнем репозитории два mount были ошибочно вложены в строку предыдущего mount; исправление включено в эту версию. Не публикуйте обычный вывод compose config: он подставляет пароли.

## App unhealthy или worker перезапускается

~~~sh
docker compose ps -a
docker compose exec -T --user www-data app php /opt/amfot/check-runtime.php
~~~

Если app не запущен, используйте docker compose run --rm --no-deps --user www-data app php /opt/amfot/check-runtime.php (БД должна работать).

- Debug/secure cookie: исправьте локальный app/.env, пересоберите config cache и пересоздайте сервисы по operations.md.
- Pcntl: нужен новый опубликованный runtime из текущего Dockerfile; pull прежнего тега не добавит расширение.
- Retry_after: проверяется эффективная конфигурация, не просто строка QUEUE_RETRY_AFTER. Timeout обязан быть меньше retry_after, включая job overrides.
- Права: проверьте UID 33 и каталоги storage/bootstrap/cache. Init не исправляет рекурсивно все старые uploads.
- DB connection: проверьте фактический пароль MySQL, DB_HOST=db, совпадение двух env и свежесть Laravel config cache. Смена MYSQL_PASSWORD не меняет пароль в уже созданном volume.

Worker с --max-time=3600 планово завершает цикл примерно раз в час и запускается снова через unless-stopped. Это ожидаемо, но частые перезапуски требуют проверки. Init Exited (0) — штатный одноразовый сервис.

## 502, HTTPS и cookies

Проверьте Nginx syntax через docker compose exec nginx nginx -t. Если app недавно пересоздан, перезапустите nginx для повторного разрешения app:9000. Проверьте proxy → приватный IP:8080, Host и X-Forwarded-Proto. Прямой запрос на 127.0.0.1:8080 может перенаправлять на HTTPS с этим адресом; для проверки домена передайте Host: lms.idcg.tj. На 8080 TLS не настроен.

~~~sh
curl -sS -o /dev/null -w '%{http_code}\n' -H 'Host: lms.idcg.tj' http://127.0.0.1:8080/login
curl -sS -o /dev/null -w '%{http_code}\n' https://lms.idcg.tj/login
~~~

Внешний HTTP login должен иметь корректный путь перехода на HTTPS. Если proxy на другом хосте, localhost bind не подойдёт. Не открывайте 8080 всем ради исправления 502.

## Очередь и scheduler

Probe выводит counts jobs/failed_jobs без payload. Убедитесь, что worker запущен на нужной очереди: здесь database/default. Новые очереди из отдельно поставленного приложения нужно явно согласовать с командой worker.

В этом чате все 8 failed jobs были от 2 августа 2023, не от текущего запуска. Не выполняйте queue:retry all или queue:flush без разбора: повтор может повторно отправить письма и изменить данные.

Scheduler пишет в app/storage/logs/scheduler.log, а не обязательно в docker logs. Проверяйте актуальное время записей локально, не публикуйте сырой лог. Нет вывода — ещё не доказательство сбоя: в конкретную минуту может не быть задач.

Настройте ротацию Docker json-логов и scheduler.log по политике сервера: Docker logging ограничен этой конфигурацией, scheduler.log требует отдельной host logrotate-политики. Не удаляйте активные логи во время диагностики.
