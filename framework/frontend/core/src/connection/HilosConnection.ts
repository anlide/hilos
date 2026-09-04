// The Connection state machine of core-and-connection.md: transport liveness
// only (`connecting → connected → reconnecting → disconnected`), with
// exponential-backoff-plus-jitter reconnect and the text-ping keepalive.
// Authentication and authorization are separate machines and do not live here.
import {
  FIELD_ACTION,
  FIELD_DATA,
  FIELD_FILTER,
  FIELD_LIMIT,
  FIELD_OFFSET,
  FIELD_GROUP,
  FIELD_PAGE,
  FIELD_REQUEST_ID,
  FIELD_SORT,
  FIELD_TABLE_KEY,
  FIELD_TYPE,
  KEEPALIVE_TEXT_PING,
  PROTECTED_MODE_PASS_PARAM,
  SIGNAL_TYPE_ACTION,
  SIGNAL_TYPE_GROUP_SUBSCRIBE,
  SIGNAL_TYPE_GROUP_UNSUBSCRIBE,
  SIGNAL_TYPE_PAGE_SUBSCRIPTION_ERROR,
  SIGNAL_TYPE_TABLE_VIEWPORT,
  SESSION_ROTATE_COOKIE_SUFFIX,
} from '../protocol/constants.js'
import { assertNever } from '../protocol/assertNever.js'
import {
  parseSignal,
  type ActionErrorSignal,
  type ActionSuccessSignal,
  type HandshakeSignal,
  type ParsedSignal,
  type ParseFailure,
  type ProjectSignal,
  type ProjectSignalSchemas,
  type ProtectedModeSignal,
  type SessionRotateSignal,
  type TableViewportAppendSignal,
  type TableViewportOwnCreateSignal,
  type TableViewportCountSignal,
  type TableViewportDeltaSignal,
  type TableWindowSignal,
  type UnknownSignal,
} from '../protocol/parseSignal.js'
import {
  isSameProtectedModeStatus,
  PROTECTED_MODE_INACTIVE,
  type ProtectedModeStatus,
} from '../protocol/protectedMode.js'
import {
  RT_STALENESS_FRESH,
  type RtStalenessStatus,
} from '../protocol/rtStaleness.js'
import {
  computeBackoffDelay,
  DEFAULT_RECONNECT_OPTIONS,
  type ReconnectOptions,
} from './backoff.js'
import {
  readProtectedModeHint,
  writeProtectedModeHint,
} from './maintenanceHint.js'
import {
  readStoredProtectedModePass,
  writeStoredProtectedModePass,
} from './protectedModePass.js'
import { Emitter } from './events.js'

export type ConnectionState =
  | 'connecting'
  | 'connected'
  | 'reconnecting'
  | 'disconnected'

/**
 * The window a connection requests for one table: an open filter map the table
 * resolves, an optional sort, and the offset/limit window. Sent over
 * {@link HilosConnection.sendTableViewport}.
 */
export interface TableViewportDescriptor {
  readonly filter: Record<string, unknown>
  readonly sort: {
    readonly field: string
    readonly direction: 'asc' | 'desc'
  } | null
  readonly offset: number
  readonly limit: number
}

/**
 * The slice of the WebSocket API the core touches. Method-style members keep
 * the browser `WebSocket` structurally assignable, and a test mock only has to
 * implement these three. `send` carries both the text protocol and the binary
 * `frame_binary` upload frames, mirroring the browser `send` overload.
 */
export interface WebSocketLike {
  send(data: string | ArrayBuffer | Blob): void
  close(code?: number, reason?: string): void
  addEventListener(
    type: 'open' | 'message' | 'close' | 'error',
    listener: (event: { data?: unknown }) => void,
  ): void
}

/**
 * A session-token rotation waiting for its cookie: the one-time ticket, and the name of
 * the cookie to put it in. The name is derived from the session cookie name the welcome
 * frame announced, because that is deployment configuration rather than a constant.
 */
export interface SessionRotation {
  ticket: string
  cookieName: string
}

/** Build-version mismatch detected on a welcome frame (wire-protocol.md forced-refresh check). */
export interface BuildMismatch {
  expected: string
  received: string
}

