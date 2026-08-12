// Make Playwright's retried tests machine-readable: a step that went green only
// because a test passed on a retry prints ONE line naming what flickered, so the
// runner can carry it into the run's summary and its rc ledger (HIL-560).
//
// `retries: 2` turning a flaky test into rc=0 is the right verdict — the suite
// did pass — but it also makes the flicker invisible, and an invisible flicker
// gets attributed to whichever ticket happens to be on Verify next. That
// misattribution cost HIL-468 two bounces, a needs-human label and a day of a
// healthy ticket standing still.
//
// The line is the entire contract between this file and
// `scripts/run-test-suite.php`. The runner parses this grammar and knows nothing
// about Playwright's own formats, because the same runner has to work in GitHub
// Actions, and a step that prints no such line (phpunit, vitest, check) reports
// zero by staying quiet.
//
// Silence at zero is deliberate and holds at every level — no line here, no field
// in the ledger, no section in the summary. A clean run has to look byte for byte
// the way it looks today, so that a section appearing at all already means there
// is something to look at; a line printed every single run becomes background
// exactly the way today's "N flaky" does.

import { relative } from 'node:path'
import process from 'node:process'

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

  /**
   * @param {{ rootDir: string }} config The resolved Playwright configuration.
   * @param {{ allTests: () => Array<ReportedTest> }} suite The root suite.
   */
  onBegin(config, suite) {
    this.#rootDir = config.rootDir
    this.#suite = suite
  }

  /** Print the line, if this run has one to print. */
  onEnd() {
    if (this.#suite === null) {
      return
    }
    const line = unstableLine(this.#suite.allTests(), this.#rootDir)
    if (line !== null) {
      process.stdout.write(line + '\n')
    }
  }
}
