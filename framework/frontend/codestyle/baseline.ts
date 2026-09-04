// Known code-style debt of the frontend rules, anchored to a file and a count
// rather than to line numbers: any edit above a violation shifts its line, while
// the count survives.
//
// Every record must name the leaf that will remove it, so the baseline reads as a
// list of owed work instead of a silent mute list. It can only shrink: a record
// that outgrows its count, shrinks below it, or has nothing left to cover is
// reported the same way a fresh violation is.
//
// This is a port of framework/tests/CodeStyle/Baseline.php and BaselineUpdate.php
// — same record shape, same key, same four verdicts, same env flag. It is the
// same button on the other half of the same guard, and the two files are meant to
// be read side by side; the merge into one module is the only difference, PHP's
// one-class-per-file rule having no counterpart here.

/** Where the debt is recorded, addressed from the repository root. */
export const BASELINE_PATH = 'framework/frontend/codestyle/baseline.txt'

/** The variable that turns the guard into the update button, set to `1`. */
export const BASELINE_UPDATE_FLAG = 'CODESTYLE_BASELINE_UPDATE'

/** Violation lines of one scan, keyed by `<rule id> <path from repository root>`. */
export type ReportedViolations = Record<string, string[]>

/** What the file says about itself; regenerating the file rewrites this too. */
const HEADER = [
  '# Known code-style debt, one record per rule and file:',
  '#     <RULE-ID> <path from repository root> <count> # <HIL-nnn>',
  '# The ticket is mandatory: it names the leaf that removes the record.',
  '# Regenerate with CODESTYLE_BASELINE_UPDATE=1 on the frontend unit run.',
].join('\n')

