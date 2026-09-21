// SHELL-PARITY under test against one deliberately drifted SDK tree and one
// parity tree. The real SDK is judged by guard.test.ts after this rule joins it.
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

import { expect, it } from 'vitest'

import { checkTree } from './shellParity.js'

/** framework/frontend/codestyle → framework/frontend → framework → repository. */
const REPOSITORY_ROOT = join(
  dirname(fileURLToPath(import.meta.url)),
  '..',
  '..',
  '..',
)

/** Fixture trees, relative to the repository root. */
const FIXTURES = 'framework/frontend/codestyle/fixtures/shellParity'

/** The canonical document tail shared by every report. */
const DOC = '(see docs/agents/frontend/multiframework-core.md)'

/**
 * @param name Fixture tree to check
 * @returns Its complete shell-parity report
 */
function reportFixture(name: string): string[] {
  return checkTree(join(REPOSITORY_ROOT, FIXTURES, name))
}

it('reports exactly the surfaces and exports the drifted tree seeds', () => {
  expect(reportFixture('drifted')).toEqual([
    `SHELL-PARITY framework/frontend/vue/src/Widget.vue:2 — the surface 'vue-only' has no counterpart in react, angular ${DOC}`,
    `SHELL-PARITY framework/frontend/vue/src/Widget.vue:3 — the surface 'strict-parity' has no counterpart in angular ${DOC}`,
    `SHELL-PARITY framework/frontend/vue/src/Widget.vue:5 — the surface 'vue-dynamic-*' has no counterpart in react, angular ${DOC}`,
    `SHELL-PARITY framework/frontend/vue/src/Widget.vue:6 — the surface 'angular-peer' has no counterpart in react ${DOC}`,
    `SHELL-PARITY framework/frontend/vue/src/Widget.vue:7 — the surface 'repeated' has no counterpart in react, angular ${DOC}`,
    `SHELL-PARITY framework/frontend/vue/src/Widget.vue:8 — the surface 'repeated' has no counterpart in react, angular ${DOC}`,
    `SHELL-PARITY framework/frontend/vue/src/Widget.vue:9 — the surface 'repeated' has no counterpart in react, angular ${DOC}`,
    `SHELL-PARITY framework/frontend/vue/src/Widget.vue:10 — the surface 'repeated' has no counterpart in react, angular ${DOC}`,
    `SHELL-PARITY framework/frontend/vue/src/Widget.vue:11 — the surface 'repeated' has no counterpart in react, angular ${DOC}`,
    `SHELL-PARITY framework/frontend/vue/src/Widget.vue:13 — the surface 'react-prop-only' has no counterpart in angular ${DOC}`,
    `SHELL-PARITY framework/frontend/vue/src/index.ts:2 — the component 'VueOnlyComponent' is exported by vue and not by angular ${DOC}`,
  ])
})

it('stays silent when all readable Vue surfaces and exports have peers', () => {
  expect(reportFixture('parity')).toEqual([])
})
