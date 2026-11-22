# Testing — Agent Guide

Quick reference for running tests across the repo. All test commands go
through Docker (`docker compose ... run --build --rm chat-cli-test ...`) and are
wrapped in `composer` scripts — **do not invoke `phpunit` / `vendor/bin/phpunit`
directly from the host**, especially on Windows (vendor binaries and MySQL
live inside the test container).

A test script or graph step builds the images it runs itself, from the plain
Docker cache, before it starts: after a Dockerfile edit nothing needs rebuilding
by hand before the tests. A failed build fails the step with a non-zero exit
code, so tests never run on the old image.

---

## Environment split — use the test env, not the developer's sandbox

The local environment is the **developer's** running sandbox: the `daemon-start`
/ `daemon-stop` / `daemon-restart` / `daemon-monitor` scripts
(`docker-compose.local.yml`) and the local database are theirs, and local
migrations apply automatically on a local daemon restart. An AI agent works the
**test** environment instead — the `test:*` composer scripts in [testing/backend-suites.md](testing/backend-suites.md), against the
test database — and does not touch the local daemon or run local migrations
(`db:migration:up/down` against local is the developer's). This keeps an agent
out of a running local sandbox.

---

## Where each section went

The sections of this guide live in topic files under [testing/](testing/). A section named by its former title, or an address `testing.md#…` written against the single file, is found here:

| Former section | Now in |
|---|---|
| Framework backend (`framework/`) | [testing/backend-suites.md](testing/backend-suites.md#framework-backend-framework) |
| demo/chat backend (`demo/chat/`) | [testing/backend-suites.md](testing/backend-suites.md#demochat-backend-demochat) |
| Code-style guard and its baseline | [testing/phpunit-gates.md](testing/phpunit-gates.md#code-style-guard-and-its-baseline) |
| An issue fails the run | [testing/phpunit-gates.md](testing/phpunit-gates.md#an-issue-fails-the-run) |
| A test that runs past 60 seconds fails | [testing/phpunit-gates.md](testing/phpunit-gates.md#a-test-that-runs-past-60-seconds-fails) |
| A test leaves the process environment as it found it | [testing/phpunit-gates.md](testing/phpunit-gates.md#a-test-leaves-the-process-environment-as-it-found-it) |
| Selective testing — what to run for which change | [testing/selective.md](testing/selective.md#selective-testing--what-to-run-for-which-change) |
| What no run proves | [testing/selective.md](testing/selective.md#what-no-run-proves) |
| A cross-process defect is an e2e defect first | [testing/selective.md](testing/selective.md#a-cross-process-defect-is-an-e2e-defect-first) |
| The full run — one graph, a bounded number of lanes | [testing/full-run.md](testing/full-run.md#the-full-run--one-graph-a-bounded-number-of-lanes) |
| Stand instances · CPU weight of a stand · Log rotation of a stand · Data root of a stand · file ownership on a non-root clone (paragraphs formerly inside The full run) | [testing/stand-compose.md](testing/stand-compose.md) |
| A test that only passed on a retry makes the step red | [testing/full-run.md](testing/full-run.md#a-test-that-only-passed-on-a-retry-makes-the-step-red) |
| Playwright quarantine | [testing/full-run.md](testing/full-run.md#playwright-quarantine) |
| Narrow runs collect evidence | [testing/full-run.md](testing/full-run.md#narrow-runs-collect-evidence) |
| Ordinary demo carriers | [testing/ordinary-carriers.md](testing/ordinary-carriers.md#ordinary-demo-carriers) |
| Run and clean up an active ordinary stand | [testing/ordinary-carriers.md](testing/ordinary-carriers.md#run-and-clean-up-an-active-ordinary-stand) |
| Runner, isolation and the comparison budget | [testing/ordinary-carriers.md](testing/ordinary-carriers.md#runner-isolation-and-the-comparison-budget) |
| Ordinary and cluster phases | [testing/ordinary-carriers.md](testing/ordinary-carriers.md#ordinary-and-cluster-phases) |
| The cluster stands — six demos, six shapes | [testing/cluster-stands.md](testing/cluster-stands.md#the-cluster-stands--six-demos-six-shapes) |
| Cluster Compose model | [testing/cluster-harness.md](testing/cluster-harness.md#cluster-compose-model) |
| Cluster harness runner | [testing/cluster-harness.md](testing/cluster-harness.md#cluster-harness-runner) |
| Attributing a red snapshot guard | [testing/phpunit-gates.md](testing/phpunit-gates.md#attributing-a-red-snapshot-guard) |
| Re-running tests and state between runs | [testing/selective.md](testing/selective.md#re-running-tests-and-state-between-runs) |
| Forgetting loaded ORM state | [testing/orm-forgetting.md](testing/orm-forgetting.md#forgetting-loaded-orm-state) |
| Writing new tests | [testing/writing-tests.md](testing/writing-tests.md#writing-new-tests) |
