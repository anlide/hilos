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

/**
 * One table row on the wire: its identity key plus its normalized slots.
 *
 * `staleSources` names the slots whose values have stopped being kept up to date —
 * a source of the row is behind a link that dropped, while the rest of it is live. It
 * is absent on a row that is entirely current, which is nearly every row, and a
 * malformed one is dropped rather than allowed to refuse the row it came on: a row
 * shown without a mark is a smaller loss than a row not shown at all.
 */
const tableRowFragmentSchema = z.looseObject({
  rowKey: z.union([z.string(), z.number()]),
  slots: z.record(z.string(), z.unknown()),
  staleSources: z.array(z.string()).optional().catch(undefined),
})

/**
 * Payload of the framework table window reply (`type: 'table_window'`, PHP
 * `TableWindowSignalData`): the rows currently in the window plus the descriptor
 * metadata. A row rides the `{rowKey, slots}` fragment shape the normalizer
 * ingests. Sent only in reply to a table_viewport request, never live.
 *
 * The two boundary anchors are what the next window is asked with — the last one pages
 * forward, the first one pages back — and an empty window carries neither.
 *
 * `totalExact` says what the total is. A windowed query counts only up to a ceiling, so
 * past it the number is that ceiling and reads as "at least this many"; page numbers are
 * what stops following from it.
 *
 * `rowsBefore` is where the window sits: how many rows of the set stand before its first
 * row. The page number and the footer range are read out of it rather than counted up by
 * presses of Next, which is what keeps them true after a row appears above the window. It
 * travels only under an exact total, and is absent otherwise for the same reason page
 * numbers are.
 */
export const tableWindowSignalDataSchema = z.looseObject({
  page: z.string(),
  tableKey: z.string(),
  rows: z.array(tableRowFragmentSchema),
  totalCount: z.number().int(),
  totalExact: z.boolean(),
  limit: z.number().int(),
  firstAnchor: z.record(z.string(), z.unknown()).nullable(),
  lastAnchor: z.record(z.string(), z.unknown()).nullable(),
  rowsBefore: z.number().int().optional(),
})

export type TableWindowSignalData = z.infer<typeof tableWindowSignalDataSchema>

/**
 * Payload of the framework table-window refusal (`type: 'table_window_refused'`,
 * PHP `TableWindowRefusedSignalData`): the page and table whose window could not
 * be built, plus the machine-readable reason (`errorCode`, PHP
 * `TableWindowRefusalCode`). The view does not branch on the code — one phrase
 * covers every reason. Sent only in reply, never live.
 */
export const tableWindowRefusedSignalDataSchema = z.looseObject({
  page: z.string(),
  tableKey: z.string(),
  errorCode: z.string(),
})

export type TableWindowRefusedSignalData = z.infer<
  typeof tableWindowRefusedSignalDataSchema
>

/**
 * Payload of the framework table viewport delta (`type: 'table_viewport_delta'`,
 * PHP `TableViewportDeltaDTO`): the addressed live row change for one table,
 * discriminated by `kind` (`row_updated` / `row_moved` / `row_removed` / `row_stale`). A
 * row rides the `{rowKey, slots}` shape; `kind` and `reason` stay loose strings so a
 * newer backend kind survives parsing. Count and append changes ride their own live
 * signals; this carries only row edits, moves, removals and freshness marks.
 *
 * What waits for the reader is decided by the kind, and by the server: a value that
 * left the row where it stood (`row_updated`) applies at once, while a move and a
 * removal wait. `position` is the slot a moved row lands in and travels only with
 * `row_moved`, absent when the table could not name one. `row` rides `row_updated`
 * and `row_moved` always, and `row_removed` for one receiver only — the tab holding
 * the row in focus for an open dialog — while the row is alive; the schema already
 * leaves it optional on every kind.
 *
 * `row_stale` carries `staleSources` in place of a row: which of the row's sources
 * stopped being kept up to date, with the list replacing whatever the row held and an
 * empty one meaning it is current again.
 */
