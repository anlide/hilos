// The calendar boundaries' shape. Under its own path the field formatter and
// its parts become numbers and pass; printing through either is a site.

/** Read numeric Gregorian fields, independent of the browser's locale. */
function localCoordinate(
  formatter: Intl.DateTimeFormat,
  moment: number,
): number {
  const parts = formatter.formatToParts(moment)
  const part = (type: Intl.DateTimeFormatPartTypes): number =>
    Number(parts.find((item) => item.type === type)?.value)

  return Date.UTC(
    part('year'),
    part('month') - 1,
    part('day'),
    part('hour'),
    part('minute'),
    part('second'),
  )
}

/** The shared Gregorian field reader; UTC needs no Intl implementation. */
function calendarFormatter(timeZone: string): Intl.DateTimeFormat | null {
  return timeZone === 'UTC'
    ? null
    : new Intl.DateTimeFormat('en-US', {
        timeZone,
        calendar: 'gregory',
        numberingSystem: 'latn',
        year: 'numeric',
        month: 'numeric',
        day: 'numeric',
        hour: 'numeric',
        minute: 'numeric',
        second: 'numeric',
        hourCycle: 'h23',
      })
}

/** Numeric calendar arithmetic over the coordinate, getUTC* included. */
export function startOfDay(moment: number, timeZone: string): number {
  const formatter = calendarFormatter(timeZone)
  const local = new Date(
    formatter === null ? moment : localCoordinate(formatter, moment),
  )
  local.setUTCDate(local.getUTCDate() - ((local.getUTCDay() + 6) % 7))
  local.setUTCHours(0, 0, 0, 0)

  return local.getTime()
}

/** The same formatter printed is a display string. */
export function periodLabel(moment: number, timeZone: string): string {
  const formatter = calendarFormatter(timeZone)
  return formatter === null ? '' : formatter.format(moment)
}

/** Parts read outside the coordinate reader, from a formatter of its own. */
export function periodParts(moment: number): string[] {
  return new Intl.DateTimeFormat('en-US')
    .formatToParts(moment)
    .map((part) => part.value)
}

/** getUTC* fields pass while they count; as text they are a display, here too. */
export function periodKey(moment: number): string {
  const local = new Date(moment)
  return `${local.getUTCFullYear()}-${local.getUTCMonth() + 1}`
}
