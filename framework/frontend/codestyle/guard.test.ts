// The one guard of the codestyle project: every frontend rule is run over the
// real SDK and demo sources here, and everything the baseline does not already
// own fails this test.
//
// One test rather than one per rule, and the reason is the baseline itself: it is
// a single file, and the update button rewrites it whole from the violations of
// the run that pressed it, so a second guard would erase the other's records
// every time either one was run. The PHP half is built the same way and says so
// in framework/tests/Unit/CodeStyle/CodeStyleGuardTest.php.
//
// What stays with each rule is its fixture test — the only thing proving the
// checker still fires, since the guard is silent on a clean tree either way.
import { existsSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

import { expect, it } from 'vitest'

import {
  Baseline,
  BASELINE_PATH,
  BASELINE_UPDATE_FLAG,
  type ReportedViolations,
} from './baseline.js'
import { checkRepository as checkE2ePageGoto } from './e2eGoto.js'
import { checkRepository as checkStyleInline } from './inlineStyle.js'
import { checkRepository as checkStyleSheetHome } from './styleSheetHome.js'
import { checkRepository as checkWireKeyCase } from './wireKeyCase.js'

/** framework/frontend/codestyle → framework/frontend → framework → the repository. */
const REPOSITORY_ROOT = join(
  dirname(fileURLToPath(import.meta.url)),
  '..',
  '..',
  '..',
)

/**
 * Every rule under the guard, in report order. A rule joins the guard by being
 * listed here and nowhere else — the baseline keys itself off the report line, so
 * no registration of the id is needed beside the checker's own.
 */
const RULES = [
  checkE2ePageGoto,
  checkStyleInline,
  checkStyleSheetHome,
  checkWireKeyCase,
]

/**
 * The head of a report line, whose shape automated-checks.md pins for both
 * suites: `<RULE-ID> <path>:<line> — ...`. It is read back here because the
 * baseline is keyed by rule and file, and the checkers hand over finished lines.
 */
const REPORT_LINE = /^(\S+) ([^\s:]+):\d+ /

/** What the guard says when a rule is right and the tree is not. */
const EXPLANATION =
  'Code-style rules are checked by machine. Fix the lines below, or — if the debt is old' +
  ` and owned by a leaf — record it in ${BASELINE_PATH} (regenerate with` +
  ` ${BASELINE_UPDATE_FLAG}=1).`

it('carries no code-style violation the baseline does not already own', () => {
  const reported = reportedViolations()
  const baseline = Baseline.fromText(baselineText())

  if (process.env[BASELINE_UPDATE_FLAG] === '1') {
    const update = baseline.update(reported)
    const text = update.text()
    if (text !== null) {
      writeFileSync(join(REPOSITORY_ROOT, BASELINE_PATH), text)
    }
    throw new Error(update.message())
  }

  expect(baseline.reconcile(reported), EXPLANATION).toEqual([])
})

/**
 * @returns Violation lines of every rule, keyed by `<rule id> <path>`
 */
function reportedViolations(): ReportedViolations {
  const reported: ReportedViolations = {}

  for (const check of RULES) {
    for (const line of check(REPOSITORY_ROOT)) {
      const key = keyOf(line)
      reported[key] = [...(reported[key] ?? []), line]
    }
  }

  return reported
}

/**
 * @param line One finished report line
 * @returns The baseline key it belongs to
 * @throws Error When the line does not carry the report shape both suites print
 */
function keyOf(line: string): string {
  const head = REPORT_LINE.exec(line)
  if (head === null) {
    throw new Error(`report line carries no rule id and path: ${line}`)
  }

  return `${head[1]} ${head[2]}`
}

/**
 * @returns Baseline contents, empty when the file does not exist yet
 */
function baselineText(): string {
  const path = join(REPOSITORY_ROOT, BASELINE_PATH)

  return existsSync(path) ? readFileSync(path, 'utf8') : ''
}
