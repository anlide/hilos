// ICON-NAME-EXISTS under test against the seeded fixture trees, which are the
// only thing proving the checker still fires. The real SDK and demo sources are
// judged by guard.test.ts, which runs every rule of this project against one
// baseline.
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

import { expect, it } from 'vitest'

import { checkTree } from './iconNameExists.js'

/** framework/frontend/codestyle → framework/frontend → framework → the repository. */
const REPOSITORY_ROOT = join(
  dirname(fileURLToPath(import.meta.url)),
  '..',
  '..',
  '..',
)

/** Where the two fixture trees sit, relative to the repository root. */
const FIXTURES = 'framework/frontend/codestyle/fixtures/iconNameExists'

/** Known icons for the fixture tests. */
const FIXTURE_ICONS = ['bi-flask', 'bi-check']

/** Installed version for the fixture tests. */
const FIXTURE_INSTALLED_VERSION = '1.13.1'

/**
 * Runs the checker over one fixture tree.
 *
 * @param name Directory name inside the fixture directory
 * @returns Reported lines, in path order
 */
function reportFixture(name: string): string[] {
  return checkTree(
    join(REPOSITORY_ROOT, FIXTURES, name),
    FIXTURE_ICONS,
    FIXTURE_INSTALLED_VERSION,
  )
}

it('reports exactly the missing names and version drift the bad fixture tree seeds', () => {
  const badMissing = 'bi-' + 'missing-icon'
  const badNonexistent = 'bi-' + 'nonexistent-icon'
  const doc = 'docs/agents/frontend/styling-rules.md'
  expect(reportFixture('bad')).toEqual([
    `ICON-NAME-EXISTS catalog.php:5 — ${badMissing} is not in the installed Bootstrap Icons set (1.13.1); use a name the set has (see ${doc})`,
    `ICON-NAME-EXISTS package.json:4 — bootstrap-icons is declared ^1.11.3 while 1.13.1 is installed; the declared floor is the version the tree stands on (see ${doc})`,
    `ICON-NAME-EXISTS surface.vue:2 — ${badNonexistent} is not in the installed Bootstrap Icons set (1.13.1); use a name the set has (see ${doc})`,
  ])
})

it('stays silent on the valid names and matched floor the good fixture tree seeds', () => {
  expect(reportFixture('good')).toEqual([])
})
