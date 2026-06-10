# Simple Poll Demo Project

**Complexity: 2/5**

Simple polling system with real-time result display.

## What is this?

Create polls with answer options. Users vote. Results update in real-time for everyone.

### Features

- **Poll creation**: Create polls with answer options
- **Voting**: User voting system
- **Real-time results**: Results update in real-time for all users
- **Visualization**: Charts show vote distribution, statistics for each answer option
- **Frontend**: Angular 22 + TypeScript over `@hilos/angular` with poll creation
  forms, voting, result charts

### Technical Highlights

- Form handling
- Vote counting
- Real-time updates
- Simple data visualization

## Angular conformance demo

This demo is built on **Angular** (not Vue): it is one of the two minimal
conformance demos that prove the framework-agnostic core
(`docs/agents/frontend/multiframework-core.md`). It consumes the Hilos frontend
SDK through `@hilos/angular` and tracks each core capability as it lands — it
is not held to parity with the Vue chat demo. The app uses the canonical
**Angular CLI** toolchain (`angular.json`; `ng serve` / `ng build` behind the
`npm run dev` / `npm run build` scripts) — the shape a real Angular adopter of
Hilos would have — and is zoneless, the Angular 22 default. Unit tests arrive
at rewrite step 7 through the CLI's native vitest runner
(`docs/agents/frontend/testing-strategy.md`).

The frontend currently renders the blank conformance shell; the poll views and
the PHP backend land with rewrite step 7.

## Frontend development

All Node tooling runs in project-defined docker containers — never on the host
(`docs/agents/frontend/build-and-docker.md`).

| Command | What it does |
|---|---|
| `composer run frontend-start` | start the local stack (Vite dev server, HMR) at http://localhost:5175 |
| `composer run frontend-stop` | stop the local stack |
| `composer run frontend:install` | `npm install` in the frontend container |
| `composer run frontend:check` | type-check the frontend (`tsc`) |
| `composer run frontend:build` | production build into `frontend/dist` |
| `composer run frontend:logs` | follow the dev-server logs |

## End-to-end tests

e2e runs against the **built** frontend artifact served by nginx
(`docs/agents/frontend/testing-strategy.md`). The stack has no backend yet;
MySQL and the daemon join it at rewrite step 7. Agent flow: one `test:e2e-up`,
any number of pointed `test:e2e` runs, one `test:e2e-down`.

| Command | What it does |
|---|---|
| `composer run test:check` | install + typecheck the frontend app (test toolchain) |
| `composer run test:e2e-build` | install + build the frontend for the test stack |
| `composer run test:e2e-install` | install the Playwright deps |
| `composer run test:e2e-check` | typecheck the e2e test code (in the runner) |
| `composer run test:e2e-up` | start the e2e stack (nginx with the built artifact) |
| `composer run test:e2e` | run the e2e suite (`-- --grep "..."` filters) |
| `composer run test:e2e-down` | tear the e2e stack down |
| `composer run test:e2e-full` | build → install → check → up → test → down |

## License

This project is licensed under the MIT License - see the LICENSE file in the root of the Hilos framework for details.
