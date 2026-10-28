// Deliberately broken Date prints. A receiver is judged by its type: typed,
// aliased, a union, any, unknown, a generic, optional and indexed members.
// Two calls on one line still owe two reports. Only the fixture test reads this
// file; it sits outside every source root the guard scans.

/** A local alias is still a Date. */
type When = Date

/** A subclass is still a Date. */
export class Moment extends Date {}

/** The plain case. */
export function printDate(at: Date): string {
  return at.toLocaleString()
}

/** Both halves on one line, both reported. */
export function printDay(at: Date): string {
  return at.toLocaleDateString() + ' ' + at.toLocaleTimeString()
}

/** A name proves nothing; the type says Date. */
export function printCount(count: When): string {
  return count.toLocaleString()
}

/** One branch of the union is a Date. */
export function printMixed(at: Date | number): string {
  return at.toLocaleString()
}

/** Whatever JSON holds, any proves nothing. */
export function printParsed(text: string): string {
  return JSON.parse(text).toLocaleString()
}

/** A type error, and still read: unknown proves nothing either. */
export function printUnknown(value: unknown): string {
  return value.toLocaleString()
}

/** An unbound generic is not proven numeric. */
export function printGeneric<T>(value: T): string {
  return value.toLocaleString()
}

/** The optional chain keeps undefined out; the Date stays. */
export function printOptional(at?: Date): string | undefined {
  return at?.toLocaleString()
}

/** Null is kept out, the Date branch is not. */
export function printOptionalMixed(
  value: number | Date | null,
): string | undefined {
  return value?.toLocaleString()
}

/** A literal member name is the same call. */
export function printIndexed(at: Date): string {
  return at['toLocaleString']()
}

/** Optional and indexed at once. */
export function printIndexedOptional(at: Date | undefined): string | undefined {
  return at?.['toLocaleDateString']()
}

/** The engine's other printers, four on one line. */
export function printStrings(at: Date): string[] {
  return [at.toDateString(), at.toTimeString(), at.toUTCString(), at.toString()]
}

/** A Date bound and a subclass are Dates. */
export function printBound<T extends Date>(value: T, moment: Moment): string {
  return value.toString() + moment.toLocaleString()
}

/** The report points at the call's own line inside a chain. */
export function printList(readings: { at: Date; shown: boolean }[]): string {
  return readings
    .filter((reading) => reading.shown)
    .map((reading) => reading.at.toLocaleDateString())
    .join(', ')
}

/** A method on any is still the Date-only one. */
export function printUntyped(text: string): string {
  return JSON.parse(text).toUTCString()
}
