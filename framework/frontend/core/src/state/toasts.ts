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
// A notice has exactly two addressees, and the type says which (toasts.md):
// the connection that acted knows what it pressed, so it names no source and
// need lead nowhere; the session the server is answering did not ask for the
// news, so it must name its sender and lead to the record it announces.
//
// This module owns the POLICY of the stack — how long a notice lives, when the
// countdown runs, what happens when the corner is full, when two pushes are one
// notice. The card itself belongs to the three hosts. The split is what keeps
// the policy in one copy: the hosts report raw measurements (the window height,
// each rendered card's height) and the arithmetic happens here, so "a third of
// the screen" cannot drift apart between Vue, React and Angular.
//
// Nothing here touches the DOM or knows about the connection.

import { createSignal, type ReadonlySignal } from './signal.js'

/**
 * How a toast reads: an outcome that failed, one that worked, one that worked
 * with a caveat, or a neutral notice.
 */
export type HilosToastSeverity = 'error' | 'success' | 'info' | 'warning'

/**
 * Who the notice is addressed to: the connection that acted, or the session the
 * server is answering. There is no "user" level — see toasts.md.
 */
export type HilosToastScope = 'connection' | 'session'

/** One notice in the stack. */
export interface HilosToast {
  /** Stack-unique id, also the key a view renders the list by. */
  readonly id: number
  /** The line shown to the user. */
  readonly message: string
  /** How it reads (drives the card's color and its live region). */
  readonly severity: HilosToastSeverity
  /** Who it is addressed to. */
  readonly scope: HilosToastScope
  /** Who sent it; `null` for the connection's own action, which needs no signature. */
  readonly source: string | null
  /** The route the whole card leads to; `null` for the connection's own action. */
  readonly destination: string | null
  /** How many identical pushes this card stands for; `1` until a repeat arrives. */
  readonly repeats: number
  /** Whether a host has reported this card's height yet. */
  readonly measured: boolean
}

/** What did not fit in the corner: the service line's two numbers. */
export interface HilosToastOverflow {
  /** Errors queued for a free slot. */
  readonly waiting: number
  /** Notices that were dropped because the stack was full. */
  readonly missed: number
}

/**
 * Options for {@link HilosToastStore.push}.
 *
 * A discriminated union rather than one shape with a runtime check: a background
 * notice with nowhere to lead is a subsystem that failed to create a record, and
 * such a call must not compile in the first place (toasts.md). The lifetime is
 * not here — time belongs to the store.
 */
export type HilosToastOptions =
  | {
      /** How the notice reads; defaults to `info`. */
      severity?: HilosToastSeverity
      /** The user's own action; the default. */
      scope?: 'connection'
    }
  | {
      /** How the notice reads; defaults to `info`. */
      severity?: HilosToastSeverity
      /** The server answering this browser. */
      scope: 'session'
      /** Which subsystem sent it, shown as the card's signature. */
      source: string
      /** The route the whole card leads to — a path, not a record id. */
      destination: string
    }

/**
 * A host's handle on the stack: what it reports, and how it holds the countdown.
 *
 * Obtained from {@link HilosToastStore.attach} when the host mounts and given
 * back with {@link detach} when it goes away. While no viewer is attached the
 * countdown does not run at all, which is how "no showing before the first
 * frame" holds without this module knowing what a frame is.
 */
export interface HilosToastViewer {
  /**
   * Report the window height the stack sits in — on mount, and on every resize.
   *
   * @param pixels The window's inner height.
   */
  setViewportHeight(pixels: number): void
  /**
   * Report one rendered card's height, including the spacing it takes in the
   * stack. The first report of a card is what admits it to the screen; a later
   * report of the same card only updates the number, because nothing disappears
   * from under the reader's eyes.
   *
   * @param id The toast's id.
   * @param pixels The card's height plus its spacing.
   */
  reportHeight(id: number, pixels: number): void
  /** Take one hold on the countdown of the whole stack. */
  hold(): void
  /** Give one hold back; the last one resumes the countdown from what is left. */
  release(): void
  /** Detach this viewer; idempotent, so a double cleanup is harmless. */
  detach(): void
}

/** The transient notice stack a view renders. */
export interface HilosToastStore {
  /** The live stack, oldest first. */
  readonly toasts: ReadonlySignal<readonly HilosToast[]>
  /** What did not fit: the numbers the stack's service line shows. */
  readonly overflow: ReadonlySignal<HilosToastOverflow>
  /**
   * Queue a notice and return its id — the id of the twin it merged into, if an
   * identical one is already waiting or on screen.
   *
   * @param message The line to show.
   * @param options Severity and addressee — see {@link HilosToastOptions}.
   */
  push(message: string, options?: HilosToastOptions): number
  /**
   * Remove one notice early (the view's close button, or the caller superseding
   * it). A queued notice cannot be dismissed: closing is the user's answer to
   * something read, and nobody has seen it yet.
   *
   * @param id The id {@link push} returned.
   */
  dismiss(id: number): void
  /** Remove every notice, drop the queue and its counts, and cancel the timers. */
  clear(): void
  /** Attach a host to the stack; see {@link HilosToastViewer}. */
  attach(): HilosToastViewer
}

