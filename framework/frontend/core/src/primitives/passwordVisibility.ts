// Headless PasswordVisibilityState: manages password reveal/hide state and
// resets visibility whenever the active context changes (e.g. switching
// step, identifier, or intent). Plain TypeScript, no DOM, no password storage.

import { createSignal, type ReadonlySignal } from '../state/signal.js'

export interface PasswordVisibilityState {
  /** Whether the password should be shown as plain text. */
  readonly visible: ReadonlySignal<boolean>
  /** Toggle visibility. No-op if current context is null (inactive). */
  toggle(): void
  /**
   * Set the active context (e.g. JSON.stringify([identifier, step, intent])).
   * If context === currentContext, visibility is preserved.
   * If context !== currentContext, currentContext becomes context and visible is reset to false.
   *
   * @param context The new context, or null if inactive/hidden.
   */
  setContext(context: string | null): void
}

/**
 * Create the headless state for password visibility. It starts inactive: the
 * context is null until the first setContext().
 */
export function createPasswordVisibilityState(): PasswordVisibilityState {
  let currentContext: string | null = null
  const visible = createSignal(false)

  return {
    visible,
    toggle(): void {
      if (currentContext === null) {
        return
      }
      visible.set(!visible.get())
    },
    setContext(context: string | null): void {
      if (context === currentContext) {
        return
      }
      currentContext = context
      visible.set(false)
    },
  }
}
