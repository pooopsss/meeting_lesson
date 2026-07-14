# Frontend — Vue 3 + PrimeVue + Vite

## Tech Stack

- **Vue 3.5** — Composition API with `<script setup>` syntax
- **PrimeVue 4.3** — UI component library with Aura theme preset
- **Vite 6.2** — dev server and build tool
- **No router, no state management, no HTTP client** — minimal setup

## Project Structure

```
frontend/
├── Dockerfile
├── index.html              # HTML entry point, mounts Vue app
├── package.json
├── vite.config.js
└── src/
    ├── App.vue             # Root component, renders child components
    ├── main.js             # App bootstrap: createApp + PrimeVue plugin
    ├── style.css           # Global styles (body reset, fonts)
    └── components/         # Vue components live here
        └── HelloWorld.vue  # Example component
```

## Entry Point

`main.js` creates the Vue app, installs PrimeVue with the Aura theme preset, and mounts to `#app`:

```js
import PrimeVue from 'primevue/config'
import Aura from '@primevue/themes/aura'
app.use(PrimeVue, { theme: { preset: Aura } })
```

## Conventions

### Component Style

- **Always use `<script setup>`** — Composition API without boilerplate
- **Props** defined via `defineProps({ ... })` with runtime type declarations (e.g., `String`, `Number`, `Boolean`)
- **Scoped styles** — use `<style scoped>` in `.vue` SFCs
- **Global styles** go in `src/style.css`

### PrimeVue Usage

- Components are auto-imported by PrimeVue plugin (e.g., `<Button>`, `<DataTable>`, `<Dialog>`, etc.)
- Theme customisation via CSS custom properties (`--p-primary-color`, etc.)
- Icons: PrimeIcons included, use `icon="pi pi-check"` attribute or `<i class="pi pi-search">`
- See [PrimeVue docs](https://primevue.org/) for component API

### Component Files

- PascalCase `.vue` files in `src/components/`
- Each component is self-contained with its own `<script setup>`, `<template>`, and `<style scoped>`
- No `components.d.ts` or auto-import config — import components explicitly

### Layout

- `App.vue` uses flexbox centering: `min-height: 100vh`, `align-items: center`, `justify-content: center`
- Layout components should follow this full-height pattern

## API Communication

- Use native `fetch()` — no Axios installed
- API base URL: `http://localhost:8081/api` (set via `VITE_API_URL` env var in docker-compose)
- **CORS is NOT configured** on the backend yet — cross-origin requests may fail until CORS middleware is enabled in `bootstrap/app.php`

## Build & Dev

```bash
npm run dev        # Start Vite dev server (port 5173 in container, mapped to :5174)
npm run build      # Production build to dist/
npm run preview    # Preview production build
```

Vite config is minimal: Vue plugin only, no path aliases, no proxy, no env prefix customization.

## Adding Pages

Since there's no Vue Router:
- Conditionally render "pages" via `v-if` / `v-show` or dynamic `<component :is>`
- Or add Vue Router if multi-page UI is needed (`npm install vue-router`)

## Adding State Management

If shared state is needed:
- Use Vue 3 `reactive()` / `ref()` with provide/inject for simple cases
- Or install Pinia (`npm install pinia`) for proper stores
