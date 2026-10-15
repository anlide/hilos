import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

import { expect, it } from 'vitest'

import { checkRepository, checkSource } from './angularSelectOption.js'

const FIXTURES = join(
  dirname(fileURLToPath(import.meta.url)),
  'fixtures/angularSelectOption',
)
const DOC = '(see docs/agents/code-style/angular-select-options.md)'
const SELECT = 'bind selection on the options, not value on the select'
const OPTION = 'an option with a value binding needs a selected binding'

function fixture(name: string): string[] {
  return checkSource(name, readFileSync(join(FIXTURES, name), 'utf8'))
}

it('finds both binding spellings in literal inline templates and control flow', () => {
  expect(fixture('bad.ts')).toEqual([
    `ANGULAR-SELECT-OPTION bad.ts:8 — ${SELECT} ${DOC}`,
    `ANGULAR-SELECT-OPTION bad.ts:13 — ${OPTION} ${DOC}`,
    `ANGULAR-SELECT-OPTION bad.ts:21 — ${SELECT} ${DOC}`,
    `ANGULAR-SELECT-OPTION bad.ts:22 — ${OPTION} ${DOC}`,
  ])
})

it('reads multiline external templates and keeps quoted greater-than signs inside attributes', () => {
  expect(fixture('bad.html')).toEqual([
    `ANGULAR-SELECT-OPTION bad.html:1 — ${SELECT} ${DOC}`,
    `ANGULAR-SELECT-OPTION bad.html:5 — ${OPTION} ${DOC}`,
  ])
})

it.each(['good.ts', 'good.html', 'unsupported.ts'])(
  'ignores valid choices, look-alikes and declared blind spots in %s',
  (name) => expect(fixture(name)).toEqual([]),
)

it('reads quoted literal metadata and keeps the physical TypeScript line', () => {
  expect(
    checkSource(
      'page.ts',
      `@Component({ 'template': '<select bind-value="choice"></select>' })\nclass Page {}`,
    ),
  ).toEqual([`ANGULAR-SELECT-OPTION page.ts:1 — ${SELECT} ${DOC}`])
  expect(checkSource('page.vue', '<select [value]="choice"></select>')).toEqual(
    [],
  )
})

it('a selected binding on the option does not excuse a select value binding', () => {
  expect(
    checkSource(
      'page.html',
      '<select [value]="choice"><option [value]="choice" [selected]="true"></option></select>',
    ),
  ).toEqual([`ANGULAR-SELECT-OPTION page.html:1 — ${SELECT} ${DOC}`])
})

it('walks only SDK and Angular demo sources, leaving dependencies, build output and fixtures out', () => {
  expect(checkRepository(join(FIXTURES, 'repository'))).toEqual([
    `ANGULAR-SELECT-OPTION demo/angular-core/frontend/src/page.html:1 — ${SELECT} ${DOC}`,
    `ANGULAR-SELECT-OPTION demo/angular-sdk/frontend/src/Page.ts:1 — ${OPTION} ${DOC}`,
    `ANGULAR-SELECT-OPTION framework/frontend/angular/src/Page.ts:1 — ${SELECT} ${DOC}`,
    `ANGULAR-SELECT-OPTION framework/frontend/angular/src/window.html:1 — ${OPTION} ${DOC}`,
  ])
})
