// Look-alikes that print no number past formatNumber(): the formatter itself,
// receivers that prove no number, other Intl formatters, a formatter's static
// metadata, methods of one's own, and conversions in no locale. Nothing here
// may be reported by this rule.
import { formatNumber } from '@hilos/core/base'

/** The formatter itself. */
export const shown = (count: number): string => formatNumber(count)

/** Receivers that prove no number belong to MOMENT-FORMAT. */
export function printUnproven(text: string, raw: unknown, at: Date): string {
  return `${JSON.parse(text).toLocaleString()} ${raw.toLocaleString()} ${at.toLocaleString()}`
}

/** A union with a Date is no number. */
export function printMixed(value: number | Date): string {
  return value.toLocaleString()
}

/** A type of one's own with the same names. */
export interface Money {
  toLocaleString(): string
  format(value: number): string
}

export function printMoney(money: Money, count: number): string {
  return `${money.toLocaleString()} ${money.format(count)}`
}

/** Other Intl formatters, and the static metadata of this one. */
export const plural = new Intl.PluralRules('en').select(2)
export const supported = Intl.NumberFormat.supportedLocalesOf(['en'])

/** Conversions in no locale. */
export function printPlain(count: number): string {
  return `${count} ${String(count)} ${count.toFixed(2)} ${count.toString()}`
}
