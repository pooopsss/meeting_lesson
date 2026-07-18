# LESSON_2 — Full-Stack Meeting App

Full-stack SPA для управления встречами с загрузкой файлов. Бэкенд на Lumen (PHP 8.3 + PostgreSQL), фронтенд на Vue 3 + PrimeVue.

Стек: Lumen 10 · PHP 8.3 · PostgreSQL 16 · Vue 3.5 · PrimeVue 4.3 · Vite 6 · Docker Compose.

## Запуск

```bash
docker compose up -d
```

| Сервис | URL | Описание |
|---|---|---|
| Frontend (Vite dev) | http://localhost:5174 | Vue SPA |
| Backend API (через Nginx) | http://localhost:8081/api | PHP-FPM + Lumen |
| PostgreSQL | `localhost:5432` | `lumen_user:secret_password@lumen_db` |

```bash
docker compose down             # остановить
docker compose logs -f backend  # логи бэкенда
```

## Профиль и аватарка

В правом верхнем углу — `UserMenu` с аватаром (PrimeVue `Avatar`): PrimeVue `Avatar` показывает изображение, если оно загружено, либо **инициалы** на детерминированном цвете (вычисляется по `user_id` на бэкенде, фронт ничего не угадывает). Клик открывает меню с двумя пунктами:

- **«Профиль»** — открывает `ProfileView` (PrimeVue `Dialog` с тремя секциями: «Основное», «Аватарка», «Безопасность»). Каждая секция сохраняется отдельно через свой API с тостом об успехе/ошибке.
- **«Выйти»** — `ConfirmDialog` с подтверждением; при «Да» — `POST /api/logout`, очистка токена в `localStorage`, редирект на форму логина. При 401 от API (токен уже истёк) — локальное состояние всё равно чистится, без всплывающей ошибки.

### Аватарка

**Загрузка:** «Профиль» → вкладка «Аватарка» → PrimeVue `FileUpload` (`mode: basic`, `auto: true`, `customUpload: true`). Клиентская валидация до отправки:

- MIME ∈ `{image/jpeg, image/png, image/webp}` (определяется через сигнатуру, не по расширению)
- Размер ≤ 2 МБ

Допустимые форматы — JPEG, PNG, WebP. Лимит — 2 МБ.

После успешной загрузки сервер:

1. Проверяет MIME через `finfo` (поверхностная проверка по `Content-Type` не пройдёт).
2. Проверяет размер ≤ 2 МБ.
3. **Ресайзит** изображение в 400×400 (фит по большей стороне, пропорции сохраняются).
4. Удаляет предыдущую аватарку с диска (если была).
5. Сохраняет как `storage/app/avatars/{user_id}.{ext}`.
6. Пишет `Log::info` (`user_id`, `avatar_path`, `status=ok`) или `Log::warning` (`status=error`) при ошибке.

**Удаление:** кнопка «Удалить» → `ConfirmDialog` → `DELETE /api/me/avatar` → файл удаляется с диска, `avatar_path` обнуляется. Идемпотентно: повторный DELETE при уже удалённой аватарке возвращает 200, не 404.

**Отдача:** `GET /api/me/avatar` под `auth` отдаёт `BinaryFileResponse` с `Content-Type: image/{jpeg|png|webp}` и `Content-Disposition: inline`. Nginx `location /storage/` остаётся `deny all` — прямой доступ к файлу невозможен, отдача только через PHP (с проверкой прав).

## Как прикрепить файл

В деталях встречи нажмите **«Загрузить файл»** → откроется диалог с PrimeVue `FileUpload` и полем подписи.

1. Выберите файл — диалог покажет текущий лимит по типу (`Лимит: 20 МБ` для документов/изображений/архивов, `200 МБ` для аудио/видео).
2. Опционально введите подпись (≤ 255 символов).
3. Нажмите **«Загрузить»** — прогрессбар отображается, диалог блокируется до завершения.
4. После успеха — тост «Файл загружен», файл появляется в списке.
5. Кнопка **«Скачать»** в строке файла скачивает через `Authorization: Bearer <token>` с правильным `original_name` из `Content-Disposition`.
6. **Аудио/видео** — кнопка «Превью» показывает нативный `<audio>`/`<video>` под строкой (blob URL, не токен в query).
7. **Удаление** — кнопка «Удалить» видна только загрузившему; требует подтверждения в `ConfirmDialog`.

### Лимиты по категориям

