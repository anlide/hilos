// Money — the one place the shop frontend counts and prints its money, the twin of
// the backend Money class (demo/ecommerce-shop/backend/Money/Money.php).
//
// An amount is a whole number of kopecks, never below zero. A line costs its price
// times its quantity, the lines add up exactly, and a discount is the only operation
// that rounds: a share of the items, half a kopeck and more going up. Nothing rounded
// is kept anywhere, so the discount and the total are worked out again every time
// they are shown.
//
// A number that breaks a precondition comes from the database or from this module,
// not from a person, so it is a mistake of the program: a RangeError, never words
// for the reader.

const SIGN = '₴'
const DEDUCTION_SIGN = '−'
const THOUSANDS_SEPARATOR = ','
const INPUT_THOUSANDS_SEPARATOR = ' '
const DECIMAL_POINT = '.'
const LABEL_SEPARATOR = ', '
const KOPECKS_IN_HRYVNIA = 100
const KOPECK_DIGITS = 2
const KOPECK_PAD = '0'
const WHOLE_PERCENT = 100

/** Half of a whole percent: added before the division, it rounds half a kopeck up. */
const HALF_KOPECK = 50

/** A place between digits where a group of three starts, counted from the end. */
const THOUSANDS = /\B(?=(?:\d{3})+$)/g

/** Decimal kopecks with no leading zeroes and nothing after the last digit. */
export const KOPECK_AMOUNT_PATTERN = /^(?:0|[1-9][0-9]*)(?![\s\S])/

// Money::parse accepts only these three spaces and up to fifteen whole digits.
const INPUT_AMOUNT =
  /^[ \u00a0\u202f]*(\d+(?:[ \u00a0\u202f]+\d+)*)(?:[.,](\d{1,2}))?[ \u00a0\u202f]*$/
const INPUT_SPACES = /[ \u00a0\u202f]/g
const MAX_HRYVNIA_DIGITS = 15

/**
 * Prints an amount the way the shop shows it.
 *
 * @param kopecks - Amount in kopecks as a safe integer or canonical decimal string
 * @returns The sign, the hryvnias in groups of three and the kopecks when there are any: ₴1,234,567.89
 * @throws RangeError When the amount is not a nonnegative safe integer or canonical decimal string
 */
export function formatMoney(kopecks: number | string): string {
  if (typeof kopecks === 'number') {
    return SIGN + amountText(kopecks, THOUSANDS_SEPARATOR)
  }
  if (!KOPECK_AMOUNT_PATTERN.test(kopecks)) {
    throw new RangeError(
      'An exact amount must be written as nonnegative decimal kopecks',
    )
  }

  const whole = kopecks.slice(0, -KOPECK_DIGITS) || KOPECK_PAD
  const fraction = kopecks
    .slice(-KOPECK_DIGITS)
    .padStart(KOPECK_DIGITS, KOPECK_PAD)
  return SIGN + groupedAmountText(whole, fraction, THOUSANDS_SEPARATOR)
}

/**
 * Prints an amount taken off a sum, such as a discount.
 *
 * @param kopecks - Amount taken off, in kopecks as a safe integer or canonical decimal string
 * @returns The minus sign U+2212 before the printed amount: −₴250
 * @throws RangeError When the amount is not a nonnegative safe integer or canonical decimal string
 */
export function formatDeduction(kopecks: number | string): string {
  return DEDUCTION_SIGN + formatMoney(kopecks)
}

/**
 * Writes an amount the way a money input field holds it.
 *
 * @param kopecks - Amount in kopecks
 * @returns The hryvnias with a plain space between the thousands and the kopecks when there are any, no sign: 2 100.50
 * @throws RangeError When the amount is not a whole number of kopecks from zero up
 */
export function moneyInputText(kopecks: number): string {
  return amountText(kopecks, INPUT_THOUSANDS_SEPARATOR)
}

/**
 * Project a money input onto its exact comparison text for a row edit.
 *
 * @param text The draft as written. Invalid input keeps its own spelling.
 */
export function moneyInputComparisonText(text: string): string {
  const amount = INPUT_AMOUNT.exec(text)
  if (amount === null) return text
  const hryvnias = amount[1].replace(INPUT_SPACES, '')
  if (hryvnias.length > MAX_HRYVNIA_DIGITS) return text

  const whole = hryvnias.replace(/^0+(?=\d)/, '')
  const fraction = (amount[2] ?? '').padEnd(KOPECK_DIGITS, KOPECK_PAD)
  return groupedAmountText(whole, fraction, INPUT_THOUSANDS_SEPARATOR)
}

/**
 * Names a money input field, so the sign never leaves this module.
 *
 * @param label - What the field holds, such as Price
 * @returns The label followed by the sign: Price, ₴
 */
