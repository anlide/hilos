// Minimal typed event emitter used inside the core. Internal — the public
// surface is HilosConnection.on(); adapters never see this class.

export class Emitter<TEventMap extends Record<string, unknown>> {
  private readonly listeners = new Map<
    keyof TEventMap,
    Set<(payload: never) => void>
  >()

  /** Subscribe to one event; returns the unsubscribe function. */
  on<K extends keyof TEventMap>(
    event: K,
    listener: (payload: TEventMap[K]) => void,
  ): () => void {
    let set = this.listeners.get(event)
    if (set === undefined) {
      set = new Set()
      this.listeners.set(event, set)
    }
    set.add(listener as (payload: never) => void)

    return () => {
      set.delete(listener as (payload: never) => void)
    }
  }

  emit<K extends keyof TEventMap>(event: K, payload: TEventMap[K]): void {
    const set = this.listeners.get(event)
    if (set === undefined) {
      return
    }
    for (const listener of set) {
      ;(listener as (value: TEventMap[K]) => void)(payload)
    }
  }
}
