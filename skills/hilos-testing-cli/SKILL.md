---
name: hilos-testing-cli
description: Run or choose Hilos tests, migration, schema, DB reset, monitoring, and CLI commands, or write a PHP integration test. Use when validating Hilos changes, writing or changing PHP integration tests, running framework or demo tests, checking migrations, resetting test databases, running frontend tests, deciding the correct composer script, or considering forgetting loaded ORM state through CLI, direct API, or a helper.
---

# Hilos Testing CLI

Use this skill whenever validation or CLI commands are needed. Start with `agents.md`, then read the test or CLI guide before running commands.

## Read First

- Before considering ORM forget by CLI, API, or helper:
  [Forgetting loaded ORM state](../../docs/agents/testing.md#forgetting-loaded-orm-state).
- Test commands and test-writing rules: `docs/agents/testing.md`
- Before writing or changing a rollback-scoped PHP integration test:
  [Rollback-scoped integration tests](../../docs/agents/testing/integration-tests.md).
- The step graph, lanes, full-run verdict and narrow diagnostic repeats:
  `docs/agents/testing.md`, section "The full run — one graph, a bounded number of lanes".
- Retry evidence and attribution:
  `docs/agents/frontend/testing-strategy.md`, section
  "A retried test makes the step red, and its cause must be traced".
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
- One e2e spec or grep, full clean cycle, from `demo/<demo>`: `composer run test:e2e-full -- <spec | --grep "…">`
- Everything, as one graph: `composer run test:suite`
- One step of that graph, alone: `php scripts/run-test-suite.php <id> --lanes=1`
- Named tests of one e2e step, alone: `php scripts/run-test-suite.php <id> --lanes=1 --tests=tests/a.spec.ts:71`
- Five diagnostic observations on `total-refac`: `php scripts/run-test-suite.php <id> --lanes=1 --tests=tests/a.spec.ts:71 --repeat=5`

## Hard Rules

- Outside tests, do not use or propose ORM forget for approval; use it only
  when the owner explicitly asks. This covers CLI, direct API, and indirect use;
  follow the linked canon.
- Never run `git commit` or `git push`.
- A unit test may spawn a second process only as the last step of the ladder in
  `docs/agents/testing.md`, section "A cross-process defect is an e2e defect
  first", and its docblock must name what stopped e2e and the stand gateway.
- Never run `phpunit` or `vendor/bin/phpunit` directly from the host.
- Prefer composer scripts documented in `docs/agents/testing.md`.
- The full run owns the verdict. A retried success makes the step red; a narrow
  green run on the same HEAD does not override it. Use narrow runs for debugging
  and evidence as described in `docs/agents/testing.md`, "Narrow runs collect evidence".
- Read `=== unstable: ... ===`, name the tests and trace their history. Attribution
  does not change the verdict; report a foreign cause without fixing it in this ticket.
