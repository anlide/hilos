# Playwright E2E (demo/chat)

End-to-end tests run the browser against the **test** Docker stack (`docker/docker-compose.test.yml`: `chat-nginx-test`, `chat-test`, `mysql-test`). Playwright defaults to `https://localhost:443` (see `playwright.config.ts`).

## For AI agents and operators: stop local Nginx before E2E

The **local development** stack can run Nginx as `chat-nginx-local` (`docker/docker-compose.local.yml`, Compose profile `full`, e.g. `composer run daemon-start-build` from `demo/chat`). That service maps host HTTP/HTTPS ports (defaults **80** and **443**, overridable via `NGINX_HTTP_PORT` / `NGINX_HTTPS_PORT`).

The **E2E test** stack runs `chat-nginx-test`, which maps the **same** default host ports (overridable via `NGINX_TEST_HTTP_PORT` / `NGINX_TEST_HTTPS_PORT`).

If `chat-nginx-local` is still running, `test:e2e-up` (or `test:e2e-full`) can fail to bind ports or hit the wrong server. **Before** starting the E2E stack, stop local Nginx (or the whole local stack if that is simpler).

From `demo/chat` (where `composer.json` lives):

```bash
docker compose -f docker/docker-compose.local.yml stop chat-nginx-local
```

Alternatively, stop the entire local compose project: `composer run daemon-stop` (stops all services defined in the local file, not only Nginx).

## Composer entrypoints (run from `demo/chat`)

| Script | Role |
|--------|------|
| `composer run test:e2e-full` | Build frontend, start E2E stack, DB wait/reset, install Playwright, run tests, tear down |
| `composer run test:e2e-build` | Build `frontend/dist` for Nginx in the test stack |
| `composer run test:e2e-up` | Start MySQL + daemon + **test** Nginx |
| `composer run test:e2e-down` | Stop the test stack |
| `composer run test:e2e-install` | `npm ci` + `playwright install` in this directory |
| `composer run test:e2e` | Run Playwright (`npm test` in `tests/e2e`) |

Framework-level note for the same port conflict pattern: see `framework/README.md` in the Hilos repository.
