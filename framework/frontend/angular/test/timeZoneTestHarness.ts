import { bindTimeZoneState, type HilosConnection } from '@hilos/core/base'
import { vi } from 'vitest'

type Listener = (signal: never) => void

/** A fake connection that carries the same welcome and session events as the core tests. */
export interface TimeZoneTestBinding {
  /** Emit the authoritative current-user zone in a handshake response. */
  receive(timeZone: string | null): void
  /** Rebind the same connection, releasing its previous listeners first. */
  rebind(): void
  /** Release the current binding. */
  release(): void
  /** Number of active welcome and session listeners. */
  listenerCount(): number
}

/**
 * Bind the real reader-zone state to a fake connection with a fixed browser zone.
 *
 * @param browserTimeZone The zone Automatic resolves to in this test.
 */
export function bindTestTimeZone(browserTimeZone: string): TimeZoneTestBinding {
  const browser = new Intl.DateTimeFormat().resolvedOptions()
  vi.spyOn(Intl.DateTimeFormat.prototype, 'resolvedOptions').mockReturnValue({
    ...browser,
    timeZone: browserTimeZone,
  })

  const handshakeListeners = new Set<Listener>()
  const projectListeners = new Set<Listener>()
  const connection = {
    projectTimeZone: undefined as string | undefined,
    on(event: string, listener: Listener): () => void {
      const listeners =
        event === 'handshake'
          ? handshakeListeners
          : event === 'projectSignal'
            ? projectListeners
            : null
      if (listeners === null) return () => {}
      listeners.add(listener)
      return () => listeners.delete(listener)
    },
    receive(timeZone: string | null): void {
      const signal = {
        type: 'handshake_response',
        data: { entities: { currentUser: { id: 1, timeZone } } },
      }
      for (const listener of projectListeners) listener(signal as never)
    },
    listenerCount: (): number =>
      handshakeListeners.size + projectListeners.size,
  }

  let release = bindTimeZoneState(connection as unknown as HilosConnection)

  return {
    receive: connection.receive,
    rebind(): void {
      release = bindTimeZoneState(connection as unknown as HilosConnection)
    },
    release(): void {
      release()
    },
    listenerCount: connection.listenerCount,
  }
}
