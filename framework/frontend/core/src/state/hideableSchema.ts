import { z } from 'zod'
import { HIDDEN_VALUE, isHiddenValue, type HiddenValue } from './hiddenValue.js'

/**
 * Schema combinator that wraps a field schema to accept the admin view mode hidden mark,
 * transforming it to {@link HIDDEN_VALUE}.
 *
 * The hidden mark is placed first in the union so generic record schemas (`z.record(...)`)
 * do not match the raw `{"_hidden": true}` mark object before the mark transform can run.
 *
 * @param schema The underlying schema for unmasked values.
 */
export function hideable<T extends z.ZodType>(schema: T) {
  const mark = z
    .custom<HiddenValue>(isHiddenValue)
    .transform(() => HIDDEN_VALUE)

  return z.union([mark, schema])
}
