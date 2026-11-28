// A number as this tab's region writes it: the group and decimal separators of
// the region's number template, never the browser's locale. One of the four
// templates of the reference — «1,000.00», «1 000,00», «1 000.00», «1.000,00» —
// read by position: the character after the leading 1 groups thousands, the one
// before the last two zeros separates the fraction. A space is printed as a
// no-break space, so the figure never wraps. The digits are assembled here, by
// hand, without Intl (docs/agents/architecture/language-and-region.md).
import {
  HILOS_DEFAULT_READER_FORMATS,
  hilosReaderFormats,
} from './readerDisplay.js'

/** The shape every number template of the reference has. */
const NUMBER_TEMPLATE = /^1\D000\D00$/u
/** Positions of the two separators inside a number template. */
const GROUP_SEPARATOR_AT = 1
const DECIMAL_SEPARATOR_AT = 5
const NO_BREAK_SPACE = ' '
const DEFAULT_MAXIMUM_FRACTION_DIGITS = 3
const COMPACT_MAXIMUM_FRACTION_DIGITS = 1
/** Compact steps in ascending order; the first one writes the number itself. */
const COMPACT_STEPS = [
  { size: 1, suffix: '' },
  { size: 1e3, suffix: 'K' },
  { size: 1e6, suffix: 'M' },
  { size: 1e9, suffix: 'B' },
] as const
const GROUP_LENGTH = 3
const THOUSANDS = /\B(?=(\d{3})+(?!\d))/gu

/** The written integer and fraction digits of one magnitude, without a sign. */
interface DecimalText {
  readonly integer: string
  readonly fraction: string
}

/**
 * Add one to a string of decimal digits; an empty string becomes '1'.
 *
 * @param digits The digits to increment, most significant first.
 */
function increment(digits: string): string {
  let carry = 1
  let result = ''
  for (let index = digits.length - 1; index >= 0; index -= 1) {
    const digit = Number(digits.charAt(index)) + carry
    carry = digit === 10 ? 1 : 0
    result = `${digit % 10}${result}`
  }

  return carry === 1 ? `1${result}` : result
}

/**
 * Round a magnitude half up at the given fraction digit. Reads the shortest
 * decimal that round-trips the number, so 1.005 rounds as it is written, to
 * 1.01, and trailing fraction zeros are dropped down to the minimum.
 *
 * @param magnitude A finite, non-negative number.
 * @param minimumFractionDigits Fraction digits that are always written.
 * @param maximumFractionDigits The fraction digit rounded at.
 */
function decimalText(
  magnitude: number,
  minimumFractionDigits: number,
  maximumFractionDigits: number,
): DecimalText {
  const [mantissa = '0', exponent = '0'] = magnitude.toExponential().split('e')
  const digits = mantissa.replace('.', '')
  const keep = Number(exponent) + 1 + maximumFractionDigits
  const kept = keep < 0 ? '' : digits.slice(0, keep).padEnd(keep, '0')
  const rounded =
    keep >= 0 && digits.charAt(keep) >= '5' ? increment(kept) : kept
  const scaled = rounded.padStart(maximumFractionDigits + 1, '0')
  const integer = scaled.slice(0, scaled.length - maximumFractionDigits)
  let fraction = scaled.slice(scaled.length - maximumFractionDigits)
  while (fraction.length > minimumFractionDigits && fraction.endsWith('0')) {
    fraction = fraction.slice(0, -1)
  }

  return { integer, fraction }
}

/**
 * A separator as it is printed: a space becomes a no-break space.
 *
 * @param separator The character the template carries.
 */
function printable(separator: string): string {
  return separator === ' ' ? NO_BREAK_SPACE : separator
}

/**
 * Write a number in the formats of the tab's region: thousands grouped by
 * three, the region's decimal separator, rounding half up by magnitude, and
 * trailing fraction zeros dropped down to the minimum. The formats are read on
 * every call. NaN and the infinities are written as String(value); a screen's
 * own fallback text stays with the screen.
 *
 * @param value The number to write.
 * @param options Fraction digits (by default 0…3) and the compact form — K, M
 *   or B with one fraction digit, «1.2K».
 * @returns The written number.
 * @throws {RangeError} When a fraction-digit option is not a non-negative
 *   integer, or the maximum is below the minimum.
 */
export function formatNumber(
  value: number,
  options: {
    readonly minimumFractionDigits?: number
    readonly maximumFractionDigits?: number
    readonly compact?: boolean
  } = {},
): string {
  if (!Number.isFinite(value)) {
    return String(value)
  }
  const compact = options.compact === true
  const minimum = options.minimumFractionDigits ?? 0
  const maximum =
    options.maximumFractionDigits ??
    Math.max(
      minimum,
      compact
        ? COMPACT_MAXIMUM_FRACTION_DIGITS
        : DEFAULT_MAXIMUM_FRACTION_DIGITS,
    )
  if (
    !Number.isInteger(minimum) ||
    !Number.isInteger(maximum) ||
    minimum < 0 ||
    maximum < minimum
  ) {
    throw new RangeError('Fraction digits must be integers, minimum ≤ maximum.')
  }
  const magnitude = Math.abs(value)
  let step = 0
  if (compact) {
    while (
      step < COMPACT_STEPS.length - 1 &&
      magnitude >= COMPACT_STEPS[step + 1].size
    ) {
      step += 1
    }
  }
  let text = decimalText(magnitude / COMPACT_STEPS[step].size, minimum, maximum)
  // A rounded figure that reaches the next step is written in that step.
  while (
    compact &&
    step < COMPACT_STEPS.length - 1 &&
    text.integer.length > GROUP_LENGTH
  ) {
    step += 1
    text = decimalText(magnitude / COMPACT_STEPS[step].size, minimum, maximum)
  }
  const regional = hilosReaderFormats().number
  const template = NUMBER_TEMPLATE.test(regional)
    ? regional
    : HILOS_DEFAULT_READER_FORMATS.number
  const integer = text.integer.replace(
    THOUSANDS,
    printable(template.charAt(GROUP_SEPARATOR_AT)),
  )
  const fraction =
    text.fraction === ''
      ? ''
      : `${printable(template.charAt(DECIMAL_SEPARATOR_AT))}${text.fraction}`
  const zero = /^0*$/u.test(`${text.integer}${text.fraction}`)
  const sign = value < 0 && !zero ? '-' : ''

  return `${sign}${integer}${fraction}${COMPACT_STEPS[step].suffix}`
}
