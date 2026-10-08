import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { expect, it } from 'vitest'
import { checkTree } from './unbuiltPages.js'

const DOC = '(see docs/agents/frontend/page-module-structure.md)'

const FIXTURES = join(
  dirname(fileURLToPath(import.meta.url)),
  'fixtures/unbuiltPages',
)

it('accepts a child staged by a row while other layered entries stay honest', () => {
  expect(checkTree(join(FIXTURES, 'honest'))).toEqual([])
})

it('reports each kind of registry drift with an actionable source location', () => {
  expect(checkTree(join(FIXTURES, 'lying'))).toEqual([
    `UNBUILT-PAGE framework/frontend/angular/src/admin/Stub.ts:5 — 'hilos_stub' is unbuilt in angular; list it in HILOS_UNBUILT_PAGES ${DOC}`,
    `UNBUILT-PAGE framework/frontend/codestyle/stagedChildren.ts:2 — the staged row for 'hilos_layered' under 'hilos_hub' in react is spent: 'hilos_hub' is built there; remove it (HIL-1) ${DOC}`,
    `UNBUILT-PAGE framework/frontend/codestyle/stagedChildren.ts:8 — the staged row for 'hilos_stub' under 'hilos_empty' in vue is spent: 'hilos_stub' is listed unbuilt there; remove it (HIL-2) ${DOC}`,
    `UNBUILT-PAGE framework/frontend/codestyle/stagedChildren.ts:14 — the staged row for 'hilos_stub' under 'hilos_hub' in vue needs a catalog child of an admin page, a layer of vue, react or angular, and a HIL-<n> leaf ${DOC}`,
    `UNBUILT-PAGE framework/frontend/core/src/routing/hilosPages.ts:5 — 'hilos_missing' is unbuilt in react; list it in HILOS_UNBUILT_PAGES ${DOC}`,
    `UNBUILT-PAGE framework/frontend/core/src/routing/hilosUnbuiltPages.ts:3 — 'hilos_built' is built in vue; strike vue from its entry ${DOC}`,
    `UNBUILT-PAGE framework/frontend/core/src/routing/hilosUnbuiltPages.ts:6 — 'hilos_hub' hides 'hilos_layered' in vue; strike vue from the section, list the unbuilt child in HILOS_UNBUILT_PAGES, or stage the child in STAGED_CHILDREN ${DOC}`,
    `UNBUILT-PAGE framework/frontend/core/src/routing/hilosUnbuiltPages.ts:7 — 'hilos_empty' hides 'hilos_stub' in angular; strike angular from the section, list the unbuilt child in HILOS_UNBUILT_PAGES, or stage the child in STAGED_CHILDREN ${DOC}`,
    `UNBUILT-PAGE framework/frontend/core/src/routing/hilosUnbuiltPages.ts:9 — 'hilos_public' is not an admin page; remove its registry entry ${DOC}`,
    `UNBUILT-PAGE framework/frontend/core/src/routing/hilosUnbuiltPages.ts:10 — '[HilosPages.UNKNOWN]' is not an admin page; remove its registry entry ${DOC}`,
    `UNBUILT-PAGE framework/frontend/core/src/routing/hilosUnbuiltPages.ts:10 — '[HilosPages.UNKNOWN]' needs a nonempty list of distinct vue, react, angular layers ${DOC}`,
    `UNBUILT-PAGE framework/frontend/core/src/routing/hilosUnbuiltPages.ts:11 — 'hilos_dashboard' needs a nonempty list of distinct vue, react, angular layers ${DOC}`,
    `UNBUILT-PAGE framework/frontend/react/src/admin/Empty.tsx:4 — 'hilos_empty' is unbuilt in react; list it in HILOS_UNBUILT_PAGES (list its section too when none of its pages is built) ${DOC}`,
  ])
})
