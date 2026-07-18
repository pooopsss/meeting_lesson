# LESSON_2 — Full-Stack Project

## Tech Stack

| Layer | Technology | Version |
|-------|-----------|---------|
| Frontend | Vue 3 (Composition API, `<script setup>`) | ^3.5.13 |
| UI Library | PrimeVue + Aura theme | ^4.3.3 |
| Build | Vite | ^6.2.0 |
| Backend | Lumen (Laravel micro-framework) | ^10.0 |
| Language | PHP | 8.3 (Docker) |
| Database | PostgreSQL | 16-alpine |
| ORM | Eloquent | |
| Web Server | Nginx (alpine) | latest |
| Containerization | Docker Compose | 4 services |

## Architecture

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

All services share a Docker bridge network `app_network`.

## Project Structure

```
/
├── backend/               # PHP Lumen API
│   ├── app/
│   │   ├── Http/Controllers/  # API controllers (Auth, User, Meeting, MeetingFile)
│   │   ├── Http/Middleware/   # Authenticate, CorsMiddleware
│   │   ├── Http/Requests/     # StoreMeetingFileRequest, ChangePasswordRequest
│   │   ├── Models/            # Eloquent models (User, UserSession, Meeting, MeetingFile)
│   │   ├── Services/          # FileValidator, FileValidationService (finfo), AvatarService
│   │   └── Exceptions/        # InvalidAvatarMimeException, AvatarTooLargeException
│   ├── routes/web.php         # API route definitions
│   ├── bootstrap/app.php      # Lumen app bootstrap (registers Translator + loaders for resources/lang)
│   ├── config/app.php         # app.locale = ru, fallback_locale = ru
│   ├── config/database.php    # DB connection config
│   ├── config/files.php       # MIME allow-list + size limits per category
│   ├── resources/lang/ru/     # Russian validation messages + human-readable attribute names
│   ├── database/migrations/   # users, user_sessions, meetings, meeting_files, add_phone_and_avatar, add_name
│   ├── storage/app/avatars/   # User avatars: {user_id}.{ext} (jpg/png/webp, ≤ 2 МБ, ресайз 400×400)
│   ├── storage/app/meetings/  # Meeting files: {meeting_id}/UUID.ext
│   ├── docker-entrypoint.sh   # Container startup (chowns storage/ to www-data)
│   ├── .dockerignore          # Excludes storage/, vendor/, etc. from build context
│   └── composer.json
├── frontend/              # Vue 3 + Vite SPA
│   ├── src/
│   │   ├── App.vue            # Root component (AuthForm ↔ Dashboard with UserMenu, ProfileView, Toast/ConfirmDialog)
│   │   ├── main.js            # App entry, PrimeVue + ToastService + ConfirmationService
│   │   ├── style.css          # Global CSS
│   │   ├── api/               # auth.js, meetings.js, files.js, mimeLimits.js, me.js
│   │   ├── store/auth.js      # Reactive session (token + user in localStorage; loadProfile, logout)
│   │   └── components/        # AuthForm, MeetingsList, MeetingFileList, UploadFileDialog, UserMenu, ProfileView
│   ├── vite.config.js
│   └── package.json
├── nginx/
│   └── default.conf        # Nginx reverse-proxy config (deny /storage/, client_max_body_size 220M)
├── docker-compose.yml      # All services
├── .env                    # Environment variables
├── .env.example            # Env template
└── README.md               # Onboarding, API reference, file-upload docs
```

## Services & Ports

| Service | Internal Port | External Port | URL |
|---------|:---:|:---:|---|
| Frontend (Vite dev) | 5173 | 5174 | `http://localhost:5174` |
| Backend API (via Nginx) | 80 | 8081 | `http://localhost:8081/api` |
| PostgreSQL | 5432 | 5432 | `localhost:5432` |

## Environment Variables (.env)

```
POSTGRES_DB=lumen_db
POSTGRES_USER=lumen_user
POSTGRES_PASSWORD=secret_password
APP_ENV=local
APP_DEBUG=true
APP_KEY=base64:...
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=lumen_db
DB_USERNAME=lumen_user
DB_PASSWORD=secret_password
VITE_API_URL=http://localhost:8081/api
```

