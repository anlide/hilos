// The browser-zone read's shape. The metadata chain passes by its form, at this
// path and at any other; a formatter kept or read for anything else does not.

/** The IANA zone this browser runs in, or null when it cannot say. */
export function readBrowserTimeZone(): string | null {
  try {
    return usable(new Intl.DateTimeFormat().resolvedOptions().timeZone)
  } catch {
    return null
  }
}

/** Kept first, the formatter could print anything later. */
export function readKeptTimeZone(): string {
  const formatter = new Intl.DateTimeFormat()
  return formatter.resolvedOptions().timeZone
}

/** The chain read for the locale is not the zone-name metadata. */
export function readBrowserLocale(): string {
  return new Intl.DateTimeFormat().resolvedOptions().locale
}

/**
 * @param value Whatever the engine resolved
 * @returns It when it is a zone name
 */
function usable(value: unknown): string | null {
  return typeof value === 'string' && value !== '' ? value : null
}
