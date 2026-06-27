// Light field readers for the single coercion point from a raw committed
// `fields` record to a typed domain entity — the `project` read an
// `EntityCollection` runs (EntityCollection.ts). Each reads one known field and
// falls back to a safe default when it is absent or the wrong shape, so a
// project's projector stays a flat list of typed reads. No runtime schema is
// needed here: the wire payload is already validated at the signal parse
// boundary and the normalizer only stores object records — these readers just
// re-type a known key out of that `unknown`-valued record.

/**
 * Read a string field, or the empty string when absent or non-string.
 *
 * @param fields The raw committed fields record.
 * @param key The field name to read.
 */
export function readString(
  fields: Readonly<Record<string, unknown>>,
  key: string,
): string {
  const value = fields[key]

  return typeof value === 'string' ? value : ''
}

/**
 * Read a nullable string field, or null when absent or non-string.
 *
 * @param fields The raw committed fields record.
 * @param key The field name to read.
 */
export function readStringOrNull(
  fields: Readonly<Record<string, unknown>>,
  key: string,
): string | null {
  const value = fields[key]

  return typeof value === 'string' ? value : null
}

/**
 * Read a numeric field, or zero when absent or non-numeric.
 *
 * @param fields The raw committed fields record.
 * @param key The field name to read.
 */
export function readNumber(
  fields: Readonly<Record<string, unknown>>,
  key: string,
): number {
  const value = fields[key]

  return typeof value === 'number' ? value : 0
}

/**
 * Read a nullable numeric field, or null when absent or non-numeric.
 *
 * @param fields The raw committed fields record.
 * @param key The field name to read.
 */
export function readNumberOrNull(
  fields: Readonly<Record<string, unknown>>,
  key: string,
): number | null {
  const value = fields[key]

  return typeof value === 'number' ? value : null
}

/**
 * Read a boolean field, true only when the value is exactly `true`.
 *
 * @param fields The raw committed fields record.
 * @param key The field name to read.
 */
export function readBoolean(
  fields: Readonly<Record<string, unknown>>,
  key: string,
): boolean {
  return fields[key] === true
}