export interface HilosConnectionEventMap extends Record<string, unknown> {
  /** Connection machine transition. */
  state: ConnectionState
  /** Every successfully parsed signal, known or unknown. */
  signal: ParsedSignal
  /** The framework welcome frame. */
  handshake: HandshakeSignal
  /** A framework action-success frame (`action_success`): the committed action and its correlating requestId. */
  actionSuccess: ActionSuccessSignal
  /** A framework action-failure frame (`action_error`): the failed action, its reason, and its correlating requestId. */
  actionError: ActionErrorSignal
  /** Welcome carried a different build than expected — the consumer forces the refresh. */
  buildMismatch: BuildMismatch
  /**
   * Protected mode was announced. A pushed `protected_mode` frame always emits,
   * because the daemon sends it exactly on a phase transition; a welcome frame
   * emits only when it changes the state it re-announces. Either way an
   * `active: false` payload means the freeze just lifted — which is what the
   * consumer reloads on (there is no catch-up snapshot; see createHilosConnection).
   */
  protectedMode: ProtectedModeStatus
  /**
   * Whether anything the open page reads is a frozen replica, and since when
   * (HIL-711). Emitted on every `rt_staleness` frame, and a frame arrives on
   * every page subscription too — so a tab that opened during a break is told,
   * although nothing moved while it was opening.
   */
  rtStaleness: RtStalenessStatus
  /**
   * The first frame is no longer held back: whatever the shell draws now, it
   * draws with the server's answer in hand (or with the fail-open verdict of a
   * dropped socket or the deadline). Only ever emitted `false` — the hold, when
   * there is one, is already up before anyone can subscribe, and is read off
   * {@link HilosConnection.firstFrameHeld}.
   */
  firstFrameHold: boolean
  /**
   * The session token was rotated by a login on THIS connection, and the browser
   * has to trade the carried ticket for its new cookie (HIL-582). The consumer
   * writes the auxiliary cookie named here; the connection then reconnects by
   * itself — that reconnect is what spends the ticket — once the replies it still
   * owes have arrived, so the login's own answer is not dropped with the socket.
   */
  sessionRotate: SessionRotation
  /** A signal validated against a project-declared schema. */
  projectSignal: ProjectSignal
  /** A table window snapshot reply (`table_window`): the rows in the requested window. */
  tableWindow: TableWindowSignal
  /** A live table pending change (`table_viewport_delta`): scoped to the connection's window. */
  tableViewportDelta: TableViewportDeltaSignal
  /** A live table count update (`table_viewport_count`): the new total/page count for the window. */
  tableViewportCount: TableViewportCountSignal
  /** A live table tail append (`table_viewport_append`): a new row added at the window's end. */
  tableViewportAppend: TableViewportAppendSignal
  /** The receiver's own new row (`table_viewport_own_create`), already placed at its index. */
  tableViewportOwnCreate: TableViewportOwnCreateSignal
  /** A signal the core has no concrete schema for; tolerated and observable. */
  unknownSignal: UnknownSignal
  /** A frame that violated the envelope contract; reported, never fatal. */
  parseFailure: ParseFailure
}

export interface HilosConnectionOptions {
  /** WebSocket endpoint, e.g. `wss://host/ws`. */
  url: string
  /** Socket constructor seam; tests inject a mock. Default `new WebSocket(url)`. */
  webSocketFactory?: (url: string) => WebSocketLike
  reconnect?: ReconnectOptions
  /** Keepalive period for the text ping. Default 40000. */
  keepaliveIntervalMs?: number
  /**
   * Build the client expects in the welcome. When unset, the first welcome's
   * build is latched and later welcomes are compared against it.
   */
  expectedBuild?: string
  /** Project-declared concrete signal schemas for the parse boundary. */
  projectSchemas?: ProjectSignalSchemas
  /** Uniform `[0, 1)` source for reconnect jitter; injectable for tests. */
  random?: () => number
}

const DEFAULT_KEEPALIVE_INTERVAL_MS = 40000

/**
 * How long a rotation waits for the replies the socket is still owed before it
 * reconnects anyway. Comfortably inside the ticket's own thirty seconds, and past any
 * reply the backend is going to send: the action lifecycle gives up on one long before.
 */
const ROTATION_REPLY_GRACE_MS = 10000

/**
 * How long the first frame waits for the welcome that decides what to draw.
 *
 * A backstop, not a budget: the hold is normally released by the welcome itself,
 * which on a reachable node arrives in a fraction of this. It is what makes a
 * hint that can only be set — never expired, and unsettable by the server —
 * safe to act on: the worst a stale one can cost is this wait.
 */
const FIRST_FRAME_HOLD_TIMEOUT_MS = 1500

/**
 * The name prefix that marks a project signal as a page's own state frame.
 *
 * What rides under this prefix is the WHOLE state of the page, never a delta:
 * a buffered frame is replayed to a listener that registered late, and a
 * replayed delta would double what was already delivered. The counter-example
 * is `'logs_lines_appended'` of the log viewer, which appends to what the view
 * already holds and therefore has no right to this name.
 */
const PAGE_FRAME_PREFIX = 'subscription_page_'

/**
 * One Hilos WebSocket connection.
 *
 * Owns the socket lifecycle: an explicit `connect()`, automatic reconnect with
 * full-jitter backoff after any drop (including a failed first attempt), the
 * keepalive ping while connected, and routing of every inbound frame through
 * the `parseSignal` boundary. An explicit `close()` is the only transition to
 * `disconnected`; no state mutates from a frame except through emitted events
 * (authoritative backend, consumers react to signals).
 */
