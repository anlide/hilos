// Deliberately broken text built from Date getters. One text is one site, at
// the operation where it is finished; parts kept under a simple local const in
// the same function join the text that reads them. Two texts on one line still
// owe two reports. Only the fixture test reads this file; it sits outside every
// source root the guard scans.

/** The former shape: three aliases and one template, one site. */
export function formatDay(at: Date): string {
  const year = at.getFullYear()
  const month = String(at.getMonth() + 1).padStart(2, '0')
  const day = String(at.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

/** A getter straight into a template. */
export function printHour(at: Date): string {
  return `${at.getHours()} h`
}

/** A string + onto an empty string. */
export function printDate(at: Date): string {
  return '' + at.getDate()
}

/** String() of a getter. */
export function printYear(at: Date): string {
  return String(at.getFullYear())
}

/** toString() of a getter. */
export function printMinutes(at: Date): string {
  return at.getMinutes().toString()
}

/** padStart() over String(): one site, where the text is finished. */
export function printSeconds(at: Date): string {
  return String(at.getSeconds()).padStart(2, '0')
}

/** join() over an array literal of components. */
export function printParts(at: Date): string {
  const y = at.getFullYear()
  const m = at.getMonth() + 1
  const dd = at.getDate()
  return [y, m, dd].join('-')
}

/** The array kept under an alias of its own. */
export function printKeptParts(at: Date): string {
  const parts = [at.getUTCHours(), at.getUTCMinutes()]
  return parts.join(':')
}

/** The UTC twins, and getYear() — a type error, and still read. */
export function printUtc(at: Date): string {
  return `${at.getUTCFullYear()}/${at.getUTCMonth() + 1} ${at.getYear()}`
}

/** A string + over several lines: one site, at its start. */
export function printClock(at: Date): string {
  return (
    at.getHours() +
    ':' +
    String(at.getMinutes()).padStart(2, '0') +
    ':' +
    at.getSeconds()
  )
}

/** A zero pad through a conditional: one site, at the template. */
export function printPadded(at: Date): string {
  const minutes = at.getMinutes()
  return `${at.getHours()}:${minutes < 10 ? '0' + minutes : minutes}`
}

/** A literal member name and an optional chain are the same getters. */
export function printOptional(at?: Date): string {
  return `${at?.['getHours']()}:${at?.getMilliseconds()}`
}

/** Whatever JSON holds, any proves nothing, so the getter is a Date's. */
export function printParsed(text: string): string {
  return `${JSON.parse(text).getDay()}`
}

/** Text kept under an alias and read by two texts: two sites on one line. */
export function printTwice(at: Date): string[] {
  const hour = String(at.getHours()).padStart(2, '0')
  return [`${hour}:00`, `${hour}:30`]
}

/** Text kept under an alias that nothing reads as text: a site where it is built. */
export function printKept(at: Date): string {
  const label = `${at.getDate()}.${at.getMonth() + 1}`
  return label
}

/** A let is not followed: its text is a site, and so is the text reading it. */
export function printLet(at: Date): string {
  let hour = String(at.getHours())
  hour = hour.padStart(2, '0')
  return `${hour}:${at.getMinutes()}`
}

/** A closure does not read its function's const: the outer text is its own site. */
export function printLater(at: Date): () => string {
  const year = `${at.getFullYear()}`
  return () => `${year}!`
}
