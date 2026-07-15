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
│   │   ├── Http/Controllers/  # API controllers (Auth, Meeting, MeetingFile)
│   │   ├── Http/Middleware/   # Authenticate, CorsMiddleware
│   │   ├── Http/Requests/     # StoreMeetingFileRequest (FormRequest-style)
│   │   ├── Models/            # Eloquent models (User, UserSession, Meeting, MeetingFile)
│   │   └── Services/          # FileValidator, FileValidationService (finfo)
│   ├── routes/web.php         # API route definitions
│   ├── bootstrap/app.php      # Lumen app bootstrap
│   ├── config/database.php    # DB connection config
│   ├── config/files.php       # MIME allow-list + size limits per category
│   ├── docker-entrypoint.sh   # Container startup
│   ├── .dockerignore          # Excludes storage/, vendor/, etc. from build context
│   └── composer.json
├── frontend/              # Vue 3 + Vite SPA
│   ├── src/
│   │   ├── App.vue            # Root component (AuthForm ↔ Dashboard with Toast/ConfirmDialog)
│   │   ├── main.js            # App entry, PrimeVue + ToastService + ConfirmationService
│   │   ├── style.css          # Global CSS
│   │   ├── api/               # auth.js, meetings.js, files.js, mimeLimits.js
│   │   ├── store/auth.js      # Reactive session (token + user in localStorage)
│   │   └── components/        # AuthForm, MeetingsList, MeetingFileList, UploadFileDialog
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
- **Eloquent is enabled** — models in `app/Models/` (`User`, `UserSession`, `Meeting`)
- **Facades are enabled** — `DB::`, `Log::`, etc. are available
- **Lumen does not support `artisan key:generate`** — APP_KEY is generated in `docker-entrypoint.sh` via PHP
- **Database migrations** — `database/migrations/` contains `create_users_table`, `create_user_sessions_table`, and `create_meetings_table`. Run automatically on backend startup.
- **Tests** — PHPUnit 10 + Mockery (dev deps). E2e feature tests in `backend/tests/Feature/` (`RegisterTest`, `LoginTest`, `MeetingsTest`, `MeetingFilesTest`, `FileValidationTest`, `LoggingAndInfraTest`). 67 tests, 240 assertions. Run: `docker compose exec backend ./vendor/bin/phpunit` (or `composer test`). Tests use the `DatabaseMigrations` trait against `lumen_db`, so they reset the schema — re-run `php artisan migrate --force` afterwards for the live app.
- **Auto-formatting**: Prettier (frontend, JS/Vue/CSS) + PHP-CS-Fixer (backend, PHP). VS Code format-on-save настроен в `.vscode/settings.json`. Ручной запуск: `npm run format` / `composer format`
- **Frontend uploads (Phases 6–8)**: `MeetingsList` shows each meeting with «Показать файлы» (`MeetingFileList` with `DataView` rows: icon by MIME, name, size, user_id, date, label, «Скачать» button, «Удалить» visible only to uploader with `ConfirmDialog` + toast) and «Загрузить файл» (`UploadFileDialog` with `FileUpload` + `InputText` + `ProgressBar` + inline validation + toast; XHR upload with progress; uploads via `Authorization: Bearer` and reads `Content-Disposition` for download filename; audio/video preview via blob URL)
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