export class HilosConnection {
  private readonly url: string
  private readonly webSocketFactory: (url: string) => WebSocketLike
  private readonly reconnectOptions: Required<ReconnectOptions>
  private readonly keepaliveIntervalMs: number
  private readonly expectedBuild: string | undefined
  private readonly projectSchemas: ProjectSignalSchemas | undefined
  private readonly random: () => number
  private readonly emitter = new Emitter<HilosConnectionEventMap>()

  private currentState: ConnectionState = 'disconnected'
  private socket: WebSocketLike | null = null
  /** Invalidates listeners of replaced sockets and pending timers. */
  private generation = 0
  private reconnectAttempt = 0
  private reconnectTimer: ReturnType<typeof setTimeout> | null = null
  private keepaliveTimer: ReturnType<typeof setInterval> | null = null
  private latchedBuild: string | undefined
  /** Session cookie name from the last welcome; the auxiliary cookie's name derives from it. */
  private sessionCookieName: string | undefined
  /**
   * Correlation ids of the tracked actions this socket has sent and not seen answered.
   * The transport's own count of what is still on the wire — the reply lifecycle keeps
   * its own richer record, but a rotation has to know whether the socket may be dropped
   * yet, and only the socket knows that.
   */
  private readonly awaitedReplies = new Set<string>()
  /** A rotation held back until the wire is quiet, and the timer that stops holding it. */
  private heldRotation: SessionRotation | null = null
  private heldRotationTimer: ReturnType<typeof setTimeout> | null = null
  private protectedModeStatus: ProtectedModeStatus = PROTECTED_MODE_INACTIVE
  private rtStalenessStatus: RtStalenessStatus = RT_STALENESS_FRESH
  /**
   * Whether the shell is still holding its first frame back, waiting to be told
   * what to draw.
   *
   * Starts from the browser's own hint rather than from any server state,
   * because there is no server state yet — that is the whole defect: the welcome
   * frame lands after the shell has painted. A browser with no hint holds
   * nothing and pays nothing.
   */
  private firstFrameHeldFlag: boolean = readProtectedModeHint()
  /** The backstop that lifts the hold when no frame comes to lift it. */
  private firstFrameHoldTimer: ReturnType<typeof setTimeout> | null = null
  /**
   * The verifier's pass presented on this browser tab, or undefined when none is.
   *
   * Not what the admission is held by — the server holds that against the browser
   * session, so other tabs get in without ever seeing the key. This is the
   * insurance against the session token changing underneath the window: a sign-in
   * rotates it, and the tab that still remembers the key presents it on the
   * reconnect and wins the admission back for the whole browser. Mirrored in
   * `sessionStorage` and only there: a cookie is domain-wide and would outlive
   * the window this admission must die with.
   */
  private presentedPass: string | undefined
  /**
   * The last state frame of each page signal type seen on this connection.
   *
   * A page sends its own frame before the `page_response` that releases the
   * view, so the view that wants it is not mounted yet when it arrives. Held
   * here, it is replayed to every `projectSignal` listener that registers
   * afterwards. Insertion order is the arrival order, which is the order the
   * replay uses.
   */
  private readonly pageFrames = new Map<string, ProjectSignal>()

  constructor(options: HilosConnectionOptions) {
    this.url = options.url
    this.webSocketFactory =
      options.webSocketFactory ?? ((url) => new WebSocket(url))
    this.reconnectOptions = {
      ...DEFAULT_RECONNECT_OPTIONS,
      ...options.reconnect,
    }
    this.keepaliveIntervalMs =
      options.keepaliveIntervalMs ?? DEFAULT_KEEPALIVE_INTERVAL_MS
    this.expectedBuild = options.expectedBuild
    this.projectSchemas = options.projectSchemas
    this.random = options.random ?? Math.random
    this.presentedPass = readStoredProtectedModePass()
  }

  get state(): ConnectionState {
    return this.currentState
  }

  /**
   * The freeze the backend last announced, on a welcome frame or a pushed one.
   *
   * Held here rather than in a page store because it must outlive routing and
   * subscription lifecycles, and survive `reconnecting` / `disconnected` — a
   * socket blip during planned maintenance must keep showing maintenance, not an
   * outage.
   */
  get protectedMode(): ProtectedModeStatus {
    return this.protectedModeStatus
  }

  /**
   * Whether anything this connection's open page reads has an unreachable source.
   *
   * Connection state and not page state, for the reason the freeze above is: the
   * backend already sent this only to the connections it concerns, so there is
   * nothing here to filter. A connection has one page, and every subscription is
   * answered afresh — so a page change carries its own verdict with it.
   */
  get rtStaleness(): RtStalenessStatus {
    return this.rtStalenessStatus
  }

