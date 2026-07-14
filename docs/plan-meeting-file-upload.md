# План: Загрузка файлов встречи

**PRD:** @docs/prd-meeting-file-upload.md
**Дата:** 2026-07-15
**Подход:** TDD — на каждой фазе сначала пишутся/обновляются тесты (red), затем реализация (green). Backend — PHPUnit Feature-тесты. Frontend — формализованные smoke-критерии (стек не имеет автотестов); критерии выполняются через Playwright MCP в фазе 8.

## Фазы реализации

### Фаза 1: Backend Tracer Bullet — Загрузка и скачивание
**Цель:** Минимальный рабочий путь: загрузить файл по API и скачать его обратно с проверкой прав.
**Затрагивает:** backend, database

**Шаг 1.1 — Тесты (red):**
- [ ] Создать `backend/tests/Feature/MeetingFilesTest.php` с заготовкой: фабрики `User` + `Meeting`, трейт `DatabaseMigrations`, helper `actingAs($user)`
- [ ] Тест `test_user_can_upload_file_to_owned_meeting` — POST `multipart` с PDF → ожидаем 201, запись в `meeting_files`, файл на fake-диске
- [ ] Тест `test_unauthenticated_upload_is_rejected` — POST без токена → 401
- [ ] Тест `test_user_can_download_uploaded_file` — после upload GET на download-эндпоинт → 200, тело совпадает с загруженными байтами, заголовок `Content-Disposition: attachment; filename="..."`
- [ ] Тест `test_unauthenticated_download_is_rejected` — GET без токена → 401
- [ ] Запустить phpunit — все 4 теста падают (контроллера ещё нет, эндпоинты 404)

**Шаг 1.2 — Реализация (green):**
- [ ] Создать миграцию `create_meeting_files_table` (id, meeting_id FK CASCADE, user_id FK CASCADE, original_name, stored_name UNIQUE, mime_type, size, label NULL, timestamps; индекс `(meeting_id, created_at DESC)`)
- [ ] Создать Eloquent-модель `App\Models\MeetingFile` с `belongsTo(Meeting)`, `belongsTo(User)`; добавить `Meeting::files()` и `User::uploadedMeetingFiles()` HasMany
- [ ] Создать `config/files.php` с картой `max_size_mb` по категориям (document/image/text/archive=20, audio/video=200) и `allowed_mimes` из PRD
- [ ] Зарегистрировать маршруты `POST /api/meetings/{id}/files` и `GET /api/meetings/{id}/files/{fileId}` в `routes/web.php` под `auth` middleware
- [ ] Реализовать `POST /api/meetings/{id}/files` (multipart `file`; MIME через `finfo`; запись `UUID.ext` в `storage/app/meetings/{id}/`; ответ 201 с моделью)
- [ ] Реализовать `GET /api/meetings/{id}/files/{fileId}` (стрим с `Content-Disposition: attachment`; 403/404 для чужих)

**Шаг 1.3 — Проверка:**
- [ ] `docker compose exec backend ./vendor/bin/phpunit --filter MeetingFilesTest` — все 4 теста зелёные
**Когда готова:** 4 зелёных теста покрывают upload+download+auth; можно руками через curl загрузить и скачать файл.

---

### Фаза 2: Backend — Список, удаление и безопасность
**Цель:** Замкнуть CRUD через API и закрыть требования по правам и санитизации.
**Затрагивает:** backend

