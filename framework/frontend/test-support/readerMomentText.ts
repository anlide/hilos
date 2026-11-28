// The text a view shows for a moment in the built-in reader formats — the date
// DD.MM.YYYY and the time HH:mm:ss of the English locale without a country —
// worked out apart from the core formatter: the moment's numeric parts in the
// zone come from the engine's own en-GB calendar and are laid out here. A view
// test compares a redrawn moment with it in any zone it chooses.

/** What a view writes, in the words of MomentFormatOptions. */
export interface ReaderMomentTextOptions {
  readonly kind?: 'date' | 'time' | 'dateTime'
  readonly seconds?: boolean
  readonly milliseconds?: boolean
}

/**
 * The moment in the built-in reader formats, in the given zone.
 *
 * @param moment Epoch milliseconds, an ISO UTC timestamp or a Date.
 * @param timeZone The reader's zone the view draws in.
 * @param options The kind and the seconds and milliseconds of the time.
 */
export function readerMomentText(
  moment: number | string | Date,
  timeZone: string,
  options: ReaderMomentTextOptions = {},
): string {
  const parts = new Intl.DateTimeFormat('en-GB', {
    timeZone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    fractionalSecondDigits: 3,
    hourCycle: 'h23',
  }).formatToParts(new Date(moment))
  const part = (type: Intl.DateTimeFormatPartTypes): string =>
    parts.find((item) => item.type === type)?.value ?? ''
  const date = `${part('day')}.${part('month')}.${part('year')}`
  const seconds = options.seconds ?? true
  const time = `${part('hour')}:${part('minute')}${
    seconds ? `:${part('second')}` : ''
  }${seconds && options.milliseconds === true ? `.${part('fractionalSecond')}` : ''}`
  switch (options.kind ?? 'dateTime') {
    case 'date':
      return date
    case 'time':
      return time
    default:
      return `${date} ${time}`
  }
}