  /**
   * Whether the shell should still draw nothing.
   *
   * True only on a browser that has met maintenance on this node before, and
   * only until the first of three things happens: the welcome frame arrives, the
   * socket fails, or {@link FIRST_FRAME_HOLD_TIMEOUT_MS} passes. It says nothing
   * about whether the node is frozen — that is {@link protectedMode}, and it is
   * only worth reading once this is false.
   */
  get firstFrameHeld(): boolean {
    return this.firstFrameHeldFlag
  }

  /**
   * Present a verifier's pass and go back in with it.
   *
   * Admission is decided on the 101, in the same place and by the same rule as the
   * initiator's own exemption, so the key rides the socket url rather than a frame:
   * while the mode holds this connection is refused every outbound frame, and a
   * connection that cannot speak cannot ask to be let in. Hence the reconnect —
   * there is no other moment at which the question can be asked.
   *
   * The key is kept for as long as the window it admits into and re-presented on
   * every (re)connect, so the verifier is not thrown out by a blip; the first frame
   * that reports the window closed drops it, whether the mode lifted or the operator
   * closed back. A key that does not open anything simply leaves this connection on the
   * maintenance screen, where {@link ProtectedModeStatus.passRejected} says so and
   * the field can be tried again.
   *
   * @param pass The pass an operator minted and handed over. Blank is ignored.
   */
  presentProtectedModePass(pass: string): void {
    const trimmed = pass.trim()
    if (trimmed === '') {
      return
    }

    this.presentedPass = trimmed
    writeStoredProtectedModePass(trimmed)
    this.reconnectNow()
  }

  /**
   * Subscribe to a connection event; the returned function unsubscribes.
   *
   * A `projectSignal` listener is handed the buffered page frames right away,
   * synchronously, before this call returns: the view has to hold the state
   * before its first paint, or it draws one empty frame — the very flicker this
   * buffer exists to remove. That the listener runs before the caller has
   * stored the unsubscribe function is harmless; the function only ever governs
   * frames still to come. Every other event stays live-only, because a replayed
   * action reply or toast would answer a question nobody asked.
   *
   * @param event The connection event to listen to.
   * @param listener Called with that event's payload.
   */
  on<K extends keyof HilosConnectionEventMap>(
    event: K,
    listener: (payload: HilosConnectionEventMap[K]) => void,
  ): () => void {
    const unsubscribe = this.emitter.on(event, listener)
    if (event === 'projectSignal') {
      const replay = listener as (payload: ProjectSignal) => void
      for (const frame of this.pageFrames.values()) {
        replay(frame)
      }
    }
    return unsubscribe
  }

  /**
   * Drop every buffered page frame.
   *
   * Called by the page subscription alone: the connection has no idea when a
   * page ends, and the subscription has no idea when a frame arrives, so the
   * two halves of the buffer's life live where each is known.
   */
  forgetPageFrames(): void {
    this.pageFrames.clear()
  }

  /** Open the connection. No-op unless currently `disconnected`. */
  connect(): void {
    if (this.currentState !== 'disconnected') {
      return
    }
    this.reconnectAttempt = 0
    this.armFirstFrameHold()
    this.setState('connecting')
    this.openSocket()
  }

  /** Close for good: cancels reconnect, stops keepalive, `disconnected`. */
  close(): void {
    this.generation += 1
    this.clearHeldRotation()
    this.clearReconnectTimer()
    this.stopKeepalive()
    this.closeSocket()
    this.setState('disconnected')
  }

  /**
   * Send one raw text frame. Returns false — and sends nothing — unless the
   * connection is currently `connected`; senders re-send on the next
   * `connected` transition instead of queueing (every new socket starts a
   * fresh protocol exchange).
   *
   * Also refuses, the same way, while protected mode holds: every outbound frame
   * reaches an agent, and a stopped agent is what the freeze is made of. This is
   * the client half of the server-side start gate — the maintenance surface must
   * not be the thing that wakes the system it is apologizing for. The keepalive
   * ping is not affected: the daemon answers it in the socket layer without
   * involving an agent, and a connection that stops pinging simply dies.
   *
   * @param text The frame payload, already serialized.
   */
  send(text: string): boolean {
    if (this.currentState !== 'connected' || this.socket === null) {
      return false
    }
    if (this.protectedModeStatus.active) {
      return false
    }
    this.socket.send(text)

    return true
  }

  /**
   * Send one raw binary frame — the `frame_binary` upload channel of
   * wire-protocol.md. Returns false, sending nothing, unless the connection is
   * currently `connected`, exactly like {@link send} — including its refusal while
   * protected mode holds; a dropped upload restarts rather than queueing. Callers stream a file as a sequence of these once the
   * backend has acknowledged the upload init sent over {@link sendAction}.
   *
   * @param data The binary chunk to send.
   */
  sendBinary(data: ArrayBuffer | Blob): boolean {
    if (this.currentState !== 'connected' || this.socket === null) {
      return false
    }
    if (this.protectedModeStatus.active) {
      return false
    }
    this.socket.send(data)

    return true
  }

