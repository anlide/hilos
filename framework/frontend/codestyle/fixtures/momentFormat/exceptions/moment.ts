// The engine's shape. Judged under the engine's own path it is the formatter
// itself and nothing is reported; under any other path in format/, every
// print below is a site, whatever its function is called.

/** Presentation in the reader's zone, the way the engine does it. */
export function formatMoment(moment: number, timeZone: string): string | null {
  if (
    typeof Intl === 'undefined' ||
    typeof Intl.DateTimeFormat !== 'function'
  ) {
    return null
  }
  if (new Date(moment).getUTCFullYear() < 0) {
    return null
  }
  return new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeZone,
  }).format(moment)
}

/** A captured snapshot, printed in the zone it was captured in. */
export function formatSnapshotMoment(moment: number): string {
  const formatter = new Intl.DateTimeFormat('en-US', { timeZone: 'UTC' })
  return formatter.format(moment) + new Date(moment).toUTCString()
}

/** Fields read back to check a parse are compared, never printed. */
export function readMoment(text: string): number | null {
  const date = new Date(text)
  return date.getUTCMonth() + 1 === Number(text.slice(5, 7))
    ? date.getTime()
    : null
}

/** The engine's fields in text: left out with the rest of the file. */
export function formatSnapshotDay(moment: number): string {
  const date = new Date(moment)
  return `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}`
}
