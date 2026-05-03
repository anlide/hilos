# Playwright E2E (demo/chat)

End-to-end tests run a real browser against the **test** Docker stack
(`docker/docker-compose.test.yml`: `chat-nginx-test`, `chat-test`,
`mysql-test`).

The Playwright runner itself runs in a docker container
(`chat-e2e-runner`, image `mcr.microsoft.com/playwright:vX.Y.Z-noble`
with browsers baked in). The runner reaches the app via the docker
network as `https://chat-nginx-test`, **not** through host ports —
`BASE_URL` is set in `docker-compose.test.yml`.

## Version pinning

`@playwright/test` in `package.json` and the Playwright image tag in
`docker/docker-compose.test.yml` (`chat-e2e-runner.image`) **must
match exactly**. Otherwise Playwright cannot locate its browsers at
`/ms-playwright`. Bump them together.

## For AI agents and operators: stop local Nginx before E2E

The **local development** stack can run Nginx as `chat-nginx-local`
(`docker/docker-compose.local.yml`, Compose profile `full`, e.g.
`composer run daemon-start-build` from `demo/chat`). That service maps
host HTTP/HTTPS ports (defaults **80** and **443**, overridable via
`NGINX_HTTP_PORT` / `NGINX_HTTPS_PORT`).

The **E2E test** stack runs `chat-nginx-test`, which maps the **same**
default host ports (overridable via `NGINX_TEST_HTTP_PORT` /
`NGINX_TEST_HTTPS_PORT`). The Playwright runner itself does not need
those host ports — it talks to nginx over the docker network — but
`test:e2e-up` will fail to bind them if the local stack already holds
them.

If `chat-nginx-local` is still running, **stop it first**:

```bash
docker compose -f docker/docker-compose.local.yml stop chat-nginx-local
```

Or stop the entire local compose project: `composer run daemon-stop`
(stops everything in the local file, not just Nginx).

## Composer entrypoints (run from `demo/chat`)

| Script | Role |
|--------|------|
| `composer run test:e2e-full` | Build frontend, start E2E stack, DB wait/reset, run Playwright in docker, tear down. |
| `composer run test:e2e-build` | Build `frontend/dist` for nginx in the test stack. |
| `composer run test:e2e-up` | Start MySQL + daemon + **test** Nginx. |
| `composer run test:e2e` | `npm ci` + `npx playwright test` inside `chat-e2e-runner`. Requires the stack to be up. |
| `composer run test:e2e-realtime` | Run only specs tagged `@realtime` inside `chat-e2e-runner`. Requires the stack to be up. |
| `composer run test:e2e-realtime-full` | Build frontend, start E2E stack, DB wait/reset, run only `@realtime`, tear down. |
| `composer run test:e2e-down` | Stop the test stack. |

There is **no** host-side `test:e2e-install` — everything runs inside
docker. If you need to debug Playwright interactively, run the runner
manually with an overridden command (e.g. `--ui` won't work without an
X server, but `--reporter=line` and `--debug` produce useful logs):

```bash
docker compose -f docker/docker-compose.test.yml --profile e2e run --rm \
  chat-e2e-runner npx playwright test --reporter=line
```

Framework-level note for the same port-conflict pattern: see
`framework/README.md` in the Hilos repository.

## Suite taxonomy

- `chat-*` specs cover the demo chat user-facing app: messages, uploads,
  profile, participants, and moderation-visible UX.
- `admin-*` specs cover demo-specific admin pages under `/hilos/admin_*`.
- `tests/realtime/*` specs cover multi-actor behavior: cross-tab sync,
  cross-user updates, admin-to-user propagation, table mutation fan-out, and
  concurrent edit/conflict scenarios. These specs are tagged `@realtime`.
- `hilos-chat` is the concrete Hilos suite for this demo. It validates
  framework Hilos frontend routes through the demo's real route overrides,
  page factory, agents, DB seeds, and Docker stack. Framework-only Hilos E2E is
  intentionally not attempted here because framework Hilos pages are abstract
  until a project implements them.

New scenario plans should be added as `test.fixme(...)` first when the
fixture or deterministic seed is not ready yet. Convert each case to `test(...)`
only when it can run reliably in `composer run test:e2e-full`.

## Realtime E2E

Realtime specs are intentionally separated because they are slower and more
stateful than single-page smoke or CRUD checks. They open multiple browser
contexts/tabs, rely on WebSocket propagation, and often need deterministic DB
seeds for the exact user, bot, prompt piece, or Hilos row under test.

Run realtime-only checks when changing:

- WebSocket connection, reconnect, page subscription, or signal routing.
- Frontend stores, table mutation handling, optimistic/pending row behavior, or
  conflict UI.
- DB/RT projection code that fans updates from one page to another.
- Admin actions that should be visible to users or other admins without reload.
- Hilos dashboard/user/log pages where demo pages implement framework contracts.

Use `composer run test:e2e-realtime` after the E2E stack is already up. Use
`composer run test:e2e-realtime-full` for a self-contained realtime pass. The
regular full pass, `composer run test:e2e-full`, runs all Playwright specs and
therefore includes `@realtime`; do not exclude realtime specs from the full
pre-PR run once they are converted from `test.fixme(...)` to executable tests.
