// The range methods of a typed NumberFormat, which the standard library
// declares from ES2023 on.

/** A range as text and as parts. */
export function printRange(
  formatter: Intl.NumberFormat,
  low: number,
  high: number,
): string {
  return `${formatter.formatRange(low, high)} ${formatter.formatRangeToParts(low, high).length}`
}
