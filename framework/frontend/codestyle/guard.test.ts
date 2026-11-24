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
import { checkRepository as checkCommentLanguage } from './commentLanguage.js'
import { checkRepository as checkDisabledTitle } from './disabledTitle.js'
import { checkRepository as checkE2eLightDesktop } from './e2eDisplay.js'
import { checkRepository as checkE2ePageGoto } from './e2eGoto.js'
import { checkRepository as checkIconNameExists } from './iconNameExists.js'
import { checkRepository as checkModalErrorRoom } from './modalErrorRoom.js'
import { checkRepository as checkModalFocus } from './modalFocus.js'
import { checkRepository as checkMomentFormat } from './momentFormat.js'
import { checkRepository as checkShellParity } from './shellParity.js'
import { checkRepository as checkUnbuiltPages } from './unbuiltPages.js'
import { checkRepository as checkSpelling } from './spelling.js'
import { checkRepository as checkStyleInline } from './inlineStyle.js'
import { checkRepository as checkStyleSheetHome } from './styleSheetHome.js'
import { checkRepository as checkStyleThemePinned } from './themePinned.js'
import { checkRepository as checkTableLiveRoom } from './tableLiveRoom.js'
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
  checkCommentLanguage,
  checkDisabledTitle,
  checkE2eBoxMeasure,
  checkE2eLightDesktop,
  checkE2ePageGoto,
  checkIconNameExists,
  modalErrorRoomViolations,
  checkModalFocus,
  checkMomentFormat,
  checkShellParity,
  checkUnbuiltPages,
  checkSpelling,
  checkStyleInline,
  checkStyleSheetHome,
  checkStyleThemePinned,
  (root: string) => checkTableLiveRoom(root, (line) => console.warn(line)),
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
 * This whole-tree scan runs every rule over SDK and demo sources, including
 * typed roots, so it needs a ceiling above the 5s default.
 *
 * Regression 9 reported 161,951 ms against the former 150-second ceiling.
 * Full validation 2916 on the test box (2026-11-24) timed out at 241,848 ms
 * against the 240-second cap. The 420-second cap leaves 178.152 seconds above
 * that measurement. These observations identify neither a slowdown cause nor
 * a responsible rule.
 */
const GUARD_TIMEOUT_MS = 420_000

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

/** Prints candidates for review and hands the baseline only proven violations. */
function modalErrorRoomViolations(repositoryRoot: string): string[] {
  const { violations, manualReview } = checkModalErrorRoom(repositoryRoot)
  if (manualReview.length)
    console.info(`Manual review\n${manualReview.join('\n')}`)
  return violations
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
