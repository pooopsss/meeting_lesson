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
│   │   ├── Http/Controllers/  # API controllers
│   │   └── Exceptions/        # Exception handler
│   ├── routes/web.php         # API route definitions
│   ├── bootstrap/app.php      # Lumen app bootstrap
│   ├── config/database.php    # DB connection config
│   ├── docker-entrypoint.sh   # Container startup
│   └── composer.json
├── frontend/              # Vue 3 + Vite SPA
│   ├── src/
│   │   ├── App.vue            # Root component
│   │   ├── main.js            # App entry, PrimeVue setup
│   │   ├── style.css          # Global CSS
│   │   └── components/        # Vue components
│   ├── vite.config.js
│   └── package.json
├── nginx/
│   └── default.conf        # Nginx reverse-proxy config
├── docker-compose.yml      # All services
├── .env                    # Environment variables
└── .env.example            # Env template
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
- **Eloquent is enabled** — models in `app/Models/` (`User`, `UserSession`, `Meeting`)
- **Facades are enabled** — `DB::`, `Log::`, etc. are available
- **Lumen does not support `artisan key:generate`** — APP_KEY is generated in `docker-entrypoint.sh` via PHP
- **Database migrations** — `database/migrations/` contains `create_users_table`, `create_user_sessions_table`, and `create_meetings_table`. Run automatically on backend startup.
- **Tests** — PHPUnit 10 + Mockery (dev deps). E2e feature tests in `backend/tests/Feature/` (`RegisterTest`, `LoginTest`, `MeetingsTest`). Run: `docker compose exec backend ./vendor/bin/phpunit` (or `composer test`). Tests use the `DatabaseMigrations` trait against `lumen_db`, so they reset the schema — re-run `php artisan migrate --force` afterwards for the live app.
- **Auto-formatting**: Prettier (frontend, JS/Vue/CSS) + PHP-CS-Fixer (backend, PHP). VS Code format-on-save настроен в `.vscode/settings.json`. Ручной запуск: `npm run format` / `composer format`

## Documentation

При любых изменениях архитектуры проекта (добавление/удаление сервисов, изменение портов, смена стека, новая структура директорий, новые ENV-переменные, изменения в маршрутах и т.д.) необходимо **синхронно актуализировать этот файл** (CLAUDE.md):
- Обновить ASCII-диаграмму в разделе Architecture
- Обновить дерево Project Structure
- Обновить таблицу Services & Ports
- Обновить блок Environment Variables
- Обновить раздел Key Facts при добавлении/удалении зависимостей или изменении поведения
