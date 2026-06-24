# Frontend Import Paths

Read this before adding or changing a relative import in frontend TypeScript
code — the agnostic core, the view packages, and the demos. For PHP `use`
aliases and helper names see
[import-aliases-and-helper-names.md](import-aliases-and-helper-names.md).

## Core Rule

A relative import carries the explicit `.js` extension of its compiled target,
and a barrel import names `index.js` in full. The frontend compiles under
`moduleResolution: "Bundler"` (where the extension is optional), but the project
writes `.js` everywhere so the same source resolves under strict ESM / NodeNext
unchanged — there a directory or extensionless import does not resolve. Do not
drop the extension and do not shorten `'…/index.js'` to the directory `'…'`.

## Preferred Shape

```ts
import { TableViewportController } from '../table/TableViewportController.js' // sibling module
import {
  HilosViewportTable,
  createHilosTrackedAction,
} from '../src/index.js'                                       // package barrel
```

## Anti-Patterns

```ts
import { x } from '../src'                                // directory import
import { x } from '../src/index'                          // no extension
import { TableViewportController } from '../table/TableViewportController' // no extension
```

PhpStorm's **"Import can be shortened"** inspection flags a `'…/index.js'` barrel
import and its quick-fix rewrites it to the directory form `'…'` above — a false
friend that breaks the convention and ESM portability. Do not apply it. Silence
the inspection in the IDE (Settings → Editor → Inspections → "Import can be
shortened"), never by editing the import. `.idea` is per-machine (gitignored), so
the setting stays local; this rule is the shared record of why the warning is
ignored.

## Exceptions

Bare package specifiers (`@hilos/core`, `vitest`, framework packages) are not
relative imports — they carry no extension. The rule covers relative paths only.