  /**
   * Send a client action frame — `{type:'action', action, data, requestId?}` —
   * that the subscribed page's ACTIONS map routes by name. Returns false,
   * sending nothing, unless the connection is `connected`, like {@link send}.
   *
   * Passing a `requestId` opts the action into the framework reply lifecycle:
   * the backend echoes that id on the action's `::success` / `::fail`. Omitting
   * it is fire-and-forget (no reply). Callers that want the loading / await /
   * timeout lifecycle use `ActionLifecycle.dispatch` rather than calling this
   * directly.
   *
   * @param action The action name the subscribed page routes on.
   * @param data The action payload, carried under the `data` field.
   * @param requestId Optional client-minted id for reply correlation.
   */
  sendAction(action: string, data: unknown, requestId?: string): boolean {
    const frame: Record<string, unknown> = {
      [FIELD_TYPE]: SIGNAL_TYPE_ACTION,
      [FIELD_ACTION]: action,
      [FIELD_DATA]: data,
    }
    if (requestId !== undefined) {
      frame[FIELD_REQUEST_ID] = requestId
    }

    const sent = this.send(JSON.stringify(frame))
    if (sent && requestId !== undefined) {
      this.awaitedReplies.add(requestId)
    }

    return sent
  }

  /**
   * Send a group-subscribe frame — `{type:'group_subscribe', group}` — joining
   * this connection to a server-side WebSocket group so it receives every signal
   * the backend fans to that group. Returns false, sending nothing, unless the
   * connection is `connected`, like {@link send}; a caller re-sends on the next
   * `connected` transition (a fresh socket starts a fresh subscription set).
   *
   * Group membership is many-per-connection and independent of the single page
   * subscription, so a live channel a connection keeps for its whole life (e.g.
   * the notification center's per-user group) rides this rather than a page.
   *
   * @param group The group identifier to join.
   */
  subscribeToGroup(group: string): boolean {
    return this.send(
      JSON.stringify({
        [FIELD_TYPE]: SIGNAL_TYPE_GROUP_SUBSCRIBE,
        [FIELD_GROUP]: group,
      }),
    )
  }

  /**
   * Send a group-unsubscribe frame — `{type:'group_unsubscribe', group}` —
   * leaving a group this connection joined with {@link subscribeToGroup}. Returns
   * false, sending nothing, unless the connection is `connected`, like
   * {@link send}. Rarely needed for connection-lifetime groups: a dropped socket
   * drops every membership server-side.
   *
   * @param group The group identifier to leave.
   */
  unsubscribeFromGroup(group: string): boolean {
    return this.send(
      JSON.stringify({
        [FIELD_TYPE]: SIGNAL_TYPE_GROUP_UNSUBSCRIBE,
        [FIELD_GROUP]: group,
      }),
    )
  }

  /**
   * Send a table viewport frame — `{type:'table_viewport', page, tableKey,
   * filter?, sort?, offset, limit}` — declaring the window this connection wants
   * for one table. The server replies a table_window snapshot and scopes live
   * deltas to the delivered rows. Returns false, sending nothing, unless the
   * connection is `connected`, like {@link send}.
   *
   * @param page The page the table belongs to.
   * @param tableKey The table key the viewport scopes.
   * @param descriptor The window descriptor (filter, sort, offset, limit).
   */
  sendTableViewport(
    page: string,
    tableKey: string,
    descriptor: TableViewportDescriptor,
  ): boolean {
    const frame: Record<string, unknown> = {
      [FIELD_TYPE]: SIGNAL_TYPE_TABLE_VIEWPORT,
      [FIELD_PAGE]: page,
      [FIELD_TABLE_KEY]: tableKey,
      [FIELD_OFFSET]: descriptor.offset,
      [FIELD_LIMIT]: descriptor.limit,
    }
    if (Object.keys(descriptor.filter).length > 0) {
      frame[FIELD_FILTER] = descriptor.filter
    }
    if (descriptor.sort !== null) {
      frame[FIELD_SORT] = descriptor.sort
    }

    return this.send(JSON.stringify(frame))
  }

  private openSocket(): void {
    const generation = ++this.generation
    const socket = this.webSocketFactory(this.socketUrl())
    this.socket = socket
    socket.addEventListener('open', () => {
      this.ifCurrent(generation, () => this.handleOpen())
    })
    socket.addEventListener('message', (event) => {
      this.ifCurrent(generation, () => this.handleFrame(event.data))
    })
    socket.addEventListener('close', () => {
      this.ifCurrent(generation, () => this.handleSocketDown())
    })
    socket.addEventListener('error', () => {
      this.ifCurrent(generation, () => this.handleSocketDown())
    })
  }

