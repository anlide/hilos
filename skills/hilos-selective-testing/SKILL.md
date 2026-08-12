---
name: hilos-selective-testing
description: Choose which Hilos tests to run for a given change — scale the test set to what changed and decide whether the heavy e2e / two-window suites are warranted. Use when deciding the test scope for a change, whether a change needs e2e or only unit/check, when to run the rare full cross-demo pass, or before reaching for two-window e2e.
---

# Hilos Selective Testing

Use this skill to decide the **test scope** for a change before running anything:
which suites the change warrants, and when the heavy runs are (and are not)
justified. For how to invoke a chosen command, use `$hilos-testing-cli`.

## Read First

- The change-type → test-set map and the rare-full-run rule:
  `docs/agents/testing.md`, section "Selective testing — what to run for which change".
- The step graph, lanes, and what a red step under concurrency means:
  `docs/agents/testing.md`, section "The full run — one graph, a bounded number of lanes".
- What a retried test reports, and whose debt it is:
  `docs/agents/frontend/testing-strategy.md`, section "A retried test is reported,
  and is not automatically your debt".
- Command mechanics, DB reset, and the re-run contract: the rest of
  `docs/agents/testing.md`.

## Workflow

1. Classify the change: PHP backend logic, FE core/SDK, an FE view, an Angular
   template, a wire/signal/subscription contract, a topology registry change
   (`Hilos::PAGES` / `AGENTS` / `ACTIONS` / `SIGNALS` / `AGENT_SIGNALS`), an e2e
   spec, or cross-connection behavior.
2. Run the narrowest set the map prescribes for that class.
3. Reach for the heavy suites — `test:e2e-full` per demo, the two-window tests, and
   the a11y tests (`a11y.spec.ts`) — only for cross-connection behavior (subscription /
   viewport / pending / presence), accessibility changes (ARIA / keyboard / focus), or
   as the pre-merge gate; they are not an inner-loop step.
4. The full cross-demo pass is `composer run test:frontend:all`, and everything at
   once is `composer run test:suite`; run either rarely.
5. Reset before re-running a data-mutating e2e (`test:e2e-up`).
6. A step that went red while another step was running is not a verdict: re-run it
   alone (`php scripts/run-test-suite.php <id> --lanes=1`) on the same HEAD. Green
   alone makes the run inconclusive, not green.
7. An `=== unstable: ... ===` section at the end of a run names tests that only
   passed on a retry. It does not widen the scope you chose: name the test, check
   how long it has flickered, and leave a foreign one to its own ticket.

## Hard Rules

- Do not run the full e2e / two-window suites on every change — match scope to the
  change.
- An Angular template change must run the ng-packagr AOT build, not just tsc.
- Never run `git commit` or `git push`.