export function moneyFieldLabel(label: string): string {
  return label + LABEL_SEPARATOR + SIGN
}

/**
 * Works out what a line costs.
 *
 * @param price - Price of one unit, in kopecks
 * @param quantity - Units on the line
 * @returns The price times the quantity, in kopecks
 * @throws RangeError When the price or the quantity is not a whole number from zero up, or the cost is past Number.MAX_SAFE_INTEGER
 */
export function lineTotal(price: number, quantity: number): number {
  requireAmount(price, 'A price')
  requireAmount(quantity, 'A quantity')
  return requireSafe(price * quantity, 'The cost of a line')
}

/**
 * Adds amounts up; which of them belong in the sum is for the caller to say.
 *
 * @param amounts - Amounts in kopecks
 * @returns Their sum in kopecks, zero for none
 * @throws RangeError When an amount is not a whole number of kopecks from zero up, or the sum is past Number.MAX_SAFE_INTEGER
 */
export function sumMoney(amounts: readonly number[]): number {
  let sum = 0
  for (const amount of amounts) {
    requireAmount(amount, 'An amount')
    sum = requireSafe(sum + amount, 'The sum')
  }
  return sum
}

/**
 * Works out the discount on the items, the one operation that rounds.
 *
 * @param items - Amount of the items, in kopecks
 * @param percent - Discount, in whole percent from 0 to 100
 * @returns The share of the items, half a kopeck and more rounded up, in kopecks
 * @throws RangeError When the items are not a whole number of kopecks from zero up, the percent is not a whole number from 0 to 100, or the share is past Number.MAX_SAFE_INTEGER before the division
 */
export function discountOf(items: number, percent: number): number {
  requireAmount(items, 'The items')
  if (!Number.isInteger(percent) || percent < 0 || percent > WHOLE_PERCENT) {
    throw new RangeError(
      `A discount of ${percent} percent is not a whole number from 0 to 100`,
    )
  }
  const share = requireSafe(items * percent + HALF_KOPECK, 'The discount')
  return Math.floor(share / WHOLE_PERCENT)
}

/**
 * Works out what is left to pay once the discount is taken off the items.
 *
 * @param items - Amount of the items, in kopecks
 * @param discount - Discount, in kopecks
 * @returns The items less the discount, in kopecks
 * @throws RangeError When the items are not a whole number of kopecks from zero up, or the discount is not a whole number from zero to the items
 */
export function totalAfterDiscount(items: number, discount: number): number {
  requireAmount(items, 'The items')
  requireAmount(discount, 'A discount')
  if (discount > items) {
    throw new RangeError(
      `A discount of ${discount} kopecks is above the items of ${items}`,
    )
  }
  return items - discount
}

/**
 * @param kopecks - Amount in kopecks
 * @param separator - What stands between the groups of three hryvnia digits
 * @returns The hryvnias in groups and the kopecks after a point when there are any
 * @throws RangeError When the amount is not a whole number of kopecks from zero up
 */
function amountText(kopecks: number, separator: string): string {
  requireAmount(kopecks, 'An amount')
  const whole = String(Math.floor(kopecks / KOPECKS_IN_HRYVNIA))
  const fraction = String(kopecks % KOPECKS_IN_HRYVNIA).padStart(
    KOPECK_DIGITS,
    KOPECK_PAD,
  )
  return groupedAmountText(whole, fraction, separator)
}

/**
 * @param whole The whole hryvnias as digits.
 * @param fraction The two kopeck digits.
 * @param separator What stands between the groups of three hryvnia digits.
 */
function groupedAmountText(
  whole: string,
  fraction: string,
  separator: string,
): string {
  const grouped = whole.replace(THOUSANDS, separator)
  return fraction === '00' ? grouped : grouped + DECIMAL_POINT + fraction
}

/**
 * @param value - Number that must be whole, safe and not below zero
 * @param what - What the number is, to start the message with
 * @throws RangeError When the number is not a safe whole number from zero up
 */
function requireAmount(value: number, what: string): void {
  if (!Number.isSafeInteger(value) || value < 0) {
    throw new RangeError(
      `${what} of ${value} is not a whole number from zero up`,
    )
  }
}

/**
 * @param value - Result of an operation on safe whole numbers
 * @param what - What the result is, to start the message with
 * @returns The same value
 * @throws RangeError When the value is past Number.MAX_SAFE_INTEGER
 */
function requireSafe(value: number, what: string): number {
  if (value > Number.MAX_SAFE_INTEGER) {
    throw new RangeError(`${what} is past Number.MAX_SAFE_INTEGER`)
  }
  return value
}