  /** Drops events from sockets and timers a newer generation has replaced. */
  private ifCurrent(generation: number, handler: () => void): void {
    if (generation === this.generation) {
      handler()
    }
  }

  private handleOpen(): void {
    this.reconnectAttempt = 0
    this.setState('connected')
    this.startKeepalive()
  }

  private handleSocketDown(): void {
    // Bump the generation so the sibling close/error event of the same dead
    // socket cannot double-schedule a reconnect.
    this.generation += 1
    this.clearHeldRotation()
    this.stopKeepalive()
    this.closeSocket()
    this.setState('reconnecting')
    this.scheduleReconnect()
  }

  private handleFrame(data: unknown): void {
    const result = parseSignal(data, this.projectSchemas)
    if (!result.ok) {
      this.emitter.emit('parseFailure', result.failure)
      return
    }

    const signal = result.signal
    switch (signal.kind) {
      case 'handshake':
        this.handleHandshake(signal)
        break
      case 'protectedMode':
        this.handleProtectedMode(signal)
        break
      case 'rtStaleness':
        this.rtStalenessStatus = signal.state
        this.emitter.emit('rtStaleness', signal.state)
        break
      case 'sessionRotate':
        this.handleSessionRotate(signal)
        break
      case 'actionSuccess':
        // Delivered first, noted second: noting it can be the arrival a held
        // rotation was waiting for, and that reconnect fails every action still
        // in flight — including this one, whose answer is right here.
        this.emitter.emit('actionSuccess', signal)
        this.replyArrived(signal.requestId)
        break
      case 'actionError':
        this.emitter.emit('actionError', signal)
        this.replyArrived(signal.requestId)
        break
      case 'project':
        this.rememberPageFrame(signal)
        this.emitter.emit('projectSignal', signal)
        break
      case 'tableWindow':
        this.emitter.emit('tableWindow', signal)
        break
      case 'tableViewportDelta':
        this.emitter.emit('tableViewportDelta', signal)
        break
      case 'tableViewportCount':
        this.emitter.emit('tableViewportCount', signal)
        break
      case 'tableViewportAppend':
        this.emitter.emit('tableViewportAppend', signal)
        break
      case 'tableViewportOwnCreate':
        this.emitter.emit('tableViewportOwnCreate', signal)
        break
      case 'unknown':
        this.emitter.emit('unknownSignal', signal)
        break
      default:
        assertNever(signal)
    }
    this.emitter.emit('signal', signal)
  }

  /**
   * Keep a page's state frame for a listener that is not there yet.
   *
   * A refusal is left out although its name fits the prefix: it does not
   * describe the page, it rides `pageError` of its own, and replayed later it
   * would draw a ban the server has since lifted.
   *
   * @param signal The project signal just parsed off the wire.
   */
  private rememberPageFrame(signal: ProjectSignal): void {
    if (
      signal.type.startsWith(PAGE_FRAME_PREFIX) &&
      signal.type !== SIGNAL_TYPE_PAGE_SUBSCRIPTION_ERROR
    ) {
      this.pageFrames.set(signal.type, signal)
    }
  }

  private handleHandshake(signal: HandshakeSignal): void {
    const expected = this.expectedBuild ?? this.latchedBuild
    if (expected === undefined) {
      this.latchedBuild = signal.build
    } else if (signal.build !== expected) {
      this.emitter.emit('buildMismatch', { expected, received: signal.build })
    }
    if (signal.sessionCookieName !== undefined) {
      this.sessionCookieName = signal.sessionCookieName
    }
    // A reconnect lands here: the welcome is how a connection that came back
    // learns the freeze it slept through, or that it is over.
    this.syncProtectedMode(signal.protectedMode)
    this.rememberMaintenance(signal.protectedMode)
    this.emitter.emit('handshake', signal)
    // Last, so that a shell woken by this release reads a connection that has
    // already taken the welcome in: the freeze it is about to draw is the one
    // stored two lines above, not the one from before the frame.
    this.releaseFirstFrame()
  }

  /**
   * A login on this connection rotated the session token: hand the ticket over and go
   * get the new cookie.
   *
   * The reconnect is the point of the whole exchange — the cookie can only be set on a
   * 101 — so it happens here rather than being left to the consumer, and it happens
   * straight after the listeners run: they write the cookie synchronously, and the
   * socket that opens next carries it. It also skips the backoff delay, which exists to
   * spread a fleet reconnecting after a restart and would only be a pause the visitor
   * spends logged in on a token the server no longer answers to.
   *
   * A welcome that never named the session cookie leaves nothing to write, so nothing
   * is done at all: reconnecting without the ticket would trade a live session for an
   * anonymous one, and staying put costs only the rotation.
   */
  private handleSessionRotate(signal: SessionRotateSignal): void {
    const cookieName = this.sessionCookieName
    if (cookieName === undefined) {
      return
    }
    const rotation = {
      ticket: signal.ticket,
      cookieName: `${cookieName}${SESSION_ROTATE_COOKIE_SUFFIX}`,
    }
    this.emitter.emit('sessionRotate', rotation)
    this.holdRotationUntilQuiet(rotation)
  }