/** How long a notice that is not an error stays on screen — time to read it. */
const TOAST_TTL_MS = 20000

/** The share of the window height the stack may fill. */
const STACK_HEIGHT_DIVISOR = 3

/**
 * How many cards the stack holds while no height has been reported yet.
 *
 * Not a second branch of behavior but the starting value of the same budget: it
 * covers an app that mounts no host of its own, and the window between this leaf
 * and the redrawn card that reports its height.
 */
const TOASTS_WITHOUT_MEASUREMENT = 4

/** What is left of one notice's lifetime, and the timer that will end it. */
interface PendingToast {
  /** The live countdown, or `null` while the stack is frozen. */
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
  const overflow = createSignal<HilosToastOverflow>({ waiting: 0, missed: 0 })
  // Only a card that is measured, on screen and not an error is tracked here:
  // an error has no countdown at all, and an unmeasured card is not yet visible.
  const pending = new Map<number, PendingToast>()
  // Reported card heights, by toast id; shared by every attached viewer, which
  // is why they are keyed by the toast and not by the host.
  const heights = new Map<number, number>()
  // Errors that did not fit, oldest first. Only errors ever wait: everything
  // else collapses into `missed`.
  let queued: readonly HilosToast[] = []
  let missed = 0
  let viewportHeight = 0
  let anyHeightReported = false
  let sequence = 0
  let holds = 0
  let viewers = 0

  /** Whether the countdown of the stack is allowed to run right now. */
  function ticking(): boolean {
    return viewers > 0 && holds === 0
  }

  /** Publish the service line's numbers, and only when they moved. */
  function publishOverflow(): void {
    const current = overflow.get()
    if (current.waiting !== queued.length || current.missed !== missed) {
      overflow.set({ waiting: queued.length, missed })
    }
  }

  /**
   * The card with `id`, if it is on screen.
   *
   * @param id The toast's id.
   */
  function onScreen(id: number): HilosToast | undefined {
    return toasts.get().find((toast) => toast.id === id)
  }

  /**
   * Replace one card on screen with a changed copy.
   *
   * @param id The toast's id.
   * @param change The fields to overwrite.
   */
  function rewrite(id: number, change: Partial<HilosToast>): void {
    toasts.set(
      toasts
        .get()
        .map((toast) => (toast.id === id ? { ...toast, ...change } : toast)),
    )
  }

  /**
   * Start the timer of one tracked notice.
   *
   * @param id The toast's id.
   * @param entry Its countdown state.
   */
  function arm(id: number, entry: PendingToast): void {
    entry.deadline = Date.now() + entry.remainingMs
    entry.timer = setTimeout(() => {
      freeSlot(id)
    }, entry.remainingMs)
  }

