// The Connection state machine of core-and-connection.md: transport liveness
// only (`connecting → connected → reconnecting → disconnected`), with
// exponential-backoff-plus-jitter reconnect and the text-ping keepalive.
// Authentication and authorization are separate machines and do not live here.
import {
  FIELD_ACTION,
  FIELD_DATA,
  FIELD_REQUEST_ID,
  FIELD_TYPE,
  KEEPALIVE_TEXT_PING,
  SIGNAL_TYPE_ACTION,
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
  type UnknownSignal,
} from '../protocol/parseSignal.js'
import {
  computeBackoffDelay,
  DEFAULT_RECONNECT_OPTIONS,
  type ReconnectOptions,
} from './backoff.js'
import { Emitter } from './events.js'

export type ConnectionState =
  | 'connecting'
  | 'connected'
  | 'reconnecting'
  | 'disconnected'

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
  /** A signal validated against a project-declared schema. */
  projectSignal: ProjectSignal
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
  }

  get state(): ConnectionState {
    return this.currentState
  }

  on<K extends keyof HilosConnectionEventMap>(
    event: K,
    listener: (payload: HilosConnectionEventMap[K]) => void,
  ): () => void {
    return this.emitter.on(event, listener)
  }

  /** Open the connection. No-op unless currently `disconnected`. */
  connect(): void {
    if (this.currentState !== 'disconnected') {
      return
    }
    this.reconnectAttempt = 0
    this.setState('connecting')
    this.openSocket()
  }

  /** Close for good: cancels reconnect, stops keepalive, `disconnected`. */
  close(): void {
    this.generation += 1
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
   * @param text The frame payload, already serialized.
   */
  send(text: string): boolean {
    if (this.currentState !== 'connected' || this.socket === null) {
      return false
    }
    this.socket.send(text)

    return true
  }

  /**
   * Send one raw binary frame — the `frame_binary` upload channel of
   * wire-protocol.md. Returns false, sending nothing, unless the connection is
   * currently `connected`, exactly like {@link send}; a dropped upload restarts
   * rather than queueing. Callers stream a file as a sequence of these once the
   * backend has acknowledged the upload init sent over {@link sendAction}.
   *
   * @param data The binary chunk to send.
   */
  sendBinary(data: ArrayBuffer | Blob): boolean {
    if (this.currentState !== 'connected' || this.socket === null) {
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

    return this.send(JSON.stringify(frame))
  }

  private openSocket(): void {
    const generation = ++this.generation
    const socket = this.webSocketFactory(this.url)
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
      case 'actionSuccess':
        this.emitter.emit('actionSuccess', signal)
        break
      case 'actionError':
        this.emitter.emit('actionError', signal)
        break
      case 'project':
        this.emitter.emit('projectSignal', signal)
        break
      case 'unknown':
        this.emitter.emit('unknownSignal', signal)
        break
      default:
        assertNever(signal)
    }
    this.emitter.emit('signal', signal)
  }

  private handleHandshake(signal: HandshakeSignal): void {
    const expected = this.expectedBuild ?? this.latchedBuild
    if (expected === undefined) {
      this.latchedBuild = signal.build
    } else if (signal.build !== expected) {
      this.emitter.emit('buildMismatch', { expected, received: signal.build })
    }
    this.emitter.emit('handshake', signal)
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
  }
}