  /**
   * Reconnect for the rotation, but not while a tracked action is still unanswered.
   *
   * The login that caused the rotation IS such an action, and its reply is behind this
   * signal on the same wire: the seam announces the rotation from inside the handler,
   * the framework sends the reply once the handler returns. Dropping the socket the
   * moment the signal lands therefore throws away the answer to the very click that
   * started it, and the surface that dispatched it waits forever. Ordering this by
   * hoping the reply arrives in the same batch of frames would be a race decided by
   * TCP; waiting for the ids the socket is actually owed is not.
   *
   * The grace timer is the other half: a reply that never comes must not strand the
   * ticket until it expires, after which the browser would hold a cookie naming a
   * session that has moved. When it fires the reconnect happens regardless, and the
   * unanswered action fails the way any dropped action does.
   */
  private holdRotationUntilQuiet(rotation: SessionRotation): void {
    if (this.awaitedReplies.size === 0) {
      this.reconnectNow()

      return
    }
    this.heldRotation = rotation
    const generation = this.generation
    this.heldRotationTimer = setTimeout(() => {
      this.heldRotationTimer = null
      this.ifCurrent(generation, () => this.releaseRotation())
    }, ROTATION_REPLY_GRACE_MS)
  }

  /** Notes a reply the socket was owed, and releases a rotation the last one was holding. */
  private replyArrived(requestId: string | undefined): void {
    if (requestId === undefined) {
      return
    }
    this.awaitedReplies.delete(requestId)
    if (this.awaitedReplies.size === 0 && this.heldRotation !== null) {
      this.releaseRotation()
    }
  }

  /** Performs the reconnect a rotation was waiting to make. */
  private releaseRotation(): void {
    if (this.heldRotation === null) {
      return
    }
    this.heldRotation = null
    this.reconnectNow()
  }

  /** Drops the current socket and opens the next one without waiting out a backoff delay. */
  private reconnectNow(): void {
    this.clearHeldRotation()
    // Bumped before the close, not only by openSocket() after it: a socket that reports
    // its close synchronously would otherwise still be current, and its handler would
    // schedule a second, delayed reconnect on top of the one opening here.
    this.generation += 1
    this.clearReconnectTimer()
    this.stopKeepalive()
    this.closeSocket()
    this.reconnectAttempt = 0
    this.setState('reconnecting')
    this.openSocket()
  }

  /**
   * A pushed frame is a phase transition, not a re-announcement — the daemon sends
   * it exactly when the mode goes on or off — so it is stored and reported without
   * comparing it to the current state. A comparison would be a second opinion about
   * a state this side does not own: the daemon speaks on the phase, and a frame that
   * repeats what this client already holds still means the phase moved under it.
   * Until HIL-718 the initiator was the standing proof of that — left out of the
   * "mode on" push, it held the mode off all the way to the lift, and comparing
   * would have made the one admin who ran the restore the one client that never
   * reloads. That browser is pushed to like every other now, and the rule stays for
   * the reason above rather than for that example.
   */
  private handleProtectedMode(signal: ProtectedModeSignal): void {
    this.keepPassWhileTheWindowIsOpen(signal.state)
    this.rememberMaintenance(signal.state)
    this.protectedModeStatus = signal.state
    this.emitter.emit('protectedMode', signal.state)
  }

  /**
   * Stores the state a welcome re-announced, emitting only when it changed.
   *
   * This is also where a presented pass gets its answer. A frozen node has no agent
   * left to compose a refusal, so a wrong key is answered by the welcome that comes
   * back still locking this connection out — and that silence is what becomes
   * `passRejected`, rather than being left to look like nothing happened.
   */
  private syncProtectedMode(status: ProtectedModeStatus): void {
    const answered: ProtectedModeStatus =
      status.active && this.presentedPass !== undefined
        ? { ...status, passRejected: true }
        : status

    // After the rejection is read off it, never before: a wrong key is answered by a
    // welcome that still locks this connection out, and that welcome describes a
    // window which is very much open.
    this.keepPassWhileTheWindowIsOpen(answered)
    if (isSameProtectedModeStatus(this.protectedModeStatus, answered)) {
      return
    }
    this.protectedModeStatus = answered
    this.emitter.emit('protectedMode', answered)
  }

