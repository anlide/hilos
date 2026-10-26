// Make Playwright's retried tests machine-readable: print ONE line naming what
// flickered so the repository runner can fail the step and carry the evidence
// into the summary and rc ledger, even when Playwright itself exits zero.
// The reporter supplies evidence; scripts/run-test-suite.php owns the verdict.
//
// The line is the entire contract between this file and the runner. It works
// on a fresh checkout with no external pipeline and needs no Playwright-specific
// parser. A step that prints no such line (phpunit, vitest, check) reports zero
// by staying quiet.
//
// Silence at zero is deliberate and holds at every level — no line here, no field
// in the ledger, no section in the summary. A clean run has to look byte for byte
// the way it looks today, so that a section appearing at all already means there
// is something to look at; a line printed every single run becomes background
// exactly the way today's "N flaky" does.

import { writeFileSync } from 'node:fs'
import { relative } from 'node:path'
import process from 'node:process'
import { stripVTControlCharacters } from 'node:util'

import { normalizeResults, plannedTest } from './quarantine.mjs'

/** The grammar's fixed head; `scripts/unstable-line.php` matches the same text. */
const LINE_PREFIX = 'hilos-unstable: '

/** What Playwright grades a test that failed and then passed on a retry. */
const FLAKY_OUTCOME = 'flaky'

/** How the reported names are joined inside the parentheses. */
const NAME_SEPARATOR = ', '

/**
 * A Playwright `TestCase`, as much of one as the rule below reads — which is what
 * lets the rule be exercised without a browser or a run.
 *
 * @typedef {object} ReportedTest
 * @property {() => string} outcome How Playwright graded it once retries were done.
 * @property {{ file: string, line: number }} location Where the test is declared.
 */

/**
 * The one line a step prints about the tests that only passed on a retry, or null
 * when nothing flickered.
 *
 * @param {Array<ReportedTest>} tests Every test the run reported, in report order.
 * @param {string} rootDir The config's root directory. Names are relative to it so
 *   that a log reads as `spec:line`, the same inside the e2e container as on the
 *   host, where the absolute paths differ.
 * @returns {string | null}
 */
export function unstableLine(tests, rootDir) {
  const flaky = tests.filter((test) => test.outcome() === FLAKY_OUTCOME)
  if (flaky.length === 0) {
    return null
  }
  const names = flaky.map(
    (test) => `${relative(rootDir, test.location.file)}:${test.location.line}`,
  )

  return `${LINE_PREFIX}${flaky.length} (${names.join(NAME_SEPARATOR)})`
}

/**
 * The reporter itself: a shell around `unstableLine` holding the two things
 * `onEnd` is not handed — the root directory and the suite that was run.
 */
export default class UnstableReporter {
  /** @type {string} The resolved config root, learned when the run begins. */
  #rootDir = ''

  /** @type {{ allTests: () => Array<ReportedTest> } | null} The root suite. */
  #suite = null

  // Run-local reporter observations; no application runtime is loaded here.
  #observations = []

  /**
   * @param {{ rootDir: string }} config The resolved Playwright configuration.
   * @param {{ allTests: () => Array<ReportedTest> }} suite The root suite.
   */
  onBegin(config, suite) {
    this.#rootDir = config.rootDir
    this.#suite = suite
    if (process.env.HILOS_E2E_PHASE === 'quarantine') this.#writeResults()
  }

  /** Persist real attempts as they arrive, including a partially finished run. */
  onTestEnd(test, result) {
    if (process.env.HILOS_E2E_PHASE !== 'quarantine') return
    const plan = plannedTest(test, this.#rootDir)
    if (plan.flake === null) return
    this.#observations.push(
      ...normalizeResults(
        [plan],
        [
          {
            ...plan,
            status: result.status,
            reason: stripVTControlCharacters(result.error?.message ?? ''),
          },
        ],
      ),
    )
    this.#writeResults()
  }

  #writeResults() {
    if (!process.env.HILOS_E2E_RESULT_FILE) {
      throw new Error('HILOS_E2E_RESULT_FILE is required in quarantine')
    }
    writeFileSync(
      process.env.HILOS_E2E_RESULT_FILE,
      JSON.stringify({
        version: 1,
        tests: this.#observations,
      }),
    )
  }

  /** Print the line, if this run has one to print. */
  onEnd() {
    if (process.env.HILOS_E2E_PHASE === 'quarantine' || this.#suite === null) {
      return
    }
    const line = unstableLine(this.#suite.allTests(), this.#rootDir)
    if (line !== null) {
      process.stdout.write(line + '\n')
    }
  }
}
