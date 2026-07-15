# Backend — Lumen PHP API

## Tech Stack

- **Lumen 10.x** — Laravel's micro-framework
- **PHP 8.3** (Docker, `php:8.3-fpm-alpine`)
- **PostgreSQL 16** via `pdo_pgsql`
- **Eloquent ORM** — enabled in `bootstrap/app.php`
- **Facades** — enabled (`DB::`, `Log::`, etc.)

## Project Structure

```
backend/
├── Dockerfile
├── docker-entrypoint.sh   # Container startup: composer install, migrate, php-fpm
├── artisan                # Lumen CLI
├── composer.json
├── bootstrap/
│   └── app.php            # Registers Translator, configures app/database/files
├── config/
│   ├── app.php            # app.locale = ru, fallback_locale = ru
│   └── database.php       # PostgreSQL connection config
├── resources/
│   └── lang/ru/           # Russian validation messages + attribute names
├── routes/
│   └── web.php            # All API routes
├── app/
│   ├── Http/
│   │   └── Controllers/   # API controllers
│   ├── Models/            # Eloquent models
│   └── Exceptions/
│       └── Handler.php    # Global exception handler
├── database/
│   └── migrations/        # Database migrations
├── storage/
│   ├── app/               # Uploaded meeting files (meetings/{id}/)
│   └── logs/              # Application logs
└── vendor/                # Composer dependencies
```

## Conventions

- **PSR-4 autoloading**: `App\` → `app/`
- **Controllers**: `PascalCaseController.php`, methods in `camelCase()`
- **Models**: `PascalCase.php`, tables `snake_case` plural (Eloquent)
- **JSON responses**: Always `response()->json()`, never `return $array`
- **No resource classes / no form requests** by default

## Database

PostgreSQL connection in `config/database.php` + `.env`. Host `postgres` (Docker), port 5432, schema `public`. Migrations run automatically on container startup via `php artisan migrate --force`.

## Error Handling

`App\Exceptions\Handler` extends the default Lumen handler. Default behaviour: validation errors → JSON, 404s → JSON. Custom Russian messages handled in controllers (see [Localization](#localization-russian) below).

## Localization (Russian)

All user-facing messages and Laravel validation errors are Russian.

- `config/app.php` — `locale = ru`, `fallback_locale = ru` (env: `APP_LOCALE` / `APP_FALLBACK_LOCALE`).
- `resources/lang/ru/validation.php` — full dictionary of `validation` rules (`required`, `email`, `unique`, `min`, `max`, `confirmed`, `string`, `date`, `nullable`, etc.) **plus** the `attributes` section for human-readable field names (`email`→`Email`, `password`→`пароль`, `title`→`название`, `scheduled_at`→`дата и время`, `file`→`файл`, `label`→`подпись`, `description`→`описание`).
- `bootstrap/app.php` calls `$app->configure('app')` so `ValidationServiceProvider` picks up `app.locale`.
- Controllers return Russian strings in JSON `message` fields:
  - 401 `Unauthenticated` → `Требуется авторизация` (`Authenticate` middleware)
  - 401 `Invalid credentials` → `Неверный email или пароль` (`AuthController@login`)
  - 404 `Meeting not found` → `Встреча не найдена` (`MeetingController`, `MeetingFileController`)
  - 404 `File not found` → `Файл не найден` (`MeetingFileController`)
  - 403 `Forbidden` → `Доступ запрещён` (`MeetingFileController@destroy`)
  - 500 `Failed to store file` → `Не удалось сохранить файл на диск`
  - 500 `Failed to record file` → `Не удалось сохранить информацию о файле`
- `App\Services\FileValidator` returns Russian strings in `errors.file[]` and `errors.label[]`.
- Logs (`Log::info|warning|error`) and test assertions stay in English.

## Destructive operations — require explicit confirmation

Любые операции, удаляющие или перезаписывающие данные в общей/продовой БД `lumen_db`, **запрещено** выполнять без явного согласия пользователя:

- `DELETE FROM …` / `TRUNCATE …` через `psql` или SQL-миграции.
- `php artisan migrate:fresh`, `migrate:reset`, `migrate:rollback` (сбрасывают схему).
- Удаление файлов в `storage/app/meetings/{id}/` (осиротевшие файлы = мусор).
- Любой `rm -rf` внутри `postgres_data` volume, `storage/`, `bootstrap/cache/`.

Перед выполнением **обязательно** спросить у пользователя и ждать подтверждения. Не расширять scope без спроса.

**Исключения** (без подтверждения, идемпотентные):
- `composer install` / `npm install` — установка зависимостей.
- `php artisan migrate` (без `fresh`/`reset`/`rollback`) — добавляет миграции, не удаляет данные.
- Чтение (`SELECT`, `GET`-запросы) — безвредно.

## File upload

См. `docs/research-meeting-file-upload.md`. Краткий обзор API и лимитов — в корневом `CLAUDE.md` (раздел Key Facts).
