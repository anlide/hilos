// SHELL-PARITY under test against one deliberately drifted SDK tree and one
// parity tree. The real SDK is judged by guard.test.ts after this rule joins it.
// Quoted concatenation is judged on a temporary copy of the parity tree.
import {
  cpSync,
  mkdtempSync,
  readFileSync,
  rmSync,
  writeFileSync,
} from 'node:fs'
import { tmpdir } from 'node:os'
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
    `SHELL-PARITY framework/frontend/vue/src/HilosRootPrimitive.vue:2 — the surface 'root-primitive' has no counterpart in react, angular ${DOC}`,
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
    `SHELL-PARITY framework/frontend/vue/src/admin/late/HilosLatePage.vue:9 — the surface 'late-page-only' has no counterpart in react ${DOC}`,
    `SHELL-PARITY framework/frontend/vue/src/admin/orphan/HilosOrphanDetail.vue:2 — the surface 'orphan-detail' has no counterpart in react, angular ${DOC}`,
    `SHELL-PARITY framework/frontend/vue/src/admin/shared/HilosSharedDetail.vue:2 — the surface 'shared-detail' has no counterpart in react ${DOC}`,
    `SHELL-PARITY framework/frontend/vue/src/index.ts:2 — the component 'VueOnlyComponent' is exported by vue and not by angular ${DOC}`,
    `SHELL-PARITY framework/frontend/vue/src/index.ts:4 — the component 'OtherPage' is exported by vue and not by react, angular ${DOC}`,
  ])
})

it('stays silent when all readable Vue surfaces and exports have peers', () => {
  expect(reportFixture('parity')).toEqual([])
})

/** Vue, React and Angular widgets inside the parity fixture. */
const VUE_WIDGET = 'framework/frontend/vue/src/Widget.vue'
const REACT_WIDGET = 'framework/frontend/react/src/Widget.tsx'
const ANGULAR_WIDGET = 'framework/frontend/angular/src/Widget.ts'

/**
 * @param edit Writes regression surfaces into the copied parity tree
 * @returns Its complete shell-parity report
 */
function reportParityCopy(edit: (root: string) => void): string[] {
  const root = mkdtempSync(join(tmpdir(), 'hilos-shell-parity-'))
  try {
    cpSync(join(REPOSITORY_ROOT, FIXTURES, 'parity'), root, {
      recursive: true,
    })
    edit(root)

    return checkTree(root)
  } finally {
    rmSync(root, { recursive: true, force: true })
  }
}

/**
 * @param root Absolute root of the copied tree
 * @param path Path from that root
 * @param anchor Unique source text the insertion is written in front of
 * @param insertion Lines placed immediately before the anchor
 */
function insertBefore(
  root: string,
  path: string,
  anchor: string,
  insertion: string,
): void {
  const full = join(root, path)
  const source = readFileSync(full, 'utf8')
  const at = source.indexOf(anchor)
  if (at < 0 || source.indexOf(anchor, at + anchor.length) !== -1) {
    throw new Error(`anchor is not unique in ${path}`)
  }
  writeFileSync(full, source.slice(0, at) + insertion + source.slice(at))
}

it('reads a quoted concatenation tail as the template prefix and keeps an exact literal', () => {
  expect(
    reportParityCopy((root) => {
      insertBefore(
        root,
        VUE_WIDGET,
        '</template>',
        [
          '  <div :data-id="`quoted-tail-${row.id}-end`" />',
          '  <div :data-id="`double-tail-${row.id}-end`" />',
          '  <div :data-id="`a\\\'b-${row.id}-end`" />',
          '  <div :data-id="\'exact-literal\'" />',
          '  <div :data-id=\'"exact-double"\' />',
          '  <div data-id="it\\\'s-exact" />',
          '',
        ].join('\n'),
      )
      insertBefore(
        root,
        REACT_WIDGET,
        '    </>',
        [
          '      <div data-id={`quoted-tail-${row.id}-end`} />',
          '      <div data-id={`double-tail-${row.id}-end`} />',
          "      <div data-id={`a\\'b-${row.id}-end`} />",
          "      <div data-id={'exact-literal'} />",
          '      <div data-id={"exact-double"} />',
          '      <div data-id="it\\\'s-exact" />',
          '',
        ].join('\n'),
      )
      insertBefore(
        root,
        ANGULAR_WIDGET,
        '  `,',
        [
          "    <div [attr.data-id]=\"'quoted-tail-' + row.id + '-end'\"></div>",
          '    <div [attr.data-id]=\'"double-tail-" + row.id + "-end"\'></div>',
          "    <div [attr.data-id]=\"'a\\'b-' + row.id + '-end'\"></div>",
          '    <div [attr.data-id]="\'exact-literal\'"></div>',
          '    <div [attr.data-id]=\'"exact-double"\'></div>',
          "    <div [attr.data-id]=\"'it\\'s-exact'\"></div>",
          '',
        ].join('\n'),
      )
    }),
  ).toEqual([])
})

it('does not let an empty prefix or an opaque expression cover a Vue surface', () => {
  expect(
    reportParityCopy((root) => {
      insertBefore(
        root,
        VUE_WIDGET,
        '</template>',
        [
          '  <div data-id="unread-one" />',
          '  <div data-id="unread-two" />',
          '',
        ].join('\n'),
      )
      insertBefore(
        root,
        REACT_WIDGET,
        '    </>',
        [
          '      <div data-id={`${row.id}-end`} />',
          '      <div data-id={row.id} />',
          '',
        ].join('\n'),
      )
      insertBefore(
        root,
        ANGULAR_WIDGET,
        '  `,',
        [
          "    <div [attr.data-id]=\"'' + row.id + '-end'\"></div>",
          '    <div [attr.data-id]="row.id"></div>',
          '',
        ].join('\n'),
      )
    }),
  ).toEqual([
    `SHELL-PARITY framework/frontend/vue/src/Widget.vue:9 — the surface 'unread-one' has no counterpart in react, angular ${DOC}`,
    `SHELL-PARITY framework/frontend/vue/src/Widget.vue:10 — the surface 'unread-two' has no counterpart in react, angular ${DOC}`,
  ])
})
