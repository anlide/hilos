// DISABLED-TITLE under test against the seeded fixtures, which are the only thing
// proving the checker still fires. The real SDK and demo sources are judged by
// guard.test.ts, which runs every rule of this project against one baseline.
//
// There are three pairs of fixtures rather than one because a form the rule does
// not know is a hole nobody sees: each view framework spells disabled, loading,
// title and aria-label its own way, and a pair proves both that the spelling is
// caught and that its look-alike is not.
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

import { expect, it } from 'vitest'

import { checkSource } from './disabledTitle.js'

/** framework/frontend/codestyle → framework/frontend → framework → the repository. */
const REPOSITORY_ROOT = join(
  dirname(fileURLToPath(import.meta.url)),
  '..',
  '..',
  '..',
)

/** Where the fixtures sit, addressed the way a report line addresses a file. */
const FIXTURES = 'framework/frontend/codestyle/fixtures'

/** The tail every offending element earns, kept in one place. */
const CURE =
  ' — a control that can be disabled carries a title that is not its accessible' +
  ' name; a disabled element gets no mouse events, so the reason must be visible' +
  ' in the row (see docs/agents/frontend/accessibility.md)'

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
 * @param line Line the offending element's title sits on
 * @returns The report line that element earns
 */
function hides(name: string, line: number): string {
  return `DISABLED-TITLE ${FIXTURES}/${name}:${line}${CURE}`
}

it('reports each of the four Vue forms once, on the line of the title', () => {
  expect(reportFixture('badDisabledTitle.vue')).toEqual([
    hides('badDisabledTitle.vue', 11),
    hides('badDisabledTitle.vue', 14),
    hides('badDisabledTitle.vue', 17),
    hides('badDisabledTitle.vue', 24),
  ])
})

it('reports each of the four JSX forms once, on the line of the title', () => {
  expect(reportFixture('badDisabledTitle.tsx')).toEqual([
    hides('badDisabledTitle.tsx', 27),
    hides('badDisabledTitle.tsx', 30),
    hides('badDisabledTitle.tsx', 33),
    hides('badDisabledTitle.tsx', 40),
  ])
})

it('reports each of the four Angular forms inside the component template', () => {
  expect(reportFixture('badDisabledTitleAngular.ts')).toEqual([
    hides('badDisabledTitleAngular.ts', 15),
    hides('badDisabledTitleAngular.ts', 18),
    hides('badDisabledTitleAngular.ts', 22),
    hides('badDisabledTitleAngular.ts', 31),
  ])
})

it('stays silent on the look-alikes the good fixtures seed', () => {
  expect(reportFixture('goodDisabledTitle.vue')).toEqual([])
  expect(reportFixture('goodDisabledTitle.tsx')).toEqual([])
  expect(reportFixture('goodDisabledTitleAngular.ts')).toEqual([])
})

it('reads a name broken over lines as the same name', () => {
  expect(
    checkSource(
      `${FIXTURES}/control.html`,
      '<button disabled title="Delete backup"\n' +
        '  aria-label="Delete\n    backup"></button>\n',
    ),
  ).toEqual([])
})

it('does not take a written text for a variable of the same name', () => {
  expect(
    checkSource(
      `${FIXTURES}/control.html`,
      '<button disabled title="label" [attr.aria-label]="label"></button>\n',
    ),
  ).toEqual([hides('control.html', 1)])
})

it('leaves a pair written inside a comment alone', () => {
  expect(
    checkSource(
      `${FIXTURES}/control.html`,
      '<!-- <button disabled title="Why"></button> -->\n<button></button>\n',
    ),
  ).toEqual([])
})
