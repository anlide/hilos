// The seeded fixtures prove that E2E-BOX-MEASURE fires. The real demo roots are
// judged by guard.test.ts against the shared baseline, never a second guard.
import {
  mkdtempSync,
  mkdirSync,
  readFileSync,
  rmSync,
  writeFileSync,
} from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

import { expect, it } from 'vitest'

import { checkRepository, checkSource } from './boxMeasure.js'

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
  ' — a demo spec measures geometry through the shared toolbox, not through' +
  ' boundingBox()/getBoundingClientRect(), whose exact number turns a repaint into a false red' +
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

it('reports each direct box measurement, including both calls on one line', () => {
  expect(reportFixture('badBoxMeasure.ts')).toEqual([
    `E2E-BOX-MEASURE ${FIXTURES}/badBoxMeasure.ts:8${REASON}`,
    `E2E-BOX-MEASURE ${FIXTURES}/badBoxMeasure.ts:15${REASON}`,
    `E2E-BOX-MEASURE ${FIXTURES}/badBoxMeasure.ts:15${REASON}`,
    `E2E-BOX-MEASURE ${FIXTURES}/badBoxMeasure.ts:21${REASON}`,
    `E2E-BOX-MEASURE ${FIXTURES}/badBoxMeasure.ts:26${REASON}`,
  ])
})

it('stays silent on look-alikes, bookmarks and scroll measurements', () => {
  expect(reportFixture('goodBoxMeasure.ts')).toEqual([])
})

it('scans every demo including helpers, leaving the toolbox and dependencies out', () => {
  const repositoryRoot = mkdtempSync(join(tmpdir(), 'hilos-box-measure-'))
  const scanned = [
    'demo/chat/tests/e2e/helpers/page.ts',
    'demo/chat/tests/e2e/tests/about.spec.ts',
    'demo/polls/tests/e2e/helpers/box.ts',
    'demo/tasks/tests/e2e/tests/about.spec.ts',
  ]
  const excluded = [
    'framework/frontend/e2e/geometry.ts',
    'demo/chat/tests/e2e/node_modules/library/index.ts',
    'demo/chat/frontend/src/measure.ts',
  ]

  try {
    expect(checkRepository(repositoryRoot)).toEqual([])
    mkdirSync(join(repositoryRoot, 'demo/without-e2e'), { recursive: true })
    for (const path of [...scanned, ...excluded]) {
      const full = join(repositoryRoot, path)
      mkdirSync(dirname(full), { recursive: true })
      writeFileSync(full, 'await element.boundingBox()\n')
    }
    expect(checkRepository(repositoryRoot)).toEqual(
      scanned.map((path) => `E2E-BOX-MEASURE ${path}:1${REASON}`),
    )
  } finally {
    rmSync(repositoryRoot, { recursive: true, force: true })
  }
})