## Running the Project

```bash
docker compose up -d          # Start all services
docker compose down           # Stop all services
docker compose logs -f        # Follow logs
docker compose restart backend # Restart a specific service
```

Frontend hot-reload is enabled via volume mount (`./frontend/src:/app/src`). Backend has full volume mount (`./backend:/var/www`).

## Database

PostgreSQL 16. Connection string for local tools:

```
postgresql://lumen_user:secret_password@localhost:5432/lumen_db
```

The `public` schema is configured as default. Migrations run automatically on backend container startup (`artisan migrate --force`).

## Key Facts

- **No Vue Router** — single-page app, no client-side routing
- **No Pinia/Vuex** — no state management library installed
- **No Axios** — no HTTP client installed; use native `fetch` if needed
- **CORS enabled** — custom `App\Http\Middleware\CorsMiddleware` registered globally in `bootstrap/app.php` (reflects request `Origin`, handles `OPTIONS` preflight). `fruitcake/php-cors` remains vendored but unused.
- **Auth implemented** — `POST /api/register` and `POST /api/login` (see `AuthController`). Login/register validate email+password and return a plaintext auth token; the token hash is stored in `user_sessions`.
- **Auth middleware** — `App\Http\Middleware\Authenticate` registered as route middleware `auth` in `bootstrap/app.php`. Reads the `Authorization: Bearer <token>` header, matches the hash against `user_sessions`, and sets the request user. Returns `401` when the token is missing/invalid.
- **Meetings module** — protected by `auth` middleware (see `MeetingController`): `POST /api/meetings` (create, validates `title` + `scheduled_at`), `GET /api/meetings` (list current user's meetings), `GET /api/meetings/{id}` (single meeting, `404` if absent). Users only see their own meetings.
- **Meeting files module (Phases 1–8)** — protected by `auth` middleware. Endpoints:
  - `POST /api/meetings/{id}/files` — upload, multipart `file` (MIME via `finfo` in `FileValidationService`) + optional `label` (≤255 chars), file saved as `UUID.ext` in `storage/app/meetings/{id}/`, `201` + model JSON
  - `GET /api/meetings/{id}/files` — list (DESC by `created_at`+`id`), only owner, `200`
  - `GET /api/meetings/{id}/files/{fileId}` — `BinaryFileResponse` with `Content-Disposition: attachment; filename=<original>`, only owner, `404` for non-owned/missing
  - `DELETE /api/meetings/{id}/files/{fileId}` — only uploader (`403` otherwise), `204` + deletes row and disk file
  - Path-traversal guard: `realpath` + `str_starts_with($abs, $diskRoot . DIRECTORY_SEPARATOR)` + rejection of `..` and `/` in `stored_name`
  - Category limits in `config/files.php`: document/image/text/archive=20 МБ, audio/video=200 МБ; selected by MIME through `categoryFor()`. Same table mirrored to `frontend/src/api/mimeLimits.js` for client-side `maxFileSize`
  - Structured logging via `Log::info|warning|error` (Phase 4): context includes `meeting_id`/`user_id`/`file_id`/`status=ok|error`/`size`/`mime_type`; logs land in `storage/logs/lumen-YYYY-MM-DD.log`
  - Nginx `location /storage/ { deny all; return 403; }` + `client_max_body_size 220M` (Phase 7)
  - `docker-entrypoint.sh` chowns `storage/` to `www-data` (Phase 5) to fix bind-mount permission conflict
- **Eloquent is enabled** — models in `app/Models/` (`User`, `UserSession`, `Meeting`, `MeetingFile`)
- **Facades are enabled** — `DB::`, `Log::`, etc. are available
- **Lumen does not support `artisan key:generate`** — APP_KEY is generated in `docker-entrypoint.sh` via PHP
- **Database migrations** — `database/migrations/` contains `create_users_table`, `create_user_sessions_table`, `create_meetings_table`, `create_meeting_files_table`, `add_phone_and_avatar_to_users_table`, `add_name_to_users_table`. Run automatically on backend startup.
- **Profile module (Phases 1, 2, 3)** — protected by `auth` middleware (see `UserController`):
  - `GET /api/me` — returns `{id, name, email, phone, avatar_url, initials, color}`. `initials` = `mb_substr` первых букв первых двух слов `name` (upperCase); `color` — детерминированный HSL→hex от `user_id` (стабилен между сессиями).
  - `PATCH /api/me` — редактирует `name` (required, ≤ 255) и/или `phone` (nullable, ≤ 20, `0-9 +()\-`). `email` в теле игнорируется (whitelist полей).
  - `POST /api/me/password` — FormRequest валидация: `current_password` required, `new_password` required|string|min:8|confirmed; затем `Hash::check` для current; на неверный current → 422 «Неверный текущий пароль». Логи: `Log::info` при успехе, `Log::warning` при ошибке.
  - `POST /api/logout` — инвалидирует текущий токен: ищет запись в `user_sessions` по `user_id` + `Hash::check($token, $session->token)`, удаляет, возвращает `204` (пустое тело). Остальные сессии пользователя не затрагиваются.
  - Колонки `users`: `name VARCHAR(255) NULL`, `phone VARCHAR(20) NULL`, `avatar_path VARCHAR(255) NULL`. Миграции: `2026_07_18_000001_add_phone_and_avatar_to_users_table`, `2026_07_18_000002_add_name_to_users_table`.
  - Аксессоры `User` (`$appends`): `avatar_url` (null или `/api/me/avatar`), `initials`, `color`.
- **Avatar module (Phase 3)** — protected by `auth` middleware (see `UserController` + `App\Services\AvatarService`):
  - `POST /api/me/avatar` — multipart `avatar` (JPEG/PNG/WebP, ≤ 2 МБ). MIME проверяется через `finfo` (`App\Exceptions\InvalidAvatarMimeException` → 422 «Недопустимый формат изображения. Разрешены JPG, PNG, WebP.»), размер через `AvatarTooLargeException` → 422 «Файл слишком большой. Максимальный размер — 2 МБ.». При успехе: ресайз до 400×400 (GD), удаление старого файла с диска, сохранение в `storage/app/avatars/{user_id}.{ext}`, обновление `users.avatar_path`. Логи `Log::info`/`Log::warning`.
  - `GET /api/me/avatar` — отдача через `BinaryFileResponse` с `Content-Type: image/{jpeg|png|webp}` и `Content-Disposition: inline; filename="avatar.{ext}"`, `Cache-Control: private, max-age=300`. Path-traversal guard (realpath + str_starts_with + запрет `..`). `404` если файла нет.
  - `DELETE /api/me/avatar` — идемпотентно удаляет файл с диска, обнуляет `avatar_path`. `200` + JSON профиля.
  - Nginx `location /storage/ { deny all; return 403; }` — аватарки отдаются только через PHP-эндпоинт, прямой доступ к `storage/app/avatars/` невозможен.
  - `docker-entrypoint.sh` гарантирует `www-data` для `storage/app/avatars/` (и всего `storage/`).
- **Tests** — PHPUnit 10 + Mockery (dev deps). E2e feature tests in `backend/tests/Feature/` (`RegisterTest`, `LoginTest`, `LogoutTest`, `MeetingsTest`, `MeetingFilesTest`, `FileValidationTest`, `LoggingAndInfraTest`, `MeTest`, `MeUpdateTest`, `AvatarTest`, `AvatarDeleteTest`, `ShowAvatarTest`, `ChangePasswordTest`, `UserModelTest`, `UserProfileFieldsMigrationTest`). **129 tests, 416 assertions**. Run: `docker compose exec backend ./vendor/bin/phpunit` (or `composer test`). Tests use the `DatabaseMigrations` trait against `lumen_db`, so they reset the schema — re-run `php artisan migrate --force` afterwards for the live app.
- **Russian UI/API** — все пользовательские сообщения и валидация на русском. `app.locale = ru` (`config/app.php`); словари в `backend/resources/lang/ru/validation.php` (правила + секция `attributes` для человекочитаемых имён полей). Контроллеры возвращают русские строки в `message`-полях JSON: `Unauthenticated` → `Требуется авторизация`, `Invalid credentials` → `Неверный email или пароль`, `Meeting not found` → `Встреча не найдена`, `File not found` → `Файл не найден`, `Avatar not found` → `Аватарка не найдена`, `Forbidden` → `Доступ запрещён`, `Password changed` → `Пароль изменён`, `Wrong current password` → `Неверный текущий пароль`, `Invalid avatar mime` → `Недопустимый формат изображения. Разрешены JPG, PNG, WebP.`, `Avatar too large` → `Файл слишком большой. Максимальный размер — 2 МБ.`. Логи и тесты остаются на английском.
- **Auto-formatting**: Prettier (frontend, JS/Vue/CSS) + PHP-CS-Fixer (backend, PHP). VS Code format-on-save настроен в `.vscode/settings.json`. Ручной запуск: `npm run format` / `composer format`
- **Frontend uploads (Phases 6–8)**: `MeetingsList` shows each meeting with «Показать файлы» (`MeetingFileList` with `DataView` rows: icon by MIME, name, size, user_id, date, label, «Скачать» button, «Удалить» visible only to uploader with `ConfirmDialog` + toast) and «Загрузить файл» (`UploadFileDialog` with `FileUpload` + `InputText` + `ProgressBar` + inline validation + toast; XHR upload with progress; uploads via `Authorization: Bearer` and reads `Content-Disposition` for download filename; audio/video preview via blob URL)
- **Frontend profile/avatar/menu (Phases 5–7)**: `UserMenu` в правом верхнем углу — PrimeVue `Avatar` (image или инициалы на детерминированном цвете из `user.color`) + `Menu` с пунктами «Профиль» и «Выйти». «Профиль» открывает `ProfileView` (PrimeVue `Dialog` с тремя секциями: «Основное» через `api/me.updateMe`, «Аватарка» через `api/me.uploadAvatar` (XHR с прогрессом, клиентская валидация JPEG/PNG/WebP + ≤ 2 МБ), «Безопасность» через `api/me.changePassword`). Logout: `ConfirmDialog` → `api/me.logout` → 204 → очистка `store/auth` + `localStorage` → редирект на `AuthForm`. При 401 от API (токен истёк) — локальное состояние всё равно чистится, без всплывающей ошибки. `App.vue` переключает `AuthForm` ↔ `Dashboard` (`MeetingsList` + `UserMenu` + `ProfileView`).
- **README.md**: onboarding, file-upload workflow with category limits, API reference, tests instructions

## Documentation

При любых изменениях архитектуры проекта (добавление/удаление сервисов, изменение портов, смена стека, новая структура директорий, новые ENV-переменные, изменения в маршрутах и т.д.) необходимо **синхронно актуализировать этот файл** (CLAUDE.md):
- Обновить ASCII-диаграмму в разделе Architecture
- Обновить дерево Project Structure
- Обновить таблицу Services & Ports
- Обновить блок Environment Variables
- Обновить раздел Key Facts при добавлении/удалении зависимостей или изменении поведения


## ВАЖНО: Соглашение о коммитах

Все коммиты **обязательно** должны следовать [Conventional Commits](https://www.conventionalcommits.org/ru/v1.0.0/).

Формат: `<тип>[область]: <описание>`

Основные типы: `feat`, `fix`, `docs`, `style`, `refactor`, `build`.

Примеры:
```
feat(api): добавить эндпоинт создания заявки
fix(crm): исправить отображение списка клиентов
refactor(api): вынести логику авторизации в отдельный класс
```

**Описание — что уже сделано, а не что нужно сделать.** Использовать свершившийся или пассивный залог:
- `добавлено` ✓, ~~добавить~~ ✗
- `перенесён` ✓, ~~перенести~~ ✗
- `исправлено` ✓, ~~исправить~~ ✗
- `убран` ✓, ~~убрать~~ ✗

## ВАЖНО: Пуш — только с согласия

**Никогда не пушить изменения в удалённый репозиторий без явного разрешения пользователя.** Коммиты создаются автоматически, но `git push` — только после слов «запушь», «отправь», «push» и т.п.

---
