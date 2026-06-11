// The wire-side schema of the scope payload (data-model.md): the form every
// scope subscription delivers — entity slots plus the scope's plain data.
// Validated at the parse boundary; the normalizer ingests the result. The
// schema mirrors the SDK-side ScopePayload type from state/normalizer.ts;
// their alignment is asserted at compile time in the unit suite.
import { z } from 'zod'

/**
 * One entity fragment on the wire: the stable `id` that makes the slot an
 * entity by convention, plus the projection's fields.
 */
export const entityFragmentSchema = z.looseObject({
  id: z.union([z.string(), z.number()]),
})

/**
 * One table row on the wire: its identity within the table plus its source
 * slots. Slot values stay unknown here — the normalizer applies the
 * entity-fragment convention per slot.
 */
export const tableRowSchema = z.looseObject({
  rowKey: z.union([z.string(), z.number()]),
  slots: z.record(z.string(), z.unknown()),
})

/** One table's wire payload: the full row set in backend order (snapshot-only). */
export const tablePayloadSchema = z.looseObject({
  rows: z.array(tableRowSchema),
})

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
  tables: z.record(z.string(), tablePayloadSchema).optional(),
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
