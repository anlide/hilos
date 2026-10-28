// The legal calendar date's shape. Under its own path the explicit-UTC print of
// a revision's YYYY-MM-DD passes; the acceptance moment beside it does not.

/** Formats a calendar date without letting the reader's time zone move its day. */
export function formatHilosLegalDate(date: string): string {
  const parsed = new Date(`${date}T00:00:00Z`)
  if (!Number.isFinite(parsed.getTime())) return date
  return new Intl.DateTimeFormat('en-GB', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
    timeZone: 'UTC',
  }).format(parsed)
}

/** A recorded acceptance is a moment, and goes through the core formatter. */
export function formatHilosLegalAcceptanceDate(moment: number): string {
  return new Intl.DateTimeFormat('en-GB', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
    timeZone: 'UTC',
  }).format(moment)
}
