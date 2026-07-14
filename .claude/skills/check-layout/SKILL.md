---
name: check-layout
description: This skill should be used after making changes to frontend Vue/Quasar components in the CRM app to verify that the layout looks correct on desktop and mobile viewports using Playwright MCP. Trigger when the user asks to "check the layout", "verify the UI", "test responsiveness", "check mobile view", "check desktop view", "проверь верстку", "проверь лейаут", "проверь мобильную версию", "посмотри как выглядит на мобиле", "посмотри как выглядит", or after completing any frontend/CSS/Vue component changes in the crm/ directory. Always use this skill proactively after editing .vue files, CSS, or layout-related code.
allowed-tools: mcp__playwright__browser_navigate, mcp__playwright__browser_resize, mcp__playwright__browser_take_screenshot, mcp__playwright__browser_snapshot, mcp__playwright__browser_wait_for, mcp__playwright__browser_close, mcp__playwright__browser_tabs
---

# Проверка верстки через Playwright MCP

Скилл проверяет UI CRM-приложения на desktop и mobile viewport после внесения изменений в frontend.

## Контекст проекта

- CRM dev-сервер: `http://localhost:9000` (Quasar/Vite, hash routing — `/#/crm`, `/#/auth`)
- Стек: Vue 3, Quasar 2, SCSS
- Запуск сервера: `cd crm/frontend/app && npm run dev` или через Docker

## Входные данные

`$ARGUMENTS` — опциональный путь/хэш-маршрут для проверки (например, `/crm`, `/auth`, `/crm/clients`). Если не указан — проверять страницу, связанную с последними изменениями. По умолчанию `/crm`.

## Порядок проверки

### 1. Определить URL для проверки

Если `$ARGUMENTS` указан — использовать его как hash-маршрут: `http://localhost:9000/#$ARGUMENTS`.  
Если не указан — определить по последним изменённым файлам, какой маршрут затронут. Если нельзя определить — использовать `http://localhost:9000/#/crm`.

### 2. Проверка Desktop (1280×800)

```
browser_resize → 1280×800
browser_navigate → целевой URL
browser_wait_for → сеть успокоилась / элемент загрузился (2–3 секунды)
browser_take_screenshot → описать что видно
browser_snapshot → получить DOM-структуру для анализа
```

Анализировать:
- Переполнение контента (overflow), горизонтальный скролл
- Обрезанный текст, неправильные отступы
- Сломанная сетка/flex layout
- Перекрывающиеся элементы
- Пустые области где должен быть контент

### 3. Проверка Mobile (375×812, iPhone-like)

```
browser_resize → 375×812
browser_wait_for → 1 секунда (ждать перерисовки)
browser_take_screenshot → описать что видно
browser_snapshot → получить DOM-структуру для анализа
```

Анализировать:
- Горизонтальный скролл (признак сломанной адаптивности)
- Элементы выходящие за границы экрана
- Мелкий нечитаемый текст
- Кнопки/элементы управления слишком маленькие для тапа
- Сломанная навигация (drawer, меню)
- Контент перекрывающий другой контент

### 4. Дополнительные viewport (опционально)

Если обнаружены проблемы или нужна промежуточная точка — проверить Tablet (768×1024).

### 5. Отчёт

Сформировать краткий отчёт:

```
## Результат проверки верстки

### Desktop (1280×800)
✅ / ❌  [краткое описание]
- Найденные проблемы (если есть)

### Mobile (375×812)
✅ / ❌  [краткое описание]
- Найденные проблемы (если есть)

### Вывод
[Нужны ли исправления / всё в порядке]
```

## Частые проблемы в Quasar

- `q-drawer` на mobile может не сворачиваться → проверить `v-model` и `mobile-breakpoint`
- Таблицы (`TableAjax.vue`) на mobile — проверить горизонтальный скролл
- `q-toolbar` с длинными заголовками — обрезание текста
- Фиксированные px-размеры вместо `%` или `vw/vh`

## Если dev-сервер не запущен

Сообщить пользователю:
```
Dev-сервер CRM не доступен на http://localhost:9000
Для запуска: cd crm/frontend/app && npm run dev
```
И не продолжать проверку.
