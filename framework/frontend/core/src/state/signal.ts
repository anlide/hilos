// The reactive signal primitive every store is built on. This is the ONLY
// module in the SDK allowed to import @vue/reactivity: the engine stays a
// private implementation detail behind this facade, so it can be swapped
// (e.g. for alien-signals) without touching stores or view adapters, and a
// duplicated engine copy in a consumer's tree stays harmless — view adapters
// consume `get()` + `subscribeSignal()` only and mirror values into their own
// framework's primitive, never sharing a reactivity instance with the app.
//
// Change detection is `Object.is` on the signal value (shallow): replace
// objects on update, never mutate them in place.

import { computed, shallowRef, watch } from '@vue/reactivity'

/** A reactive value that can be read and subscribed to, but not written. */
export interface ReadonlySignal<T> {
  /** Current value; reading inside `computedSignal` tracks it as a dependency. */
  get(): T
}

/** A reactive value the owning store writes; expose it as {@link ReadonlySignal}. */
export interface WritableSignal<T> extends ReadonlySignal<T> {
  /** Replace the value; listeners fire only when `Object.is` says it changed. */
  set(value: T): void
}

/** Stops the subscription created by {@link subscribeSignal}. */
export type Unsubscribe = () => void

/**
 * Create a writable signal holding `initial`.
 *
 * @param initial The starting value.
 */
export function createSignal<T>(initial: T): WritableSignal<T> {
  const value = shallowRef(initial)

  return {
    get: () => value.value,
    set: (next) => {
      value.value = next
    },
  }
}

/**
 * Create a derived signal: `getter` re-runs lazily when any signal it read
 * changes, and downstream listeners fire only when the result itself changes.
 *
 * @param getter Pure derivation over other signals' `get()` calls.
 */
export function computedSignal<T>(getter: () => T): ReadonlySignal<T> {
  const value = computed(getter)

  return {
    get: () => value.value,
  }
}

/**
 * Invoke `listener` with the new value whenever `signal` changes —
 * synchronously, on change only (never on subscription). This is the seam
 * view adapters mirror through.
 *
 * @param signal The signal to observe.
 * @param listener Called with the new value after each change.
 */
export function subscribeSignal<T>(
  signal: ReadonlySignal<T>,
  listener: (value: T) => void,
): Unsubscribe {
  const handle = watch(
    () => signal.get(),
    (value: T) => listener(value),
  )

  return () => handle.stop()
}
