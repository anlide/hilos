// The zone-name validation's shape. Under its own path the bare construction in
// usableTimeZone passes; the neighbors below are sites there too.

/** A bare construction with the zone in its options validates the name. */
export function usableTimeZone(value: unknown): string | null {
  if (typeof value !== 'string' || value === '') {
    return null
  }

  try {
    new Intl.DateTimeFormat(undefined, { timeZone: value })

    return value
  } catch {
    return null
  }
}

/** The same bare form beside the validation is not the validation. */
export function checkTimeZone(value: string): boolean {
  new Intl.DateTimeFormat(undefined, { timeZone: value })
  return true
}

/** Printing in the validated zone is display. */
export function previewTimeZone(value: string, moment: number): string {
  return new Intl.DateTimeFormat(undefined, { timeZone: value }).format(moment)
}
