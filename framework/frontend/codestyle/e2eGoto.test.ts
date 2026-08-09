// E2E-PAGE-GOTO under test twice over: against the seeded fixtures, which are the
// only thing proving the checker still fires, and against the demos' real e2e
// roots, which is the guard itself.
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

import { expect, it } from 'vitest'

import { checkRepository, checkSource } from './e2eGoto.js'

/** framework/frontend/codestyle → framework/frontend → framework → the repository. */
const REPOSITORY_ROOT = join(
  dirname(fileURLToPath(import.meta.url)),
  '..',
  '..',
  '..',
)

/** Where the fixtures sit, addressed the way a report line addresses a file. */
const FIXTURES = 'framework/frontend/codestyle/fixtures'

/** The tail every report line carries, kept in one place so a test reads short. */
const REASON =
  ' — a spec opens a page through gotoPage(), not through goto(), which waits' +
  " for the document and not for the subscription's answer" +
  ' (see docs/agents/frontend/testing-strategy.md)'

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

it('reports exactly the navigations the bad fixture seeds', () => {
  expect(reportFixture('badE2eGoto.ts')).toEqual([
    `E2E-PAGE-GOTO ${FIXTURES}/badE2eGoto.ts:14${REASON}`,
    `E2E-PAGE-GOTO ${FIXTURES}/badE2eGoto.ts:22${REASON}`,
    `E2E-PAGE-GOTO ${FIXTURES}/badE2eGoto.ts:23${REASON}`,
    `E2E-PAGE-GOTO ${FIXTURES}/badE2eGoto.ts:28${REASON}`,
  ])
})

it('stays silent on the look-alikes the good fixture seeds', () => {
  expect(reportFixture('goodE2eGoto.ts')).toEqual([])
})

it('lets the wrapper owner call goto, since it owns the wrappers', () => {
  expect(
    checkSource(
      'demo/chat/tests/e2e/helpers/page.ts',
      'await page.goto(path)\n',
    ),
  ).toEqual([])
})

it('finds no spec opening a page behind the wrappers', () => {
  expect(checkRepository(REPOSITORY_ROOT)).toEqual([])
})
