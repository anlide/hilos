---
name: hilos-testing-cli
description: Run or choose Hilos test, migration, schema, DB reset, monitoring, and CLI commands. Use when validating Hilos changes, running framework or demo tests, checking migrations, resetting test databases, running frontend tests, or deciding the correct composer script.
---

# Hilos Testing CLI

Use this skill whenever validation or CLI commands are needed. Start with `agents.md`, then read the test or CLI guide before running commands.

## Read First

- Test commands and test-writing rules: `docs/agents/testing.md`
- The full run — the step graph, lanes, and the re-run rule for red under
  concurrency: `docs/agents/testing.md`, section "The full run — one graph, a
  bounded number of lanes"
- Migrations, schema checks, DB reset, monitoring: `docs/agents/cli/commands.md`
- Legacy CLI reference only if needed: `docs/cli-commands.md`

## Workflow

1. Pick the narrowest validation that covers the changed behavior.
2. Run tests through composer scripts only.
3. For framework tests, run composer scripts from the repo root.
4. For demo/chat tests, run composer scripts from `demo/chat`.
5. Use DB setup/reset scripts before integration tests that require a clean schema.
6. Report any skipped tests and the reason.

## Common Choices

- Framework backend unit loop: `composer run test:framework:unit`
- Framework frontend loop: `composer run test:framework:frontend`
- Demo backend unit loop from `demo/chat`: `composer run test:unit`
- Demo frontend loop from `demo/chat`: `composer run test:frontend`
- Full framework pass: `composer run test:framework:all`
- Full demo/chat pass from `demo/chat`: `composer run test:all`
- Everything, as one graph: `composer run test:suite`
- One step of that graph, alone: `php scripts/run-test-suite.php <id> --lanes=1`

## Hard Rules

- Never run `git commit` or `git push`.
- Never run `phpunit` or `vendor/bin/phpunit` directly from the host.
- Prefer composer scripts documented in `docs/agents/testing.md`.
- A step of `test:suite` that goes red while another step was running is not a
  verdict — re-run it with `--lanes=1` on the same HEAD before believing it.
