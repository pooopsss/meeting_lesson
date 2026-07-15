# Research: Загрузка файлов встречи (техническое ресерч)

**PRD:** @docs/prd-meeting-file-upload.md
**План:** @docs/plan-meeting-file-upload.md
**Дата:** 2026-07-15
**Назначение:** технический ресерч «как лучше реализовать» — для верификации решений плана и закрытия открытых вопросов.

---

## TL;DR — сводка рекомендаций

| # | Решение | Обоснование |
|---|---------|-------------|
| 1 | **Storage** работает в Lumen 10 из коробки — НЕ нужно регистрировать `FilesystemServiceProvider` в `bootstrap/app.php` (см. `Application::registerFilesystemBindings`, lazy-binding). Достаточно создать `backend/config/filesystems.php` (или положиться на framework-default) и `Storage::disk('local')` уже доступен. | Подтверждено в `vendor/laravel/lumen-framework/src/Application.php:485–496, 1147–1152`. |
| 2 | **MIME-валидация** — только через `finfo` по содержимому. Использовать `Symfony\Http\File\File::getMimeType()` (через `$uploadedFile->getMimeType()`), т.к. он уже использует `FileinfoMimeTypeGuesser` и согласован с `guessExtension()`. **Никогда** не использовать `getClientMimeType()`. | `vendor/symfony/http-foundation/File/File.php:71–78`. |
| 3 | **Загрузка** — `$request->file('file')->storeAs('meetings/{id}', Str::uuid().'.{ext}', ['disk' => 'local'])`. Расширение берём через `guessExtension()` ПОСЛЕ успешной MIME-валидации, не из имени клиента. | `vendor/illuminate/http/UploadedFile.php:84–97`. |
| 4 | **Скачивание** — `response()->download($absPath, $originalName)` (обёртка над Symfony `BinaryFileResponse`). Стримит по 8 МБ чанками, ставит `Content-Disposition: attachment`, поддерживает RFC 5987. Дополнительно — `realpath()` + prefix-check защита от path-traversal. | `vendor/laravel/lumen-framework/src/Http/ResponseFactory.php:104–113`, `vendor/symfony/http-foundation/BinaryFileResponse.php:296–339`. |
| 5 | **Валидация** — `Validator::make($request->all(), ['file' => 'required\|file', 'label' => 'nullable\|string\|max:255'])` + отдельный `FileValidationService` для контентных проверок. Возврат ошибок — **ручной** `response()->json(['errors' => …], 422)` (как в существующем коде, для консистентности). | `MeetingController.php:36–44`, `AuthController.php:16–23, 40–47`. |
| 6 | **Rollback-паттерн** — файл первым, потом БД. На исключении БД — `Storage::delete($relPath)`. PRD явно требует «нет осиротевших файлов», а не «нет осиротевших строк», поэтому этот порядок правильный. | См. §7 Backend. |
| 7 | **Тесты хранилища** — `Storage::fake('local')` в `setUp()` (доступен в Illuminate). Мокать сбой БД через Eloquent `creating`-событие: `MeetingFile::creating(fn() => throw new Exception)`. | `vendor/illuminate/support/Facades/Storage.php:94–113`, `HasEvents.php:318–321`. |
| 8 | **Frontend upload** — `XMLHttpRequest` вместо `fetch` ради `upload.onprogress` (требование PRD о видимом прогресс-баре). Это платформенный примитив, не HTTP-клиент, не нарушает правило «no Axios». | См. §3 Frontend. |
| 9 | **Frontend media preview** — **конфликт PRD и текущей auth-модели** (HTML-`<audio>`/`<video>` не отправляют `Authorization` header). Решение v1: `fetch → blob → URL.createObjectURL` для файлов < 5 МБ; для крупных видео — «Скачать», без inline preview. Правильное долгосрочное решение — **signed URL** в v2. | См. §8 Frontend. |
| 10 | **Nginx** — добавить `client_max_body_size 220m`, `client_body_timeout 300s`, `fastcgi_buffering off` (для стрима 200 МБ), `fastcgi_read_timeout 300s`, `fastcgi_request_buffering off`, и явный `location ~ ^/storage/ { deny all; return 404; }`. Без этого 200 МБ видео отлупится `HTTP 413` ещё до PHP. | Текущий `nginx/default.conf` не содержит ни одной из этих директив. |
| 11 | **PHP** — создать `backend/docker/php.ini` с `upload_max_filesize=220M`, `post_max_size=240M`, `memory_limit=256M`, `max_input_time=300`, `max_execution_time=300`, `output_buffering=Off`. Скопировать в `/usr/local/etc/php/conf.d/zz-uploads.ini` через `COPY` в `Dockerfile`. | `php:8.3-fpm-alpine` не имеет этих лимитов «из коробки». |
| 12 | **Иконки** — `primeicons` НЕ установлен (`primeicons` пакет отсутствует в `package.json` и `node_modules`). Существующие `pi pi-calendar`/`pi pi-user` сейчас не рендерят глифы. Нужно `npm install primeicons` + `import "primeicons/primeicons.css"` в `main.js`. | См. §4 Frontend. |
| 13 | **Toast/ConfirmDialog** — PrimeVue 4 требует `app.use(ToastService)` + `app.use(ConfirmationService)` в `main.js` и `<Toast />` + `<ConfirmDialog />` в `App.vue` (один раз, вне `v-if`). Сейчас не подключено. | `frontend/src/main.js:1–13`, `frontend/src/App.vue`. |
| 14 | **Volume** — `storage/app/` живёт на хоста под `./backend/storage/app/` (bind-mount в `docker-compose.yml:42, 57`). Переживает `docker compose restart backend`, `down && up`, `system prune`. **Не переживает** `docker compose down -v` (удаление volumes) — это документируемое ограничение. | См. §5 Infrastructure. |

---

## Содержание

