// MODAL-FOCUS under test against the seeded fixtures, which are the only thing
// proving the checker still fires. The real SDK and demo sources are judged by
// guard.test.ts, which runs every rule of this project against one baseline.
//
// There are three pairs of fixtures rather than one because a form the rule does
// not know is a hole nobody sees: each view framework spells the mount, the prop
// and the mark its own way, and a pair proves both that the spelling is caught
// and that its look-alike is not.
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

import { expect, it } from 'vitest'

import { checkSource } from './modalFocus.js'

/** framework/frontend/codestyle → framework/frontend → framework → the repository. */
const REPOSITORY_ROOT = join(
  dirname(fileURLToPath(import.meta.url)),
  '..',
  '..',
  '..',
)

/** Where the fixtures sit, addressed the way a report line addresses a file. */
const FIXTURES = 'framework/frontend/codestyle/fixtures'

/** The tail every undeclared mount earns, kept in one place. */
const UNDECLARED =
  ' — this modal does not say where focus lands: mark the element focus belongs' +
  ' on with data-autofocus, or declare initial-focus="dialog" when there is' +
  ' nothing to fill (see docs/agents/frontend/accessibility.md)'

/** The tail a bound form earns. */
const EXPRESSION =
  ' — initial focus is declared by an expression and cannot be read: write it as' +
  ' a literal, "dialog" or "inner" (see docs/agents/frontend/accessibility.md)'

/** The tail an unknown static value earns. */
const UNKNOWN =
  ' — initial focus "first" is not a value this rule knows: write "dialog" or' +
  ' "inner" (see docs/agents/frontend/accessibility.md)'

/**
 * Runs the checker over a fixture exactly as the repository scan would.
 *
 * @param name File name inside the fixture directory
 * @returns Reported lines, in source order
 */
function reportFixture(name: string): string[] {
  const relativePath = `${FIXTURES}/${name}`

  return checkSource(
    relativePath,
    readFileSync(join(REPOSITORY_ROOT, relativePath), 'utf8'),
  )
}

/**
 * @param name File name inside the fixture directory
 * @param line Line the offending mount's opening tag sits on
 * @param tail Message tail including the leading em dash
 * @returns The report line that mount earns
 */
function hit(name: string, line: number, tail: string): string {
  return `MODAL-FOCUS ${FIXTURES}/${name}:${line}${tail}`
}

it('reports each of the four Vue forms once, on the line of the opening tag', () => {
  expect(reportFixture('badModalFocus.vue')).toEqual([
    hit('badModalFocus.vue', 5, UNDECLARED),
    hit('badModalFocus.vue', 8, EXPRESSION),
    hit('badModalFocus.vue', 11, UNKNOWN),
    hit('badModalFocus.vue', 14, UNDECLARED),
  ])
})

it('reports each of the four JSX forms once, on the line of the opening tag', () => {
  expect(reportFixture('badModalFocus.tsx')).toEqual([
    hit('badModalFocus.tsx', 15, UNDECLARED),
    hit('badModalFocus.tsx', 18, EXPRESSION),
    hit('badModalFocus.tsx', 21, UNKNOWN),
    hit('badModalFocus.tsx', 24, UNDECLARED),
  ])
})

it('reports each of the four Angular forms inside the component template', () => {
  expect(reportFixture('badModalFocusAngular.ts')).toEqual([
    hit('badModalFocusAngular.ts', 12, UNDECLARED),
    hit('badModalFocusAngular.ts', 15, EXPRESSION),
    hit('badModalFocusAngular.ts', 18, UNKNOWN),
    hit('badModalFocusAngular.ts', 21, UNDECLARED),
  ])
})

it('stays silent on the look-alikes the good fixtures seed', () => {
  expect(reportFixture('goodModalFocus.vue')).toEqual([])
  expect(reportFixture('goodModalFocus.tsx')).toEqual([])
  expect(reportFixture('goodModalFocusAngular.ts')).toEqual([])
})
