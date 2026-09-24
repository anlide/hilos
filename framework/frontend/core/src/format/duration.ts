// Turning a span of time into what a person reads. A core/format helper that
// carries the three forms the mockups draw and nothing else — every "switched off"
// or "not measured" answer stays with the screen that owns it.
//
// The three forms stay three on purpose: a threshold an administrator typed reads
// in words ('30 days'), a measured run reads compactly ('3m 05s'), and a ticking
// countdown reads as a clock ('1:05').

/** Milliseconds in one second, the step a countdown is read in. */
const MILLISECONDS_PER_SECOND = 1000

/** Seconds in one minute. */
const SECONDS_PER_MINUTE = 60

/** Seconds in one hour. */
const SECONDS_PER_HOUR = 3600

/** Seconds in one day. */
const SECONDS_PER_DAY = 86400

/** The units a span in words may be read in, largest first, second excluded. */
const WORD_UNITS: ReadonlyArray<{ seconds: number; noun: string }> = [
  { seconds: SECONDS_PER_DAY, noun: 'day' },
  { seconds: SECONDS_PER_HOUR, noun: 'hour' },
  { seconds: SECONDS_PER_MINUTE, noun: 'minute' },
]

/**
 * A count and the word it counts, in agreement.
 *
 * @param count How many.
 * @param noun The singular of what is counted.
 */
function counted(count: number, noun: string): string {
  return `${count} ${noun}${count === 1 ? '' : 's'}`
}

/**
 * A span in words, in the largest unit the span is whole in: '30 days',
 * '36 hours', '90 seconds'.
 *
 * @param seconds The span, in whole seconds.
 */
export function formatDurationInWords(seconds: number): string {
  for (const unit of WORD_UNITS) {
    if (seconds >= unit.seconds && seconds % unit.seconds === 0) {
      return counted(seconds / unit.seconds, unit.noun)
    }
  }

  return counted(seconds, 'second')
}

/**
 * A measured span, compactly: '7s', '3m 05s', '1h 02m 05s'.
 *
 * The leading unit is printed without a leading zero and every unit after it in two
 * digits, so a column of runs lines up by eye.
 *
 * @param seconds The span, in whole seconds.
 */
export function formatDurationShort(seconds: number): string {
  if (seconds < SECONDS_PER_MINUTE) {
    return `${seconds}s`
  }

  const secondsPart = String(seconds % SECONDS_PER_MINUTE).padStart(2, '0')
  if (seconds < SECONDS_PER_HOUR) {
    return `${Math.floor(seconds / SECONDS_PER_MINUTE)}m ${secondsPart}s`
  }

  const minutesPart = String(
    Math.floor((seconds % SECONDS_PER_HOUR) / SECONDS_PER_MINUTE),
  ).padStart(2, '0')

  return `${Math.floor(seconds / SECONDS_PER_HOUR)}h ${minutesPart}m ${secondsPart}s`
}

/**
 * What is left until a moment, as a clock the person watches tick: '1:05'.
 *
 * Seconds are rounded up, so the clock never reads '0:00' while the moment is still
 * ahead. Redrawing it every second stays with the view that shows it.
 *
 * @param moment The local-scale epoch-ms moment counted down to, or null when
 *   nothing is armed.
 * @param now The clock the span is measured against — the ticking one, so the
 *   number keeps counting down instead of freezing where the screen opened.
 * @returns The countdown, or null when no moment is armed or it has passed.
 */
export function formatCountdown(
  moment: number | null,
  now: number,
): string | null {
  if (moment === null) {
    return null
  }
  const left = moment - now
  if (left <= 0) {
    return null
  }
  const seconds = Math.ceil(left / MILLISECONDS_PER_SECOND)
  const minutes = Math.floor(seconds / SECONDS_PER_MINUTE)

  return `${minutes}:${String(seconds % SECONDS_PER_MINUTE).padStart(2, '0')}`
}
