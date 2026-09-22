# Выкладка на reg.ru (sibiradm.ru)

Что выкладываем: админку Redbox CRM (`/admin`) и API для сайта (`/api/v1`) — это один проект.
Хостинг: виртуальный хостинг reg.ru (ISPmanager), обновления — через `git pull` по SSH.

Секреты в репозиторий не попадают: они живут в `.env.prod.local` на сервере (файл в `.gitignore`).

---

## 1. Требования к хостингу

Проверьте до выкладки, в панели reg.ru:

| Что | Значение |
|---|---|
| PHP | **8.4 или новее**, и для сайта, и для CLI (`php -v` по SSH) |
| Расширения PHP | `intl`, `mbstring`, `iconv`, `ctype`, `pdo_mysql`, `dom`, `xml`, `xmlreader`, `zip`, `gd`, `opcache` |
| База данных | MySQL 8 или MariaDB 10.6+ |
| Доступ | SSH включён, есть `git` и `composer` |
| Cron | есть (нужен один раз в 15 минут) |
| SSL | сертификат Let's Encrypt для `sibiradm.ru` и `www.sibiradm.ru` |

`intl` нужен обязательно: из него берутся русские названия месяцев. `zip` и `xmlreader` — для импорта
адресной программы из xlsx, `dom` и `gd` — для PDF медиапланов.

Node.js на виртуальном хостинге обычно нет. Это не проблема: собранный фронтенд (`public/build`)
заливается с вашей машины, см. шаг 4.6.

---

## 2. Подготовка в панели reg.ru

1. Направьте домен `sibiradm.ru` на хостинг (A-запись или NS reg.ru), дождитесь делегирования.
2. Создайте сайт `sibiradm.ru`. **Корневой каталог сайта укажите `www/sibiradm.ru/public`** — это
   правильный вариант: наружу видно только `public/`.
   Если панель не даёт изменить корень, оставьте `www/sibiradm.ru`: в репозитории есть корневой
   [`.htaccess`](../.htaccess), он сам направляет запросы в `public/` и закрывает остальное.
3. Выпустите SSL-сертификат и включите его для домена и для `www`.
4. Создайте базу данных и пользователя, запишите логин, пароль и имя базы.
5. Выберите для сайта PHP 8.4.
6. Включите SSH-доступ.

---

## 3. Ключ Яндекс Карт

