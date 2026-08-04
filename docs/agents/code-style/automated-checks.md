# Automated Rule Checks

Read this when a rule in `docs/agents/*` should stop depending on memory, when a
guard test fails on your change, or when you are about to add a machine-checkable
rule.

## What is checked by machine

| Rule id | Enforces | Canonical rule |
|---|---|---|
| `PHPDOC-FQN` | A docblock references a class by its imported short name, never by a leading-backslash fully qualified name. Covers the type position of `@throws`, `@param`, `@return`, `@var`, `@property`, `@property-read`, `@method`, `@extends`, `@implements` (generic arguments included), and the `{@see ...}` / `{@link ...}` cross-references. | [phpdoc.md](phpdoc.md) rules 9 and 12 |
| `RT-STATE-REACH` | `getStateCollection()`, `getStateItem()`, and `$this->stateCollection` are used only in files under `Database/` or `Runtime/`, whatever the caller's role. | [rt-state.md](../runtime/rt-state.md) |

The checker is not a second source of truth. Each rule points back at the
document that owns it, and the failure line carries that path. Change the rule in
the document first; the check follows.

## How to run it

The guard lives in the ordinary framework unit suite, so it runs in the coding
loop and inside `test:framework:all` on Verify without a target of its own:

```bash
composer run test:framework:unit
```

A failure lists every unbaselined hit as
`<RULE-ID> <path>:<line> — <what is wrong> (see <doc>)`.

## Why guard tests and not PHPStan

- **No new dependency.** The rules are lexical, and `token_get_all()` already
  ships with PHP. PHPStan or a fixer would add a toolchain to install, pin, and
  keep green.
- **A precedent already exists.** `framework/tests/Unit/PageSubscriptionContractTest.php`
  guards a contract the same way.
- **It lands in both loops for free.** A PHPUnit test is already run by the
  coding loop and by the full run; a separate tool would need a new composer
  target and a place in every pipeline.

A rule that needs type resolution rather than tokens — propagating documented
`@throws` through call chains, for example — does not belong here. That one is
PHPStan's `missingCheckedExceptionInThrows`, and it is a leaf of its own.

## The baseline

Existing debt is recorded in `framework/tests/CodeStyle/baseline.txt`, one record
per rule and file:

```text
RT-STATE-REACH framework/backend/Core/Daemon/DaemonManager.php 1 # HIL-508
```

- **The anchor is the file and a count, not a line number.** Any edit above a
  violation shifts its line; the count survives.
- **The ticket is mandatory.** A record names the leaf that will remove it, so
  the file reads as a list of owed work instead of a mute list. A record without
  a `HIL-nnn` fails the guard.
- **The baseline can only shrink.** More hits than recorded fails with the new
  lines; fewer hits asks you to lower the count; nothing left asks you to delete
  the record.

Regenerate it after paying debt off — the update mode rewrites the file and then
fails on purpose, so the diff is reviewed rather than committed blind:

```bash
docker compose -f framework/docker/docker-compose.yml run --rm \
  --user "$(id -u):$(id -g)" -e CODESTYLE_BASELINE_UPDATE=1 \
  hilos-cli-test php vendor/bin/phpunit -c framework/tests/phpunit.xml \
  --testsuite unit --filter CodeStyleGuardTest
```

Pass `--user` as shown: the test container otherwise runs as root and leaves a
root-owned `baseline.txt` behind. A record the update mode cannot attribute is
written with `TODO-TICKET`, which is not a valid ticket — the next run fails
until a person names the owing leaf.

## Adding a rule

1. Write or extend the canonical rule in `docs/agents/*` first, and add
   *"Checked automatically: `<RULE-ID>`"* next to it.
2. Implement `Hilos\Tests\CodeStyle\CodeStyleRule` under
   `framework/tests/CodeStyle/Rule/`. `check()` receives the file path **relative
   to the scanned root** and the `token_get_all()` output, and yields one
   `Violation` per occurrence, not per line.
3. Read tokens, not raw text. Both current rules depend on it: a docblock rule
   that reads the file as a string would fire on line comments, and a call rule
   would fire on the same name quoted inside a string literal.
4. Seed `framework/tests/CodeStyle/Fixtures/` with both a case that must be
   caught and a look-alike that must not, and pin the exact report in
   `RuleFixtureTest`. The guard leaves that directory out of its scan — by exact
   path, never by directory name — so this test is the only thing proving the
   rule still fires.
5. Register the rule in `CodeStyleGuardTest`, regenerate the baseline, and give
   every new record an owing leaf.
