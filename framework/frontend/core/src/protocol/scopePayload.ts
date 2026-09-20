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
 * One bar of work running on a table, as the `windows` section of a page answer carries it.
 *
 * The body of a `table_progress` frame with the address left off — the same keys, read the
 * same way. The pair `scope` / `rowKey` is not checked here either: the check lives where the
 * bars are taken in, so that a bar arriving live and a bar arriving with the window are judged
 * by one rule and not by two.
 */
export const tableProgressSectionSchema = z.looseObject({
  scope: z.enum(['row', 'table', 'bulk']),
  progressKey: z.string(),
  rowKey: z.string().optional(),
  current: z.number().int(),
  total: z.number().int().optional(),
  ended: z.boolean().optional(),
  detail: z.record(z.string(), z.unknown()).optional(),
})

export type TableProgressWire = z.infer<typeof tableProgressSectionSchema>

/**
 * One table's first window on the wire, as a page subscription answers with it.
 *
 * The same rows and the same coordinates a `table_window` reply carries — it is one window
 * either way — plus the order it ran in, which the reply leaves out because the client asked
 * for it there and here nobody did. The filter is deliberately absent: on a cold entry it is
 * empty, and on a reconnect the tab sent it and still holds it, so a second source of truth
 * about it could only disagree.
 *
 * The work running on the table rides along under `progress`, without an address: the section
 * already stands under the table's own key. It is absent rather than empty when nothing is
 * running, for the reason every other empty section is absent.
 *
 * `rowsBefore` is where the window sits in the set, and travels here exactly as it does in a
 * `table_window` reply: only under an exact total, absent otherwise.
 */
export const tableWindowSectionSchema = z.looseObject({
  rows: z.array(tableRowSchema),
  sort: z.array(
    z.looseObject({
      field: z.string(),
      direction: z.enum(['asc', 'desc']),
    }),
  ),
  limit: z.number().int(),
  totalCount: z.number().int(),
  totalExact: z.boolean(),
  firstAnchor: z.record(z.string(), z.unknown()).nullable(),
  lastAnchor: z.record(z.string(), z.unknown()).nullable(),
  rowsBefore: z.number().int().optional(),
  progress: z.array(tableProgressSectionSchema).optional(),
})

export type TableWindowSectionWire = z.infer<typeof tableWindowSectionSchema>

/**
 * A scope-shaped payload as the backend serializes it. Every section is
 * optional because empty sections are omitted on the wire (PHP would
 * serialize an empty map as a JSON array).
 */
export const scopePayloadSchema = z.looseObject({
  entities: z
    .record(
      z.string(),
      // A `null` value clears the slot — the downgrade path (e.g. the session
      // user leaving the `currentUser` slot on logout), symmetric to delivering
      // a fragment. Without it the whole handshake response fails validation.
      z.union([entityFragmentSchema, z.array(entityFragmentSchema), z.null()]),
    )
    .optional(),
  data: z.record(z.string(), z.unknown()).optional(),
  lists: z.record(z.string(), listSectionSchema).optional(),
  tables: z.record(z.string(), tableSectionSchema).optional(),
  // The fifth section, and the one the page scope does not store: a window is held by the
  // table's own controller, and a copy of it in the scope would drift on the first delta.
  windows: z.record(z.string(), tableWindowSectionSchema).optional(),
})

export type ScopePayloadWire = z.infer<typeof scopePayloadSchema>

/**
 * The page_response project-signal data: the page key that lets the client
 * drop a late signal for a page it has left, plus the page's scope payload.
 */
export const pageResponseSchema = z.looseObject({
  page: z.string().min(1),
  // Optional because a page that contributes nothing still answers: the frame
  // is the acknowledgement the routed outlet waits on, and it carries no
  // payload key at all rather than an empty map.
  payload: scopePayloadSchema.optional(),
})

export type PageResponseWire = z.infer<typeof pageResponseSchema>
