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
| POST | `/api/register` | — | email + password (+ confirmation) → token |
| POST | `/api/login` | — | email + password → token |
| POST | `/api/meetings` | ✓ | создать встречу (title + scheduled_at) |
| GET | `/api/meetings` | ✓ | список встреч текущего пользователя |
| GET | `/api/meetings/{id}` | ✓ | одна встреча (404 если не владелец) |
| POST | `/api/meetings/{id}/files` | ✓ | загрузить файл (multipart `file` + опц. `label`) |
| GET | `/api/meetings/{id}/files` | ✓ | список файлов встречи (DESC по created_at) |
| GET | `/api/meetings/{id}/files/{fileId}` | ✓ | скачать (Content-Disposition, original_name) |
| DELETE | `/api/meetings/{id}/files/{fileId}` | ✓ | удалить (только загрузившему) |

Все защищённые эндпоинты требуют `Authorization: Bearer <token>`. На 401 клиент автоматически сбрасывает сессию и редиректит на логин.

## Тесты

### Backend

```bash
docker compose exec backend composer install --dev
docker compose exec backend ./vendor/bin/phpunit
```

Покрытие (67 тестов, 240+ assertions):
- `tests/Feature/MeetingFilesTest.php` — upload, download, list, delete, MIME/size, sanitization, path-traversal, rollback (25)
- `tests/Feature/FileValidationTest.php` — 8 кейсов валидации из Phase 3 (8)
- `tests/Feature/LoggingAndInfraTest.php` — логирование, Nginx, restart-volume (6)
- `tests/Feature/{Register,Login,Meetings}Test.php` — auth и встречи (28)

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