  /**
   * Bring every countdown in line with {@link ticking}: run them all, or freeze
   * them all keeping what is left of each.
   */
  function retime(): void {
    if (ticking()) {
      for (const [id, entry] of pending) {
        if (entry.timer === null) {
          arm(id, entry)
        }
      }

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
  }

  /**
   * Take one card off the screen, cancelling whatever it owned.
   *
   * @param id The toast's id.
   */
  function take(id: number): void {
    const entry = pending.get(id)
    if (entry !== undefined && entry.timer !== null) {
      clearTimeout(entry.timer)
    }
    pending.delete(id)
    heights.delete(id)
    toasts.set(toasts.get().filter((toast) => toast.id !== id))
  }

  /**
   * Whether what is on screen is within the budget.
   *
   * The single card is always within it: one long message on a phone has to be
   * showable at all. Until a height has been reported the budget is counted in
   * cards; from the first report on it is the window's height over
   * {@link STACK_HEIGHT_DIVISOR}, summed over the cards that reported one.
   */
  function withinBudget(): boolean {
    const stack = toasts.get()
    if (stack.length <= 1) {
      return true
    }
    if (!anyHeightReported || viewportHeight <= 0) {
      return stack.length <= TOASTS_WITHOUT_MEASUREMENT
    }
    let used = 0
    for (const toast of stack) {
      used += heights.get(toast.id) ?? 0
    }

    return used <= viewportHeight / STACK_HEIGHT_DIVISOR
  }

  /**
   * Put a notice that did not fit where it belongs: an error waits for a slot in
   * arrival order, everything else becomes one more in the missed count.
   *
   * @param toast The card being taken off the screen.
   */
  function overflowed(toast: HilosToast): void {
    take(toast.id)
    if (toast.severity !== 'error') {
      missed += 1

      return
    }
    const waiting = { ...toast, measured: false }
    const after = queued.findIndex((other) => other.id > waiting.id)
    queued =
      after === -1
        ? [...queued, waiting]
        : [...queued.slice(0, after), waiting, ...queued.slice(after)]
  }

  /**
   * Give one measured notice its countdown. An error never gets one: it does not
   * expire at all, the user closes it.
   *
   * @param toast The card that just earned its place on screen.
   */
  function startCountdown(toast: HilosToast): void {
    if (toast.severity === 'error') {
      return
    }
    const entry: PendingToast = {
      timer: null,
      remainingMs: TOAST_TTL_MS,
      deadline: 0,
    }
    pending.set(toast.id, entry)
    if (ticking()) {
      arm(toast.id, entry)
    }
  }

  /** Let the first waiting error into the slot that just came free. */
  function admitNext(): void {
    const next = queued[0]
    if (next === undefined) {
      return
    }
    queued = queued.slice(1)
    toasts.set([...toasts.get(), next])
    // It arrives unmeasured, so a host admits it the same way it admits a fresh
    // push. Without measurements the budget is counted here, as it was on push.
    if (!anyHeightReported && !withinBudget()) {
      overflowed(next)
    }
  }

  /**
   * Free the slot of a card that is gone and settle what follows: the next error
   * in, and the missed count forgotten once there is nobody left to tell.
   *
   * @param id The toast's id.
   */
  function freeSlot(id: number): void {
    take(id)
    admitNext()
    if (toasts.get().length === 0 && queued.length === 0) {
      missed = 0
    }
    publishOverflow()
  }

  /**
   * The identical notice already known, on screen or waiting, if there is one.
   *
   * @param candidate The notice about to be pushed.
   */
  function twinOf(candidate: HilosToast): HilosToast | undefined {
    const same = (other: HilosToast): boolean =>
      other.message === candidate.message &&
      other.severity === candidate.severity &&
      other.source === candidate.source &&
      other.destination === candidate.destination

    return toasts.get().find(same) ?? queued.find(same)
  }

  /**
   * Count one more repeat on a notice already known, and give it its full time
   * back — it has only just been said again.
   *
   * @param twin The card the repeat merged into.
   */
  function repeat(twin: HilosToast): void {
    if (onScreen(twin.id) === undefined) {
      queued = queued.map((other) =>
        other.id === twin.id ? { ...other, repeats: other.repeats + 1 } : other,
      )

      return
    }
    rewrite(twin.id, { repeats: twin.repeats + 1 })
    const entry = pending.get(twin.id)
    if (entry === undefined) {
      return
    }
    if (entry.timer !== null) {
      clearTimeout(entry.timer)
      entry.timer = null
    }
    entry.remainingMs = TOAST_TTL_MS
    if (ticking()) {
      arm(twin.id, entry)
    }
  }

  return {
    toasts,
    overflow,
    push(message, options = {}) {
      const session = options.scope === 'session'
      const candidate: HilosToast = {
        id: sequence + 1,
        message,
        severity: options.severity ?? 'info',
        scope: session ? 'session' : 'connection',
        source: session ? options.source : null,
        destination: session ? options.destination : null,
        repeats: 1,
        measured: false,
      }
      const twin = twinOf(candidate)
      if (twin !== undefined) {
        repeat(twin)
        publishOverflow()

        return twin.id
      }
      sequence += 1
      // It goes up unmeasured and without a countdown: the host draws it,
      // measures it and reports back, and only then is it really on screen.
      toasts.set([...toasts.get(), candidate])
      if (!anyHeightReported && !withinBudget()) {
        overflowed(candidate)
      }
      publishOverflow()

      return candidate.id
    },
    dismiss(id) {
      if (onScreen(id) === undefined) {
        return
      }
      freeSlot(id)
    },
    clear() {
      for (const entry of pending.values()) {
        if (entry.timer !== null) {
          clearTimeout(entry.timer)
        }
      }
      pending.clear()
      heights.clear()
      queued = []
      missed = 0
      // The holds are deliberately left alone: each one belongs to a viewer that
      // is still holding its own half of the count, and zeroing them here would
      // make that viewer's next release drive the total below zero — after which
      // the countdown would run only while the cursor rests on the stack. A hold
      // comes back from its owner, or from detach.
      toasts.set([])
      publishOverflow()
    },
    attach() {
      viewers += 1
      retime()
      let held = 0
      let attached = true

      return {
        setViewportHeight(pixels) {
          if (!attached) {
            return
          }
          // A window that shrank takes nothing off the screen — new notices just
          // stop coming in until a slot frees. Nothing vanishes from under the
          // reader's eyes; the same rule that ended silent eviction.
          viewportHeight = pixels
        },
        reportHeight(id, pixels) {
          if (!attached || onScreen(id) === undefined) {
            return
          }
          const remeasured = heights.has(id)
          heights.set(id, pixels)
          anyHeightReported = true
          if (remeasured) {
            return
          }
          rewrite(id, { measured: true })
          // Read after the rewrite, not before it: what goes into the waiting
          // queue has to be the card as it now stands.
          const drawn = onScreen(id)
          if (drawn === undefined) {
            return
          }
          if (withinBudget()) {
            startCountdown(drawn)

            return
          }
          overflowed(drawn)
          publishOverflow()
        },
        hold() {
          if (!attached) {
            return
          }
          held += 1
          holds += 1
          retime()
        },
        release() {
          if (!attached || held === 0) {
            return
          }
          held -= 1
          holds -= 1
          retime()
        },
        detach() {
          if (!attached) {
            return
          }
          attached = false
          holds -= held
          held = 0
          viewers -= 1
          retime()
        },
      }
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
