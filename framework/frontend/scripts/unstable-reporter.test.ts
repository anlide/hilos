// The flaky-line rule on synthetic Playwright results: which outcomes are counted,
// how a name is spelled, and — the whole point of the gate — that a clean run
// prints nothing at all. No browser and no run are involved, which is exactly why
// the rule takes the reported tests as an argument.
import { expect, it, vi } from 'vitest'

import UnstableReporter, { unstableLine } from './unstable-reporter.mjs'

/** The config root every fake test below is addressed relative to. */
const ROOT_DIR = '/work/tests/e2e'

/** A Playwright `TestCase`, as much of one as the rule reads. */
function testCase(outcome: string, file: string, line: number) {
  return { outcome: () => outcome, location: { file: ROOT_DIR + file, line } }
}

/** A test that failed and then passed on a retry — the thing being counted. */
const FLAKY = testCase('flaky', '/tests/chat.spec.ts', 42)

/** A second one, in another spec, so a list has something to join. */
const ALSO_FLAKY = testCase('flaky', '/tests/table.spec.ts', 7)

/** A test that passed the first time, which is what most of a run looks like. */
const EXPECTED = testCase('expected', '/tests/chat.spec.ts', 12)

it('says nothing when no test flickered', () => {
  expect(unstableLine([EXPECTED], ROOT_DIR)).toBeNull()
})

it('says nothing when the run had no tests at all', () => {
  expect(unstableLine([], ROOT_DIR)).toBeNull()
})

it('names the one test that flickered, relative to the config root', () => {
  expect(unstableLine([EXPECTED, FLAKY], ROOT_DIR)).toBe(
    'hilos-unstable: 1 (tests/chat.spec.ts:42)',
  )
})

it('counts and lists every test that flickered', () => {
  expect(unstableLine([FLAKY, EXPECTED, ALSO_FLAKY], ROOT_DIR)).toBe(
    'hilos-unstable: 2 (tests/chat.spec.ts:42, tests/table.spec.ts:7)',
  )
})

it('counts neither a failure nor a skip as a flicker', () => {
  const graded = [
    testCase('unexpected', '/tests/chat.spec.ts', 20),
    testCase('skipped', '/tests/chat.spec.ts', 30),
  ]

  expect(unstableLine(graded, ROOT_DIR)).toBeNull()
})

it('writes the line to stdout once the run ends', () => {
  const written = reportedBy([EXPECTED, FLAKY])

  expect(written).toEqual(['hilos-unstable: 1 (tests/chat.spec.ts:42)\n'])
})

it('writes NOTHING at all when the run was clean', () => {
  expect(reportedBy([EXPECTED])).toEqual([])
})

/**
 * Everything the reporter writes to stdout over a whole run of the given tests.
 *
 * @param tests What the fake suite reports.
 * @returns The chunks written, so that "wrote nothing" is an empty array rather
 *   than an absent assertion.
 */
function reportedBy(tests: ReturnType<typeof testCase>[]): string[] {
  const written: string[] = []
  const stdout = vi
    .spyOn(process.stdout, 'write')
    .mockImplementation((chunk: string | Uint8Array): boolean => {
      written.push(String(chunk))

      return true
    })
  try {
    const reporter = new UnstableReporter()
    reporter.onBegin({ rootDir: ROOT_DIR }, { allTests: () => tests })
    reporter.onEnd()
  } finally {
    stdout.mockRestore()
  }

  return written
}
