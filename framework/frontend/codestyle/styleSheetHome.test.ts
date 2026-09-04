// STYLE-SHEET-HOME under test against the seeded fixture trees, which are the
// only thing proving the checker still fires. The real SDK and demo frontends are
// judged by guard.test.ts, which runs every rule of this project against one
// baseline.
//
// The fixtures are trees rather than files because this rule judges a file list:
// a stylesheet is legal by its path and by nothing else, so the case has to have
// a sanctioned home of its own to be a case at all.
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

import { expect, it } from 'vitest'

import { checkTree } from './styleSheetHome.js'

/** framework/frontend/codestyle → framework/frontend → framework → the repository. */
const REPOSITORY_ROOT = join(
  dirname(fileURLToPath(import.meta.url)),
  '..',
  '..',
  '..',
)

/** Where the two fixture trees sit, relative to the repository root. */
const FIXTURES = 'framework/frontend/codestyle/fixtures/styleSheetHome'

/**
 * The sanctioned home of the fixture trees, addressed from the tree being judged.
 * The good tree carries the file, the bad one does not — which is the point: the
 * same list decides both times.
 */
const FIXTURE_SANCTIONED = ['hilos-styles.scss']

/** The tail every report line carries, kept in one place so a test reads short. */
const CURE =
  ' custom declarations live only in hilos-styles.scss' +
  ' (see docs/agents/frontend/styling-rules.md)'

/**
 * Runs the checker over one fixture tree, which addresses its files from its own
 * root the way the repository scan addresses them from the repository's.
 *
 * @param name Directory name inside the fixture directory
 * @returns Reported lines, in path order
 */
function reportFixture(name: string): string[] {
  return checkTree(join(REPOSITORY_ROOT, FIXTURES, name), FIXTURE_SANCTIONED)
}

it('reports exactly the homes the bad fixture tree seeds', () => {
  expect(reportFixture('bad')).toEqual([
    `STYLE-SHEET-HOME Widget.ts:11 — a component declares styles of its own;${CURE}`,
    `STYLE-SHEET-HOME Widget.ts:12 — a component declares styles of its own;${CURE}`,
    `STYLE-SHEET-HOME Widget.vue:7 — an SFC carries a <style> block;${CURE}`,
    `STYLE-SHEET-HOME app.css:1 — a stylesheet outside the Bootstrap Sass layer;${CURE}`,
  ])
})

it('stays silent on the look-alikes the good fixture tree seeds', () => {
  expect(reportFixture('good')).toEqual([])
})

it('reports a stylesheet the sanctioned list does not name, whatever it is called', () => {
  expect(checkTree(join(REPOSITORY_ROOT, FIXTURES, 'good'), [])).toEqual([
    `STYLE-SHEET-HOME hilos-styles.scss:1 — a stylesheet outside the Bootstrap Sass layer;${CURE}`,
  ])
})
