// The TypeScript half of SPELLING under test against the seeded fixtures, which
// are the only thing proving the checker still fires. The real SDK and demo
// sources are judged by guard.test.ts, which runs every rule of this project
// against one baseline. The PHP half is split the same way, between
// framework/tests/Unit/CodeStyle/{RuleFixtureTest,CodeStyleGuardTest}.
import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

import { expect, it } from 'vitest'

import { checkSource } from './spelling.js'

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
const DOC = ' (see docs/agents/code-style/spelling.md)'

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

it('reports exactly the British forms the bad fixture seeds', () => {
  expect(reportFixture('badSpelling.ts')).toEqual([
    `SPELLING ${FIXTURES}/badSpelling.ts:10 — write "color", not "colour"${DOC}`,
    `SPELLING ${FIXTURES}/badSpelling.ts:11 — write "COLOR", not "COLOUR"${DOC}`,
    `SPELLING ${FIXTURES}/badSpelling.ts:14 — write "CANCELED", not "CANCELLED"${DOC}`,
    `SPELLING ${FIXTURES}/badSpelling.ts:14 — write "canceled", not "cancelled"${DOC}`,
    `SPELLING ${FIXTURES}/badSpelling.ts:17 — write "behavior", not "behaviour"${DOC}`,
    `SPELLING ${FIXTURES}/badSpelling.ts:18 — write "license", not "licence"${DOC}`,
    `SPELLING ${FIXTURES}/badSpelling.ts:18 — write "organized", not "organised"${DOC}`,
    `SPELLING ${FIXTURES}/badSpelling.ts:20 — write "serialize", not "serialise"${DOC}`,
    `SPELLING ${FIXTURES}/badSpelling.ts:20 — write "Behavior", not "Behaviour"${DOC}`,
    `SPELLING ${FIXTURES}/badSpelling.ts:20 — write "behavior", not "behaviour"${DOC}`,
    `SPELLING ${FIXTURES}/badSpelling.ts:21 — write "behavioral", not "behavioural"${DOC}`,
    `SPELLING ${FIXTURES}/badSpelling.ts:22 — write "Color", not "Colour"${DOC}`,
    `SPELLING ${FIXTURES}/badSpelling.ts:22 — write "behavior", not "behaviour"${DOC}`,
    `SPELLING ${FIXTURES}/badSpelling.ts:22 — write "serializes", not "serialises"${DOC}`,
    `SPELLING ${FIXTURES}/badSpelling.ts:22 — write "COLOR", not "COLOUR"${DOC}`,
    `SPELLING ${FIXTURES}/badSpelling.ts:24 — write "Color", not "Colour"${DOC}`,
  ])
})

it('reads a .vue template, which no parser of this project would reach', () => {
  expect(reportFixture('badSpelling.vue')).toEqual([
    `SPELLING ${FIXTURES}/badSpelling.vue:7 — write "color", not "colour"${DOC}`,
    `SPELLING ${FIXTURES}/badSpelling.vue:7 — write "color", not "colour"${DOC}`,
    `SPELLING ${FIXTURES}/badSpelling.vue:8 — write "license", not "licence"${DOC}`,
    `SPELLING ${FIXTURES}/badSpelling.vue:8 — write "Canceled", not "Cancelled"${DOC}`,
    `SPELLING ${FIXTURES}/badSpelling.vue:14 — write "color", not "colour"${DOC}`,
    `SPELLING ${FIXTURES}/badSpelling.vue:14 — write "behavior", not "behaviour"${DOC}`,
  ])
})

it('stays silent on the look-alikes the good fixture seeds', () => {
  expect(reportFixture('goodSpelling.ts')).toEqual([])
})

it("lets the rule's own files write the British forms, since they hold the table", () => {
  expect(
    checkSource(
      'framework/frontend/codestyle/spelling.ts',
      "  colour: 'color',\n",
    ),
  ).toEqual([])
})
