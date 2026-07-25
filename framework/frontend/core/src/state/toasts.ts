// The framework toast store: transient, self-expiring notices the view layer
// renders in a fixed corner stack (the Bootstrap toast component). It is the
// presentation half of an outcome the user must notice but need not act on — a
// run that failed after its action was already acked, a late reply reconciling
// after a timeout (actionLifecycle.ts), a background job reporting back.
//
// The backend never asks for a toast: it reports domain outcomes and failures,
// and the frontend decides what deserves one (wire-protocol.md). Nothing here
// is durable — a toast that expires is gone, so anything a user may need later
// belongs in the feature's own record, not in this store.

import { createSignal, type ReadonlySignal } from './signal.js'

/** How a toast reads: an outcome that failed, one that worked, or a neutral notice. */
export type HilosToastSeverity = 'error' | 'success' | 'info'

/** One queued notice. */
export interface HilosToast {
  /** Stack-unique id, also the key a view renders the list by. */
  readonly id: number
  /** The line shown to the user. */
  readonly message: string
  /** How it reads (drives the Bootstrap color). */
  readonly severity: HilosToastSeverity
}

/** Options for {@link HilosToastStore.push}. */
export interface HilosToastOptions {
  /** How the notice reads; defaults to `info`. */
  severity?: HilosToastSeverity
  /**
   * Lifetime in milliseconds; 0 keeps it until dismissed. Defaults to
   * {@link DEFAULT_ERROR_TTL_MS} for an error (a failure deserves reading time)
   * and {@link DEFAULT_TTL_MS} otherwise.
   */
  ttlMs?: number
}

/** Lifetime of a success / info toast. */
const DEFAULT_TTL_MS = 5000

/** Lifetime of an error toast — longer, because it carries a reason worth reading. */
const DEFAULT_ERROR_TTL_MS = 12000

/** The transient notice stack a view renders. */
export interface HilosToastStore {
  /** The live stack, oldest first. */
  readonly toasts: ReadonlySignal<readonly HilosToast[]>
  /**
   * Queue a notice and return its id.
   *
   * @param message The line to show.
   * @param options Severity and lifetime — see {@link HilosToastOptions}.
   */
  push(message: string, options?: HilosToastOptions): number
  /**
   * Remove one notice early (the view's close button, or the caller superseding it).
   *
   * @param id The id {@link push} returned.
   */
  dismiss(id: number): void
  /** Remove every notice and cancel their timers. */
  clear(): void
}

/**
 * Create an independent toast stack.
 *
 * Applications use the shared {@link hilosToasts}; this factory exists so a test
 * (or a second window) gets its own stack with its own timers.
 */
export function createHilosToastStore(): HilosToastStore {
  const toasts = createSignal<readonly HilosToast[]>([])
  const timers = new Map<number, ReturnType<typeof setTimeout>>()
  let sequence = 0

  function drop(id: number): void {
    const timer = timers.get(id)
    if (timer !== undefined) {
      clearTimeout(timer)
      timers.delete(id)
    }
    toasts.set(toasts.get().filter((toast) => toast.id !== id))
  }

  return {
    toasts,
    push(message, options = {}) {
      const severity = options.severity ?? 'info'
      const ttlMs =
        options.ttlMs ??
        (severity === 'error' ? DEFAULT_ERROR_TTL_MS : DEFAULT_TTL_MS)
      const id = ++sequence

      toasts.set([...toasts.get(), { id, message, severity }])
      if (ttlMs > 0) {
        timers.set(
          id,
          setTimeout(() => {
            drop(id)
          }, ttlMs),
        )
      }

      return id
    },
    dismiss(id) {
      drop(id)
    },
    clear() {
      for (const timer of timers.values()) {
        clearTimeout(timer)
      }
      timers.clear()
      toasts.set([])
    },
  }
}

/**
 * The application-wide toast stack.
 *
 * One per loaded SDK: the host component mounted in the shell renders it, and any
 * store or view pushes into it without threading a reference through props in
 * three frameworks. A test that needs isolation builds its own with
 * {@link createHilosToastStore}.
 */
export const hilosToasts: HilosToastStore = createHilosToastStore()
