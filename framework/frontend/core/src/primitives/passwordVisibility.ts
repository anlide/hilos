// Headless PasswordVisibilityState: manages password reveal/hide state and
// resets visibility whenever the active context changes (e.g. switching
// step, identifier, or intent). Plain TypeScript, no DOM, no password storage.

import { createSignal, type ReadonlySignal } from '../state/signal.js'

export interface PasswordVisibilityState {
  /** Whether the password should be shown as plain text. */
  readonly isVisible: ReadonlySignal<boolean>
  /** Toggle visibility. No-op if current context is null (inactive). */
  toggle(): void
  /**
   * Update the active context key (e.g. JSON.stringify([identifier, step, intent])).
   * If key === currentContext, visibility is preserved.
   * If key !== currentContext, currentContext becomes key and isVisible is reset to false.
   *
   * @param key The new context key, or null if inactive/hidden.
   */
  updateContext(key: string | null): void
}

/**
 * Create the headless state for password visibility.
 *
 * @param initialContext The initial context key; defaults to null.
 */
export function createPasswordVisibilityState(
  initialContext: string | null = null,
): PasswordVisibilityState {
  let currentContext = initialContext
  const visible = createSignal(false)

  return {
    isVisible: visible,
    toggle(): void {
      if (currentContext === null) {
        return
      }
      visible.set(!visible.get())
    },
    updateContext(key: string | null): void {
      if (key === currentContext) {
        return
      }
      currentContext = key
      visible.set(false)
    },
  }
}
