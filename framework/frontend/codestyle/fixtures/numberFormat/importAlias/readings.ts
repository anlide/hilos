// Receivers typed through an import: the numbers are sites only once the
// import resolves, and the moment is never this rule's.
import type { Count, Reading } from './counts.js'

/** An imported alias of number. */
export function printCount(count: Count): string {
  return count.toLocaleString()
}

/** An imported bigint beside an imported moment. */
export function printReading(reading: Reading): string {
  return `${reading.total.toLocaleString()} at ${reading.at.toLocaleString()}`
}
