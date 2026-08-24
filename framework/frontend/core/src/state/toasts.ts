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
//
// A notice nobody asked for lands while the user is looking elsewhere, so the
// lifetime is measured in reading time, not in noticing time, and the countdown
// freezes while the stack is under the cursor or holds keyboard focus (WCAG
// 2.2.1 Timing Adjustable). Long lifetimes stack up, so the stack is capped:
// see MAX_VISIBLE_TOASTS.

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
   * {@link DEFAULT_ERROR_TTL_MS} — 40 seconds, an error carries a reason to read
   * and understand — and to {@link DEFAULT_TTL_MS} — 20 seconds — otherwise.
   * Pass a lifetime of your own only with the reason written at the call site
   * (toasts.md).
   */
  ttlMs?: number
}

/** Lifetime of a success / info toast. */
const DEFAULT_TTL_MS = 20000

/** Lifetime of an error toast — longer, because it carries a reason worth reading. */
const DEFAULT_ERROR_TTL_MS = 40000

/**
 * How many notices the corner shows at once; a further push evicts the oldest.
 *
 * Module-private on purpose: the cap answers how much of the screen corner stays
 * readable, which is this store's business and not a caller's.
 */
const MAX_VISIBLE_TOASTS = 4

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
  /**
   * Freeze the countdown of the whole stack, taking one hold.
   *
   * The cursor and keyboard focus are independent sources, so holds are counted:
   * leaving with the cursor while focus is still inside must not resume.
   */
  pause(): void
  /**
   * Release one hold; on the last one the countdown continues from what is left,
   * not from the full lifetime, and postponed evictions are applied.
   */
  resume(): void
}

/** What is left of one notice's lifetime, and the timer that will end it. */
interface PendingToast {
  /** The live countdown, or `null` while the stack is held. */
  timer: ReturnType<typeof setTimeout> | null
  /** Milliseconds still to run; frozen while `timer` is `null`. */
  remainingMs: number
  /** When the live timer fires; meaningless while `timer` is `null`. */
  deadline: number
}

/**
 * Create an independent toast stack.
 *
 * Applications use the shared {@link hilosToasts}; this factory exists so a test
 * (or a second window) gets its own stack with its own timers.
 */
export function createHilosToastStore(): HilosToastStore {
  const toasts = createSignal<readonly HilosToast[]>([])
  // Only a notice with a lifetime is tracked here: a sticky one (ttlMs 0) never
  // gets a timer, so it has nothing to freeze and nothing to resume.
  const pending = new Map<number, PendingToast>()
  let sequence = 0
  let holds = 0

  function cancel(id: number): void {
    const entry = pending.get(id)
    if (entry === undefined) {
      return
    }
    if (entry.timer !== null) {
      clearTimeout(entry.timer)
    }
    pending.delete(id)
  }

  function drop(id: number): void {
    cancel(id)
    toasts.set(toasts.get().filter((toast) => toast.id !== id))
  }

  function arm(id: number, entry: PendingToast): void {
    entry.deadline = Date.now() + entry.remainingMs
    entry.timer = setTimeout(() => {
      drop(id)
    }, entry.remainingMs)
  }

  /**
   * Trim `stack` down to the visible cap, cancelling what it evicts.
   *
   * Eviction is postponed while the stack is held: a pause promises that nothing
   * disappears while the user is reading, so an over-tall stack is allowed and
   * the excess leaves in one move on resume. A sticky notice is evicted like any
   * other, or four of them would own the corner forever.
   */
  function enforceLimit(stack: readonly HilosToast[]): readonly HilosToast[] {
    const excess = stack.length - MAX_VISIBLE_TOASTS
    if (holds > 0 || excess <= 0) {
      return stack
    }
    // The first of the list is the top one on screen: the container sits at
    // bottom-0 and new notices come in below.
    for (const toast of stack.slice(0, excess)) {
      cancel(toast.id)
    }

    return stack.slice(excess)
  }

  return {
    toasts,
    push(message, options = {}) {
      const severity = options.severity ?? 'info'
      const ttlMs =
        options.ttlMs ??
        (severity === 'error' ? DEFAULT_ERROR_TTL_MS : DEFAULT_TTL_MS)
      const id = ++sequence

      if (ttlMs > 0) {
        const entry: PendingToast = {
          timer: null,
          remainingMs: ttlMs,
          deadline: 0,
        }
        pending.set(id, entry)
        // Pushed into a held stack, it waits with its lifetime untouched and
        // starts ticking on resume.
        if (holds === 0) {
          arm(id, entry)
        }
      }
      toasts.set(enforceLimit([...toasts.get(), { id, message, severity }]))

      return id
    },
    dismiss(id) {
      drop(id)
    },
    clear() {
      for (const entry of pending.values()) {
        if (entry.timer !== null) {
          clearTimeout(entry.timer)
        }
      }
      pending.clear()
      holds = 0
      toasts.set([])
    },
    pause() {
      holds += 1
      if (holds > 1) {
        return
      }
      const now = Date.now()
      for (const entry of pending.values()) {
        if (entry.timer !== null) {
          clearTimeout(entry.timer)
          entry.timer = null
          entry.remainingMs = entry.deadline - now
        }
      }
    },
    resume() {
      if (holds === 0) {
        return
      }
      holds -= 1
      if (holds > 0) {
        return
      }
      // Every tracked notice is frozen here — either the pause stopped it or it
      // arrived during the hold — so all of them are armed again.
      for (const [id, entry] of pending) {
        arm(id, entry)
      }
      toasts.set(enforceLimit(toasts.get()))
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
