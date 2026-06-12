// Light field readers for projecting a committed entity's raw `fields` record
// into a typed domain entity. This is the only place untyped `unknown` fields
// are coerced; the wire payload is already zod-validated at the parse boundary
// (scopePayloadSchema), so these need no runtime schema — they read a known
// field and fall back to a default when it is absent or the wrong shape.

/** Read a string field, or the empty string when absent or non-string. */
export function str(fields: Readonly<Record<string, unknown>>, key: string): string {
  const value = fields[key]

  return typeof value === 'string' ? value : ''
}

/** Read a nullable string field, or null when absent or non-string. */
export function strOrNull(
  fields: Readonly<Record<string, unknown>>,
  key: string,
): string | null {
  const value = fields[key]

  return typeof value === 'string' ? value : null
}

/** Read a numeric field, or zero when absent or non-numeric. */
export function num(fields: Readonly<Record<string, unknown>>, key: string): number {
  const value = fields[key]

  return typeof value === 'number' ? value : 0
}

/** Read a nullable numeric field, or null when absent or non-numeric. */
export function numOrNull(
  fields: Readonly<Record<string, unknown>>,
  key: string,
): number | null {
  const value = fields[key]

  return typeof value === 'number' ? value : null
}

/** Read a boolean field, true only when the value is exactly `true`. */
export function bool(fields: Readonly<Record<string, unknown>>, key: string): boolean {
  return fields[key] === true
}
