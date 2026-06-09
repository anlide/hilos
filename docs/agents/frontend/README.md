# Frontend

Start here for any Hilos frontend change. This file routes to the specification
documents by touched surface; it does not replace them.

The frontend is a single-page, no-refresh WebSocket application with a
**framework-agnostic core** and thin per-framework view layers (Vue first;
React and Angular as conformance demos). The specification is graduated from
the rewrite vision ahead of the code — read the foundations before any topic
document.

> **Status:** specification graduation in progress. Document names and scope are
> locked here; normative text lands incrementally, one document per commit. An
> entry stays a `code` filename until its document is authored, then becomes a
> link.

## Foundations (read first)

- `ai-first-premise.md` — why this specification is large and precise, and why
  collapsing a page definition into one place or one language is a non-goal.
- `rules-and-violations.md` — the enforced rules and the catalog of "gross Hilos
  violation" patterns that apply across every topic below.

## Reading Matrix

| Working on... | Read |
|---|---|
| connection / authentication / authorization state, the authoritative-backend rule, per-datum loading and placeholder state, disconnect and stale-data UX | `core-and-connection.md` |
| the handshake and cookie auth, the server-side session store, signals / actions / subscribe, discriminated-union parsing, requestId correlation, build-version forced refresh | `wire-protocol.md` |
| the entity store, the four scopes (page / session / user / group), the normalizer boundary, entity references, the rowKey / sourceKey / entityType keys | `data-model.md` |
| tables: viewport subscriptions, pending changes and Apply, row-id deltas, custom filters, search-as-filter | `table-subscription.md` |
| editing in a modal, the baseline / draft / incoming 3-way merge, surfacing conflicts, entity-deleted-while-open | `conflict-resolution.md` |
| SDK packaging, the monorepo workspace, the two-tier component model and slots, Composer / tarball distribution | `sdk-packaging.md` |
| the framework-agnostic core, the signal primitive, Vue / React / Angular adapters, the conformance demos | `multiframework-core.md` |
| styling — the Bootstrap-only rule, the Sass customization layer, theming, accessibility | `styling-rules.md` |
| tests — vitest across the monorepo, Playwright multi-context, full DB and daemon reset per test, stable-id selectors | `testing-strategy.md` |
| the build, the dev / unit / e2e / prod matrix, Docker dev, the Windows-Docker HMR spike | `build-and-docker.md` |

When a change touches more than one surface, read every matching document
before editing.

## Document Roles

- `ai-first-premise.md` — the framing premise: Hilos is built and extended by an
  AI agent reading a large, precise spec, so the dense rule-set is the product,
  not overhead.
- `rules-and-violations.md` — the cross-cutting enforced rules and gross-violation
  catalog referenced by every topic document.
- `core-and-connection.md` — the no-refresh SPA model: three orthogonal state
  machines (connection / authentication / authorization), the per-datum state
  machine, and the authoritative-backend / no-optimistic-update rule.
- `wire-protocol.md` — the handshake as the authorization step, the cookie-only
  auth credential and server-side session store, and the signal / action /
  subscribe protocol with layered discriminated-union parsing.
- `data-model.md` — the scope-partitioned entity store, the single normalizer
  boundary, entity references, and the keying invariants.
- `table-subscription.md` — viewport-scoped table subscriptions, the
  pending / Apply taxonomy, and row-id-anchored deltas.
- `conflict-resolution.md` — modal-owned editing with a baseline / draft /
  incoming 3-way merge and its edge cases.
- `sdk-packaging.md` — the dev monorepo, the two-tier SDK, the slot-first
  extension model, and Composer-vendored distribution.
- `multiframework-core.md` — the agnostic core, the neutral signal primitive,
  and the per-framework view adapters proven by conformance demos.
- `styling-rules.md` — Bootstrap-only styling, the Sass layer as the sole home
  for custom declarations, theming, and accessibility.
- `testing-strategy.md` — the unit and end-to-end strategy and the three e2e
  categories.
- `build-and-docker.md` — the build, the environment and test matrix, and the
  Windows-Docker dev-server research.

## Working Rule

Frontend FE↔BE contract changes — signals, signal and action DTO payloads,
routes, and DB / RT entity shapes — pass the Contract approval gate in
[agents.md](../../../agents.md) before implementation, exactly as backend
changes do. The specification can move; each move is a deliberate, confirmed
step.
