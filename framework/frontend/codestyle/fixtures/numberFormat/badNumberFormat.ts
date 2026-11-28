// Intl.NumberFormat in every form the rule reads: a construction, a call, a
// chain finished by a printing method as one site, and the printing methods
// of a typed formatter held elsewhere.

/** A construction. */
export const grouped = new Intl.NumberFormat('en-US')

/** A call without `new`, and the same through a string-literal member. */
export const called = Intl.NumberFormat()
export const indexed = Intl['NumberFormat']('de-DE')

/** A chain: one site, at the construction. */
export function printChained(count: number): string {
  return new Intl.NumberFormat(undefined, {
    maximumFractionDigits: 1,
  }).format(count)
}

/** A typed formatter held elsewhere prints through format() and formatToParts(). */
export function printHeld(formatter: Intl.NumberFormat, count: number): string {
  return `${formatter.format(count)} ${formatter.formatToParts(count).length}`
}

/** Through an alias, an optional chain and a string-literal member. */
export type Printer = Intl.NumberFormat

export function printAliased(
  printer: Printer | null,
  count: number,
): string | undefined {
  return printer?.format(count) ?? printer?.['formatToParts'](count)[0]?.value
}

/** Metadata is a construction all the same. */
export const locale = new Intl.NumberFormat().resolvedOptions().locale
