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
│   └── app.php            # Application bootstrap (load env, register services)
├── config/
│   └── database.php       # PostgreSQL connection config
├── routes/
│   └── web.php            # All API routes
├── public/
│   └── index.php          # HTTP front controller
├── database/
│   ├── migrations/        # Database migrations (create tables here)
│   └── seeds/             # Database seeders
├── storage/
│   ├── logs/              # Application logs
│   └── framework/         # Cache, views
├── vendor/                # Composer dependencies
└── app/
    ├── Http/
    │   └── Controllers/   # API controllers
    ├── Models/            # Eloquent models (create this directory)
    └── Exceptions/
        └── Handler.php    # Global exception handler
```

## Routes

All routes defined in `routes/web.php`. Registered in `bootstrap/app.php` with `App\Http\Controllers` namespace prefix.

Example:
```php
$router->get('/', function () use ($router) {
    return response()->json(['app' => 'Lumen', ...]);
});
$router->get('/api/hello', 'ExampleController@hello');
```

Controller method syntax: `'ControllerName@methodName'` (string reference to controller class).

### API endpoints

| Method | Path | Controller | Auth | Notes |
|--------|------|------------|:----:|-------|
| POST | `/api/register` | `AuthController@register` | — | email+password, returns token |
| POST | `/api/login` | `AuthController@login` | — | email+password, returns token |
| POST | `/api/meetings` | `MeetingController@store` | `auth` | validates `title` + `scheduled_at` |
| GET | `/api/meetings` | `MeetingController@index` | `auth` | current user's meetings |
| GET | `/api/meetings/{id}` | `MeetingController@show` | `auth` | `404` if not found / not owned |

## Controllers

Controllers go in `app/Http/Controllers/`. Extend `App\Http\Controllers\Controller` (or `Laravel\Lumen\Routing\Controller`).

Return JSON responses using `response()->json($data)` helper.

Example pattern:
```php
namespace App\Http\Controllers;

class ExampleController extends Controller
{
    public function hello()
    {
        return response()->json([
            'message' => 'Hello from Lumen + PostgreSQL!',
            'time' => now()->toISOString(),
        ]);
    }
}
```

## Models

Create Eloquent models in `app/Models/` (create the directory first):

```bash
mkdir -p backend/app/Models
```

Model example:
```php
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = ['name', 'price', 'description'];
}
```

## Database

### Connection

PostgreSQL configured in `config/database.php` and `.env`:
- Driver: `pgsql`
- Host: `postgres` (Docker service name)
- Port: `5432`
- Database: `lumen_db`
- Schema: `public`
- Charset: `utf8`

### Migrations

Create migrations in `database/migrations/`. Migrations run automatically on container startup via `docker-entrypoint.sh`:

```bash
php artisan migrate --force
```

Create a migration:
```bash
php artisan make:migration create_products_table
```

### Seeders

Seeders go in `database/seeds/`. Run manually:
```bash
php artisan db:seed
```

## CORS

The `fruitcake/php-cors` package is installed in `vendor/` but **not enabled**. Frontend runs on `localhost:5174`, API on `localhost:8081` — cross-origin requests will fail.

To enable CORS, register the middleware in `bootstrap/app.php`:

```php
$app->middleware([
    Fruitcake\Cors\HandleCors::class,
]);
```

And publish/config the CORS config if needed.

## Middleware

Route middleware and global middleware are registered in `bootstrap/app.php`. `CorsMiddleware` runs globally; `App\Http\Middleware\Authenticate` is registered as route middleware `auth` and reads the `Authorization: Bearer <token>` header, matching the hash against `user_sessions` (returns `401` when missing/invalid).

Add custom middleware in `app/Http/Middleware/` (create if needed).

## Running Commands

All `artisan` commands run inside the backend container:

```bash
docker compose exec backend php artisan make:migration create_xxx_table
docker compose exec backend php artisan migrate
docker compose exec backend php artisan db:seed
```

## Error Handling

`App\Exceptions\Handler` extends the default Lumen handler. Override `render()` for custom JSON error responses. Default behaviour: validation errors return JSON, 404s return JSON.

## Conventions

- **PSR-4 autoloading**: `App\` namespace maps to `app/` directory
- **Controller naming**: `PascalCaseController.php`, methods in `camelCase()`
- **Model naming**: `PascalCase.php`, table names are `snake_case` plural (Eloquent convention)
- **Route naming**: RESTful patterns — `GET /api/resource`, `POST /api/resource`, etc.
- **JSON responses**: Always use `response()->json()`, never `return $array`
- **No resource classes** installed by default — return plain arrays/collections
- **No form requests** installed by default — validate manually or install `laravel/lumen-framework` form request support


## File upload

Use this research for it: @docs/research-meeting-file-upload.md
