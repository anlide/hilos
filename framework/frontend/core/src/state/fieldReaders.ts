// Light field readers for the single coercion point from a raw committed
// `fields` record to a typed domain entity — the `project` read an
// `EntityCollection` runs (EntityCollection.ts). Each reads one known field and
// falls back to a safe default when it is absent or the wrong shape, so a
// project's projector stays a flat list of typed reads. No runtime schema is
// needed here: the wire payload is already validated at the signal parse
// boundary and the normalizer only stores object records — these readers just
// re-type a known key out of that `unknown`-valued record.
//
// The hideable readers keep the one field shape the plain readers fold away:
// the hidden mark a viewer of the admin view mode is sent (hiddenValue.ts).

import { HIDDEN_VALUE, type Hideable, isHiddenValue } from './hiddenValue.js'

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
 * Read a string field a viewer of the admin view mode may be sent hidden:
 * {@link HIDDEN_VALUE} for the hidden mark, otherwise as {@link readString}.
 *
 * @param fields The raw committed fields record.
 * @param key The field name to read.
 */
export function readHideableString(
  fields: Readonly<Record<string, unknown>>,
  key: string,
): Hideable<string> {
  return isHiddenValue(fields[key]) ? HIDDEN_VALUE : readString(fields, key)
}

/**
 * Read a nullable string field a viewer of the admin view mode may be sent
 * hidden: {@link HIDDEN_VALUE} for the hidden mark, otherwise as
 * {@link readStringOrNull}. A hidden null is hidden too — the server hides the
 * whole field, whatever it holds.
 *
 * @param fields The raw committed fields record.
 * @param key The field name to read.
 */
export function readHideableStringOrNull(
  fields: Readonly<Record<string, unknown>>,
  key: string,
): Hideable<string | null> {
  return isHiddenValue(fields[key])
    ? HIDDEN_VALUE
    : readStringOrNull(fields, key)
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

/**
 * Read a boolean field a viewer of the admin view mode may be sent hidden:
 * {@link HIDDEN_VALUE} for the hidden mark, otherwise as {@link readBoolean}.
 *
 * @param fields The raw committed fields record.
 * @param key The field name to read.
 */
export function readHideableBoolean(
  fields: Readonly<Record<string, unknown>>,
  key: string,
): Hideable<boolean> {
  return isHiddenValue(fields[key]) ? HIDDEN_VALUE : readBoolean(fields, key)
}
