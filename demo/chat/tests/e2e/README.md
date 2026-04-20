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
