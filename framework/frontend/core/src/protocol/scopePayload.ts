// The wire-side schema of the scope payload (data-model.md): the form every
// scope subscription delivers — entity slots, the scope's plain data, and its
// lists. Validated at the parse boundary; the normalizer ingests the result.
// The schema mirrors the SDK-side ScopePayload type from state/normalizer.ts;
// their alignment is asserted at compile time in the unit suite.
import { z } from 'zod'

/**
 * One entity fragment on the wire: the stable `id` that makes the slot an
 * entity by convention, plus the projection's fields.
 */
export const entityFragmentSchema = z.looseObject({
  id: z.union([z.string(), z.number()]),
})

/** A list item's identity/order key on the wire. */
const itemKeySchema = z.union([z.string(), z.number()])

/**
 * One list item on the wire: its `itemKey` plus its slots. Slots are opaque
 * here — the normalizer tells an entity slot from a plain one by the `id`
 * convention — so the schema only fixes the envelope.
 */
export const listItemSchema = z.looseObject({
  itemKey: itemKeySchema,
  slots: z.record(z.string(), z.unknown()),
})

/**
 * One list collection on the wire: the items delivered this round and the keys
 * removed since the last. Both are optional — a snapshot omits `deleted`, a
 * delete-only delta omits `items`. An empty section arrives as `[]`, not `{}`,
 * because PHP serializes an empty map as a JSON array; it is normalized to an
 * empty section so the rest of the schema sees one shape.
 */
export const listSectionSchema = z.preprocess(
  (value) => (Array.isArray(value) && value.length === 0 ? {} : value),
  z.looseObject({
    items: z.array(listItemSchema).optional(),
    deleted: z.array(itemKeySchema).optional(),
  }),
)

/** A table row's identity key on the wire. */
const rowKeySchema = z.union([z.string(), z.number()])

/**
 * One table row on the wire: its `rowKey` plus its slots, the twin of
 * {@link listItemSchema}. Slots are opaque here — the normalizer tells an
 * entity slot from a plain one by the `id` convention — so the schema only
 * fixes the envelope.
 */
export const tableRowSchema = z.looseObject({
  rowKey: rowKeySchema,
  slots: z.record(z.string(), z.unknown()),
})

/**
 * One table collection on the wire, the twin of {@link listSectionSchema}: the
 * rows delivered this round and the keys removed since the last. Both are
 * optional — a snapshot omits `deleted`, a delete-only delta omits `rows`. An
 * empty section arrives as `[]`, not `{}`, because PHP serializes an empty map
 * as a JSON array; it is normalized to an empty section so the rest of the
 * schema sees one shape.
 */
export const tableSectionSchema = z.preprocess(
  (value) => (Array.isArray(value) && value.length === 0 ? {} : value),
  z.looseObject({
    rows: z.array(tableRowSchema).optional(),
    deleted: z.array(rowKeySchema).optional(),
  }),
)

/**
 * A scope-shaped payload as the backend serializes it. Every section is
 * optional because empty sections are omitted on the wire (PHP would
 * serialize an empty map as a JSON array).
 */
export const scopePayloadSchema = z.looseObject({
  entities: z
    .record(
      z.string(),
      z.union([entityFragmentSchema, z.array(entityFragmentSchema)]),
    )
    .optional(),
  data: z.record(z.string(), z.unknown()).optional(),
  lists: z.record(z.string(), listSectionSchema).optional(),
  tables: z.record(z.string(), tableSectionSchema).optional(),
})

export type ScopePayloadWire = z.infer<typeof scopePayloadSchema>

/**
 * The page_response project-signal data: the page key that lets the client
 * drop a late signal for a page it has left, plus the page's scope payload.
 */
export const pageResponseSchema = z.looseObject({
  page: z.string().min(1),
  payload: scopePayloadSchema,
})

export type PageResponseWire = z.infer<typeof pageResponseSchema>
