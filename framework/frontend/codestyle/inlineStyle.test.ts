// STYLE-INLINE under test against the seeded fixtures, which are the only thing
// proving the checker still fires. The real SDK and demo sources are judged by
// guard.test.ts, which runs every rule of this project against one baseline.
//
// There are three pairs of fixtures rather than one because a form the rule does
// not know is a hole nobody sees: each view framework spells an inline style its
// own way, and a pair proves both that the spelling is caught and that its
// look-alike is not.
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

import { expect, it } from 'vitest'

import { checkSource } from './inlineStyle.js'

/** framework/frontend/codestyle → framework/frontend → framework → the repository. */
const REPOSITORY_ROOT = join(
  dirname(fileURLToPath(import.meta.url)),
  '..',
  '..',
  '..',
)

/** Where the fixtures sit, addressed the way a report line addresses a file. */
const FIXTURES = 'framework/frontend/codestyle/fixtures'

/** The tail of the line a forbidden property earns, kept in one place. */
const CURE =
  ' inline; styling is Bootstrap classes, and only a CSS custom property (--*)' +
  ' may carry a computed value (see docs/agents/frontend/styling-rules.md)'

/** The whole line a site earns when the names it sets cannot be read there. */
const UNREADABLE =
  ' — the properties this style sets are not visible here; write the object' +
  ' literal in the template so the rule can read it' +
  ' (see docs/agents/frontend/styling-rules.md)'

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
 * @param line Line the site sits on
 * @param property First forbidden property the site sets
 * @returns The report line that site earns
 */
function sets(name: string, line: number, property: string): string {
  return `STYLE-INLINE ${FIXTURES}/${name}:${line} — an element sets '${property}'${CURE}`
}

/**
 * @param name File name inside the fixture directory
 * @param line Line the site sits on
 * @returns The report line a site whose names cannot be read earns
 */
function hides(name: string, line: number): string {
  return `STYLE-INLINE ${FIXTURES}/${name}:${line}${UNREADABLE}`
}

it('reports every Vue spelling the bad fixture seeds, one line per element', () => {
  expect(reportFixture('badInlineStyle.vue')).toEqual([
    sets('badInlineStyle.vue', 6, 'max-width'),
    sets('badInlineStyle.vue', 7, 'width'),
    sets('badInlineStyle.vue', 8, 'color'),
    hides('badInlineStyle.vue', 9),
    hides('badInlineStyle.vue', 10),
    sets('badInlineStyle.vue', 26, 'color'),
  ])
})

it('reports the React spellings and all three imperative forms', () => {
  expect(reportFixture('badInlineStyle.tsx')).toEqual([
    sets('badInlineStyle.tsx', 12, 'maxWidth'),
    hides('badInlineStyle.tsx', 13),
    sets('badInlineStyle.tsx', 20, 'color'),
    sets('badInlineStyle.tsx', 21, 'width'),
    hides('badInlineStyle.tsx', 22),
    sets('badInlineStyle.tsx', 23, 'cssText'),
  ])
})

it('reports every Angular spelling inside the component template', () => {
  expect(reportFixture('badInlineStyleAngular.ts')).toEqual([
    sets('badInlineStyleAngular.ts', 12, 'max-width'),
    sets('badInlineStyleAngular.ts', 13, 'width'),
    sets('badInlineStyleAngular.ts', 14, 'height'),
    sets('badInlineStyleAngular.ts', 15, 'color'),
    hides('badInlineStyleAngular.ts', 16),
    hides('badInlineStyleAngular.ts', 17),
  ])
})

it('stays silent on the look-alikes the good fixtures seed', () => {
  expect(reportFixture('goodInlineStyle.vue')).toEqual([])
  expect(reportFixture('goodInlineStyle.tsx')).toEqual([])
  expect(reportFixture('goodInlineStyleAngular.ts')).toEqual([])
})

it('reads an element only once, however many declarations it carries', () => {
  expect(
    checkSource(
      `${FIXTURES}/inline.html`,
      '<div style="color: red; width: 10px; height: 10px"></div>\n',
    ),
  ).toEqual([sets('inline.html', 1, 'color')])
})

it('leaves a declaration written inside a comment alone', () => {
  expect(
    checkSource(
      `${FIXTURES}/inline.html`,
      '<!-- <div style="color: red"></div> -->\n<div class="p-3"></div>\n',
    ),
  ).toEqual([])
})

it('keeps an expression carrying a comparison inside its own tag', () => {
  expect(
    checkSource(
      `${FIXTURES}/inline.html`,
      '<div :hidden="a > b" style="color: red"></div>\n',
    ),
  ).toEqual([sets('inline.html', 1, 'color')])
})
