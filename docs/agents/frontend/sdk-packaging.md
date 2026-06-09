# SDK and Packaging

Part of the code lives in the framework SDK and part in the consumer project. The
SDK ships full, slot-extensible components so a project customizes by filling
slots, not by re-implementing templates — and a project's IDE resolves everything
correctly. Two concerns interlock here: how the code is developed (a monorepo)
and how it is shipped (vendored through Composer).

## The framework / project split

The SDK provides components, the agnostic core, and the framework machinery; the
project supplies its pages, its credential verifier, and its customizations.
Ideally a great many framework features appear in the project as **"empty
inheritance"** — a one-line re-export of an SDK component, customized only through
props and slots, never re-implemented. Duplicated component code is the anti-goal
(it is what the rewrite exists to remove).

## Dev monorepo workspace

Two needs pull in opposite directions: real package `exports` / `main` (so
consumers and PhpStorm resolve the SDK without deep-pathing into `src`) versus
live HMR into SDK source during development. A **monorepo workspace** (pnpm or npm
workspaces) with **conditional exports** resolves both:

- a `development` (source) condition resolves to `src` — HMR works while
  developing the SDK and a demo together;
- a build condition resolves to `dist` — consumers and the IDE get the built,
  typed package.

The SDK, the demo apps, and the React / Angular conformance demos
([multiframework-core.md](multiframework-core.md)) all develop together in this workspace.

## Distribution: a Composer-vendored tarball

Distribution is **separate** from the dev monorepo and is **Composer-only for
v1** — no public npm publish. The SDK build runs `npm pack` to produce a `.tgz`,
which ships inside the Composer package; a consumer project references it as a
`file:` dependency into `vendor/`. Every package manager **extracts a tarball as a
copy** — never a symlink (symlinks are rejected; they have caused real pain).
There is no custom installer: the only recurring step is `npm install` after a
`composer update` re-pulls Hilos, and that step is documented (a README note
and/or a composer `post-update-cmd` reminder) so a consumer never runs a stale
vendored SDK.

A consumer project is **not** part of the monorepo — it vendors Hilos through
Composer. (A spike alternative, if the built `dist` is self-contained, is a pure
Vite `resolve.alias` + tsconfig `paths` straight into `vendor/.../dist`, removing
the install hop; see [build-and-docker.md](build-and-docker.md).)

## Keep the agnostic core a separate package

The agnostic core is its **own** workspace package, cleanly split from the Vue
view layer. v1 ships only through Composer, but keeping the core separate means
publishing **just the core** to npm later — for a pure-JS or non-PHP consumer —
stays a cheap, additive option, never a v1 channel.

## Template extension, not re-implementation

TS inheritance is clear; "template inheritance" is the real question. The answer
in Vue: the SDK ships **full** components with extension points via **slots,
scoped slots, and composables**. A project customizes by filling slots, swapping
sub-components, or passing config — and **never** by re-implementing a template.
**No mixins.** Re-implementing an SDK component's template to change it is the
duplication this model exists to prevent.

## Two tiers of components

The SDK component library is two tiers, differing in how much extension surface
they expose.

### Tier 1 — universal components

Modal, loading-button, toast, inputs, table shell, and the like. These offer the
**maximum** extension surface, authored slot-first with scoped slots and
composables so a project can customize them heavily.

### Tier 2 — page-chunk components

Feature pages (logs, backups, users). These are more opinionated, with **fewer**
extension points; a project customizes them by composing tier-1 primitives and a
few slots, and replaces one wholesale only when it truly diverges.

The mechanism across both tiers is the same — slots + scoped slots + shared
composables, no mixins — and "empty inheritance" (a one-line re-export) is the
default when nothing is customized.

## Tier-1 cross-cutting components

Three tier-1 components are part of the contract, so pages never reinvent them:

- a **`HilosErrorBoundary`** wrapping each page or major block, so one
  component's runtime error degrades locally instead of blanking the long-lived
  SPA;
- standard **skeleton / empty / error** components for a data block's three
  states — the concrete form of "a placeholder for everything"
  ([core-and-connection.md](core-and-connection.md)).

Per-page code-splitting (a dynamic `import()` per route) pairs with these: the
loading state during a chunk fetch is the skeleton ([build-and-docker.md](build-and-docker.md)).

## IDE correctness

Real `exports` / `main` plus the built `dist` and types are what let PhpStorm and
the TypeScript language service resolve SDK symbols correctly in a consumer
project — the reason distribution ships a built package, not raw `src`.
