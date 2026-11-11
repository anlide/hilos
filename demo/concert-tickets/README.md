# Hilos Concert Tickets Demo Project

An Angular demo project for selling concert tickets. The application shell and
framework sections are active; the product itself - the hall, its seats and a
booking - has not been built.

## What is in the project

The main page at `/` is intentionally empty on screen. It has a visually hidden
`Concert Tickets` heading for assistive technology. The signed-in person's name
is shown in the profile control in the header.

Sign-in uses a password, a passkey, a code sent by SMS or a GitHub account.
Registration and account recovery confirm the email address with a code sent by
email. The project also includes:

- Profile details, a photo checked by moderation, notification preferences,
  sign-in methods, sessions, two-step verification, agreements and a copy of
  the person's data.
- An admin area at `/hilos` with Users, Settings, Appearance, Analytics, Daemon,
  Logs and Change Log.
- Email delivery for account codes and notifications.
- Public About, Terms, Privacy and License pages, plus the license dependency
  list and Terms/Privacy history routes.

The demo has no tables of its own. Its Analytics section uses the normal Hilos
journal-to-database flow, and its Change Log journals the framework tables it
carries into a database of its own beside the primary one.

## Run locally

From the repository root, prepare the local environment and dependencies, build
the Angular frontend, then start the local stack:

```bash
composer -d demo/concert-tickets run setup-env
composer -d demo/concert-tickets run install-deps
composer -d demo/concert-tickets run frontend:install
composer -d demo/concert-tickets run frontend:build
composer -d demo/concert-tickets run daemon-start-build
```

The local nginx stack publishes HTTPS on port `8276`; Mailpit's web interface
is on port `8280`. Stop the local services with:

```bash
composer -d demo/concert-tickets run daemon-stop
```

The project provides ordinary local and test Compose stacks. Unit tests and
frontend checks run with:

```bash
composer -d demo/concert-tickets run test:unit
composer -d demo/concert-tickets run test:check
```

The full browser cycle is:

```bash
composer -d demo/concert-tickets run test:e2e-full
```

`test:e2e-full` runs the full clean browser cycle: the activation scenarios
(a guest's opening of the empty home and a real sign-in reaching Analytics) and
the specs of the Daemon, Change Log, profile and account deletion areas. If a
failed run leaves its stand up for diagnosis, clean it with:

```bash
composer -d demo/concert-tickets run test:down
```

For integration tests, start the test database and prepare its schema first:

```bash
composer -d demo/concert-tickets run test:up
composer -d demo/concert-tickets run test:db-prepare
composer -d demo/concert-tickets run test:integration
composer -d demo/concert-tickets run test:down
```

The project's Composer scripts in `composer.json` are the complete command
interface for its local and test stacks.