  /**
   * Drops a presented pass as soon as a frame says the window that minted it is over.
   *
   * One rule for both frames, because both can be the one that brings the news: a tab
   * that was disconnected when the mode lifted learns it from the welcome of the socket
   * that comes back. `acceptsPass` is the row's own bit and says nothing about this
   * connection, which is what makes it usable here — it stays true for the verifier
   * already admitted, and goes false on the lift and on the operator closing back, the
   * two moments the row voids every hash it holds. Dropped before the event, so a
   * listener reading the connection sees a key that opens something or none at all.
   *
   * @param status The state the frame announced.
   */
  private keepPassWhileTheWindowIsOpen(status: ProtectedModeStatus): void {
    if (!status.acceptsPass) {
      this.forgetProtectedModePass()
    }
  }

  /** The socket url, carrying a presented pass so admission is decided on the 101. */
  private socketUrl(): string {
    if (this.presentedPass === undefined) {
      return this.url
    }

    const separator = this.url.includes('?') ? '&' : '?'

    return `${this.url}${separator}${PROTECTED_MODE_PASS_PARAM}=${encodeURIComponent(this.presentedPass)}`
  }

  /** Drops the presented pass, here and in storage: it opens nothing any more. */
  private forgetProtectedModePass(): void {
    this.presentedPass = undefined
    writeStoredProtectedModePass(undefined)
  }

  private scheduleReconnect(): void {
    const generation = this.generation
    const delay = computeBackoffDelay(
      this.reconnectAttempt,
      this.reconnectOptions,
      this.random,
    )
    this.reconnectAttempt += 1
    this.reconnectTimer = setTimeout(() => {
      this.reconnectTimer = null
      this.ifCurrent(generation, () => this.openSocket())
    }, delay)
  }

  private clearReconnectTimer(): void {
    if (this.reconnectTimer !== null) {
      clearTimeout(this.reconnectTimer)
      this.reconnectTimer = null
    }
  }

  private startKeepalive(): void {
    this.keepaliveTimer = setInterval(() => {
      this.socket?.send(KEEPALIVE_TEXT_PING)
    }, this.keepaliveIntervalMs)
  }

  private stopKeepalive(): void {
    if (this.keepaliveTimer !== null) {
      clearInterval(this.keepaliveTimer)
      this.keepaliveTimer = null
    }
  }

  /** Forgets what the socket was owed, and any rotation waiting on it. */
  private clearHeldRotation(): void {
    this.awaitedReplies.clear()
    this.heldRotation = null
    if (this.heldRotationTimer !== null) {
      clearTimeout(this.heldRotationTimer)
      this.heldRotationTimer = null
    }
  }

  private closeSocket(): void {
    if (this.socket === null) {
      return
    }
    try {
      this.socket.close()
    } catch {
      // A socket already closed by the peer may throw; the instance is
      // discarded either way.
    }
    this.socket = null
  }

  private setState(state: ConnectionState): void {
    if (this.currentState === state) {
      return
    }
    this.currentState = state
    this.emitter.emit('state', state)
    // Nothing is going to answer on this socket, so the hold would become a blank
    // screen with no end to it. Fail open: draw the ordinary shell, which is
    // exactly what an unreachable node showed before any of this existed. Last,
    // for the same reason as on the welcome — the shell it wakes reads a
    // connection that has already moved.
    if (state === 'reconnecting' || state === 'disconnected') {
      this.releaseFirstFrame()
    }
  }

  /**
   * Start the backstop that ends the hold when no frame does.
   *
   * Armed only where there is a hold to end: a browser with no hint is not
   * waiting for anything, and giving it a timer would mean every ordinary load
   * pays for a defect it never had.
   */
  private armFirstFrameHold(): void {
    if (!this.firstFrameHeldFlag || this.firstFrameHoldTimer !== null) {
      return
    }
    this.firstFrameHoldTimer = setTimeout(() => {
      this.releaseFirstFrame()
    }, FIRST_FRAME_HOLD_TIMEOUT_MS)
  }

  /**
   * Let the shell draw, once and for good.
   *
   * Idempotent because all three of its callers race by design — the welcome, a
   * dropped socket and the deadline are alternatives, not a sequence — and
   * whichever gets there first is the one whose verdict the shell already drew.
   */
  private releaseFirstFrame(): void {
    if (this.firstFrameHoldTimer !== null) {
      clearTimeout(this.firstFrameHoldTimer)
      this.firstFrameHoldTimer = null
    }
    if (!this.firstFrameHeldFlag) {
      return
    }
    this.firstFrameHeldFlag = false
    this.emitter.emit('firstFrameHold', false)
  }

  /**
   * Write down what this frame said about maintenance, for the next load to act
   * on.
   *
   * `acceptsPass` counts as maintenance and not only `active`, because an
   * admitted verifier is told `active: false` — the freeze is off for this
   * connection alone. Judged by `active` he would clear the hint on arrival and
   * then flash the shell on every reload he makes inside the window, which is
   * the one window where reloads are frequent.
   *
   * @param status The state the frame announced.
   */
  private rememberMaintenance(status: ProtectedModeStatus): void {
    writeProtectedModeHint(status.active || status.acceptsPass)
  }
}
