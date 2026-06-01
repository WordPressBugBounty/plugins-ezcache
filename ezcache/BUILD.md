# 🔧 ezCache — Build Instructions

> ⚠️ **DO NOT include this file in production releases or ZIP uploads to Freemius/WordPress.org.**
> This file is for developers only.

## Prerequisites

- Node.js 18+ 
- npm 9+

## Setup

```bash
npm install
```

## Build for production

```bash
npm run build
```

This compiles `assets/src/` → `assets/dist/` (JS + CSS).

## Development

```bash
npm run dev
```

Starts Vite dev server with HMR.

## Project Structure

```
assets/
├── src/                    # Vue 3 source (DO NOT ship to production)
│   ├── main.js             # Entry point
│   ├── App.vue             # Root component (layout + router)
│   ├── views/              # Page components
│   │   ├── Dashboard.vue   # Stats page (/)
│   │   ├── Settings.vue    # Cache settings (/settings)
│   │   ├── Performance.vue # Performance settings (/performance)
│   │   ├── Advanced.vue    # Advanced settings (/advanced)
│   │   └── License.vue     # License/Freemius (/license)
│   └── composables/        # Shared logic
│       ├── useApi.js       # REST API helper
│       └── useToast.js     # SweetAlert2 wrapper
└── dist/                   # Compiled output (committed to repo)
    ├── js/options.js       # Built JS
    └── css/options.css     # Built CSS
```

## Files NOT to include in release ZIP

- `BUILD.md` (this file)
- `package.json`
- `package-lock.json`
- `vite.config.js`
- `assets/src/` (entire directory)
- `node_modules/` (entire directory)
- `.git/`
- `*.bak`, `*.bak.*`

## Tech Stack

- **Vue 3** (Composition API + Options API)
- **Vue Router 4** (hash mode)
- **Vite 5** (build tool)
- **SweetAlert2** (toast notifications)
- No Bootstrap — custom CSS

## Premium Features

Premium feature gates are managed by `includes/PremiumFeatures.php`.
The PHP passes `is_premium` and `premium_features` to the Vue app via `wp_localize_script`.
The Vue components check these values to disable/lock premium toggles for free users.