| Категория | MIME | Лимит |
|---|---|---|
| document | `application/pdf`, Word, Excel | 20 МБ |
| image | `image/jpeg`, `png`, `gif`, `webp`, `svg+xml` | 20 МБ |
| text | `text/plain`, `csv`, `markdown` | 20 МБ |
| archive | `application/zip` | 20 МБ |
| audio | `audio/mpeg`, `mp4`, `wav`, `ogg`, `webm`, `aac`, `flac`, `x-m4a` | 200 МБ |
| video | `video/mp4`, `webm`, `ogg`, `quicktime`, `x-matroska`, `x-msvideo` | 200 МБ |

Полный allow-list — в [`backend/config/files.php`](backend/config/files.php). Клиент и сервер используют одну и ту же таблицу: [`frontend/src/api/mimeLimits.js`](frontend/src/api/mimeLimits.js) для UI-валидации, [`backend/app/Services/FileValidationService.php`](backend/app/Services/FileValidationService.php) для серверной проверки (через `finfo`).

### Где хранятся файлы

Файлы сохраняются на диск в `backend/storage/app/meetings/{meetingId}/` под именем `UUID.ext`. Доступ через авторизованный эндпоинт `GET /api/meetings/{id}/files/{fileId}`.

⚠️ **Локальное хранение, не персистентное.** В `docker-compose.yml` бэкенд и nginx примонтированы через `./backend:/var/www` (bind-mount), но PostgreSQL использует volume `postgres_data`. Файлы **не** дублируются в именованный volume — при пересоздании контейнера бэкенда `storage/` сохранится (bind-mount), но при `docker volume rm` или полном удалении `./backend` — будут потеряны. Для production замените `local` диск на S3/MinIO через [`league/flysystem-aws-s3-v3`](https://flysystem.thephpleague.com/) (см. `docs/research-meeting-file-upload.md`).

⚠️ **Nginx запрещает прямой доступ.** `GET http://localhost:8081/storage/meetings/1/x.pdf` возвращает **403**. Файлы отдаются только через авторизованный API.

⚠️ **Прямой путь к файлу на диске нельзя подделать.** В `MeetingFileController::download` стоит path-traversal guard (`realpath` + проверка префикса + запрет `..` и `/` в `stored_name`).

## API

| Метод | Path | Auth | Описание |
|---|---|:---:|---|
| POST | `/api/register` | — | email + password (+ confirmation) → token + профиль |
| POST | `/api/login` | — | email + password → token + профиль |
| GET | `/api/me` | ✓ | профиль текущего пользователя (id, name, email, phone, avatar_url, initials, color) |
| PATCH | `/api/me` | ✓ | редактировать `name` (обязательно, ≤ 255) и/или `phone` (опц., ≤ 20, `0-9 +()\-`) |
| POST | `/api/me/avatar` | ✓ | загрузить аватарку (multipart `avatar`, JPEG/PNG/WebP, ≤ 2 МБ, ресайз 400×400) |
| GET | `/api/me/avatar` | ✓ | отдача аватарки (`Content-Type: image/*`, `Content-Disposition: inline`) |
| DELETE | `/api/me/avatar` | ✓ | удалить аватарку (идемпотентно, 200) |
| POST | `/api/me/password` | ✓ | сменить пароль (`current_password` + `new_password` + `new_password_confirmation`; `new_password` ≥ 8) |
| POST | `/api/logout` | ✓ | инвалидировать текущий токен (204, запись в `user_sessions` удалена) |
| POST | `/api/meetings` | ✓ | создать встречу (title + scheduled_at) |
| GET | `/api/meetings` | ✓ | список встреч текущего пользователя |
| GET | `/api/meetings/{id}` | ✓ | одна встреча (404 если не владелец) |
| POST | `/api/meetings/{id}/files` | ✓ | загрузить файл (multipart `file` + опц. `label`) |
| GET | `/api/meetings/{id}/files` | ✓ | список файлов встречи (DESC по created_at) |
| GET | `/api/meetings/{id}/files/{fileId}` | ✓ | скачать (Content-Disposition, original_name) |
| DELETE | `/api/meetings/{id}/files/{fileId}` | ✓ | удалить (только загрузившему) |

### Примеры ответов профиля

```json
// 200 — GET /api/me
{
  "id": 1,
  "name": "Иван Петров",
  "email": "ivan@example.com",
  "phone": "+7 999 123-45-67",
  "avatar_url": "/api/me/avatar",
  "initials": "ИП",
  "color": "#3FA6C9"
}

// 200 — после загрузки аватарки
{
  "id": 1, "name": "...", "email": "...",
  "avatar_path": "avatars/1.jpg",
  "avatar_url": "/api/me/avatar",
  "initials": "...", "color": "..."
}

// 204 — POST /api/logout (пустое тело)
```

`initials` — `mb_substr` первых букв первых двух слов `name`, в верхнем регистре. `color` — детерминированный HSL-конверт в hex, стабильный между сессиями и перезагрузками (вычисляется на бэкенде от `user_id`).

Все защищённые эндпоинты требуют `Authorization: Bearer <token>`. На 401 клиент автоматически сбрасывает сессию и редиректит на логин.

Все пользовательские сообщения и валидация — на русском. Примеры ответов:

```json
// 401 — нет/неверный токен
{ "message": "Требуется авторизация" }

// 401 — неверный логин/пароль
{ "message": "Неверный email или пароль" }

// 404 — встреча/файл/аватарка не найдены
{ "message": "Встреча не найдена" }
{ "message": "Файл не найден" }
{ "message": "Аватарка не найдена" }

// 403 — попытка удалить чужой файл
{ "message": "Доступ запрещён" }

// 422 — смена пароля: неверный текущий
{ "errors": { "current_password": ["Неверный текущий пароль"] } }

// 422 — аватарка: недопустимый формат / превышен размер
{ "errors": { "avatar": ["Недопустимый формат изображения. Разрешены JPG, PNG, WebP."] } }
{ "errors": { "avatar": ["Файл слишком большой. Максимальный размер — 2 МБ."] } }

// 200 — смена пароля
{ "message": "Пароль изменён" }

// 422 — ошибки валидации (русские ключи + :attribute → человекочитаемое имя)
{ "errors": {
  "email": ["Поле Email должно быть действительным электронным адресом."],
  "password": ["Поле пароль обязательно для заполнения."]
} }
```

Словари переводов: `backend/resources/lang/ru/validation.php` (правила + секция `attributes`). Локаль задаётся через `config/app.php` (`locale = ru`). Логи и тесты — на английском.

## Тесты

### Backend

```bash
docker compose exec backend composer install --dev
docker compose exec backend ./vendor/bin/phpunit
```

Покрытие (129 тестов, 416 ассертов):
- `tests/Feature/RegisterTest.php` — регистрация, токен, сессия, валидации (9)
- `tests/Feature/LoginTest.php` — вход, токен, сессия, валидации (7)
- `tests/Feature/LogoutTest.php` — инвалидация токена, 401 без токена, изоляция (4)
- `tests/Feature/MeetingsTest.php` — создание, список, 404, изоляция (12)
- `tests/Feature/MeetingFilesTest.php` — upload, download, list, delete, MIME/size, sanitization, path-traversal, rollback (24)
- `tests/Feature/FileValidationTest.php` — 8 кейсов валидации из Phase 3 (8)
- `tests/Feature/LoggingAndInfraTest.php` — логирование, Nginx, restart-volume (6)
- `tests/Feature/MeTest.php` — GET /api/me, профиль, initials/color, hidden password (5)
- `tests/Feature/MeUpdateTest.php` — PATCH /api/me, валидации, email игнорируется (10)
- `tests/Feature/AvatarTest.php` — JPEG/PNG/WebP, MIME через finfo, лимит 2 МБ, ресайз 400×400, замена файла (9)
- `tests/Feature/AvatarDeleteTest.php` — DELETE, идемпотентность, изоляция пользователей (5)
- `tests/Feature/ShowAvatarTest.php` — GET /api/me/avatar, Content-Type, inline, 401/404 (7)
- `tests/Feature/ChangePasswordTest.php` — успех, неверный current, валидации, изоляция сессий (8)
- `tests/Feature/UserModelTest.php` — fillable, appends, initials, color (детерминизм) (12)
- `tests/Feature/UserProfileFieldsMigrationTest.php` — колонки phone/avatar_path (2)

После прогона `DatabaseMigrations` сбрасывает схему — восстановите live-БД:

```bash
docker compose exec backend php artisan migrate --force
```

### Frontend

```bash
docker compose exec frontend npm run build      # production build
docker compose exec frontend npm run format:check  # Prettier
```

Smoke-чеклисты фаз 6–8 (список, диалог загрузки, удаление, плееры, тосты) проверяются вручную через браузер или Playwright MCP — desktop (≥1280px) и mobile (≤414px).

## Архитектура

```
                  ┌─────────────┐
                  │   Browser   │
                  └──────┬──────┘
           :5174         │         :8081
    ┌─────────┴─────┐   │   ┌─────┴─────┐
    │ Frontend (Vite)│   │   │   Nginx   │
    │   dev server   │   │   │  proxy    │
    └───────────────┘   │   └─────┬─────┘
           API calls ───┘         │ :9000
                          ┌───────┴───────┐
                          │ PHP-FPM (Lumen)│
                          └───────┬───────┘
                                  │ :5432
                          ┌───────┴───────┐
                          │  PostgreSQL   │
                          └───────────────┘
```

Подробности — в [`CLAUDE.md`](CLAUDE.md) и [`docs/`](docs/).
