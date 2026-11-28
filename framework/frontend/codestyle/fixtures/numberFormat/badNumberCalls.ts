// toLocaleString() on receivers proven a number or a bigint: each call is a
// number printed past formatNumber(), whatever shape the type takes.

/** A numeric enum. */
export enum Priority {
  Low = 1,
  High = 2,
}

/** A branded number. */
export type Cents = number & { readonly brand: 'cents' }

/** A typed parameter. */
export function printCount(count: number): string {
  return count.toLocaleString()
}

/** A literal and a conversion. */
export const printed = `${(5).toLocaleString()} ${Number('7').toLocaleString()}`

/** A bigint, a union of both, and a bigint literal. */
export function printTotal(total: bigint, either: number | bigint): string {
  return `${total.toLocaleString()} ${either.toLocaleString()} ${5n.toLocaleString()}`
}

/** A numeric enum member and a branded number. */
export function printPriority(priority: Priority, price: Cents): string {
  return `${priority.toLocaleString()} ${price.toLocaleString()}`
}

/** A numeric generic bound. */
export function printBounded<T extends number>(value: T): string {
  return value.toLocaleString()
}

/** An optional chain drops the missing value before the call. */
export function printOptional(count: number | undefined): string {
  return count?.toLocaleString() ?? '—'
}

/** A member named by a string literal, and behind an assertion. */
export function printIndexed(count: number, raw: unknown): string {
  return `${count['toLocaleString']()} ${(raw as number).toLocaleString()}`
}

/** Locale arguments change nothing: the browser's locale or a named one. */
export function printFraction(share: number): string {
  return share.toLocaleString('de-DE', { maximumFractionDigits: 2 })
}
