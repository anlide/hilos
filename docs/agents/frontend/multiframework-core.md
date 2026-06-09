# Multi-Framework Core

The product is built in Vue, but Hilos is a framework, so it provides for React
and Angular from the start — this is the one moment to organize cross-framework
compatibility cheaply, before pages scale. The mechanism is a **framework-agnostic
core** in plain TypeScript with **thin per-framework view layers** over it.

## The agnostic core owns all non-visual logic

A single core, plain TypeScript, owns everything that is not rendering:

- WS transport, handshake, and reconnect ([wire-protocol.md](wire-protocol.md),
  [core-and-connection.md](core-and-connection.md));
- the signal / action protocol and its discriminated-union parsers
  ([wire-protocol.md](wire-protocol.md));
- the subscription manager — page, group, and row-id viewport
  ([table-subscription.md](table-subscription.md));
- the normalized entity store and the per-scope stores ([data-model.md](data-model.md));
- table logic (filter / sort / paginate / pending) as **headless state
  machines**;
- modal and conflict logic as **headless state machines**
  ([conflict-resolution.md](conflict-resolution.md)).

A view layer renders over this core and emits user intents; it holds no protocol,
store, or table logic of its own.

## The core never imports a framework

The agnostic core **never imports Vue** (or React, or Angular) — only a neutral
signal primitive and plain TypeScript. This is the rule that keeps it portable: a
Vue import in the core is a leak that the conformance demos below exist to catch.

## The signal primitive

"Off Pinia" means adopting an **existing** reactive signal primitive, never
hand-rolling one. The default is **`@vue/reactivity`** — a standalone package that
runs without Vue: the Vue adapter is then nearly free, React adapts via
`useSyncExternalStore`, and Angular via signals / `effect`. The choice is
reversible because it sits **behind the store API**; neutral alternatives
(`alien-signals`, `@preact/signals-core`) could replace it without touching
consumers.

## Per-framework view adapters

Each framework gets a thin adapter over the core:

- **Vue** — the primary view layer and the canonical product (the chat demo);
- **React** — adapts via `useSyncExternalStore`; the likeliest real adopter;
- **Angular** — adapts via signals / `effect`; it stresses the seam hardest (DI,
  zones, RxJS), so it is the most valuable portability proof.

The reference resolver (`useEntity` in Vue) is a per-framework wrapper over the
same plain-function core selector ([data-model.md](data-model.md)).

## Templates cannot be shared

An honest constraint: templates **cannot** be shared across Vue, React, and
Angular. Two options were weighed:

- **(a) per-framework view layers** — templates are triplicated but each is thin,
  over the one shared core (**chosen**);
- **(b) a web-components layer** (e.g. Lit) all three embed — rejected for v1 due
  to interop friction (forms, SSR, styling).

So the triplication is deliberate and bounded: only the thin view renders, never
the logic.

## Conformance demos, not full parity

React and Angular are **not** held to full parity with the Vue product — that is
unsustainable. Instead, a small slice that exercises the core's hardest seams —
connect / auth → subscribe → table + pending → edit-in-modal + conflict — is
built on React and Angular as **minimal conformance demos**, kept green in CI.
Two thin extra consumers *prove* the core is agnostic; a Vue-only codebase cannot
(one consumer hides leaked assumptions). These demos are the simplest of the
project's planned demos, so they double as real demos and as portability proofs.

Unit tests run framework-free against the core (most logic needs no browser); the
React slice uses vitest + `@testing-library/react`, and the Angular slice uses
vitest via the Analog plugin ([testing-strategy.md](testing-strategy.md)).

## Sequencing

Build the slice in **Vue first**, then port that same slice to **React, then
Angular, early** — before scaling pages — so portability is proven while the core
is still small and cheap to change. After that, every new core capability stays
green in all three demos as it lands.
