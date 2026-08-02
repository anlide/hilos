// The signal envelope: the abstract layer every server frame is narrowed
// through before any concrete signal is typed (wire-protocol.md, layered
// discriminated-union parsing). The envelope is deliberately loose — unknown
// extra keys and unknown signal types must survive parsing so a newer backend
// never breaks an older client.
import { z } from 'zod'

/**
 * Abstract envelope of every server→client frame:
 * `{type, data, outcome?, requestId?, time?}` as serialized by the daemon.
 *
 * `outcome` marks action acknowledgements (success/fail); `requestId` echoes the
 * client-minted id that correlates an action reply with its action; `time` is
 * the reserved server clock tick in milliseconds. All ride the envelope, not
 * the payload.
 */
export const signalEnvelopeSchema = z.looseObject({
  type: z.string().min(1),
  data: z.unknown(),
  outcome: z.enum(['success', 'fail']).optional(),
  requestId: z.string().optional(),
  time: z.number().int().optional(),
})

export type SignalEnvelope = z.infer<typeof signalEnvelopeSchema>

/** Payload of the framework welcome (`type: 'handshake'`), sent first after the socket opens. */
export const handshakeSignalDataSchema = z.looseObject({
  build: z.string().min(1),
})

export type HandshakeSignalData = z.infer<typeof handshakeSignalDataSchema>

/**
 * Payload of the framework action-failure signal (`type: 'action_error'`, PHP
 * `PageActionErrorSignalData`): the failed action's name, a human-readable
 * reason, and an optional machine-readable `errorCode` (e.g. `'unauthorized'`
 * for an anonymous write action). The `outcome: 'fail'` marker and the
 * correlating `requestId` ride the envelope, not this payload. Parsed natively
 * by the core parse boundary (action_error is a framework signal, not a project
 * schema); the request-correlated acknowledgement lifecycle (ActionLifecycle)
 * consumes the echoed requestId, and the auth gate reads `errorCode` to open the
 * sign-in surface on an action-level 401. A `rate_limited` failure additionally
 * carries `retryAfter` (seconds to wait before retrying).
 */
export const actionErrorSignalDataSchema = z.looseObject({
  action: z.string(),
  reason: z.string(),
  errorCode: z.string().optional(),
  retryAfter: z.number().int().optional(),
})

export type ActionErrorSignalData = z.infer<typeof actionErrorSignalDataSchema>

/**
 * Payload of the framework action-success reply (`type: 'action_success'`, PHP
 * `PageActionSuccessSignalData`): the committed action's name and an optional
 * backend-authored `message` — the outcome sentence the frontend surfaces as a
 * success toast (present only when the handler set one; the driver falls back to
 * a generic string otherwise). It may also carry an optional domain `reply` — the
 * array form of the reply DTO the handler returned — which the action lifecycle
 * validates against the caller's optional schema and resolves the request with;
 * absent entirely when the handler answered with nothing. The real state arrives
 * over the page payload, and the correlating `requestId` plus the
 * `outcome: 'success'` marker ride the envelope, not this payload.
 */
export const actionSuccessSignalDataSchema = z.looseObject({
  action: z.string(),
  message: z.string().optional(),
  reply: z.unknown().optional(),
})

export type ActionSuccessSignalData = z.infer<
  typeof actionSuccessSignalDataSchema
>

/** One table row on the wire: its identity key plus its normalized slots. */
const tableRowFragmentSchema = z.looseObject({
  rowKey: z.union([z.string(), z.number()]),
  slots: z.record(z.string(), z.unknown()),
})

/**
 * Payload of the framework table window reply (`type: 'table_window'`, PHP
 * `TableWindowSignalData`): the rows currently in the window plus the descriptor
 * metadata. A row rides the `{rowKey, slots}` fragment shape the normalizer
 * ingests. Sent only in reply to a table_viewport request, never live.
 */
export const tableWindowSignalDataSchema = z.looseObject({
  page: z.string(),
  tableKey: z.string(),
  rows: z.array(tableRowFragmentSchema),
  totalCount: z.number().int(),
  offset: z.number().int(),
  limit: z.number().int(),
})

export type TableWindowSignalData = z.infer<typeof tableWindowSignalDataSchema>

/**
 * Payload of the framework table viewport delta (`type: 'table_viewport_delta'`,
 * PHP `TableViewportDeltaDTO`): the addressed live PENDING row change for one
 * table, discriminated by `kind` (`row_updated` / `row_removed`). A row rides the
 * `{rowKey, slots}` shape; `kind` and `reason` stay loose strings so a newer
 * backend kind survives parsing. Count and append changes ride their own live
 * signals; this carries only row edits and removals, never auto-applied.
 */
export const tableViewportDeltaSignalDataSchema = z.looseObject({
  page: z.string(),
  tableKey: z.string(),
  kind: z.string(),
  rowKey: z.union([z.string(), z.number()]).optional(),
  row: tableRowFragmentSchema.optional(),
  reason: z.string().optional(),
  live: z.boolean().optional(),
  own: z.boolean().optional(),
})

export type TableViewportDeltaSignalData = z.infer<
  typeof tableViewportDeltaSignalDataSchema
>

/**
 * Payload of the framework table viewport count (`type: 'table_viewport_count'`,
 * PHP `TableViewportCountDTO`): the addressed live total and page count for one
 * table's window. Navigation metadata, not row content — the frontend applies it
 * immediately instead of gating it as pending.
 */
export const tableViewportCountSignalDataSchema = z.looseObject({
  page: z.string(),
  tableKey: z.string(),
  totalCount: z.number().int(),
  pageCount: z.number().int(),
})

export type TableViewportCountSignalData = z.infer<
  typeof tableViewportCountSignalDataSchema
>

/**
 * Payload of the framework table viewport append (`type: 'table_viewport_append'`,
 * PHP `TableViewportAppendDTO`): the addressed live row to add at the tail of one
 * table's window, plus the new counts. Sent only when the window is the last page
 * with room, so the frontend applies it immediately. The row rides the
 * `{rowKey, slots}` shape.
 */
export const tableViewportAppendSignalDataSchema = z.looseObject({
  page: z.string(),
  tableKey: z.string(),
  row: tableRowFragmentSchema,
  totalCount: z.number().int(),
  pageCount: z.number().int(),
})

export type TableViewportAppendSignalData = z.infer<
  typeof tableViewportAppendSignalDataSchema
>
