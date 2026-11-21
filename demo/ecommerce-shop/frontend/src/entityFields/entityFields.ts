// Entity-slot reading for the shop's tables and lists. A table row or list
// item slot bearing an `id` is normalized into the page scope's entity store,
// leaving an EntityRef in the slot; the fields are read back through the
// resolver the caller hands in, keeping the pure selectors free of the
// application bootstrap.
import { type EntityRef } from '@hilos/core/base'

/** Resolve an entity reference to its committed fields, if the scope holds it. */
export type EntityFieldsResolver = (
  ref: EntityRef,
) => Readonly<Record<string, unknown>> | undefined

/**
 * Read a table row's or list item's entity slot as its fields, or an empty
 * record when the slot or the entity is absent.
 *
 * @param slot The raw slot value, a reference after normalization.
 * @param fieldsOf The resolver of a reference to its fields.
 */
export function slotFields(
  slot: unknown,
  fieldsOf: EntityFieldsResolver,
): Readonly<Record<string, unknown>> {
  const ref = slot as EntityRef | undefined

  return (ref ? fieldsOf(ref) : undefined) ?? {}
}
