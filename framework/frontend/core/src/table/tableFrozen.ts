// What a table says when its window stopped receiving its live changes (HIL-1139):
// the rows on the screen stay as they were, and the live line over them says since
// when they may be out of date. Like tableStaleness.ts this file holds the words and
// one pure function — no window logic, which lives in the TableViewportController,
// and no markup, which belongs to each view package.
//
// The words live here rather than in the three view packages for the reason
// RT_STALENESS_COPY already carries (protocol/rtStaleness.ts): three shells wording
// the same fact each in its own way is three different products.

import { toLocal } from '../session/serverClock.js'

/** The words a frozen table is spoken with — the live line, and its name once another message holds it. */
export const TABLE_FROZEN_COPY = {
  /** The live line while the table is frozen; `{time}` is the moment of the first failure in the reader's own timezone. */
  bar: 'This table has not updated since {time}. The rows shown may be out of date.',
  /** How the frozen table is named after the message holding the line. */
  rest: 'the table stopped updating',
} as const

/**
 * The live line of a frozen table, with the moment in the reader's own timezone.
 *
 * The wire carries a server moment, so the conversion goes through `serverClock`, the
 * same way the page-wide mark of a lost source does (`rtStalenessLabel`).
 *
 * @param since Server milliseconds of the first failure, or null while the table is live.
 * @returns The sentence, or null when the table is not frozen.
 */
export function hilosTableFrozenLabel(since: number | null): string | null {
  if (since === null) {
    return null
  }

  return TABLE_FROZEN_COPY.bar.replace(
    '{time}',
    new Date(toLocal(since)).toLocaleTimeString(),
  )
}
