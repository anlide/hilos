// The signal envelope: the abstract layer every server frame is narrowed
// through before any concrete signal is typed (wire-protocol.md, layered
// discriminated-union parsing). The envelope is deliberately loose — unknown
// extra keys and unknown signal types must survive parsing so a newer backend
// never breaks an older client.
import { z } from 'zod'
import { protectedModeBlockSchema } from './protectedMode.js'

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

/**
 * Payload of the framework welcome (`type: 'handshake'`), sent first after the
 * socket opens: the daemon build plus, since HIL-268, the protected-mode block
 * that lets a connection arriving mid-freeze paint the maintenance surface before
 * it subscribes to anything. The block is optional and `catch`-guarded rather than
 * required: an older daemon sends none, and a half-built one must not cost the
 * client its welcome — the build check and the session it opens matter more than
 * the freeze notice, which the pushed frame would repeat anyway.
 */
export const handshakeSignalDataSchema = z.looseObject({
  build: z.string().min(1),
  sessionCookieName: z.string().min(1).optional().catch(undefined),
  protectedMode: protectedModeBlockSchema.optional().catch(undefined),
})

export type HandshakeSignalData = z.infer<typeof handshakeSignalDataSchema>

/**
 * Payload of the framework session-rotation signal (`type: 'hilos_session_rotate'`,
 * PHP `SessionRotateSignalData`): the one-time ticket the connection that just logged
 * in trades for its rotated session cookie on the next handshake. Required and
 * non-empty — a rotation frame that names no ticket is nothing the client can act on,
 * and the reconnect it would trigger would drop the session it was meant to save.
 */
export const sessionRotateSignalDataSchema = z.looseObject({
  ticket: z.string().min(1),
})

export type SessionRotateSignalData = z.infer<
  typeof sessionRotateSignalDataSchema
>

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
 *
 * An admin surface receives two more optional fields, `errorType` and
 * `errorDetail`: the class name of the failure the generic `reason` stands for,
 * and that failure's own message. The backend fills them only when the page
 * owning the action declares admin access and the failure was NOT written for a
 * person to read — so their presence is itself the sign that something was held
 * back. Any other caller receives the frame exactly as it was before they
 * existed (docs/agents/frontend/wire-protocol.md).
 */
export const actionErrorSignalDataSchema = z.looseObject({
  action: z.string(),
  reason: z.string(),
  errorCode: z.string().optional(),
  retryAfter: z.number().int().optional(),
  errorType: z.string().optional(),
  errorDetail: z.string().optional(),
})

export type ActionErrorSignalData = z.infer<typeof actionErrorSignalDataSchema>

/**
 * Payload of the framework action-success reply (`type: 'action_success'`, PHP
 * `PageActionSuccessSignalData`): the committed action's name and an optional
 * backend-authored `message` — the outcome sentence the frontend surfaces as a
 * success toast (present only when the handler set one; the driver shows no
 * success toast otherwise). It may also carry an optional domain `reply` — the
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

/**
 * Payload of the framework table viewport own-create
 * (`type: 'table_viewport_own_create'`, PHP `TableViewportOwnCreateDTO`): the
 * addressed live row the receiver itself created, the index it takes in that
 * receiver's window, and the new counts. `requestId` names the action that
 * created it, so a surface can tell which of its own presses this answers; it is
 * absent when the write was not tracked. The row rides the `{rowKey, slots}` shape.
 */
export const tableViewportOwnCreateSignalDataSchema = z.looseObject({
  page: z.string(),
  tableKey: z.string(),
  row: tableRowFragmentSchema,
  position: z.number().int(),
  totalCount: z.number().int(),
  pageCount: z.number().int(),
  requestId: z.string().nullish(),
})

export type TableViewportOwnCreateSignalData = z.infer<
  typeof tableViewportOwnCreateSignalDataSchema
>