В [кабинете разработчика](https://developer.tech.yandex.ru/services) у ключа «JavaScript API и HTTP
Геокодер» добавьте в ограничения домен `sibiradm.ru`. Ключ виден в коде страницы, ограничение по
домену — единственная защита от чужого использования.

---

## 4. Первая выкладка

### 4.1. Код

```bash
ssh ВАШ_ЛОГИН@ВАШ_СЕРВЕР.hosting.reg.ru
cd ~/www
git clone АДРЕС_РЕПОЗИТОРИЯ sibiradm.ru
cd sibiradm.ru
```

Если репозиторий приватный, добавьте деплой-ключ: `ssh-keygen -t ed25519 -C sibiradm.ru`, затем
содержимое `~/.ssh/id_ed25519.pub` — в настройки репозитория как Deploy key (только чтение).

### 4.2. Секреты

Файлы `.env` и `.env.prod` лежат в репозитории, их на сервере **менять не нужно**: при следующем
`git pull` правки будут мешать обновлению. Всё своё — в два файла, которых нет в git.

Порядок чтения, каждый следующий перебивает предыдущий:
`.env` → `.env.local` → `.env.prod` → `.env.prod.local`

Сначала переключаем сервер в боевое окружение (иначе сайт работает в режиме разработки и показывает
посетителям отладочную панель):

```bash
echo 'APP_ENV=prod' > .env.local
```

Затем секреты:

```bash
cat > .env.prod.local <<'EOF'
APP_SECRET=ЗАМЕНИТЕ
DATABASE_URL="mysql://ПОЛЬЗОВАТЕЛЬ:ПАРОЛЬ@localhost:3306/БАЗА?serverVersion=8.0&charset=utf8mb4"
JWT_PASSPHRASE=ЗАМЕНИТЕ
MAILER_DSN=smtp://noreply%40sibiradm.ru:ПАРОЛЬ@ВАШ_SMTP:465?encryption=ssl
YANDEX_MAPS_API_KEY=КЛЮЧ_ЯНДЕКС_КАРТ
EOF
chmod 600 .env.prod.local
```

- `APP_SECRET` и `JWT_PASSPHRASE` сгенерируйте: `php -r 'echo bin2hex(random_bytes(32)), "\n";'`.
  Значения из `.env` — только для разработки, в прод их переносить нельзя.
- `serverVersion` укажите свой: для MariaDB, например, `10.11.2-MariaDB`.
- `YANDEX_MAPS_API_KEY` — тот же ключ, что в вашем локальном `.env.local`.
- Пока сайта на Next.js нет, `WEBSITE_URL` и `CORS_ALLOW_ORIGIN` из `.env.prod` менять не нужно.
  Когда домен сайта появится, добавьте его в `CORS_ALLOW_ORIGIN` (это регулярное выражение) и
  поставьте в `WEBSITE_URL` — по нему строятся ссылки в письмах о восстановлении пароля.

### 4.3. Зависимости

```bash
composer install --no-dev --optimize-autoloader --no-interaction
```

### 4.4. Ключи JWT

Токены личного кабинета подписываются парой ключей; в репозитории их нет.

```bash
php bin/console lexik:jwt:generate-keypair --env=prod
```

### 4.5. База

```bash
php bin/console doctrine:migrations:migrate --no-interaction --env=prod
php bin/console app:user:create --env=prod     # администратор CRM
```

Если нужно перенести данные с рабочей машины:
`mysqldump -u root -p redbox > dump.sql`, залить файл и `mysql -u ПОЛЬЗОВАТЕЛЬ -p БАЗА < dump.sql`.
Вместе с базой перенесите `public/uploads` (фото конструкций) и `var/storage` (документы клиентов).

### 4.6. Фронтенд

Если на сервере есть npm — `npm ci && npm run build`. Если нет, соберите у себя и залейте:

```bash
npm run build
rsync -avz --delete public/build/ ВАШ_ЛОГИН@ВАШ_СЕРВЕР:~/www/sibiradm.ru/public/build/
```

`public/build` в `.gitignore`, поэтому `git pull` его не перезапишет.

### 4.7. Кеш и права

```bash
php bin/console cache:clear --env=prod
php bin/console cache:warmup --env=prod
chmod -R ug+rwX var public/uploads
```

### 4.8. Cron

Раз в 15 минут снимаются брони, которые не оплатили за 24 часа:

```
*/15 * * * * cd ~/www/sibiradm.ru && php bin/console app:booking:expire --env=prod --no-interaction >> var/log/cron.log 2>&1
```

Если `php` в cron — не та версия, укажите полный путь (в панели он написан рядом с выбором версии PHP).

---

## 5. Проверка после выкладки

- `http://sibiradm.ru` и `http://www.sibiradm.ru` переводят на `https://sibiradm.ru`.
- Открывается `https://sibiradm.ru/admin`, вход работает.
- `https://sibiradm.ru/api/v1/structures` отдаёт JSON.
- `https://sibiradm.ru/.env` и `https://sibiradm.ru/var/log/prod.log` отдают 403 или 404.
- На странице «Карта» видны конструкции — значит, ключ Яндекса принят.
- В карточке клиента открывается документ — значит, доступ к приватному хранилищу работает.
- В `var/log/prod.log` нет ошибок.
- `php bin/console about` показывает `Environment: prod` и `Debug: false` — значит, `.env.local` подхватился.

---

## 6. Обновления

```bash
cd ~/www/sibiradm.ru && bash bin/deploy.sh
```

Скрипт [`bin/deploy.sh`](../bin/deploy.sh) делает `git pull`, ставит зависимости, собирает фронтенд
(если есть npm), применяет миграции, чистит и прогревает кеш, поправляет права.
Если фронтенд собирается локально, после скрипта повторите `rsync` из шага 4.6.

---

## 7. Резервные копии

Хранить нужно три вещи:

```bash
mysqldump -u ПОЛЬЗОВАТЕЛЬ -p БАЗА | gzip > ~/backups/db-$(date +%F).sql.gz
tar czf ~/backups/files-$(date +%F).tar.gz public/uploads var/storage
```

`public/uploads` — фотографии конструкций и категорий, `var/storage` — договоры, счета и фотоотчёты
клиентов. В базе их нет.

---

## 8. Если что-то не работает

| Симптом | Что смотреть |
|---|---|
| Белый экран или 500 | `tail -50 var/log/prod.log`, затем `var/log/` панели хостинга |
| «Invalid Host» | Домен не совпал с `APP_TRUSTED_HOSTS` в `.env.prod` |
| Стили и скрипты не грузятся | Не залит `public/build` (шаг 4.6) |
| «The "intl" extension is required» | В панели не включено расширение `intl` |
| Не приходят письма | Проверьте `MAILER_DSN`; у reg.ru бывает закрыт 25-й порт, используйте 465 с SSL |
| Внизу страницы видна панель отладки, в ответах есть трассировки | Сервер работает в dev: проверьте, что в `.env.local` написано `APP_ENV=prod`, затем `cache:clear --env=prod` |
| Изменения не видны | `php bin/console cache:clear --env=prod`, при включённом opcache — перезапуск PHP в панели |
| Права на запись | `chmod -R ug+rwX var public/uploads` |
