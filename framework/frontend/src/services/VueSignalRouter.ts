// TODO: integrate framework WebSocket message types (websocket-messages.ts) for type-safe signal handlers

export type SignalHandler = (data: unknown) => void

/**
 * Frontend signal router — config-driven dispatch of WebSocket messages.
 * Analogous to backend SignalRouter (framework/backend/Core/Router/SignalRouter.php).
 *
 * Dispatch priority: exact match -> first prefix match -> fallback -> throw.
 */
export class VueSignalRouter {
  private exact = new Map<string, SignalHandler>()
  private prefixes: Array<{ prefix: string; handler: SignalHandler }> = []
  private fallback: SignalHandler | null = null

  /**
   * Register a handler for an exact signal type.
   */
  on(signal: string, handler: SignalHandler): this {
    this.exact.set(signal, handler)
    return this
  }

  /**
   * Register a handler for all signal types starting with the given prefix.
   * Checked only when no exact match is found.
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
   */
  dispatch(type: string, data: unknown): void {
    const exactHandler = this.exact.get(type)
    if (exactHandler) {
      exactHandler(data)
      return
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

    throw new Error(`Unhandled websocket signal type: ${type}`)
  }
}
