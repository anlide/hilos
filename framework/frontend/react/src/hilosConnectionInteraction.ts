import { createContext, useContext } from 'react'
import type { ConnectionInteractionState } from '@hilos/core/base'

/** The shell's live core signals; consumers mirror them through their SDK adapter. */
export type ConnectionInteraction = Pick<
  ConnectionInteractionState,
  'unavailable' | 'blocked'
>

// Standalone components keep their existing interaction contract. This fallback
// has no writer, subscription, connection or browser lifetime.
const outsideLayout: ConnectionInteraction = Object.freeze({
  unavailable: Object.freeze({ get: () => false }),
  blocked: Object.freeze({ get: () => false }),
})

/** One core state shared by the page and the shell's own descendants, including portals. */
export const HilosConnectionInteractionContext =
  createContext<ConnectionInteractionState | null>(null)

/**
 * Read the shell's core signals; both are readonly false outside a layout.
 * ThemeMenu guards account actions with `unavailable`.
 */
export function useConnectionInteraction(): ConnectionInteraction {
  return useContext(HilosConnectionInteractionContext) ?? outsideLayout
}
