// E2E-PAGE-GOTO under test against the seeded fixtures, which are the only thing
// proving the checker still fires. The real e2e roots are judged by guard.test.ts,
// which runs every rule of this project against one baseline — a rule holding a
// repository assertion of its own would be a second guard, and the baseline is
// one file.
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

import { expect, it } from 'vitest'

import { checkSource } from './e2eGoto.js'

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
    `E2E-PAGE-GOTO ${FIXTURES}/badE2eGoto.ts:25${REASON}`,
    `E2E-PAGE-GOTO ${FIXTURES}/badE2eGoto.ts:33${REASON}`,
    `E2E-PAGE-GOTO ${FIXTURES}/badE2eGoto.ts:34${REASON}`,
    `E2E-PAGE-GOTO ${FIXTURES}/badE2eGoto.ts:39${REASON}`,
    `E2E-PAGE-GOTO ${FIXTURES}/badE2eGoto.ts:44${REASON}`,
    `E2E-PAGE-GOTO ${FIXTURES}/badE2eGoto.ts:45${REASON}`,
    `E2E-PAGE-GOTO ${FIXTURES}/badE2eGoto.ts:46${REASON}`,
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

it('reads the stand base from the shared gateway module', () => {
  expect(
    checkSource(
      'demo/polls/tests/e2e/tests/auth.spec.ts',
      "import { STAND_GATEWAY_URL as BASE } from '../../../../../framework/frontend/scripts/standGateway.mjs'\n" +
        'await page.goto(`${BASE}/oauth/github/authorize`)\n',
    ),
  ).toEqual([])
})

it('does not take a same-named constant from anywhere else for the stand base', () => {
  const spec = 'demo/chat/tests/e2e/tests/lookAlike.spec.ts'

  expect(
    checkSource(
      spec,
      "const STAND_GATEWAY_URL = '/hilos'\n" +
        'await page.goto(`${STAND_GATEWAY_URL}/settings`)\n',
    ),
  ).toEqual([`E2E-PAGE-GOTO ${spec}:2${REASON}`])
  expect(
    checkSource(
      spec,
      "import { STAND_GATEWAY_URL } from '../helpers/product'\n" +
        'await page.goto(`${STAND_GATEWAY_URL}/settings`)\n',
    ),
  ).toEqual([`E2E-PAGE-GOTO ${spec}:2${REASON}`])
  expect(
    checkSource(
      spec,
      "import { STAND_GATEWAY_URL } from '../helpers/gateway'\n" +
        'await page.goto(`${STAND_GATEWAY_URL}/oauth/github/authorize`)\n',
    ),
  ).toEqual([`E2E-PAGE-GOTO ${spec}:2${REASON}`])
})
