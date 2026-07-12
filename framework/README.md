# Hilos framework (v2)

PHP backend (`framework/backend`) and shared frontend SDK (`framework/frontend`) for Hilos applications. Demos and apps consume this package via Composer path repository and local SDK linking.

## Docker: single framework compose file

**Rule: the framework level has exactly one Docker Compose file —
`framework/docker/docker-compose.yml`.** Every framework stack lives in it as a
service behind a **profile**, so nothing starts implicitly and each stack is
opt-in. Do not add sibling `docker-compose.*.yml` or `*-local.yml` override
files at framework level; add a service and a profile to the one file instead.
(This is the framework-level rule only — each demo/app under `demo/` keeps its
own project-level `docker-compose.local.yml` + `docker-compose.test.yml`.)

Profiles:

| Profile | Stack |
|---|---|
| `ollama` / `ollama-gpu-nvidia` / `ollama-gpu-amd` | local Ollama (one service per device variant, shared data volume) |
| `agent-host` | dev-only unified agent host (code guardian), port 9309 |
| `preview` | in-tailnet preview lane: dnsmasq split-DNS + Caddy proxy |
| `frontend` | Node CLI runner for the frontend SDK |
| `test` | MySQL + CLI runner for framework PHPUnit |

Select a stack with `--profile <name>` (or target a service explicitly). Most
stacks have `composer run` wrappers. Because it is one project, lifecycle
scripts scope teardown to their own services (`rm -sf <service>`) rather than a
project-wide `down`, so tearing down one stack never removes another stack's
volumes.

## E2E tests and Docker: local Nginx vs test Nginx

Projects that use Docker for both **day-to-day development** and **automated E2E** often run two Compose files:

- A **local** stack may include an Nginx container that publishes **HTTP/HTTPS on the host** (commonly ports 80 and 443, or values from `NGINX_HTTP_PORT` / `NGINX_HTTPS_PORT`).
- A **test/E2E** stack typically starts its own Nginx (or reverse proxy) for Playwright, with **the same default host ports** (e.g. `NGINX_TEST_HTTP_PORT` / `NGINX_TEST_HTTPS_PORT` in a `docker-compose.test.yml`).

Those defaults **collide on the host**: only one process can listen on a given host port. If an agent (or developer) runs E2E while the **local** Nginx container is still up, Compose may fail on port binding, or the browser may talk to the wrong stack.

**Operational rule:** before bringing up the **test/E2E** stack, stop the **local** Nginx service (or stop the entire local stack). The demo chat documents concrete service names and commands in `demo/chat/tests/e2e/README.md`.