**Шаг 2.1 — Тесты (red):**
- [ ] Добавить в `MeetingFilesTest.php`:
- [ ] `test_user_can_list_files_of_owned_meeting` — 3 загруженных файла → GET возвращает 3, отсортированы DESC по `created_at`
- [ ] `test_user_cannot_upload_to_others_meeting` — другой пользователь → 403/404
- [ ] `test_user_cannot_download_file_of_others_meeting` — чужой meeting_id в URL → 404
- [ ] `test_uploader_can_delete_file` — DELETE → 204, запись в БД отсутствует, файл на диске отсутствует
- [ ] `test_non_uploader_cannot_delete_file` — другой авторизованный пользователь (но владелец встречи) → 403
- [ ] `test_unauthenticated_list_and_delete_are_rejected` — GET list и DELETE без токена → 401
- [ ] `test_path_traversal_in_filename_is_sanitized` — имя `../../etc/passwd.pdf` → 201, на диске сохранён `UUID.pdf` (не относительный путь)
- [ ] `test_failed_db_write_rolls_back_disk_file` — мок-исключение при `MeetingFile::create` → файл на диске удалён (через `Storage::disk('local')->assertMissing`)
- [ ] Запустить phpunit — новые тесты падают

**Шаг 2.2 — Реализация (green):**
- [ ] `GET /api/meetings/{id}/files` — список файлов встречи, сортировка `DESC` по `created_at`, только владельцу встречи (иначе 403/404)
- [ ] `DELETE /api/meetings/{id}/files/{fileId}` — только загрузившему пользователю (иначе 403/404); удалить запись в БД и файл с диска
- [ ] Авторизация на чтение/загрузку/скачивание: `meeting.user_id === currentUser.id` (иначе 403/404 — не 200/400, защита от перечисления id)
- [ ] Санитизация `original_name`: убрать разделители пути (`/`, `\`, `..`) и управляющие символы, ограничить длину 255; на диск сохранять только `UUID.ext`
- [ ] Транзакция загрузки: при ошибке записи в БД после сохранения файла — `Storage::delete()` откатывает файл (нет осиротевших файлов)

**Шаг 2.3 — Проверка:**
- [ ] `docker compose exec backend ./vendor/bin/phpunit --filter MeetingFilesTest` — все 12 тестов зелёные
**Когда готова:** Полный CRUD через API; 403/404 для чужих встреч/файлов; path-traversal безопасен; rollback работает.

---

### Фаза 3: Backend — Валидация файлов (MIME, размер, label)
**Цель:** Закрыть все `422` из PRD: пустой/отсутствующий файл, неподдерживаемый MIME, превышение лимита категории, длинная подпись.
**Затрагивает:** backend

**Шаг 3.1 — Тесты (red):**
- [ ] Добавить в `MeetingFilesTest.php`:
- [ ] `test_upload_rejects_missing_file` — POST без поля `file` → 422
- [ ] `test_upload_rejects_disallowed_mime` — `.exe` payload (бинарник с PE-заголовком) → 422
- [ ] `test_upload_rejects_oversize_document` — PDF > 20 МБ (in-memory fixture 21 МБ) → 422
- [ ] `test_upload_rejects_oversize_video` — MP4 > 200 МБ → 422
- [ ] `test_upload_accepts_audio_within_higher_limit` — MP3 30 МБ → 201 (проверка: 200 МБ лимит для audio работает)
- [ ] `test_upload_accepts_video_within_higher_limit` — 150 МБ видео → 201
- [ ] `test_upload_uses_actual_mime_from_finfo` — файл с расширением `.pdf`, но MIME `application/zip` по finfo → 422
- [ ] `test_upload_rejects_oversize_label` — `label` 300 символов → 422
- [ ] Запустить phpunit — новые тесты падают

**Шаг 3.2 — Реализация (green):**
- [ ] Расширить `POST /api/meetings/{id}/files`: FormRequest валидация (`file.required`, `file.file`, `label.string|max:255`)
- [ ] Сервис `FileValidationService::validate($uploadedFile)` — определяет MIME через `finfo`, категорию, проверяет `allowed_mimes` и `max_size_mb` из `config/files.php`; бросает `ValidationException` с человеко-читаемым сообщением
- [ ] Лимит выбирается по категории, не зашит константой: document/image/text/archive=20 МБ, audio/video=200 МБ

**Шаг 3.3 — Проверка:**
- [ ] `docker compose exec backend ./vendor/bin/phpunit --filter MeetingFilesTest` — все 20 тестов зелёные
**Когда готова:** Все валидационные сценарии из PRD покрыты; лимиты категорий читаются из конфига, а не захардкожены.

---

### Фаза 4: Backend — Инфраструктура и логирование
**Цель:** Подготовить окружение к большим файлам и закрыть требования Nginx/логов/персистентности.
**Затрагивает:** backend, nginx

**Шаг 4.1 — Тесты (red):**
- [ ] `test_upload_writes_log_on_success` — мок `Log::shouldReceive('info')->once()` с контекстом `meeting_id`/`user_id`/`file_id`/`status=ok`
- [ ] `test_download_writes_log_on_success` — мок `Log::info` при download
- [ ] `test_delete_writes_log_on_success` — мок `Log::info` при delete
- [ ] `test_upload_writes_log_on_error` — мок `Log::error` со `status=error` при невалидном файле
- [ ] `test_nginx_blocks_storage_path` — `curl -I http://localhost:8081/storage/meetings/1/x.pdf` → ожидаем 403/404 (тест запускается из CI через `docker compose exec` против nginx)
- [ ] `test_files_survive_backend_restart` — загрузить файл → `docker compose restart backend` → файл всё ещё доступен по пути `storage/app/meetings/...`
- [ ] Запустить phpunit + ручной curl — тесты логирования и nginx-блокировки падают

**Шаг 4.2 — Реализация (green):**
- [ ] Обновить `nginx/default.conf`: добавить `location ~ ^/storage/ { deny all; }` (запрет прямой отдачи `storage/app/...`)
- [ ] Поднять лимиты PHP в `Dockerfile`/php.ini: `upload_max_filesize=220M`, `post_max_size=220M`, `memory_limit=512M`
- [ ] Проверить, что `backend/` смонтирован как volume (файлы переживают `docker compose restart backend`); задокументировать поведение
- [ ] Логирование через `Log::info`/`Log::error` для upload/download/delete: запись с `meeting_id`, `user_id`, `file_id`, `status` (`ok`/`error`)

**Шаг 4.3 — Проверка:**
- [ ] `docker compose exec backend ./vendor/bin/phpunit --filter MeetingFilesTest` — все 25 тестов зелёные
- [ ] `curl -I http://localhost:8081/storage/meetings/1/x.pdf` → 403
- [ ] `docker compose restart backend` — загруженный ранее файл по-прежнему доступен через API
**Когда готова:** Nginx не отдаёт storage напрямую; 200 МБ MP4 загружается без ошибок PHP; логи пишутся в `backend/storage/logs/`.

---

### Фаза 5: Backend — Восстановление live-БД
**Цель:** После `DatabaseMigrations` восстановить live-приложение.
**Затрагивает:** backend

**Шаг 5.1 — Задачи:**
- [ ] `docker compose exec backend php artisan migrate --force` для восстановления схемы live-приложения после тестов
- [ ] Smoke: `curl http://localhost:8081/api/meetings` с валидным токеном → 200, схема таблицы `meeting_files` присутствует
**Когда готова:** PHPUnit зелёный; все 25 кейсов покрыты; live-БД готова к работе.

---

### Фаза 6: Frontend — Список файлов и скачивание
**Цель:** Показать пользователю прикреплённые файлы в деталях встречи и дать скачать.
**Затрагивает:** frontend

**Шаг 6.1 — Smoke-критерии (формализуем как чек-лист, проверяется в фазе 8):**
- [ ] `smoke_list_shows_files` — открыть детали встречи с 2+ файлами → виден список, каждая строка содержит иконку (по MIME), имя, размер, автора, время
- [ ] `smoke_list_empty_state` — открыть встречу без файлов → «Файлов пока нет»
- [ ] `smoke_download_button` — клик «Скачать» → файл скачивается с `original_name` (берётся из Content-Disposition, не из URL)
- [ ] `smoke_download_sends_bearer_token` — в Network-табе DevTools запрос содержит `Authorization: Bearer <token>`
- [ ] `smoke_unauthorized_redirects_to_login` — удалить токен из localStorage → 401 на API → редирект на логин

**Шаг 6.2 — Реализация:**
- [ ] Создать компонент `frontend/src/components/MeetingFileList.vue`: PrimeVue `DataView` строк с иконкой по MIME, именем, размером (КБ/МБ, 1 знак), автором, временем (локализованным), подписью
- [ ] Кнопка «Скачать» в каждой строке → `fetch` с `Authorization: Bearer <token>` и `Content-Disposition`-стимом; `original_name` из ответа сервера
- [ ] Пустое состояние «Файлов пока нет» + подсказка «Загрузите первый файл»
- [ ] Интегрировать `MeetingFileList` в детали встречи (рядом с `MeetingDetails`/`MeetingForm`); загрузка через `fetch('/api/meetings/{id}/files')` с токеном из `localStorage`

**Шаг 6.3 — Проверка:**
- [ ] Вручную пройти smoke-чеклист 6.1 (фиксация результата в комментарии к PR)
**Когда готова:** Smoke-критерии 6.1 выполнены вручную; в деталях встречи виден список файлов с корректной иконкой и кнопкой скачивания; пустое состояние отображается; 401 редиректит на логин.

---

### Фаза 7: Frontend — Диалог загрузки
**Цель:** Дать UI для прикрепления файла с подписью, прогрессом и валидацией.
**Затрагивает:** frontend

**Шаг 7.1 — Smoke-критерии (red):**
- [ ] `smoke_upload_dialog_opens` — клик «Загрузить файл» → диалог с `FileUpload` + `InputText` подписи
- [ ] `smoke_upload_progress_visible` — во время загрузки виден `ProgressBar`, диалог нельзя закрыть
- [ ] `smoke_upload_limit_shown_in_dialog` — при выборе PDF отображается «Лимит: 20 МБ», при выборе MP4 — «Лимит: 200 МБ»
- [ ] `smoke_upload_oversize_document_blocked` — выбрать PDF 25 МБ → инлайн-ошибка «Файл превышает допустимый лимит», файл не сохраняется
- [ ] `smoke_upload_oversize_video_blocked` — выбрать MP4 250 МБ → инлайн-ошибка
- [ ] `smoke_upload_disallowed_mime_blocked` — выбрать `.exe` → инлайн-ошибка «Неподдерживаемый тип файла»
- [ ] `smoke_upload_success_shows_toast` — успешная загрузка → тост «Файл загружен», файл появляется в списке

**Шаг 7.2 — Реализация:**
- [ ] Диалог загрузки: PrimeVue `Dialog` с `FileUpload` (mode=`basic`, `:auto="true"`, `:customUpload="true"`) + `InputText` для опциональной подписи
- [ ] Динамический `maxFileSize` по MIME выбранного файла: 20 МБ для document/image/text/archive, 200 МБ для audio/video; отображать текущий лимит в диалоге
- [ ] `ProgressBar` во время `customUpload`; блокировка диалога (`modal: true` + `closable: false`)
- [ ] Тосты через PrimeVue `Toast`: success «Файл загружен», error «Файл превышает допустимый лимит», error «Неподдерживаемый тип файла»
- [ ] После успеха — закрыть диалог, перезагрузить `MeetingFileList`, сбросить `FileUpload`

**Шаг 7.3 — Проверка:**
- [ ] Smoke 7.1 пройдены вручную; фиксация в комментарии к PR
**Когда готова:** Все smoke-критерии 7.1 выполнены; можно загрузить файл, ввести подпись; прогресс виден; при ошибке валидации — инлайн-сообщение; после успеха файл появляется в списке.

---

### Фаза 8: Frontend — Удаление и медиа-превью
**Цель:** Замкнуть UI-цикл: удаление с подтверждением, нативные плееры для аудио/видео.
**Затрагивает:** frontend

**Шаг 8.1 — Smoke-критерии (red):**
- [ ] `smoke_delete_button_only_for_uploader` — загрузивший видит кнопку «Удалить», другой пользователь — нет
- [ ] `smoke_delete_confirm_dialog` — клик «Удалить» → `ConfirmDialog` с заголовком/сообщением
- [ ] `smoke_delete_success` — подтверждение → файл исчезает из списка, тост «Файл удалён»
- [ ] `smoke_delete_cancelled_keeps_file` — отмена в ConfirmDialog → файл остаётся
- [ ] `smoke_audio_player_renders` — MP3 в списке → нативный `<audio controls>` под строкой, проигрывается
- [ ] `smoke_video_player_renders` — MP4 в списке → нативный `<video controls>` под строкой, проигрывается
- [ ] `smoke_media_preview_responsive` — на вьюпорте 414px плеер не вылезает за границы (max-width: 100%)

**Шаг 8.2 — Реализация:**
- [ ] Кнопка «Удалить» в строке файла, видна только если `currentUser.id === file.user_id`; клик → `ConfirmDialog` с заголовком и сообщением из `useConfirm()`
- [ ] После подтверждения — `DELETE /api/meetings/{id}/files/{fileId}` с bearer-токеном; тост «Файл удалён» (success) / сообщение об ошибке; обновление списка
- [ ] Для `audio/*` — нативный `<audio controls preload="none" :src="downloadUrl(file.id)">` под строкой
- [ ] Для `video/*` — нативный `<video controls preload="metadata" :src="downloadUrl(file.id)">` под строкой; ограничить `max-width: 100%` для мобильных

**Шаг 8.3 — Проверка:**
- [ ] Smoke 8.1 пройдены вручную через Playwright MCP; фиксация скриншотов
**Когда готова:** Все smoke-критерии 8.1 выполнены; удаление работает только у загрузившего, с подтверждением; аудио/видео играют в строке; мобильный вьюпорт не ломает превью.

---

### Фаза 9: Документация, качество, финальный smoke
**Цель:** Закрыть нефункциональные критерии готовности: документация, форматирование, полный ручной smoke + проверка всех smoke-критериев из фаз 6–8 через Playwright MCP.
**Затрагивает:** backend, frontend, docs

**Шаг 9.1 — Задачи:**
- [ ] Обновить `README.md` (или создать `docs/meeting-files.md` со ссылкой из README): раздел «Как прикрепить файл», оговорка про локальное хранение и потерю volume, ссылка на `backend/config/files.php` с таблицей лимитов
- [ ] Запустить `composer format` (PHP-CS-Fixer) и `npm run format` (Prettier) — без замечаний
- [ ] Прогнать **весь** PHPUnit-сьют — все 25 тестов из фаз 1–4 зелёные
- [ ] Через Playwright MCP прогнать **все** smoke-критерии фаз 6–8 на desktop (≥ 1280px) и mobile (≤ 414px): список, диалог загрузки, удаление, плееры, пустое состояние, тосты
- [ ] Выполнить ручной smoke-чеклист из PRD: PDF 5 МБ, MP3 15 МБ, MP4 50 МБ, обновление страницы, второй пользователь, скачивание
- [ ] Проверить перформанс: список из 100 файлов рендерится без видимых лагов; p95 загрузки PDF 20 МБ < 5 с на dev-окружении
- [ ] Восстановить live-БД: `docker compose exec backend php artisan migrate --force`
**Когда готова:** README актуален; форматирование чисто; PHPUnit зелёный; все smoke-критерии фаз 6–8 пройдены через Playwright MCP на двух вьюпортах; ручной smoke-чеклист PRD пройден; live-БД готова; все критерии готовности из PRD выполнены.

---

## Сводка тестового покрытия

| Слой | Тип | Кол-во | Где фиксируется |
|---|---|---|---|
| Backend | PHPUnit Feature (`MeetingFilesTest`) | 25 | Фазы 1–4 (red → green в каждой фазе) |
| Backend | Bash/curl | 2 | Фаза 4 (Nginx-блокировка, restart-volume) |
| Frontend | Smoke-чеклист → Playwright MCP | 20 (5+7+8) | Фазы 6, 7, 8 (формализация) → Фаза 9 (прогон) |
| Общее | — | 47 | — |
