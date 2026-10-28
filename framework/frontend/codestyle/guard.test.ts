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

import { checkRepository as checkAngularSelectOption } from './angularSelectOption.js'
import {
  Baseline,
  BASELINE_PATH,
  BASELINE_UPDATE_FLAG,
  type ReportedViolations,
} from './baseline.js'
import { checkRepository as checkE2eBoxMeasure } from './boxMeasure.js'
import { checkRepository as checkBrowserValueDeclared } from './browserValueDeclared.js'
import { checkRepository as checkDisabledTitle } from './disabledTitle.js'
import { checkRepository as checkE2eLightDesktop } from './e2eDisplay.js'
import { checkRepository as checkE2ePageGoto } from './e2eGoto.js'
import { checkRepository as checkIconNameExists } from './iconNameExists.js'
import { checkRepository as checkModalFocus } from './modalFocus.js'
import { checkRepository as checkMomentFormat } from './momentFormat.js'
import { checkRepository as checkShellParity } from './shellParity.js'
import { checkRepository as checkUnbuiltPages } from './unbuiltPages.js'
import { checkRepository as checkSpelling } from './spelling.js'
import { checkRepository as checkStyleInline } from './inlineStyle.js'
import { checkRepository as checkStyleSheetHome } from './styleSheetHome.js'
import { checkRepository as checkStyleThemePinned } from './themePinned.js'
import { checkRepository as checkTypeCheck } from './typeCheck.js'
import { checkRepository as checkVueTemplateRef } from './vueTemplateRef.js'
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
  checkAngularSelectOption,
  checkBrowserValueDeclared,
  checkDisabledTitle,
  checkE2eBoxMeasure,
  checkE2eLightDesktop,
  checkE2ePageGoto,
  checkIconNameExists,
  checkModalFocus,
  checkMomentFormat,
  checkShellParity,
  checkUnbuiltPages,
  checkSpelling,
  checkStyleInline,
  checkStyleSheetHome,
  checkStyleThemePinned,
  checkTypeCheck,
  checkVueTemplateRef,
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

/**
 * The one test here that reads the whole tree, and the only one needing a
 * ceiling of its own: every rule walks the SDK and the demos and hands each
 * source to the compiler, which is not the shape the 5s default is sized for.
 * The scan sits close enough to that default to cross it under load, and a run
 * lost that way costs far more than it reports — the tree carried no violation
 * either time, and proving so took a second run.
 *
 * The number is generous because generosity is free: a ceiling is only ever
 * waited on when it is hit, so a scan that ends in twenty seconds ends in
 * twenty seconds whatever stands here. Raising it therefore buys the honest
 * margin without lengthening a single green run.
 *
 * Since TYPE-CHECK the scan also compiles the codestyle roots: about twenty
 * seconds on the line, and 36.5 at seven lanes on a busy host, which a half
 * minute ceiling turned into a red run with no violation (2026-10-19).
 *
 * Since MOMENT-FORMAT it also builds a typed program for every SDK and demo
 * source root (2026-10-28). On an idle host the guard went from 7.3 seconds to
 * about 13, once each declaration file was parsed once per scan, and inside
 * the full frontend cycle it took 27 to 32. The twelve Verify runs before it
 * spent 19 to 30 seconds on the guard without the rule, three to four times
 * its idle time; the same factor puts the guard with it at 40 to 55, and a
 * narrow run beside a full one had crossed the minute before the declarations
 * were shared. Ninety keeps over that the margin a minute kept over 36.5.
 *
 * What a minute and a half still catches is the failure worth catching — a
 * rule that stopped terminating or a walk that left the repository — and the
 * cure for that is the rule that hangs, not a larger number written here.
 */
const GUARD_TIMEOUT_MS = 90_000

it(
  'carries no code-style violation the baseline does not already own',
  () => {
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
  },
  GUARD_TIMEOUT_MS,
)

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
