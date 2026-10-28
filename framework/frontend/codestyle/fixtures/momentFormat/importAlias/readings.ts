// Receivers typed through an import: the Stamp prints are sites, the numbers are
// not — but only once the import resolves.
import type { Count, Reading, Stamp } from './stamps.js'

/** An imported alias of Date. */
export function printStamp(at: Stamp): string {
  return at.toLocaleString()
}

/** An imported alias of number. */
export function printCount(count: Count): string {
  return count.toLocaleString()
}

/** Both on one line, only the moment reported. */
export function printReading(reading: Reading): string {
  return `${reading.count.toLocaleString()} at ${reading.at.toLocaleString()}`
}

/** An imported bigint. */
export function printTotal(reading: Reading): string {
  return reading.total.toLocaleString()
}
