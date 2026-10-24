import { InjectionToken, inject } from '@angular/core'
import { computedSignal, type ReadonlySignal } from '@hilos/core/base'
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

/**
 * The layout's core state, published after required inputs are bound. The holder
 * is readonly to descendants and the same provider reaches projection and view.
 */
export const HILOS_CONNECTION_INTERACTION = new InjectionToken<
  ReadonlySignal<ConnectionInteractionState | null>
>('HilosConnectionInteraction')

/**
 * Read the shell's core signals in an injection context; both are readonly false
 * outside a layout. ThemeMenu guards account actions with `unavailable`.
 */
export function injectConnectionInteraction(): ConnectionInteraction {
  const state = inject(HILOS_CONNECTION_INTERACTION, { optional: true })
  if (state === null) return outsideLayout

  // Forward the instance, including its initial publication. There is no second
  // availability machine: core owns both values and their synchronous updates.
  return {
    unavailable: computedSignal(() => state.get()?.unavailable.get() ?? false),
    blocked: computedSignal(() => state.get()?.blocked.get() ?? false),
  }
}
