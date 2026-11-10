# Hilos Preferans Demo Project

An Angular demo project for a Sochi preferans card game. The application shell
and framework sections are active; the card game itself has not been built.

## What is in the project

The main page at `/` is intentionally empty on screen. It has a visually hidden
`Preferans` heading for assistive technology. The signed-in person's name is
shown in the profile control in the header.

Sign-in uses a password. Registration and account recovery confirm the email
address with a code sent by email. The project also includes:

- Profile details and notification preferences.
- An admin area at `/hilos` with Users, Settings, Appearance, Languages and
  Countries, and Analytics.
- Email delivery for account codes and notifications.
- Public About, Terms, Privacy and License pages, plus the license dependency
  list and Terms/Privacy history routes.

The demo has no game tables of its own. Its Analytics section uses the normal
Hilos journal-to-database flow.

## Run locally

From the repository root, prepare the local environment and dependencies, build
the Angular frontend, then start the local stack:

```bash
composer -d demo/preferans run setup-env
composer -d demo/preferans run install-deps
composer -d demo/preferans run frontend:install
composer -d demo/preferans run frontend:build
composer -d demo/preferans run daemon-start-build
```

The local nginx stack publishes HTTPS on port `8256`; Mailpit's web interface
is on port `8260`. Stop the local services with:

```bash
composer -d demo/preferans run daemon-stop
```

The project provides ordinary local and test Compose stacks. Unit tests and
frontend checks run with:

```bash
composer -d demo/preferans run test:unit
composer -d demo/preferans run test:check
```

The full browser cycle is:

```bash
composer -d demo/preferans run test:e2e-full
```

`test:e2e-full` runs the full clean browser cycle. If a failed run leaves its
stand up for diagnosis, clean it with:

```bash
composer -d demo/preferans run test:down
```

For integration tests, start the test database and prepare its schema first:

```bash
composer -d demo/preferans run test:up
composer -d demo/preferans run test:db-prepare
composer -d demo/preferans run test:integration
composer -d demo/preferans run test:down
```

The project's Composer scripts in `composer.json` are the complete command
interface for its local and test stacks.
