# Reading a Ref in a Vue Template

Read this when a Vue single-file component reads something off a value handed to
it — a prop, or what a composable returned — or when the `VUE-TEMPLATE-REF`
guard fails on your change.

## Core Rule

Inside the `<template>` block of a `.vue` file, a field of a **ref-bearing
value** is read through `.value`, or it is unwrapped once in `<script setup>`
and the template reads the unwrapped name. Never bare.

```vue
<!-- wrong: `action.error` is a ref object, and an object is always truthy -->
<div v-if="action.error">{{ action.error }}</div>

<!-- right, and the shape this repository prefers -->
<div v-if="message">{{ message }}</div>
```

```ts
const message = computed(() => props.action.error.value)
```

Writing `.value` in the template is right too, and the tree does it — see
`framework/frontend/vue/src/admin/communications/HilosCommunicationsChannelPage.vue`,
which tests `testAction.loading.value`. The computed is preferred where the same
field is read more than once, because it names the thing once.

**Checked automatically: `VUE-TEMPLATE-REF`**, see
[automated-checks.md](automated-checks.md).

## Why the rule exists at all

Vue unwraps a ref that is a **top-level binding** of `<script setup>`, and it
unwraps nothing that sits one level below that. A ref held as a **field of a
plain object** — which is exactly what `useTrackedAction()` returns, and what a
`TrackedAction` prop carries — stays a ref in a template expression.

An interpolation happens to survive this: `toDisplayString` unwraps a ref by
itself, so `{{ action.error }}` prints the message. Every other position does
not. `v-if="action.error"` tests a `RefImpl` object, which is truthy whether the
message is there or not, so the block is drawn forever.

That is not a hypothetical. `HilosActionError.vue` drew an empty red alert on
every admin screen, before any action had failed, from the day it was written
until a person opened the screen and said so (HIL-887).

## Why it is a machine check and not a note

Nothing in the toolchain says a word about it, and each silence has its own
reason:

- **The types are content.** `Ref<string | null>` is a legal thing to test for
  truthiness. `tsc` is right and the code is wrong.
- **ESLint cannot see it.** The frontend config is the recommended presets with
  no type-aware rules (`framework/frontend/eslint.config.mjs`), and the truth
  about an expression here is a type.
- **A component without a test is judged by nobody.** The plate had no test on
  any front until the leaf that fixed it wrote one.

So the rule is enforced by a checker that reads the template as text, and the
only thing it needs from the type system is a list of names.

## What the checker judges

`framework/frontend/codestyle/vueTemplateRef.ts`, on `.vue` files only.

A value is **ref-bearing** when it arrives through one of two declarations in
the file's own `<script setup>`:

- `const <name> = <composable>(…)`, with the composable named in
  `REF_BEARING_COMPOSABLES` — today `useTrackedAction`;
- a prop declared in `defineProps<{ … }>()` with a type named in
  `REF_BEARING_TYPES` — today `TrackedAction`.

Widening the rule to another composable or another type is **one line** in one
of those two lists.

A read of `<name>.<field>` in the template is a violation unless the next thing
written is `.value`. A call is not a read: `action.run(handle)` names a function,
and a function is not a ref.

The whole template is judged, interpolations included, even though Vue would
have managed there. "Bare in the moustaches, `.value` in an attribute" is a rule
nobody holds in their head while editing a template, and the price of the
uniform one is a `.value` that was not strictly needed.

## Blind spots the rule declares about itself

- **A value that arrived any other way.** Destructured out of a composable,
  imported from another module, handed over through `provide`/`inject`, or
  declared with a type alias the file does not spell out — the checker reads the
  two declarations above and nothing else.
- **A composable or a type nobody listed.** A new composable that returns refs
  as fields is invisible until its name is added.

Both are the price of a lexical rule. Closing them means taking the type of
every template expression, which is a type-aware pass over `.vue` files and is
not what this project is.

## The other view layers have no such rule

React hands over plain values, and Angular hands over signals, which are called.
Neither has a shape where a read looks right and answers wrong, so neither has a
half of this rule.
