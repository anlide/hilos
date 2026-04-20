import type { WebSocketOutcome } from '../types/websocket-messages'
import { SignalDefinition, computeDispatchKey } from './signals'

/**
 * Raw (untyped) signal handler. Used by prefix / fallback / legacy `on(string)`.
 */
export type SignalHandler = (data: unknown) => void

/**
 * Typed signal handler, bound to a {@link SignalDefinition} payload type.
 */
export type TypedSignalHandler<D> = (data: D) => void

/**
 * Frontend signal router — config-driven dispatch of WebSocket messages.
 *
 * Analogous to backend SignalRouter (framework/backend/Core/Router/SignalRouter.php).
 *
 * Dispatch priority: exact dispatch key match -> first prefix match -> fallback -> throw.
 *
 * Exact dispatch keys come in two flavours:
 * - Plain: just `type`.
 * - Outcome-marked: `type::success` or `type::fail` — matches {@link SignalDefinition.dispatchKey}.
 */
export class VueSignalRouter {
  private exact = new Map<string, SignalHandler>()
  private prefixes: Array<{ prefix: string; handler: SignalHandler }> = []
  private fallback: SignalHandler | null = null

  /**
   * Register a typed handler for a {@link SignalDefinition}.
   *
   * The router invokes `definition.parse(raw)` before calling the handler;
   * on parse failure (null) the message is logged and dropped.
   */
  on<T extends string, D, O extends WebSocketOutcome | null>(
    definition: SignalDefinition<T, D, O>,
    handler: TypedSignalHandler<D>,
  ): this
  /**
   * Register a raw handler for an exact signal type string (no outcome, no parsing).
   * Prefer the {@link SignalDefinition} overload; this exists for legacy call sites.
   */
  on(signal: string, handler: SignalHandler): this
  on(
    signalOrDef: string | SignalDefinition<string, unknown, WebSocketOutcome | null>,
    handler: SignalHandler | TypedSignalHandler<unknown>,
  ): this {
    if (typeof signalOrDef === 'string') {
      this.exact.set(signalOrDef, handler as SignalHandler)
      return this
    }

    const def = signalOrDef
    this.exact.set(def.dispatchKey, (raw: unknown) => {
      const parsed = def.parse(raw)
      if (parsed === null) {
        console.warn(
          `[VueSignalRouter] Dropped signal ${def.dispatchKey}: parser rejected payload`,
          raw,
        )
        return
      }
      ;(handler as TypedSignalHandler<unknown>)(parsed)
    })
    return this
  }

  /**
   * Register a handler for all signal types starting with the given prefix.
   * Checked only when no exact match is found.
   *
   * Prefix handlers receive the raw `data` payload — no parsing is performed.
   */
  onPrefix(prefix: string, handler: SignalHandler): this {
    this.prefixes.push({ prefix, handler })
    return this
  }

  /**
   * Register a fallback handler invoked when no exact or prefix match is found.
   */
  onFallback(handler: SignalHandler): this {
    this.fallback = handler
    return this
  }

  /**
   * Dispatch a signal to the appropriate handler.
   *
   * @param type    Wire signal name from the message envelope.
   * @param data    Raw `data` payload (will be passed through parser for typed handlers).
   * @param outcome Optional envelope-level outcome marker (action-ack signals).
   */
  dispatch(type: string, data: unknown, outcome?: WebSocketOutcome): void {
    const key = computeDispatchKey(type, outcome)

    const exactHandler = this.exact.get(key)
    if (exactHandler) {
      exactHandler(data)
      return
    }

    // When outcome is set but no outcome-specific handler exists, fall back to plain `type`.
    if (outcome !== undefined) {
      const plainHandler = this.exact.get(type)
      if (plainHandler) {
        plainHandler(data)
        return
      }
    }

    for (const { prefix, handler } of this.prefixes) {
      if (type.startsWith(prefix)) {
        handler(data)
        return
      }
    }

    if (this.fallback) {
      this.fallback(data)
      return
    }

    throw new Error(
      `Unhandled websocket signal: ${type}${outcome !== undefined ? ` (outcome=${outcome})` : ''}`,
    )
  }
}
