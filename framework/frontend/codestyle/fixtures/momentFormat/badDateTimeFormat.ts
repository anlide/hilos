// Deliberately broken formatter use: Intl.DateTimeFormat built as a call or a
// construction, through a literal member, and the format methods of a typed
// formatter. A construction formatted in the same chain is one site; a stored
// formatter and its later format() are two.

/** Built and formatted in one chain: one site. */
export function printChain(moment: number): string {
  return new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium' }).format(
    moment,
  )
}

/** The call form is the same constructor. */
export function printCalled(moment: number): string {
  return Intl.DateTimeFormat().format(moment)
}

/** A literal member name is the same constructor. */
export function printIndexedConstructor(moment: number): string {
  return new Intl['DateTimeFormat']().formatToParts(moment)[0].value
}

/** Reported at the construction, wherever the chain ends. */
export function printSpread(moment: number): string {
  return new Intl.DateTimeFormat('en-GB', {
    day: 'numeric',
  }).format(moment)
}

/** Stored and formatted later: two sites. */
export function printStored(moment: number): string {
  const formatter = new Intl.DateTimeFormat()
  return formatter.format(moment)
}

/** A typed formatter handed in. */
export function printParts(
  formatter: Intl.DateTimeFormat,
  moment: number,
): string {
  return formatter
    .formatToParts(moment)
    .map((part) => part.value)
    .join('')
}

/** Optional and indexed calls on a typed formatter. */
export function printOptional(
  formatter: Intl.DateTimeFormat | null,
  moment: number,
): string | undefined {
  return formatter?.format(moment) ?? formatter?.['format'](moment)
}

/** A range prints two moments. */
export function printRange(
  formatter: Intl.DateTimeFormat,
  start: number,
  end: number,
): string {
  return formatter.formatRange(start, end)
}

/** Stored first: the formatter outlives the zone read, so it is reported. */
export function readStoredZone(): string {
  const formatter = new Intl.DateTimeFormat()
  return formatter.resolvedOptions().timeZone
}
