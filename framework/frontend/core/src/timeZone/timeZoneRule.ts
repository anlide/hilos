/** The defensive end of the chain: a reader always reads some clock. */
const FALLBACK_TIME_ZONE = 'UTC'

/**
 * A numeric offset such as `+02:00`, `-0500` or `−05:00` (U+2212): the engine
 * accepts these as zones since ECMAScript 2024, but an offset is no reader's
 * clock — it has no daylight saving time. No IANA name starts with a sign.
 */
const NUMERIC_OFFSET = /^[+\-−]\d/

/**
 * The zone name exactly as given when this engine knows it, or null.
 * Shared with the sibling modules of timeZone/ only; not package API.
 *
 * A usable name is a non-empty string without outer whitespace, not a numeric
 * offset, that `Intl.DateTimeFormat` accepts as `timeZone`. The engine is the
 * judge, so every alias it recognizes passes, and the name is never trimmed,
 * re-cased or canonicalized. `Intl.supportedValuesOf` is no judge: it omits
 * aliases. An absent `Intl` judges nothing usable.
 *
 * @param value Any candidate, from a payload, the browser or a test.
 */
export function usableTimeZone(value: unknown): string | null {
  if (
    typeof value !== 'string' ||
    value === '' ||
    value.trim() !== value ||
    NUMERIC_OFFSET.test(value)
  ) {
    return null
  }

  try {
    new Intl.DateTimeFormat(undefined, { timeZone: value })

    return value
  } catch {
    return null
  }
}

/**
 * Resolve the reader's zone: the first usable of their own choice, the
 * browser's zone and the project's zone, else UTC. An unusable step is
 * skipped silently, and nothing throws.
 *
 * @param own The person's explicit IANA choice; null means Automatic.
 * @param browser The zone the current browser reports.
 * @param project The installation's project zone.
 */
export function resolveTimeZone(
  own: string | null | undefined,
  browser: string | null | undefined,
  project: string | null | undefined,
): string {
  return (
    usableTimeZone(own) ??
    usableTimeZone(browser) ??
    usableTimeZone(project) ??
    FALLBACK_TIME_ZONE
  )
}
