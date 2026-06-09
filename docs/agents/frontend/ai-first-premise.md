# The AI-First Premise

Hilos is an **AI-first** framework: it is designed to be built and extended by
an AI agent reading a large, precise body of specification and rules — not
optimized for a human hand-authoring everything with the fewest keystrokes.
This premise is why the framework exists, and it shapes every other document in
this section. Read it first.

## What "AI-first" means here

A non-trivial feature — say, a page that lists users in a table — legitimately
spans several layers and languages:

- **PHP backend** — what data the page needs, who may see it, and how the
  payload is shaped for the browser.
- **The view** — the route and the component that renders it (Vue first; see
  `multiframework-core.md`).
- **Table / list / entity configuration** — columns and their sources, filters,
  and which entities the page composes.

This spread is **intentional and is kept**. The framework does not try to
collapse a page definition into one place or one language. That collapse is not
generally automatable, and chasing it is the dead end this project was created
to avoid. Instead of fighting the spread with converters, Hilos leans into it:
the leverage is a large, exact specification from which an AI agent assembles
the page correctly across every layer.

## Consequences

These follow directly from the premise and govern how the rest of this section
is written and used.

### The rule-set is the product

Every locked decision and every "gross Hilos violation" rule in this section
exists so that an AI agent builds the right thing without re-deriving it. The
density is the value, not overhead. Investing in specification, rules, and
skills is first-class framework work — budget for it as such, not as
documentation written after the fact.

### No converters or codegen in v1

Under the authoritative-backend model (see `core-and-connection.md`) every
signal, entity, and action shape is defined on the PHP side, and the TypeScript
frontend needs matching types. In v1 the **AI agent keeps the two sides
consistent from the specification** — no PHP→TS generator is built. A
contract-codegen tool (generating zod schemas and inferred TS types from the
PHP contract, for a single source of truth and zero drift) is a recorded
post-v1 optimization, never a v1 blocker.

### A multi-layer page is not a defect

A feature spread across several files and two languages is accepted, not a
problem to be tooled away. **Do not propose tooling whose only purpose is to
reduce how many files or languages a human touches** — under this premise that
solves the wrong problem. Tooling that improves *correctness* — validation,
contract checks, tests — is welcome; tooling that only saves human keystrokes
is not.

## Relationship to the rest of the section

This premise frames the SDK developer experience (`sdk-packaging.md`) and
supersedes any "single-place page definition" goal. The cross-cutting rules it
justifies are catalogued in `rules-and-violations.md`, and each topic document
applies them to its own surface.
