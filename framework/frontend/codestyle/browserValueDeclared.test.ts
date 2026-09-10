// BROWSER-VALUE-DECLARED under test against the seeded fixtures, which are the
// only thing proving the checker still fires. The real SDK and demo sources are
// judged by guard.test.ts, which runs every rule of this project against one
// baseline.
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

import { expect, it } from 'vitest'

import { checkSource } from './browserValueDeclared.js'

/** framework/frontend/codestyle → framework/frontend → framework → the repository. */
const REPOSITORY_ROOT = join(
  dirname(fileURLToPath(import.meta.url)),
  '..',
  '..',
  '..',
)

/** Where the fixtures sit, addressed the way a report line addresses a file. */
const FIXTURES = 'framework/frontend/codestyle/fixtures'

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

it('reports the undeclaring file once, at its first write', () => {
  expect(reportFixture('badBrowserValue.ts')).toEqual([
    'BROWSER-VALUE-DECLARED framework/frontend/codestyle/fixtures/badBrowserValue.ts:13 —' +
      ' this file leaves a value in this browser and declares none; declare it with' +
      ' browserValue({ store, key, label }) beside the write, so the erase on /privacy' +
      ' can sweep it (see docs/agents/frontend/core-and-connection.md)',
  ])
})

it('stays silent on the file that declares, and on the look-alikes beside it', () => {
  expect(reportFixture('goodBrowserValue.ts')).toEqual([])
})

it('is silent on a file that writes nothing at all', () => {
  expect(
    checkSource(
      'framework/frontend/core/src/nothing.ts',
      'export const a = 1\n',
    ),
  ).toEqual([])
})
