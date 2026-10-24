import {
  bindTimeZoneState,
  type HilosConnection,
  type ProjectSignal,
} from '@hilos/core/base'

/** Bind real reader-zone state to a connection whose session answers the test controls. */
export function readerTimeZone() {
  const listeners = new Set<(signal: ProjectSignal) => void>()
  const release = bindTimeZoneState({
    projectTimeZone: 'UTC',
    on(event: string, listener: (signal: ProjectSignal) => void) {
      if (event === 'projectSignal') listeners.add(listener)
      return () => {
        listeners.delete(listener)
      }
    },
  } as unknown as HilosConnection)
  const choose = (timeZone: string | null): void => {
    for (const listener of listeners) {
      listener({
        type: 'handshake_response',
        data: { entities: { currentUser: { id: 99, timeZone } } },
      } as ProjectSignal)
    }
  }
  return {
    choose,
    release() {
      choose(null)
      release()
    },
  }
}
