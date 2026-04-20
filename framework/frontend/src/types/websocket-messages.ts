/**
 * WebSocket transport types.
 *
 * Protocol (matches backend contract in docs/agents/frontend-sdk/backend-contract.md):
 * - Incoming (server -> client): { type, data, time?, outcome? }
 * - Outgoing (client -> server):
 *   - Action:          { type: 'action', action, data }
 *   - Page subscribe:  { type: 'page_subscribe' | 'page_update_subscription', page, params? }
 */

/**
 * Envelope-level outcome marker for action-acknowledgement signals.
 *
 * Absent for regular broadcast signals. Present (= 'success' | 'fail') only on
 * signals addressed to the initiator of a client-originated action, following
 * the convention `<action>_success` / `<action>_fail`.
 */
export type WebSocketOutcome = 'success' | 'fail'

/**
 * Base incoming envelope (server -> client).
 *
 * Payload always lives under `data`. `time` is an optional server clock tick
 * for clock-sync capable signals (interactive apps, games). `outcome` is an
 * architectural marker for action-ack signals (see {@link WebSocketOutcome}).
 */
export interface WebSocketIncoming<T extends string = string, D = unknown> {
  type: T
  data: D
  time?: number
  outcome?: WebSocketOutcome
}

/**
 * Incoming signal with no payload body.
 * Used for events that carry only their type, e.g. `bot_joined`, `file_upload_complete`.
 */
export interface WebSocketIncomingEmpty<T extends string = string> {
  type: T
  time?: number
  outcome?: WebSocketOutcome
}

/**
 * Clock-sync only signal: server clock tick without any `data` payload.
 * Reserved for interactive apps (games) that need server time synchronization.
 */
export interface WebSocketTimeSync<T extends string = string> {
  type: T
  time: number
}

/**
 * Standard shape of fail-outcome signal payload.
 *
 * `message` is human-readable text and is always provided by the backend
 * (i18n-ready, suitable for direct display in UI / toast).
 * `reason` is an enum-like code for programmatic handling.
 */
export interface ActionFailData<R extends string = string> {
  reason: R
  message: string
}

/**
 * Success-outcome acknowledgement of a client-initiated action.
 *
 * Convention: emitted as `<action>_success` addressed only to the initiator.
 * Optional `data` payload and optional top-level `message` (for future toast UI).
 */
export type WebSocketActionSuccess<T extends string = string, D = undefined> =
  D extends undefined
    ? { type: T; outcome: 'success'; time?: number; message?: string }
    : { type: T; outcome: 'success'; data: D; time?: number; message?: string }

/**
 * Fail-outcome acknowledgement of a client-initiated action.
 *
 * Convention: emitted as `<action>_fail` addressed only to the initiator.
 * `data` is a mandatory {@link ActionFailData} — backend always provides both
 * reason code and human-readable message.
 */
export interface WebSocketActionFail<T extends string = string, R extends string = string> {
  type: T
  outcome: 'fail'
  data: ActionFailData<R>
  time?: number
}

/**
 * Outgoing action envelope (client -> server).
 *
 * Routed server-side to the current page's agent `onAction()`.
 */
export interface WebSocketActionMessage<
  A extends string = string,
  D = Record<string, unknown>,
> {
  type: 'action'
  action: A
  data: D
}

/**
 * Outgoing page subscription envelope (client -> server).
 */
export interface WebSocketPageSubscribeMessage<P extends string = string> {
  type: 'page_subscribe' | 'page_update_subscription'
  page: P
  params?: Record<string, string>
}
