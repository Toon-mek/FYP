# FYP

This repository now mirrors the original layout you were expecting:

- `BackEnd/` – PHP APIs plus `services/ai-trip-api`, a small Node/Express bridge for Google, Booking.com, and AI orchestration.
- `FrontEnd/` – the production Vue 3 + Vite application.
- `FrontEnd/prototypes/ai-react-client/` – the experimental React client that was created by `create-react-app`. Keeping it inside `FrontEnd/prototypes` keeps the root tidy without throwing the code away.
- `tests/` – shared Playwright/Vitest suites.

## Running the projects

```bash
# Vue front-end
cd FrontEnd
npm install
npm run dev

# Node/Express helper API (loads .env from BackEnd/services/ai-trip-api/.env or repo root)
cd BackEnd/services/ai-trip-api
npm install
npm start

# React prototype (optional)
cd FrontEnd/prototypes/ai-react-client
npm install
npm start
```

> Each Node-based project keeps its own `node_modules` folder. This is intentional—Vue, the Node proxy, and the React prototype have different dependency trees and versions. They are isolated now so they no longer clutter the repository root, but they can be installed or removed independently without side effects.