export const tableViewportDeltaSignalDataSchema = z.looseObject({
  page: z.string(),
  tableKey: z.string(),
  kind: z.string(),
  rowKey: z.union([z.string(), z.number()]).optional(),
  row: tableRowFragmentSchema.optional(),
  reason: z.string().optional(),
  position: z.number().int().optional(),
  staleSources: z.array(z.string()).optional(),
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
 *
 * `pageCount` is absent, not zero, whenever `totalExact` is false: a total that stopped
 * at its ceiling supports no page count, and zero would read as a table with no pages.
 */
export const tableViewportCountSignalDataSchema = z.looseObject({
  page: z.string(),
  tableKey: z.string(),
  totalCount: z.number().int(),
  totalExact: z.boolean(),
  pageCount: z.number().int().optional(),
})

export type TableViewportCountSignalData = z.infer<
  typeof tableViewportCountSignalDataSchema
>

/**
 * One count beside an option of a table's filter: how many rows picking it would
 * leave, and whether that number is the size of the set rather than the ceiling
 * the count stopped at — `exact: false` is drawn as "500+".
 */
export const tableFacetCountSchema = z.looseObject({
  count: z.number().int(),
  exact: z.boolean(),
})

/**
 * Payload of the framework facet counts frame (`type: 'table_facet_counts'`, PHP
 * `TableFacetCountsSignalData`): the counts beside the options of one table's
 * filters, filter by filter. Each filter carries `any` — the set with that filter
 * lifted — and one count per option, keyed by the option value as text.
 *
 * A frame need not name every filter: it carries the ones whose counts moved, and
 * the client lays them over the counts it holds. A filter the table does not count
 * is absent, which is how "no numbers here" is said.
 */
export const tableFacetCountsSignalDataSchema = z.looseObject({
  page: z.string(),
  tableKey: z.string(),
  facets: z.record(
    z.string(),
    z.looseObject({
      any: tableFacetCountSchema,
      options: z.record(z.string(), tableFacetCountSchema),
    }),
  ),
})

export type TableFacetCountsSignalData = z.infer<
  typeof tableFacetCountsSignalDataSchema
>

/**
 * Payload of the framework table viewport announcement
 * (`type: 'table_viewport_announce'`, PHP `TableViewportAnnounceDTO`): word that a
 * row was created which this window cannot show, and where it fell — `above` the
 * window or `inside` it. The row body does not travel; `rowKey` does, so the same
 * row announced twice is counted once. The counts ride along because an
 * announcement is also a count.
 *
 * `placement` names only the two places a window cannot show. A tail row arrives as
 * itself and a row below the window is a count, so neither is announceable, and a
 * frame naming one of them is refused rather than counted.
 *
 * `pageCount` is absent when `totalExact` is false, exactly as on the count signal.
 */
export const tableViewportAnnounceSignalDataSchema = z.looseObject({
  page: z.string(),
  tableKey: z.string(),
  rowKey: z.string(),
  placement: z.enum(['above', 'inside']),
  totalCount: z.number().int(),
  totalExact: z.boolean(),
  pageCount: z.number().int().optional(),
})

export type TableViewportAnnounceSignalData = z.infer<
  typeof tableViewportAnnounceSignalDataSchema
>

/**
 * Payload of the framework table viewport unannouncement
 * (`type: 'table_viewport_unannounce'`, PHP `TableViewportUnannounceDTO`): word that a
 * row this window does not hold was deleted, so a key announced for it is taken back.
 * The server does not remember what it announced, so a window that was never told
 * about the key drops the frame. No place travels, the row having none anymore, and
 * no count: the total arrives on its own count frame.
 */
export const tableViewportUnannounceSignalDataSchema = z.looseObject({
  page: z.string(),
  tableKey: z.string(),
  rowKey: z.string(),
})

export type TableViewportUnannounceSignalData = z.infer<
  typeof tableViewportUnannounceSignalDataSchema
>

/**
 * Payload of the framework table progress frame (`type: 'table_progress'`, PHP
 * `TableProgressSignalData`): one bar of work running on a table, and never a row
 * of its set. `scope` names where it is drawn — under its own row, above the table,
 * or inside the selection panel — and `progressKey` says whose work it is, so a bar
 * of a new run replaces the one standing there.
 *
 * An absent `total` is the work saying it has no estimate, `ended` absent is a bar
 * still running, and `detail` is the project's own payload for the content beside
 * the bar, which the core carries and never reads.
 *
 * The pair `scope` / `rowKey` is not checked here. Every schema of this file is
 * loose by design, so a key it does not name is legal, and the check that a row bar
 * names a row lives where the frame is taken in.
 */
export const tableProgressSignalDataSchema = z.looseObject({
  page: z.string(),
  tableKey: z.string(),
  scope: z.enum(['row', 'table', 'bulk']),
  progressKey: z.string(),
  rowKey: z.string().optional(),
  current: z.number().int(),
  total: z.number().int().optional(),
  ended: z.boolean().optional(),
  detail: z.record(z.string(), z.unknown()).optional(),
})

export type TableProgressSignalData = z.infer<
  typeof tableProgressSignalDataSchema
>

/**
 * Payload of the framework table bulk report frame (`type: 'table_bulk_report'`,
 * PHP `TableBulkReportSignalData`): how one bulk run judged the rows it reached.
 * It arrives after the run ends, addressed to the connection that started it, and
 * carries the same `progressKey` its reply and its bar did.
 *
 * `touched` is a count and never a list: rows that changed have already arrived as
 * live deltas. `untouched` is the list, because nothing else on the wire ever
 * mentions those rows, and a partial success without their names is silent.
 * `untouchedOmitted` is absent when every name fit under the server's ceiling.
 */
export const tableBulkReportSignalDataSchema = z.looseObject({
  page: z.string(),
  tableKey: z.string(),
  progressKey: z.string(),
  touched: z.number().int(),
  untouched: z.array(
    z.looseObject({
      rowKey: z.string(),
      reason: z.string(),
    }),
  ),
  untouchedOmitted: z.number().int().optional(),
})

export type TableBulkReportSignalData = z.infer<
  typeof tableBulkReportSignalDataSchema
>

/**
 * Payload of the framework table viewport append (`type: 'table_viewport_append'`,
 * PHP `TableViewportAppendDTO`): the addressed live row to add at the tail of one
 * table's window, plus the new counts. Sent only when the window is the last page
 * with room, so the frontend applies it immediately. The row rides the
 * `{rowKey, slots}` shape.
 *
 * The row arrives whatever the counts say; `pageCount` is absent when `totalExact` is
 * false, exactly as it is on the count signal.
 */
export const tableViewportAppendSignalDataSchema = z.looseObject({
  page: z.string(),
  tableKey: z.string(),
  row: tableRowFragmentSchema,
  totalCount: z.number().int(),
  totalExact: z.boolean(),
  pageCount: z.number().int().optional(),
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
 *
 * The counts follow the window's rule: `pageCount` is absent when `totalExact` is false.
 */
export const tableViewportOwnCreateSignalDataSchema = z.looseObject({
  page: z.string(),
  tableKey: z.string(),
  row: tableRowFragmentSchema,
  position: z.number().int(),
  totalCount: z.number().int(),
  totalExact: z.boolean(),
  pageCount: z.number().int().optional(),
  requestId: z.string().nullish(),
})

export type TableViewportOwnCreateSignalData = z.infer<
  typeof tableViewportOwnCreateSignalDataSchema
>
