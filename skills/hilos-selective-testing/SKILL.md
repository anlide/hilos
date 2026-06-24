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
- Command mechanics, DB reset, and the re-run contract: the rest of
  `docs/agents/testing.md`.

## Workflow

1. Classify the change: PHP backend logic, FE core/SDK, an FE view, an Angular
   template, a wire/signal/subscription contract, an e2e spec, or cross-connection
   behavior.
2. Run the narrowest set the map prescribes for that class.
3. Reach for the heavy suites — `test:e2e-full` per demo and the two-window tests —
   only for cross-connection behavior (subscription / viewport / pending / presence)
   or as the pre-merge gate; they are not an inner-loop step.
4. The full cross-demo pass is `composer run test:frontend:all`; run it rarely.
5. Reset before re-running a data-mutating e2e (`test:e2e-up`).

## Hard Rules

- Do not run the full e2e / two-window suites on every change — match scope to the
  change.
- An Angular template change must run the ng-packagr AOT build, not just tsc.
- Never run `git commit` or `git push`.
