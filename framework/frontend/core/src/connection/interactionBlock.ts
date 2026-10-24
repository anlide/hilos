import {
  computedSignal,
  createSignal,
  type ReadonlySignal,
  type Unsubscribe,
} from '../state/signal.js'
import type { HilosConnection } from './HilosConnection.js'

/** The shell's connection explanation, shared by controls that refer to it. */
export const HILOS_CONNECTION_STATUS_TEXT_ID = 'hilos-connection-status-text'
/** Marks a region whose interaction is currently blocked by its connection. */
export const HILOS_INTERACTION_BLOCKED_ATTR = 'data-hilos-interaction-blocked'
/** Marks a region governed by the shell's connection interaction state. */
export const HILOS_INTERACTION_REGION_ATTR = 'data-hilos-interaction-region'

/** Shared connection explanation for all three view packages. */
export const CONNECTION_INTERACTION_COPY = {
  unavailable:
    'The connection is unavailable. Controls will work when it returns.',
} as const

/** Connection availability and the independent interaction policy of one shell. */
export interface ConnectionInteractionState {
  /** Whether the connection is in any state other than connected. */
  readonly unavailable: ReadonlySignal<boolean>
  /** Whether unavailable connection and the shell's policy block interaction. */
  readonly blocked: ReadonlySignal<boolean>
  /**
   * Detach the previous connection and read and observe the new one immediately.
   *
   * @param connection The shell's current connection.
   */
  setConnection(connection: HilosConnection): void
  /**
   * Change the interaction policy without changing connection availability.
   *
   * @param allow Whether the shell allows interaction without a connection.
   */
  setAllowDisconnectedInteraction(allow: boolean): void
  /** Detach once and prevent any later callback or setter from publishing. */
  dispose(): void
}

/**
 * Observe a connection for one shell, without DOM access or a global singleton.
 * An opt-out releases that shell's controls; it grants no server permission and
 * leaves `unavailable` true for connection-dependent chrome.
 *
 * @param connection The shell's initial connection; its snapshot is read at once.
 * @param allowDisconnectedInteraction Whether this shell permits disconnected interaction.
 */
export function createConnectionInteractionState(
  connection: HilosConnection,
  allowDisconnectedInteraction = false,
): ConnectionInteractionState {
  const unavailable = createSignal(connection.state !== 'connected')
  const allowDisconnected = createSignal(allowDisconnectedInteraction)
  const blocked = computedSignal(
    () => unavailable.get() && !allowDisconnected.get(),
  )
  let generation = 0
  let disposed = false
  let unsubscribe: Unsubscribe | undefined

  function setConnection(next: HilosConnection): void {
    if (disposed) return
    const currentGeneration = ++generation
    unsubscribe?.()
    unsubscribe = next.on('state', (state) => {
      if (!disposed && currentGeneration === generation) {
        unavailable.set(state !== 'connected')
      }
    })
    unavailable.set(next.state !== 'connected')
  }

  setConnection(connection)

  return {
    unavailable,
    blocked,
    setConnection,
    setAllowDisconnectedInteraction(allow): void {
      if (!disposed) allowDisconnected.set(allow)
    },
    dispose(): void {
      if (disposed) return
      disposed = true
      generation += 1
      unsubscribe?.()
      unsubscribe = undefined
    },
  }
}
