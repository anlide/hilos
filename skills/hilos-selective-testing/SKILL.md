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
- What no run can prove — the part a person checks on a real device or provider,
  and what a change touching it names in its hand-over: `docs/agents/manual-checks.md`.
- The ladder for a defect two processes disagree about, and when a unit test may
  spawn a second process: `docs/agents/testing.md`, section "A cross-process
  defect is an e2e defect first".
- The step graph, lanes, and the full-run verdict:
  `docs/agents/testing.md`, section "The full run — one graph, a bounded number of lanes".
- Retry evidence and attribution:
  `docs/agents/frontend/testing-strategy.md`, section
  "A retried test makes the step red, and its cause must be traced".
- Main versus quarantine observations and native diagnostic repeats:
  `docs/agents/testing.md`, "Playwright quarantine" and "Narrow runs collect evidence".
- Command mechanics, DB reset, and the re-run contract: the rest of
  `docs/agents/testing.md`.

## Workflow

1. Classify the change: PHP backend logic, FE core/SDK, an FE view, an Angular
   template, a wire/signal/subscription contract, a topology registry change
   (`Hilos::PAGES` / `AGENTS` / `ACTIONS` / `SIGNALS` / `AGENT_SIGNALS`), an e2e
   spec, cross-connection behavior, or cluster behavior.
2. A defect where two processes see different state goes down that ladder: e2e
   first, the stand gateway when the missing participant is an external service
   (`docs/agents/stand-services.md`), a second process inside a unit test last
   and only with the reason written in its docblock.
3. Run the narrowest set the map prescribes for that class.
4. Reach for the heavy suites — `test:e2e-full` per demo, unpointed, the two-window tests, and
   the a11y tests (`a11y.spec.ts`) — only for cross-connection behavior (subscription /
   viewport / pending / presence), accessibility changes (ARIA / keyboard / focus), or
   as the pre-merge gate; they are not an inner-loop step.
5. The full cross-demo pass is `composer run test:frontend:all`, and everything at
   once is `composer run test:suite`; run either rarely.
6. An e2e spec runs through its demo's full cycle pointed at it —
   `composer run test:e2e-full -- <spec | --grep "…">` — which resets for you.
7. Cluster behavior runs the scenarios that cover it on the stand that carries
   them — `composer run test:cluster:scenarios -- <numbers>` from the directory of
   the demo whose stand it is, a fresh stand every time; which stand carries which
   scenario: `docs/agents/testing.md`, section "The cluster stands — three demos,
   three shapes".
8. Use narrow runs for debugging and evidence as described in
   `docs/agents/testing.md`, "Narrow runs collect evidence". The full run owns the
   verdict; a narrow green run on the same HEAD cannot replace a full red one.
9. An `=== unstable: ... ===` section names tests that only passed on a retry and
   made their step red. Trace their history without widening the ticket's scope;
   attribution does not change the verdict. Quarantine failed/not-run results
   are separate observations; diagnostic repeats neither heal a tag nor add to
   the full cycle's quarantine statistics.

## Hard Rules

- Do not run the full e2e / two-window suites on every change — match scope to the
  change.
- An Angular template change is judged by `test:framework:frontend`: the Angular
  package check compiles templates with the build's own tsconfig.
- Never run `git commit` or `git push`.