/** A record the reader ignores: a comment, or the blank line between records. */
const IGNORED_RECORD = /^(#|$)/

/** How many whitespace-separated fields one record is written in. */
const RECORD_FIELDS = 5

/** The field that separates the count from the owing leaf. */
const TICKET_SEPARATOR = '#'

/** What a count looks like: digits and nothing else. */
const COUNT = /^\d+$/

/** What the owing leaf looks like; a record without one is malformed. */
const OWING_LEAF = /^HIL-\d+$/

/** Said when the button did its work, whatever it then refused to write. */
const REWRITTEN =
  'Baseline regenerated from the current tree — review the diff before committing it.'

/** Said above the records the button left alone, with their uncovered lines under them. */
const WITHHELD =
  'The update mode only shrinks the debt, so %d record(s) below stayed as they were.' +
  ' Fix the lines, or raise the count by hand: written by a person, the growth shows up' +
  ' in the diff as a decision.'

/** Said when the file could not be read, so it must not be written either. */
const REFUSED =
  'Baseline left untouched: its own records must be readable before the tree is written' +
  ' into them. Fix the lines below by hand.'

/**
 * Outcome of pressing the update button: the file to write, if writing is allowed
 * at all, and the whole failure message the guard test reports.
 *
 * The button only shrinks the debt, so an update routinely lands with records it
 * refused to touch; those refusals are the reason the message exists, and the
 * reason the outcome is a value of its own rather than a plain string.
 */
export class BaselineUpdate {
  /**
   * @param written Baseline file contents, or null when the file must not be written
   * @param withheld One block of lines per record the update refused to write
   * @param problems Reasons the baseline could not be written at all
   */
  private constructor(
    private readonly written: string | null,
    private readonly withheld: string[][],
    private readonly problems: string[],
  ) {}

  /**
   * The button did its work: the file is written, and every record it could not
   * shrink is named in the message.
   *
   * @param text Baseline file contents to write
   * @param withheld One block of lines per record left as it was
   * @returns Outcome carrying the new file
   */
  static rewritten(text: string, withheld: string[][]): BaselineUpdate {
    return new BaselineUpdate(text, withheld, [])
  }

  /**
   * The button did nothing: the baseline cannot be read, so it cannot be
   * rewritten without losing the records nobody could parse.
   *
   * @param problems Records rejected while reading the file
   * @returns Outcome carrying no file
   */
  static refused(problems: string[]): BaselineUpdate {
    return new BaselineUpdate(null, [], problems)
  }

  /**
   * @returns Baseline file contents, or null when nothing may be written
   */
  text(): string | null {
    return this.written
  }

  /**
   * @returns Whole failure message of the update run, refusals included
   */
  message(): string {
    if (this.written === null) {
      return [REFUSED, ...this.problems].join('\n')
    }
    if (this.withheld.length === 0) {
      return REWRITTEN
    }

    return [
      REWRITTEN,
      WITHHELD.replace('%d', String(this.withheld.length)),
      ...this.withheld.flat(),
    ].join('\n')
  }
}

/**
 * The debt as the file records it: what each record allows, who owes it, and what
 * could not be read at all.
 */
export class Baseline {
  /**
   * @param allowances Allowed count keyed by `<rule id> <path>`
   * @param tickets Owing leaf keyed by `<rule id> <path>`
   * @param parseProblems Records rejected while reading the file
   */
  private constructor(
    private readonly allowances: Record<string, number>,
    private readonly tickets: Record<string, string>,
    private readonly parseProblems: string[],
  ) {}

  /**
   * @param text Raw baseline file contents; empty text means no known debt
   * @returns Parsed baseline, carrying the problems of its own malformed records
   */
  static fromText(text: string): Baseline {
    const allowances: Record<string, number> = {}
    const tickets: Record<string, string> = {}
    const problems: string[] = []

    text.split('\n').forEach((raw, index) => {
      const line = raw.trim()
      if (IGNORED_RECORD.test(line)) {
        return
      }

      const fields = line.split(/\s+/)
      if (
        fields.length !== RECORD_FIELDS ||
        fields[3] !== TICKET_SEPARATOR ||
        !COUNT.test(fields[2])
      ) {
        problems.push(`baseline line ${index + 1} is malformed: ${line}`)
        return
      }
      if (!OWING_LEAF.test(fields[4])) {
        problems.push(
          `baseline record "${fields[0]} ${fields[1]}" names no owing leaf: ${fields[4]}`,
        )
        return
      }

      const key = `${fields[0]} ${fields[1]}`
      allowances[key] = Number(fields[2])
      tickets[key] = fields[4]
    })

    return new Baseline(allowances, tickets, problems)
  }

  /**
   * Everything the guard test must report: violations above the allowed count,
   * then the bookkeeping the baseline itself owes.
   *
   * @param reported Violation lines of the scan, keyed by `<rule id> <path>`
   * @returns Problems, ordered by record
   */
  reconcile(reported: ReportedViolations): string[] {
    const problems = [...this.parseProblems]

    for (const key of Object.keys(reported).sort()) {
      const allowance = this.allowances[key] ?? 0
      if (reported[key].length > allowance) {
        problems.push(...reported[key].slice(allowance))
      }
    }

    for (const key of Object.keys(this.allowances).sort()) {
      const left = reported[key]?.length ?? 0
      if (left === 0) {
        problems.push(`baseline record "${key}" is paid off — delete the line`)
        continue
      }
      if (left < this.allowances[key]) {
        problems.push(
          `baseline record "${key}" allows ${this.allowances[key]},` +
            ` only ${left} left — lower the count`,
        )
      }
    }

    return problems
  }

  /**
   * Rewrites the baseline against what the scan actually found, keeping the owing
   * leaf of every record that survives.
   *
   * The rewrite only shrinks the debt: a known record is written at the lower of
   * its count and the tree, a record with nothing left disappears, and a key the
   * baseline never knew is not written at all. Growth stays for a person to write
   * by hand, where it reads as a decision instead of a side effect of the button.
   *
   * @param reported Violation lines of the scan, keyed by `<rule id> <path>`
   * @returns File to write and the message the run reports
   */
  update(reported: ReportedViolations): BaselineUpdate {
    if (this.parseProblems.length > 0) {
      return BaselineUpdate.refused(this.parseProblems)
    }

    const lines = [HEADER]
    const withheld: string[][] = []
    for (const key of Object.keys(reported).sort()) {
      const found = reported[key].length
      const allowance = this.allowances[key]
      if (allowance === undefined) {
        withheld.push(
          withhold(
            `${key}: not written, the tree has ${found}` +
              ' — the update mode never adds a record',
            reported[key],
          ),
        )
        continue
      }
      if (found > allowance) {
        withheld.push(
          withhold(
            `${key}: kept at ${allowance}, the tree has ${found}` +
              ' — the update mode never raises a count',
            reported[key].slice(allowance),
          ),
        )
      }

      const written = Math.min(found, allowance)
      if (written === 0) {
        continue
      }

      lines.push(`${key} ${written} ${TICKET_SEPARATOR} ${this.tickets[key]}`)
    }

    return BaselineUpdate.rewritten(`${lines.join('\n')}\n`, withheld)
  }
}

/**
 * @param refusal Why the record was left as it was
 * @param lines Violation lines the record does not cover
 * @returns Refusal with its uncovered lines indented under it
 */
function withhold(refusal: string, lines: string[]): string[] {
  return [refusal, ...lines.map((line) => `  ${line}`)]
}