1. [Backend (Lumen 10 + PHP 8.3)](#1-backend-lumen-10--php-83)
2. [Frontend (Vue 3 + PrimeVue 4)](#2-frontend-vue-3--primevue-4)
3. [Infrastructure (Nginx, PHP-FPM, Docker, Postgres)](#3-infrastructure-nginx-php-fpm-docker-postgres)
4. [Open questions и конфликты плана](#4-open-questions-и-конфликты-плана)
5. [Карта файлов к правке](#5-карта-файлов-к-правке)

---

## 1. Backend (Lumen 10 + PHP 8.3)

### 1.1. Filesystem — уже работает, регистрация не нужна

**Проверено:** `illuminate/filesystem` приходит как транзитивная зависимость `laravel/lumen-framework:^10.0` (лежит в `backend/vendor/illuminate/filesystem/`). `Application::registerFilesystemBindings()` (`vendor/laravel/lumen-framework/src/Application.php:485–496`) лениво регистрирует singleton-биндинги `'filesystem'`, `'filesystem.disk'`, `'filesystem.cloud'` через `loadComponent('filesystems', FilesystemServiceProvider::class, …)`. `loadComponent` сам вызывает `configure('filesystems')` (`:704–716`), который читает `backend/config/filesystems.php`, а если файла нет — framework-default `vendor/laravel/lumen-framework/config/filesystems.php`.

**Вывод:** для базовой работы `Storage::disk('local')` достаточно **ничего не трогать в `bootstrap/app.php`**. Если хочется явно задокументировать диск — создать `backend/config/filesystems.php` (минимальная копия framework-дефолта, при необходимости с дополнительными дисками).

**Диск `local` по умолчанию** (`vendor/laravel/lumen-framework/config/filesystems.php:44–68`):
- `driver` = `local`
- `root` = `storage_path('app')` → `/var/www/storage/app` в контейнере
- `throw` = `false`

Это ровно то, что требует PRD. **Рекомендация: оставить framework-default, не плодить конфиг-файл без необходимости.** Отдельный `config/files.php` создаём для лимитов/MIME-allow-list (см. §1.5).

### 1.2. Получение файла из запроса

`$request->file('file')` возвращает `Illuminate\Http\UploadedFile` (`vendor/illuminate/http/UploadedFile.php:13`), наследник `Symfony\Component\HttpFoundation\File\UploadedFile`. Доступные методы, которые мы используем:

| Метод | Что делает | Доверять? |
|---|---|---|
| `isValid()` | `UPLOAD_ERR_OK` и файл существует на temp-пути | ✅ |
| `getRealPath()` | абсолютный путь к temp-файлу (`/tmp/phpXXXX`) | ✅ |
| `getSize()` | байты (`int`) | ✅ |
| `getClientOriginalName()` | имя, присланное клиентом | ⚠️ только для отображения |
| `getClientMimeType()` | MIME из multipart-headers клиента | ❌ НИКОГДА для валидации |
| `getMimeType()` | MIME по `finfo` через `MimeTypes::getDefault()->guessMimeType($path)` | ✅ |
| `guessExtension()` | расширение, выведенное из `getMimeType()` | ✅ |
| `storeAs($path, $name, $options)` | сохраняет на диск, возвращает путь относительно корня диска | ✅ |

**Snippet (controller signature):**

```php
public function store(Request $request, int $meetingId): JsonResponse
{
    // ...
    $uploaded = $request->file('file');
    // ...
}
```

### 1.3. MIME-детекция через finfo

**Реализация `getMimeType()`** (`vendor/symfony/http-foundation/File/File.php:71–78`):

```php
public function getMimeType(): ?string
{
    if (! class_exists(MimeTypes::class)) {
        throw new \LogicException('You cannot guess the mime type as the Mime component is not installed.');
    }
    return MimeTypes::getDefault()->guessMimeType($this->getPathname());
}
```

`MimeTypes::guessMimeType()` (`vendor/symfony/mime/MimeTypes.php:116–133`) перебирает guessers в обратном порядке регистрации, по умолчанию первый — `FileinfoMimeTypeGuesser`, который вызывает `new \finfo(\FILEINFO_MIME_TYPE, …)->file($path)`.

**Альтернатива:** PHP-нативный `finfo_file(…, FILEINFO_MIME_TYPE)` — то же самое в один вызов. **Рекомендация:** использовать `getMimeType()` ради согласованности с `guessExtension()`.

**Trust model:** `getClientMimeType()` возвращает `Content-Type` из multipart — **недоверенный**. Атакующий переименовывает `malware.exe` в `report.pdf` с правильным Content-Type в форме — finfo всё равно скажет `application/x-dosexec`. Наш код:

```php
$mime = $uploaded->getMimeType();         // ✅ finfo по содержимому
$ext  = $uploaded->guessExtension();      // ✅ из MIME, не из имени клиента
// и только теперь: in_array($mime, $allowed_for_category) и getSize() <= $max_bytes
```

**`fileinfo` в Docker:** `php:8.3-fpm-alpine` собирается с `--enable-fileinfo` по умолчанию. Дополнительных `docker-php-ext-install fileinfo` не требуется.

### 1.4. Сохранение файла

```php
$relDir = "meetings/{$meetingId}";
$ext    = $uploaded->guessExtension();   // safe: derived from getMimeType()
$stored = Str::uuid()->toString() . '.' . $ext;

$storedRel = $request->file('file')
    ->storeAs($relDir, $stored, ['disk' => 'local']);
// $storedRel = "meetings/42/9f3a….mp4"
```

`storeAs` (`vendor/illuminate/http/UploadedFile.php:84–97`): `Arr::pull($options, 'disk')` → `Container::getInstance()->make(FilesystemFactory::class)->disk($disk)` → `putFileAs($path, $this, $name, $options)`. Возвращает путь **относительно корня диска**.

**Путь на диске:** `storage/app` + `meetings/{id}/{uuid}.{ext}` → `/var/www/storage/app/meetings/42/9f3a….mp4` в контейнере.

**Важно:** `guessExtension()` вызываем **после** успешной проверки `in_array($mime, $allowed)`, не до. Это гарантирует, что расширение соответствует **разрешённому** MIME, а не (потенциально лживому) имени файла клиента.

**`Str::uuid()`** — `vendor/illuminate/support/Str.php:1630`, возвращает `Ramsey\Uuid\UuidInterface`, `->toString()` даёт канонический hyphenated UUIDv4. Уже подгружен.

**Права на запись:** `docker-entrypoint.sh:6` делает `chown -R www-data:www-data /var/www/storage` на каждом старте контейнера. PHP-FPM работает под `www-data` — запись успешна. Рекомендация: добавить `chmod -R 775 /var/www/storage` (см. §3 Infrastructure).

### 1.5. Конфиг лимитов — `config/files.php`

```php
<?php
// backend/config/files.php
return [
    'categories' => [
        'document' => [
            'mimes' => [
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ],
            'max_size' => 20 * 1024 * 1024,
        ],
        'image' => [
            'mimes' => [
                'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml',
            ],
            'max_size' => 20 * 1024 * 1024,
        ],
        'text' => [
            'mimes' => [
                'text/plain', 'text/csv', 'text/markdown',
            ],
            'max_size' => 20 * 1024 * 1024,
        ],
        'archive' => [
            'mimes' => [
                'application/zip',
            ],
            'max_size' => 20 * 1024 * 1024,
        ],
        'audio' => [
            'mimes' => [
                'audio/mpeg', 'audio/mp4', 'audio/wav', 'audio/ogg',
                'audio/webm', 'audio/aac', 'audio/x-aac', 'audio/flac', 'audio/x-m4a',
            ],
            'max_size' => 200 * 1024 * 1024,
        ],
        'video' => [
            'mimes' => [
                'video/mp4', 'video/webm', 'video/ogg',
                'video/quicktime', 'video/x-matroska', 'video/x-msvideo',
            ],
            'max_size' => 200 * 1024 * 1024,
        ],
    ],
];
```

Загрузка: `$app->configure('files')` (одна строка в `bootstrap/app.php`, опционально — без неё `config('files')` всё равно найдёт файл через тот же механизм, что и `filesystems`). Соответствует PRD — лимиты не зашиты в контроллер, а читаются из конфига.

### 1.6. Скачивание — стрим через `BinaryFileResponse`

**`response()->download()`** (`vendor/laravel/lumen-framework/src/Http/ResponseFactory.php:104–113`):

```php
public function download($file, $name = null, array $headers = [], $disposition = 'attachment')
{
    $response = new BinaryFileResponse($file, 200, $headers, true, $disposition);
    if (! is_null($name)) {
        return $response->setContentDisposition($disposition, $name, $this->fallbackName($name));
    }
    return $response;
}
```

→ `Symfony\Component\HttpFoundation\BinaryFileResponse` с `Content-Disposition: attachment; filename="..."` (поддержка RFC 5987 для не-ASCII имён).

**Поведение для 200 МБ:** `BinaryFileResponse::sendContent()` (`vendor/symfony/http-foundation/BinaryFileResponse.php:296–339`) стримит чанками (по умолчанию 8 МБ с Symfony 5.2) через `fopen('php://output', 'w')` + `fread`. **Память не растёт** с размером файла, ограничена размером чанка. `Content-Length` выставляется автоматически.

**`X-Accel-Redirect` / `X-Sendfile`:** поддерживается `BinaryFileResponse`, **но** требует `location /__internal/ { internal; alias /var/www/storage/app/; }` в Nginx, плюс хранения storage под Nginx-рутом. Текущий `root /var/www/public` это не выполняет. **Рекомендация: НЕ использовать в v1.** Стрим из PHP-FPM + `fastcgi_buffering off` в Nginx (см. §3) даёт сопоставимую скорость и не усложняет security-границу.

**Защита от path-traversal:**

```php
$base = realpath(Storage::disk('local')->path(''));
$abs  = realpath(Storage::disk('local')->path($row->stored_path));
if ($abs === false || ! Str::startsWith($abs, $base . DIRECTORY_SEPARATOR)) {
    return response()->json(['message' => 'File not found'], 404);
}
return response()->download($abs, $row->original_name);
```

`Storage::disk('local')->path($rel)` возвращает сконфигурированный абсолютный путь (`FilesystemAdapter::path` `:245–248`); `realpath()` резолвит `..` и symlink'и. Строгое сравнение с `.DIRECTORY_SEPARATOR` защищает от `/var/www/storage/appx` vs `/var/www/storage/app` (prefix-confusion).

`$row->stored_path` — UUID-имя из БД, без пользовательского ввода, поэтому `..` теоретически туда не попадёт. `realpath()` — defense in depth.

### 1.7. Валидация — двухступенчатая

**Соглашение проекта** (проверено в `MeetingController.php:36–44`, `AuthController.php:16–23, 40–47`):

```php
$validator = Validator::make($request->all(), [
    'field' => 'rules',
]);
if ($validator->fails()) {
    return response()->json(['errors' => $validator->errors()], 422);
}
```

**Ступень 1 — shape-валидация:**

```php
$validator = Validator::make($request->all(), [
    'file'  => 'required|file',
    'label' => 'nullable|string|max:255',
]);
if ($validator->fails()) {
    return response()->json(['errors' => $validator->errors()], 422);
}
```

`'file'` rule в Illuminate проверяет, что значение — `UploadedFile`. `'required|file'` отвергает отсутствующее поле, пустой multipart, и не-файловый payload.

**Ступень 2 — контентная валидация (`App\Services\FileValidationService::validate($uploaded)`):**

1. `$uploaded->getMimeType()` (finfo) → lookup в `config('files.categories')` → категория + список разрешённых MIME.
2. Если MIME не входит ни в одну категорию — `throw new ValidationException` ИЛИ вернуть `['file' => ['Неподдерживаемый тип файла.']]`.
3. Сравнить `$uploaded->getSize()` с `categories[$cat]['max_size']`.
4. Санитизация `original_name`: `basename()` → strip NUL и C0 control chars (`preg_replace('/[\x00-\x1F\x7F]/u', '', $name)`) → `Str::limit($name, 255, '')`.

**Формат ошибки.** Lumen по умолчанию конвертирует `ValidationException` в `{ "message": "…", "errors": {…} }` через `Handler::render` (`vendor/laravel/lumen-framework/src/Exceptions/Handler.php:115`). Но проект использует другой формат — `{ "errors": {…} }` (см. `MeetingController.php:43`, `AuthController.php:22, 46`). **Рекомендация: оставаться в согласии с проектом, возвращать `422` вручную из контроллера:**

```php
return response()->json(['errors' => ['file' => ['Disallowed file type.']]], 422);
```

Это сохраняет API-контракт идентичным существующим эндпоинтам.

### 1.8. Транзакционность и rollback

**Факт:** `Storage::put` / `putFileAs` **не транзакционен** с БД. `DB::transaction(fn() => …)` обернёт только DB-вызовы, и при сбое БД файл останется на диске.

**Рекомендованный паттерн (файл → БД, чистка при сбое БД):** соответствует PRD-требованию «нет осиротевших файлов».

```php
$relPath = "meetings/{$meetingId}/" . Str::uuid()->toString() . '.' . $ext;

try {
    $storedRel = $request->file('file')
        ->storeAs("meetings/{$meetingId}", basename($relPath), ['disk' => 'local']);
} catch (\Throwable $e) {
    Log::error('meeting_file: write failed', [
        'meeting_id' => $meetingId, 'err' => $e->getMessage(),
    ]);
    return response()->json(['message' => 'Failed to store file'], 500);
}

try {
    $row = MeetingFile::create([
        'meeting_id'    => $meetingId,
        'user_id'       => $request->user()->id,
        'original_name' => $sanitized,
        'stored_path'   => $storedRel,
        'mime_type'     => $mime,
        'size_bytes'    => $uploaded->getSize(),
        'label'         => $label,
    ]);
} catch (\Throwable $e) {
    Storage::disk('local')->delete($storedRel);
    Log::error('meeting_file: db write failed, file rolled back', [
        'path' => $storedRel, 'err' => $e->getMessage(),
    ]);
    return response()->json(['message' => 'Failed to record file'], 500);
}
```

**Обратный порядок** (БД → файл) дал бы осиротевшие DB-строки при сбое записи файла — это запрещено PRD. Принят file-first.

**Edge case:** крэш между `storeAs` и `delete` в catch оставит «голый» файл, недостижимый из БД (UUID-имя не подобрать). Смягчение: периодический janitor-крон, удаляющий `storage/app/meetings/*/*`, для которого нет записи в `meeting_files` (вне scope v1).

### 1.9. Storage в тестах

**`Storage::fake('local')`** (`vendor/illuminate/support/Facades/Storage.php:94–113`):
- Дефолтный корень = `storage_path('framework/testing/disks/local')` (вне продового `storage/app`).
- `cleanDirectory($root)` перед каждым `fake()`.
- Ре-биндит `filesystem.disk('local')` в контейнере на local-адаптер поверх тестовой папки.

**`setUp()` в `MeetingFilesTest`:**

```php
protected function setUp(): void
{
    parent::setUp();
    Storage::fake('local');
}
```

Плюс существующий `use DatabaseMigrations` (см. `MeetingsTest.php:10`) — изоляция БД и хранилища в каждом тесте.

**Мок сбоя БД** (PRD: `test_failed_db_write_rolls_back_disk_file`). Eloquent-событие проще и надёжнее Mockery-мока:

```php
MeetingFile::creating(function () {
    throw new \RuntimeException('boom');
});
// → file на диске должен отсутствовать:
//   Storage::disk('local')->assertMissing(...)
```

`creating` срабатывает **до** INSERT в БД, поэтому нам не нужны ни DB-constraint'ы, ни реальное исключение Postgres — событие само бросает исключение, контроллер ловит, удаляет файл. Детерминированно.

**`Storage::disk('local')->assertExists/assertMissing`** — стандартный API fake-адаптера (`Illuminate\Filesystem\FilesystemAdapter`).

### 1.10. Routes

Внутри уже существующей `auth`-группы в `backend/routes/web.php:18–22`:

```php
$router->group(['middleware' => 'auth', 'prefix' => 'api'], function () use ($router) {
    $router->get   ('/meetings/{id}/files',            'MeetingFileController@index');
    $router->post  ('/meetings/{id}/files',            'MeetingFileController@store');
    $router->get   ('/meetings/{id}/files/{fileId}',   'MeetingFileController@download');
    $router->delete('/meetings/{id}/files/{fileId}',   'MeetingFileController@destroy');
});
```

`auth` middleware первым делом 401-ит неаутентифицированные запросы (см. `Authenticate.php:17–19`), что закрывает требование «401 без токена для всех 4 эндпоинтов».

---

## 2. Frontend (Vue 3 + PrimeVue 4)

### 2.1. Что установлено (проверено)

- `primevue@4.5.x` (объявлен `^4.3.3` в `package.json:15`).
- `@primevue/themes@4.5.x` (Aura preset).
- `@primevue/icons@4.5.x` (транзитивно).
- **`primeicons` НЕ установлен** (нет в `package.json`, нет в `node_modules`). Используемые сейчас `pi pi-calendar`/`pi pi-user`/`pi pi-sign-out` (в `MeetingsList.vue:67`, `App.vue`) **не рендерят глифы** — silent fallback. **Нужно исправить** перед показом иконок файлов.
- Никаких router, Pinia, Axios.

### 2.2. `FileUpload` — проверенный API (PrimeVue 4)

Источник: `node_modules/primevue/fileupload/{BaseFileUpload.vue, FileUpload.vue, index.d.ts}`.

Используемые пропы (все существуют в PrimeVue 4, подтверждено интерфейсом `FileUploadProps`):

```html
<FileUpload
  ref="uploader"
  mode="basic"
  :multiple="false"
  :auto="true"
  :customUpload="true"
  :maxFileSize="200 * 1024 * 1024"
  accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.gif,.webp,.svg,
         .txt,.csv,.md,.zip,
         .mp3,.m4a,.wav,.ogg,.flac,.aac,
         .mp4,.mov,.webm,.mkv,.avi"
  chooseLabel="Выбрать файл"
  chooseIcon="pi pi-paperclip"
  :showUploadButton="false"
  :showCancelButton="false"
  :invalidFileSizeMessage="'{0}: файл больше допустимого размера'"
  :invalidFileTypeMessage="'{0}: недопустимый тип файла'"
  :disabled="uploading"
  @select="onSelect"
  @uploader="onUploader"
  @error="onUploadError"
  @clear="onClear"
/>
```

**Жизненный цикл (проверено в `FileUpload.vue:140–176`):**
1. `@select` — `{ originalEvent, files: File[] }` — выбор файла, валидация уже прошла внутри PrimeVue.
2. Из-за `auto + customUpload` FileUpload после select вызывает свой внутренний `uploader()`, который **только эмитит `@uploader` с `{ files }`** и **не делает XHR**. Наш колбэк полностью владеет запросом.
3. `upload`/`progress`/`before-send`/`before-upload` срабатывают **только** при `customUpload=false` — мы их не используем.

**`maxFileSize` — клиентский only.** Это UX-подсказка, не security. Сервер 422-ит реальное превышение. `accept` — тоже UI-hint, не security.

### 2.3. `@uploader` — реальный прогресс через `XMLHttpRequest`

`fetch` не умеет `upload.onprogress`. Для видимого прогресс-бара — `XMLHttpRequest`. Это **платформенный примитив**, не библиотека. Правило «no HTTP client» в `frontend/CLAUDE.md` трактуется как «no Axios-обёртка» — `fetch` уже используется, XHR не хуже.

**`frontend/src/api/uploadMeetingFile.js`:**

```js
import { auth } from "../store/auth.js";

const API_URL = import.meta.env.VITE_API_URL || "http://localhost:8081/api";

export function uploadMeetingFile(meetingId, file, label, { onProgress } = {}) {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open("POST", `${API_URL}/meetings/${meetingId}/files`);
    xhr.setRequestHeader("Accept", "application/json");
    xhr.setRequestHeader("Authorization", `Bearer ${auth.token}`);
    // ❗ НЕ устанавливать Content-Type — браузер сам сгенерирует boundary.
    xhr.upload.onprogress = (e) => {
      if (e.lengthComputable && typeof onProgress === "function") {
        onProgress(Math.round((e.loaded / e.total) * 100));
      }
    };
    xhr.onload = () => {
      const body = (() => {
        try { return JSON.parse(xhr.responseText); } catch { return {}; }
      })();
      if (xhr.status >= 200 && xhr.status < 300) resolve(body);
      else reject({
        status: xhr.status,
        message: body.message || `HTTP ${xhr.status}`,
        errors: body.errors || null,
      });
    };
    xhr.onerror = () => reject({ status: 0, message: "Network error" });
    xhr.onabort = () => reject({ status: 0, message: "aborted" });

    const fd = new FormData();
    fd.append("file", file);
    if (label) fd.append("label", label);
    xhr.send(fd);
  });
}
```

**В компоненте (`<script setup>` `MeetingDetails.vue`):**

```js
async function onUploader(e) {
  const file = e.files?.[0];
  if (!file) return;
  uploading.value = true;
  progress.value = 0;
  errorMessage.value = "";
  try {
    await uploadMeetingFile(props.meetingId, file, label.value, {
      onProgress: (p) => (progress.value = p),
    });
    dialogVisible.value = false;
    selectedFile.value = null;
    label.value = "";
    await loadFiles();
    toast.add({ severity: "success", summary: "Загружено", life: 2500 });
  } catch (err) {
    if (err.status === 401) return handleUnauthorized(err);
    errorMessage.value = err.message || "Ошибка загрузки";
    toast.add({ severity: "error", summary: "Ошибка", detail: err.message, life: 4000 });
  } finally {
    uploading.value = false;
    progress.value = 0;
  }
}
```

`:disabled="uploading"` на `FileUpload` + `closable: !uploading` на `Dialog` блокируют двойную отправку.

### 2.4. Toast & ConfirmDialog — критические правки

PrimeVue 4: `$toast` (Vue 2) → `useToast()` composable; `$confirm` → `useConfirm()`. **Сервисы НЕ зарегистрированы** в `frontend/src/main.js:1–13`. Без этого `useToast()`/`useConfirm()` бросают ошибку.

**`frontend/src/main.js` — добавить:**

```js
import ToastService from "primevue/toastservice";
import ConfirmationService from "primevue/confirmationservice";
// ...
app.use(PrimeVue, { theme: { preset: Aura } });
app.use(ToastService);
app.use(ConfirmationService);
```

**`frontend/src/App.vue` — добавить в `<template>` (один раз, вне `v-if`):**

```vue
<Toast position="top-right" />
<ConfirmDialog />
```

**Иконки** — нужен `primeicons`. Без него глифы не рендерятся.

```bash
npm install primeicons
```

```js
// main.js, добавить:
import "primeicons/primeicons.css";
```

**Маппинг MIME → иконка** (при наличии `primeicons`):

| MIME prefix | Icon class |
|---|---|
| `image/` | `pi pi-image` |
| `video/` | `pi pi-video` |
| `audio/` | `pi pi-volume-up` |
| `application/pdf` | `pi pi-file-pdf` |
| `application/zip` | `pi pi-box` |
| `text/` | `pi pi-file` |
| прочее | `pi pi-file` |

**Использование:**

```js
import { useToast } from "primevue/usetoast";
import { useConfirm } from "primevue/useconfirm";

const toast = useToast();
const confirm = useConfirm();

function askDelete(file) {
  confirm.require({
    message: `Удалить «${file.original_name}»?`,
    header: "Удаление",
    icon: "pi pi-exclamation-triangle",
    acceptLabel: "Удалить",
    rejectLabel: "Отмена",
    acceptClass: "p-button-danger",
    accept: async () => {
      try {
        await deleteMeetingFile(props.meetingId, file.id);
        await loadFiles();
        toast.add({ severity: "success", summary: "Удалено", life: 2500 });
      } catch (err) {
        if (err.status === 401) return handleUnauthorized(err);
        toast.add({ severity: "error", summary: "Ошибка", detail: err.message });
      }
    },
  });
}
```

### 2.5. Диалог загрузки — Dialog

```vue
<Dialog
  v-model:visible="dialogVisible"
  modal
  :closable="!uploading"
  :closeOnEscape="!uploading"
  :dismissableMask="!uploading"
  header="Загрузить файл"
  :style="{ width: '480px' }"
  :breakpoints="{ '640px': '95vw' }"
>
  <div class="upload-body">
    <label class="field">
      <span class="field-label">Название (необязательно)</span>
      <InputText v-model="label" :disabled="uploading" maxlength="255" />
    </label>

    <FileUpload ... />

    <small v-if="selectedFile" class="hint">
      Лимит для {{ categoryLabel }}: {{ formatLimit(limitBytes) }}
    </small>

    <ProgressBar
      v-if="uploading"
      :value="progress"
      :showValue="true"
    />

    <Message
      v-if="errorMessage && !uploading"
      severity="error"
      :closable="false"
    >
      {{ errorMessage }}
    </Message>
  </div>

  <template #footer>
    <Button
      label="Отмена"
      severity="secondary"
      :disabled="uploading"
      @click="dialogVisible = false"
    />
  </template>
</Dialog>
```

`limitBytes` вычисляется из `selectedFile.type`:
- `image/*` → 20 МБ
- `video/*` → 200 МБ
- `audio/*` → 200 МБ (в v1 — для прагматизма; PRD допускает такой лимит)
- остальные → 20 МБ

`formatLimit(bytes)` — `formatSize()` в человекочитаемый вид (КБ/МБ, 1 знак).

### 2.6. Список файлов — НЕ `DataView`, обычный `v-for`

PRD говорит «DataView строк», но для 0–20 строк с inline-`<audio>`/`<video>` проще и легче flat-список. PrimeVue `DataView` (есть в `node_modules/primevue/dataview/`) принуждает `list`/`grid` режимы, не очевидно слотуется с медиа. Рекомендация: `v-for` по массиву, отдельный компонент `FileRow` для строки.

```vue
<ul class="file-list" v-if="files.length">
  <FileRow
    v-for="f in files"
    :key="f.id"
    :file="f"
    :meeting-id="meetingId"
    @deleted="onDeleted"
    @downloaded="onDownloaded"
  />
</ul>
<p v-else class="file-empty">Файлов пока нет.</p>
```

### 2.7. Скачивание — `Content-Disposition` парсинг

Symfony `BinaryFileResponse` выставляет оба варианта — legacy `filename="…"` и RFC 5987 `filename*=UTF-8''…`. Парсим оба:

```js
export async function downloadMeetingFile(meetingId, fileId, fallbackName) {
  const res = await fetch(`${API_URL}/meetings/${meetingId}/files/${fileId}`, {
    headers: { Authorization: `Bearer ${auth.token}` },
  });
  if (!res.ok) {
    const body = await res.json().catch(() => ({}));
    throw { status: res.status, message: body.message || `HTTP ${res.status}` };
  }
  const disp = res.headers.get("Content-Disposition") || "";
  const star  = /filename\*=UTF-8''([^;]+)/i.exec(disp);
  const plain = /filename="?([^";]+)"?/i.exec(disp);
  const raw   = star ? star[1] : plain ? plain[1] : null;
  const name  = raw ? decodeURIComponent(raw) : fallbackName || `file-${fileId}`;

  const blob = await res.blob();
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url; a.download = name;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1500);
}
```

`<a :download="…">` работает для same-origin (наш случай — идём через Nginx-прокси).

### 2.8. ⚠️ Конфликт media-preview с auth-моделью

**Суть:** все бэкенд-эндпоинты требуют `Authorization: Bearer <token>`. HTML-`<audio src="…">` и `<video src="…">` шлют простой GET без кастомных хедеров → 401 → тишина.

| Вариант | Плюсы | Минусы | Вердикт |
|---|---|---|---|
| (a) `?token=…` query | одна строка на фронте | утечка токена в history, server logs, `Referer` | ❌ для продакшна |
| (b) Cookie-based session | стандарт web-auth | переписывать `Authenticate` middleware; вне scope | ⏸ отложить |
| (c) `fetch → blob → URL.createObjectURL` | без изменений бэка; работает с bearer | весь файл в памяти (200 МБ видео = плохо) | ✅ **для маленьких файлов** |
| (d) `MediaSource` + `Response.body.getReader()` | стрим, мало памяти | ~80 строк сложного кода; не v1 | ⏸ отложить в v2 |
| (e) Без preview для крупных | просто; покрывает 200 МБ | нет превью у 200 МБ видео | ✅ **прагматичный компромисс** |
| (f) Signed URL (`?exp=…&sig=HMAC(file_id\|exp)`) | GET без хедеров; TTL | новый эндпоинт; +1 день | ⏸ **рекомендация для v2** |

**План v1 (комбинация c + e):**

1. **Изображения** и **аудио/видео < 5 МБ** — `fetch → blob → URL.createObjectURL`, рендерим в `<audio>/<video>`/`<img>`. На unmount чистим `URL.revokeObjectURL` чтобы не текла память.
2. **Видео ≥ 5 МБ** и **аудио ≥ 5 МБ** — строка БЕЗ inline preview, кнопка `pi pi-external-link` «Открыть» в новой вкладке. **Проблема:** новая вкладка тоже упрётся в 401. **Workaround для v1:** endpoint download должен поддерживать query-параметр `?disposition=inline` — для inline-типов (audio/video) возвращать `Content-Disposition: inline` вместо `attachment`. Тогда новая вкладка показывает нативный плеер, **но** всё равно 401 без токена. Реальное решение — **signed URL в v2**.
3. **Картинки** всегда — через blob-URL flow (c). Изображения маленькие, проблемы памяти нет.
4. **Документировать** trade-off в README: «inline превью ограничено 5 МБ до реализации signed URL в v2».

**Helper для blob-URL:**

```js
async function ensureBlobUrl(fileId) {
  if (blobCache.has(fileId)) return blobCache.get(fileId);
  const res = await fetch(`${API_URL}/meetings/${props.meetingId}/files/${fileId}`, {
    headers: { Authorization: `Bearer ${auth.token}` },
  });
  if (!res.ok) throw { status: res.status, message: res.statusText };
  const blob = await res.blob();
  const url = URL.createObjectURL(blob);
  blobCache.set(fileId, url);
  return url;
}
```

`onBeforeUnmount` в `FileRow.vue` — обязательно `URL.revokeObjectURL(...)` для всех blob-URL компонента, иначе утечка.

### 2.9. Глобальный 401

Сейчас `MeetingsList.vue:32–36` показывает ошибку в `<Message>`, не разлогинивает. PRD требует «401 → редирект на логин» (в нашей безроутерной модели — очистка `auth.token` → `<AuthForm v-if="!isAuthenticated()" />`).

**Минимальная правка — helper в `api/`:**

```js
import { clearSession } from "../store/auth.js";

export function handleUnauthorized() {
  clearSession();
  // toast — через useToast() внутри компонента, не здесь
}
```

В каждом catch:

```js
} catch (err) {
  if (err.status === 401) return handleUnauthorized();
  // …
}
```

### 2.10. Структура компонентов

**Сейчас в `frontend/src/components/`:**
- `AuthForm.vue`, `HelloWorld.vue`, `MeetingsList.vue` (118 строк, `.slice(0, 3)`).

**Нет `MeetingDetails.vue`.** Нужен — для UI файлов.

**Рекомендация:** новый `MeetingDetails.vue`, выбор встречи — `v-if` в `App.vue` (`selectedId` ref, эмитится из `MeetingsList` через `@select="selectedId = $event"`). **НЕ** встраивать в `MeetingsList` — он уже ограничен 3 элементами, разделит ответственности чище.

State в `MeetingDetails.vue` — локальные `ref(files)`, `ref(dialogVisible)`, `ref(uploading)`, `ref(progress)`, `ref(errorMessage)`.

### 2.11. Полный `frontend/src/api/meetingFiles.js`

```js
import { auth } from "../store/auth.js";

const API_URL = import.meta.env.VITE_API_URL || "http://localhost:8081/api";

async function request(path, options = {}) {
  const headers = {
    Accept: "application/json",
    Authorization: auth.token ? `Bearer ${auth.token}` : "",
    ...(options.headers || {}),
  };
  const res = await fetch(`${API_URL}${path}`, { ...options, headers });
  if (res.status === 204) return null;
  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    throw { status: res.status, message: data.message || "Request failed", errors: data.errors || null };
  }
  return data;
}

export function listMeetingFiles(meetingId) {
  return request(`/meetings/${meetingId}/files`);
}

export function deleteMeetingFile(meetingId, fileId) {
  return request(`/meetings/${meetingId}/files/${fileId}`, { method: "DELETE" });
}

export { uploadMeetingFile } from "./uploadMeetingFile.js";
export { downloadMeetingFile } from "./downloadMeetingFile.js";
```

---

## 3. Infrastructure (Nginx, PHP-FPM, Docker, Postgres)

### 3.1. Nginx — `nginx/default.conf` (полная замена)

**Текущее состояние** (`nginx/default.conf:1–22`): 22 строки, `root /var/www/public`, `try_files $uri $uri/ /index.php?$query_string`, `\.php$` → `backend:9000`. **Нет** лимитов, таймаутов, buffering-overrides, явного `/storage/` deny.

**Гэпы для 200 МБ:**

| Директива | Default | Почему ломает 200 МБ |
|---|---|---|
| `client_max_body_size` | `1m` | HTTP 413 от Nginx до PHP |
| `client_body_buffer_size` | `8k` | body в temp-файл, лишний I/O |
| `client_body_timeout` | `60s` | аборт медленных 200 МБ |
| `fastcgi_buffering` | `on` (8×16k) | 200 МБ response буферизуется в Nginx RAM |
| `fastcgi_read_timeout` | `60s` | аборт длинных downloads |
| `fastcgi_request_buffering` | `on` | 200 МБ body в temp перед PHP-FPM |
| `location ~ ^/storage/` | отсутствует | storage сейчас неявно 404 (через `try_files`); нужен явный deny |

**Drop-in `nginx/default.conf`:**

```nginx
# Лимиты действуют на весь server: 220m матчит upload_max_filesize в PHP.
# Больше 220m — Nginx вернёт 413 ДО того, как PHP увидит запрос.
server {
    listen 80;
    index index.php index.html;
    server_name localhost;
    root /var/www/public;

    # ---- Upload лимиты (должны матчиться с backend/docker/php.ini) ----
    # +20M запас на multipart envelope и form-поля сверх 200M файла.
    client_max_body_size 220m;
    # 128k в RAM, остальное в temp-путь. Default 8k → temp-файл почти всегда.
    client_body_buffer_size 128k;
    # 5 минут на приём 200M с медленного клиента.
    client_body_timeout 300s;
    # Default 60s — ок.
    client_header_timeout 60s;

    # ---- Static defense in depth ----
    # Storage никогда не отдаётся Nginx напрямую. Файлы идут клиенту
    # только после прохождения PHP auth-проверки. Прямой GET /storage/...
    # должен быть отвергнут, а не молча упасть в /index.php.
    location ~ ^/storage/ {
        deny all;
        return 404;
    }

    # ---- Front controller ----
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # ---- PHP-FPM ----
    location ~ \.php$ {
        fastcgi_pass backend:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param PATH_INFO $fastcgi_path_info;
        include fastcgi_params;

        # Стрим 200M-ответа напрямую от PHP-FPM клиенту.
        # С buffering on Nginx держал бы весь файл в RAM.
        fastcgi_buffering off;
        # Явный размер буферов на случай, если в будущем вернём buffering on.
        fastcgi_buffers 8 16k;
        # 5 минут на отдачу тела PHP-FPM'ом. Default 60s режет длинные downloads.
        fastcgi_read_timeout 300s;
        # Форвардить байты PHP-FPM'у по мере прихода. Default "on" пишет
        # все 200M в temp-файл. Trade-off: при обрыве клиента PHP не узнает.
        fastcgi_request_buffering off;
    }

    location ~ /\.ht {
        deny all;
    }
}
```

`client_max_body_size` ставим на server-блок (после `server_name localhost;`, строка 4 текущего файла), **не** внутрь `location ~ \.php$`. Глобальный cap лучше `0;` (unlimited) внутри PHP-location — иначе API становится DoS-таргетом.

### 3.2. PHP-FPM — `backend/docker/php.ini` (новый файл)

`php:8.3-fpm-alpine` загружает все `*.ini` из `/usr/local/etc/php/conf.d/` лексикографически. Префикс `zz-` гарантирует загрузку последним → override дефолтов из `php.ini-production`.

```ini
; Upload лимиты — должны матчиться с nginx/default.conf: client_max_body_size 220m
; Файл живёт в /usr/local/etc/php/conf.d/zz-uploads.ini в контейнере.
; 220M даёт 20M запаса сверх 200M на multipart envelope и form-поля.
upload_max_filesize = 220M
post_max_size       = 240M

; Headroom для парсинга формы; BinaryFileResponse сам по себе не буферизует.
memory_limit        = 256M

; 5 минут на 200M upload с медленного клиента и на стрим + sha256 на выдаче.
max_input_time      = 300
max_execution_time  = 300

; sendContent() в BinaryFileResponse делает flush по чанку, но Off снимает
; целый класс багов "headers already sent".
output_buffering    = Off
```

### 3.3. Backend image — `backend/Dockerfile` (патч)

`fileinfo` собирается по умолчанию в `php:8.3-fpm-alpine` (`--enable-fileinfo`). Дополнительных `docker-php-ext-install` не требуется.

**Изменение:** добавить одну строку `COPY docker/php.ini …` перед `COPY docker-entrypoint.sh …` (после существующего `COPY . .` на строке 14):

```dockerfile
FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
    postgresql-dev \
    && docker-php-ext-install pdo pdo_pgsql

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

COPY composer.json composer.lock* ./
RUN composer install --no-interaction --optimize-autoloader

COPY . .

# Upload лимиты. zz- префикс — файл грузится последним из conf.d/*.ini,
# его значения перебивают базовый php.ini-production.
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-uploads.ini

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 9000

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php-fpm"]
```

**Warning для будущих maintainer'ов:** любой рефакторинг с multi-stage build в runtime-stage **не должен** делать `rm -rf /var/www/storage` или `VOLUME ["/var/www/storage"]` — это data-loss footgun.

### 3.4. Entrypoint — `backend/docker-entrypoint.sh` (патч)

`chown` на строке 6 покрывает `storage/app/meetings/`, потому что Flysystem создаёт подпапки лениво, а chown запускается на каждом старте → новые каталоги наследуют `www-data:www-data`. **Добавить `chmod -R 775`** для group-writable (если когда-нибудь появится воркер под другим UID, но в общей группе):

```sh
#!/bin/sh
set -e

composer install --no-dev --no-interaction --optimize-autoloader

# storage/app/ — куда Flysystem пишет meeting-файлы.
# chown на каждом старте: свежесозданные каталоги (storage/app/meetings/)
# наследуют правильного владельца. 02775 — group-writable.
chown -R www-data:www-data /var/www/storage
chmod -R 775 /var/www/storage

if [ -z "$APP_KEY" ] || [ "$APP_KEY" = "base64:CHANGE_ME_GENERATE_WITH_32_CHAR_STRING" ]; then
    APP_KEY=$(php -r "echo 'base64:' . base64_encode(random_bytes(32));")
    export APP_KEY
    echo "APP_KEY=$APP_KEY" > /var/www/.env
fi

php artisan migrate --force 2>/dev/null || true

exec "$@"
```

`composer install --no-dev` на каждом старте **безопасен**: трогает только `vendor/` на хосте (через bind-mount, `docker-compose.yml:42`) → `storage/` не задевается.

### 3.5. Volume persistence

`docker-compose.yml:42` (backend) и `:57` (nginx) монтируют `./backend:/var/www`. → `storage/app/meetings/...` живёт на хосте по `./backend/storage/app/meetings/...`.

- Переживает `docker compose restart backend`, `down && up`, `system prune` (нет анонимных/именованных volume'ов на этот путь).
- `docker compose build --no-cache` производит новый image, в нём `COPY . .` создаёт копию `storage/`, **но** bind-mount при старте контейнера оверрайдит хостовым путём → хостовые данные побеждают, потерь нет.
- **Не** переживает `docker compose down -v` (удаление volume'ов — но volume с именем только у `postgres_data`).
- Dual-mount в nginx делает явный `deny all` осмысленным (Nginx-контейнер физически видит файлы на диске).

**Trade-off bind-mount кода:** хостовая машина должна иметь рабочий `composer.json`/`composer.lock` и совместимый PHP. Это dev-only паттерн. Для прода переключиться на named volume для `vendor/` + build step. **Out of scope v1.**

### 3.6. `X-Accel-Redirect` — НЕ используем в v1

Паттерн: PHP возвращает хедер `X-Accel-Redirect: /__internal_files__/meetings/42/abc.mp4`, Nginx матчит `location /__internal_files__/ { internal; alias /var/www/storage/app/; }` и стримит через `sendfile()` (zero-copy на Linux).

Технически возможно (storage под тем же root), но:
- Усложняет security-границу (нужен internal location с alias).
- `BinaryFileResponse` + `fastcgi_buffering off` уже даёт сопоставимую скорость на локальном Docker bridge.
- Auth-check всё равно остаётся в PHP.

**Рекомендация:** не в v1. Пересмотреть в v2, если download latency станет tracked metric.

### 3.7. Postgres — миграция `meeting_files`

Существующая `meetings` (см. `database/migrations/2026_07_08_000003_create_meetings_table.php:10–17`) — `id`, `user_id` (FK CASCADE на `users`), `title`, `description`, `scheduled_at`, `timestamps`. Этого parent'а достаточно.

**Новая миграция** `2026_xx_xx_xxxxxx_create_meeting_files_table.php`:

```php
Schema::create('meeting_files', function (Blueprint $table) {
    $table->id();
    $table->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();
    $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
    $table->string('original_name', 255);
    $table->string('stored_name', 100)->unique();   // uuid.ext
    $table->string('stored_path');                    // meetings/{id}/{stored_name}
    $table->string('mime_type', 100);
    $table->bigInteger('size_bytes');
    $table->string('label', 255)->nullable();
    $table->timestamps();

    $table->index(['meeting_id', 'created_at']);
    $table->index('user_id');
});
```

Два столбца (`stored_name` + `stored_path`) рекомендованы вместо одного: `stored_name` — глобально уникальное имя файла (для UNIQUE), `stored_path` — относительный путь от `storage/app/`. Разделение снимает неоднозначность при будущих рефакторингах (например, перенос в подпапки по году/месяцу).

**`created_at` индекс по убыванию** (для сортировки `ORDER BY created_at DESC` в list-эндпоинте): BTree-индекс по умолчанию двунаправленный, отдельный `DESC`-вариант не нужен — планировщик Postgres использует индекс в обоих направлениях.

### 3.8. Performance vs PRD-критерии

| Критерий | Ожидаемое время на dev-окружении | Узкое место |
|---|---|---|
| PDF 20 МБ upload < 5 с | 2–3 с | `client_body_timeout` и `max_input_time` (подняты до 300 с) |
| MP4 200 МБ upload < 30 с | 8 с @ 200 Mbps, 20–40 с на dev disk-bound | те же таймауты |
| Список 100 файлов < видимая задержка | <1 с | только индекс `(meeting_id, created_at)` |
| Inline audio/video preview | работает только для < 5 МБ (см. §2.8) | — |

Chunked upload — out of scope v1, не provisioned.

### 3.9. Логирование

`App\Exceptions\Handler.php:12–25` extends `Laravel\Lumen\Exceptions\Handler`, **не** переопределяет `report()`. Lumen default логгер пишет в `storage/logs/lumen.log` (Monolog stream handler, single file). `Log::info('meeting_file.uploaded', [...])` попадает туда.

Bind-mount (`docker-compose.yml:42`) покрывает `storage/logs/` → переживает рестарты. Daily rotation / JSON output — v2, v1 держим single-file.

### 3.10. Time / NTP

`bootstrap/app.php:9` → `date_default_timezone_set('UTC')`. Postgres хранит `TIMESTAMPTZ` в UTC. Frontend форматирует через `toLocaleString()` в локали браузера. Audit-логи — UTC, документировать.

---

## 4. Open questions и конфликты плана

### Q1. Inline `<audio>` / `<video>` preview с bearer-токеном (КОНФЛИКТ)

**Проблема.** PRD (Фаза 8, шаг 8.2) требует:

> Для `audio/*` — нативный `<audio controls preload="none" :src="downloadUrl(file.id)">` под строкой
> Для `video/*` — нативный `<video controls preload="metadata" :src="downloadUrl(file.id)">` под строкой

Но все бэкенд-эндпоинты защищены `Authorization: Bearer <token>`. HTML-`<audio>`/`<video>` НЕ шлют кастомные хедеры → 401.

**Рекомендация v1:** BLOB-URL flow для файлов < 5 МБ; для крупных видео — кнопка «Открыть в новой вкладке» (тоже упирается в 401, см. §2.8).

**Рекомендация v2 (правильное решение):** **signed URL**. Бэкенд генерирует `?exp=<unix_ts>&sig=HMAC-SHA256(secret, file_id|user_id|exp)`, отдаёт в `GET /api/meetings/{id}/files` list-response. Новый эндпоинт `GET /storage-inline/meetings/{id}/{fileId}` валидирует HMAC + срок (TTL 1 час), стримит файл. Frontend использует прямой URL в `<audio>`/`<video>`. Без изменений auth-модели, без blob-in-memory.

**Стоимость:** ~1 день работы. Бэкенд: новый метод в `MeetingFileController` + middleware `ValidateSignedUrl`. Фронт: ничего, только `file.download_url` вместо конструкции URL на лету.

### Q2. `primeicons` не установлен

В `package.json` и `node_modules` пакета `primeicons` нет. Существующие `pi pi-calendar`/`pi pi-user`/`pi pi-sign-out` сейчас не рендерят глифы. **Без `npm install primeicons + import primeicons.css` в `main.js` иконки файлов работать не будут.**

**Рекомендация:** добавить в `package.json:14-16` (`dependencies`):

```json
"primeicons": "^7.0.0"
```

### Q3. Toast/ConfirmDialog services не зарегистрированы

`main.js` не вызывает `app.use(ToastService)`/`app.use(ConfirmationService)`. Без них `useToast()`/`useConfirm()` бросают runtime-ошибку. `App.vue` не монтирует `<Toast />`/`<ConfirmDialog />`.

**Рекомендация:** патч `main.js` + `App.vue` (см. §2.4). **Без этого этапы 6–8 плана не запустятся в UI.**

### Q4. Path-traversal check

PRD/план не уточняют, что `stored_name` генерируется на бэке как UUID (не из пользовательского ввода), поэтому `..` туда теоретически попасть не может. **Defense in depth** через `realpath()` + prefix check рекомендуется явно добавить в §1.6 как best practice.

### Q5. Расширение файла: client original vs finfo

Если в `accept`-атрибуте указан `.txt`, а finfo говорит `text/plain`, расширение берём из MIME. Если finfo не распознал (например, exotic формат вне allow-list) — это уже ошибка валидации.

**Рекомендация:** НЕ делать `pathinfo($original, PATHINFO_EXTENSION)` (ненадёжно, лжёт). Использовать только `$uploaded->guessExtension()` **после** успешной проверки MIME.

### Q6. UX лимита в диалоге — какие значения показывать

PRD-таблица лимитов:
- document/image/text/archive = 20 МБ
- audio/video = 200 МБ

Но фронт не может знать категорию до того, как файл выбран. По `selectedFile.type` (ненадёжный client-MIME):
- `image/*` → 20 МБ
- `audio/*` → 200 МБ
- `video/*` → 200 МБ
- прочие → 20 МБ

Это совпадает с PRD для image/audio/video. Для document (PDF/DOC/XLS) client-MIME-префикс `application/...` → попадёт в «прочие» → 20 МБ. **Корректно.** Только edge case: client выдаёт `file.type = ''` (unknown). В этом случае показываем 20 МБ (минимальный), сервер всё равно 422-ит если больше.

### Q7. `?disposition=inline` для медиа

В §2.8 предложено: для audio/video endpoint принимает query-параметр `?disposition=inline` и возвращает `Content-Disposition: inline` вместо `attachment` (тогда новая вкладка с видео показывает нативный плеер). Но **всё равно** упрётся в 401 без токена. **Это не решение**, только work-around на будущее (когда будет signed URL).

**Рекомендация:** **НЕ** добавлять `?disposition=inline` в v1. Только лишний код. До v2 inline preview — только blob-URL.

### Q8. PDF 5 МБ → p95 ≤ 3 с (метрика успеха)

PRD метрика (через 30 дней): «p95 времени загрузки PDF 5 МБ ≤ 3 с». На dev-окружении 5 МБ ≈ 0.5 с. В проде зависит от ширины канала клиента → для браузерного пользователя на 4G 5 МБ ≈ 1–2 с — в пределах нормы. **Backend не нужно дополнительно оптимизировать** для этой метрики в v1.

---

## 5. Карта файлов к правке

### 5.1. Backend

| Файл | Действие | Назначение |
|---|---|---|
| `backend/database/migrations/2026_xx_xx_xxxxxx_create_meeting_files_table.php` | NEW | схема `meeting_files` (см. §3.7) |
| `backend/app/Models/MeetingFile.php` | NEW | Eloquent + relations |
| `backend/app/Models/Meeting.php` | edit | добавить `files(): HasMany` |
| `backend/app/Models/User.php` | edit | добавить `uploadedMeetingFiles(): HasMany` |
| `backend/app/Services/FileValidationService.php` | NEW | finfo + лимиты + санитизация |
| `backend/app/Http/Controllers/MeetingFileController.php` | NEW | 4 эндпоинта |
| `backend/routes/web.php` | edit | добавить 4 маршрута в `auth` группу |
| `backend/config/files.php` | NEW | категории MIME + max_size |
| `backend/config/filesystems.php` | OPTIONAL | если хотим явно зафиксировать диск (не обязательно) |
| `backend/tests/Feature/MeetingFilesTest.php` | NEW | 25 тестов по плану |
| `backend/bootstrap/app.php` | OPTIONAL edit | `$app->configure('files')` для config('files') — не критично, framework загрузит и без явного вызова |
| `backend/Dockerfile` | edit | добавить `COPY docker/php.ini` (см. §3.3) |
| `backend/docker/php.ini` | NEW | upload лимиты (см. §3.2) |
| `backend/docker-entrypoint.sh` | edit | `chmod -R 775 /var/www/storage` (см. §3.4) |

### 5.2. Frontend

| Файл | Действие | Назначение |
|---|---|---|
| `frontend/package.json` | edit | `+ "primeicons": "^7.0.0"` |
| `frontend/src/main.js` | edit | `+ import "primeicons/primeicons.css"`, `+ app.use(ToastService)`, `+ app.use(ConfirmationService)` |
| `frontend/src/App.vue` | edit | `+ <Toast position="top-right" />`, `+ <ConfirmDialog />` (один раз, вне `v-if`) |
| `frontend/src/components/MeetingDetails.vue` | NEW | детали встречи + список файлов + кнопка «Загрузить» |
| `frontend/src/components/MeetingFileList.vue` (или встроенный в Details) | NEW | строки файлов с иконкой/мета/действиями |
| `frontend/src/components/MeetingFileRow.vue` | NEW (опц.) | одна строка, удобно для слотов `<audio>`/`<video>` |
| `frontend/src/api/meetings.js` | edit (опц.) | добавить `handleUnauthorized()` helper, или вынести в `api/http.js` |
| `frontend/src/api/meetingFiles.js` | NEW | `listMeetingFiles`, `deleteMeetingFile` (через общий `request()`) |
| `frontend/src/api/uploadMeetingFile.js` | NEW | XHR с прогрессом (см. §2.3) |
| `frontend/src/api/downloadMeetingFile.js` | NEW | fetch + Content-Disposition парсинг (см. §2.7) |
| `frontend/src/components/MeetingsList.vue` | edit | `@select="$emit('select', meeting)"` → App.vue |
| `frontend/src/App.vue` | edit (опц.) | `v-if="selectedId"` switch MeetingsList ↔ MeetingDetails |

### 5.3. Infrastructure

| Файл | Действие | Назначение |
|---|---|---|
| `nginx/default.conf` | replace | полная замена на версию §3.1 |
| `backend/Dockerfile` | edit | см. §3.3 |
| `backend/docker/php.ini` | NEW | см. §3.2 |
| `backend/docker-entrypoint.sh` | edit | см. §3.4 |
| `docker-compose.yml` | NO CHANGE for v1 | (опц. в v2: named volume для `vendor/`) |

### 5.4. Documentation

| Файл | Действие |
|---|---|
| `README.md` | update — раздел «Как прикрепить файл», оговорка про локальное хранение, ссылка на `config/files.php` |
| `docs/meeting-files.md` (опц.) | NEW — детальная документация (если выносим из README) |
| `CLAUDE.md` | update — см. секцию ниже |

### 5.5. Обновление `CLAUDE.md`

По правилам `CLAUDE.md` (раздел «Documentation»), при изменениях архитектуры требуется синхронное обновление. Изменения после реализации плана:

- **Architecture diagram:** добавить `storage/app/meetings/...` путь (опционально, не меняет диаграмму сервисов).
- **Project Structure:** добавить `backend/app/Services/`, `backend/config/files.php`, `backend/docker/php.ini`, `frontend/src/api/meetingFiles.js`, `frontend/src/api/uploadMeetingFile.js`, `frontend/src/api/downloadMeetingFile.js`, `frontend/src/components/MeetingDetails.vue`, `frontend/src/components/MeetingFileList.vue`.
- **Services & Ports:** без изменений (порты те же).
- **Environment Variables:** добавить `FILES_MAX_SIZE_*` (опц., если выносим лимиты в env). В текущем плане — лимиты в `config/files.php`, без env.
- **Key Facts:** дополнить —
  - "Files module: 4 эндпоинта под `auth` middleware; локальное хранилище `storage/app/meetings/{id}/{uuid}.{ext}`; лимиты 20/200 МБ из `config/files.php`"
  - "MIME-проверка через `finfo` по содержимому"
  - "`primeicons` установлен, иконки через `pi pi-...` классы"
  - "Toast/ConfirmDialog services зарегистрированы"
  - "Nginx: `client_max_body_size=220m`, `fastcgi_buffering=off`"
  - "PHP-FPM: `upload_max_filesize=220M`, `post_max_size=240M`"

---

## Приложение А. Сводка тестового покрытия (по плану)

| Слой | Тип | Кол-во | Где фиксируется |
|---|---|---|---|
| Backend | PHPUnit Feature (`MeetingFilesTest`) | 25 | Фазы 1–4 (red → green в каждой фазе) |
| Backend | Bash/curl | 2 | Фаза 4 (Nginx-блокировка `/storage/`, restart-volume) |
| Frontend | Smoke-чеклист → Playwright MCP | 20 (5+7+8) | Фазы 6, 7, 8 (формализация) → Фаза 9 (прогон) |
| **Общее** | — | **47** | — |

## Приложение Б. Соответствие критериям готовности PRD → реализация

| Критерий PRD | Где покрыт |
|---|---|
| 4 эндпоинта | `routes/web.php` (auth-группа) + `MeetingFileController` |
| Валидация → 422 с понятным сообщением | `Validator::make` + `FileValidationService` (§1.7) |
| MIME через `finfo` | `UploadedFile::getMimeType()` (§1.3) |
| `original_name` санитизируется, диск = UUID | §1.4, §1.7 |
| Лимит по категории, не хардкод | `config/files.php` + `FileValidationService` (§1.5) |
| Удаление только загрузившему | `MeetingFileController::destroy` + тест `test_non_uploader_cannot_delete_file` |
| 401 без токена | `auth` middleware (уже есть) |
| Чужой файл → 403/404 | `meeting.user_id === currentUser.id` check в контроллере |
| Скачивание стримится через PHP; `/storage/` закрыт | `nginx/default.conf: location ~ ^/storage/` (deny) + `response()->download` |
| Path-traversal безопасен | `realpath()` + `Str::startsWith` (§1.6) |
| Миграция чисто накатывается/откатывается | standard Eloquent migration |
| Файлы переживают `docker compose restart backend` | bind-mount (`docker-compose.yml:42`) (§3.5) |
| Rollback файла при сбое БД | file-first + `Storage::delete` в catch (§1.8) |
| Удаление убирает и БД, и файл | `MeetingFileController::destroy` + `Storage::delete` |
| `config/files.php` существует, лимиты из него | §1.5 |
| Список: иконка, имя, размер, автор, время | `MeetingFileList.vue` (строки с PrimeIcons) |
| Диалог загрузки с `FileUpload` + подпись | `Dialog` + `FileUpload` + `InputText` (§2.5) |
| Блокировка диалога + прогресс во время upload | `closable: !uploading` + `ProgressBar` (XHR-driven) |
| Пустое состояние, тосты | `<p v-else>Файлов пока нет</p>` + `useToast` (§2.4) |
| Нативный `<audio>`/`<video>` | `<audio controls preload="none">` / `<video controls preload="metadata">` (§2.8, **ограничение: < 5 МБ**) |
| Удаление только загрузившему + `ConfirmDialog` | `confirm.require` в `useConfirm` (§2.4) |
| Лимит (20/200 МБ) в диалоге | `formatLimit(limitBytes)` под `FileUpload` |
| 100 файлов без лагов | `(meeting_id, created_at)` индекс + flat `v-for` |
| Автотесты зелёные | 25 тестов (Фазы 1–4) |
| PHP-CS-Fixer + Prettier | `composer format` / `npm run format` |
| Логи upload/download/delete | `Log::info('meeting_file.uploaded', [...])` (Фаза 4) |
| README обновлён | `docs/meeting-files.md` или раздел в `README.md` |
| PDF 20 МБ < 5 с | 2–3 с на dev (§3.8) |
| MP4 200 МБ < 30 с | 8–40 с на dev (§3.8) |
