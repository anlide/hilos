// The framework toast store: transient, self-expiring notices the view layer
// renders in a fixed corner stack (the Bootstrap toast component). It is the
// presentation half of an outcome the user must notice but need not act on — a
// run that failed after its action was already acked, a late reply reconciling
// after a timeout (actionLifecycle.ts), a background job reporting back.
//
// A notice has exactly two addressees, and the type says which (toasts.md):
// the connection that acted knows what it pressed, so it names no source and
// need lead nowhere; the session the server is answering did not ask for the
// news, so it must name its sender and lead to the record it announces.
//
// The two are BORN in different places, and that is the leaf's whole point
// (HIL-768). A toast of one's own action is pushed here and lives and dies here:
// the frontend decides what an outcome deserves (wire-protocol.md). A toast
// addressed to the SESSION is raised on the backend and arrives whole through
// {@link HilosToastStore.syncSession}; the tabs of one browser have to agree
// about it, so this store neither invents one nor takes one away by itself. What
// it does instead is answer — closed here, counted down here, being read here —
// and wait for the next frame.
//
// Nothing here is durable — a toast that expires is gone, so anything a user may
// need later belongs in the feature's own record, not in this store.
//
// This module owns the POLICY of the stack — how long a notice lives, when the
// countdown runs, what happens when the corner is full, when two pushes are one
// notice. The card itself belongs to the three hosts. The split is what keeps
// the policy in one copy: the hosts report raw measurements (the window height,
// each rendered card's height) and the arithmetic happens here, so "a third of
// the screen" cannot drift apart between Vue, React and Angular.
//
// Nothing here touches the DOM or knows about the connection.

import {
  createSignal,
  type ReadonlySignal,
  type WritableSignal,
} from './signal.js'

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
  /**
   * The server's own name for this card, which is what an answer about it names;
   * `null` for a toast of one's own action, which the server never heard of.
   */
  readonly sessionKey: string | null
}

/**
 * One card of the stack the server says this session is being shown, as it
 * arrives on `hilos_session_toasts`.
 *
 * The frame carries the LIST rather than a change, so this is also the whole of
 * what {@link HilosToastStore.syncSession} is given: what is not in it is not
 * owed any more.
 */
export interface HilosSessionToast {
  /** The server's name for the card; what an answer about it names. */
  readonly key: string
  /** The line shown to the user. */
  readonly message: string
  /** How it reads. */
  readonly severity: HilosToastSeverity
  /** Which subsystem sent it, shown as the card's signature. */
  readonly source: string
  /** The route the whole card leads to. */
  readonly destination: string
  /** How many times the server has counted this exact card. */
  readonly repeats: number
}

/**
 * Why a host is holding the countdown of the stack.
 *
 * The store freezes on all three alike — a card must not burn down unseen — but
 * only the two READING holds are reported to the server: they are a person
 * looking at the stack, and a neighbouring tab's finished countdown waits for
 * them. A hidden tab is nobody looking, and treating it as a reader would make
 * every toast immortal in the window actually in use (HIL-768).
 */
export type HilosToastHoldReason = 'cursor' | 'focus' | 'tab'

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
 * Severity and nothing else: a push is always the connection's own action. The
 * session scope was a member of this union until HIL-768 and is gone from it,
 * because the truth about a session's stack lives in RT — a second door into it
 * on the client would be state the server does not know about, in the one place
 * the leaf promises the tabs agree. The lifetime is not here either: time belongs
 * to the store.
 */
export interface HilosToastOptions {
  /** How the notice reads; defaults to `info`. */
  severity?: HilosToastSeverity
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
  /**
   * Take one hold on the countdown of the whole stack.
   *
   * @param reason What is holding it — see {@link HilosToastHoldReason}.
   */
  hold(reason: HilosToastHoldReason): void
  /**
   * Give one hold back; the last one resumes the countdown from what is left.
   *
   * @param reason The reason the matching {@link hold} named.
   */
  release(reason: HilosToastHoldReason): void
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
   * A card of the SESSION is not removed here — its key joins
   * {@link dismissedSessionKeys} and the card stays until the server's next frame
   * takes it off every tab at once. The cost is named and accepted: on a dead
   * connection the card stands under the finger until the answer arrives, whereas
   * hiding it locally would bring it back on the very next frame (HIL-768).
   *
   * @param id The id {@link push} returned.
   */
  dismiss(id: number): void
  /** Remove every notice, drop the queue and its counts, and cancel the timers. */
  clear(): void
  /** Attach a host to the stack; see {@link HilosToastViewer}. */
  attach(): HilosToastViewer
  /**
   * Bring the session's cards in line with the whole list the server sent.
   *
   * New keys are admitted with a full countdown of their own — the countdown is
   * deliberately not synchronized between tabs, so a card only just seen here is
   * given the whole time to read it. A key whose count moved restarts its
   * countdown, because the server has just said the same thing again. A key that
   * is not in the list is taken away wherever it stood, screen or waiting queue.
   *
   * @param toasts The whole stack the session is being shown.
   */
  syncSession(toasts: readonly HilosSessionToast[]): void
  /** Keys of the session's cards a person closed here, awaiting the server's word. */
  readonly dismissedSessionKeys: ReadonlySignal<readonly string[]>
  /** Keys of the session's cards whose countdown burned down in THIS tab. */
  readonly expiredSessionKeys: ReadonlySignal<readonly string[]>
  /** Whether the stack is being read here — a cursor over it or the focus inside it. */
  readonly reading: ReadonlySignal<boolean>
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
  // The three answers this tab owes the server about the session's cards. The two
  // key sets are what it has said and not yet seen taken back; they are sets
  // rather than events so a binder that reconnected can send them again without
  // the store having to remember who heard what.
  const dismissedSessionKeys = createSignal<readonly string[]>([])
  const expiredSessionKeys = createSignal<readonly string[]>([])
  const reading = createSignal(false)
  const dismissed = new Set<string>()
  const expired = new Set<string>()
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
  // Of the holds above, the ones a person is behind. The countdown freezes on all
  // of them alike; only these are reported, and only these veto a neighbour's
  // finished countdown.
  let readingHolds = 0
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
   * Publish one of the answer sets, and only when it moved.
   *
   * Sorted so that the same set is always the same array: the signal fires on
   * `Object.is`, and a binder woken by a reordering would send an answer twice.
   *
   * @param signal The signal carrying that set.
   * @param keys The set as it now stands.
   */
  function publishKeys(
    signal: WritableSignal<readonly string[]>,
    keys: ReadonlySet<string>,
  ): void {
    const next = [...keys].sort()
    const current = signal.get()
    if (
      current.length === next.length &&
      current.every((key, index) => key === next[index])
    ) {
      return
    }
    signal.set(next)
  }

  /** Publish whether somebody is reading the stack here. */
  function publishReading(): void {
    reading.set(readingHolds > 0)
  }

  /**
   * Forget what this tab had said about one card, because it is not that card any
   * more: it was taken away, or the server counted it again and every tab starts
   * counting afresh.
   *
   * @param key The card's server-side key.
   */
  function forgetAnswers(key: string): void {
    dismissed.delete(key)
    expired.delete(key)
    publishKeys(dismissedSessionKeys, dismissed)
    publishKeys(expiredSessionKeys, expired)
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
      expire(id)
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
   * A countdown has burned down. A card of one's own action goes; a card of the
   * SESSION stays and the server is told instead.
   *
   * Hiding the session's card here would be the very desynchronization the leaf
   * was written against: the countdown is each tab's own, so the first tab to
   * finish counting would empty its corner while the neighbour still shows the
   * card. What leaves the screen leaves it on the frame that follows, in every
   * tab at once — or does not leave at all, because somebody is reading.
   *
   * @param id The toast's id.
   */
  function expire(id: number): void {
    const toast = onScreen(id)
    if (toast === undefined || toast.sessionKey === null) {
      freeSlot(id)

      return
    }
    // The timer has fired, so the entry is spent: dropping it stops retime() from
    // arming a countdown this tab has already reported.
    pending.delete(id)
    expired.add(toast.sessionKey)
    publishKeys(expiredSessionKeys, expired)
  }

  /**
   * Give one card its full time back, because it has just been said again.
   *
   * A card with no countdown is left alone unless it is one that BURNED DOWN and
   * the server kept it up: an error has no countdown by design, and one the host
   * has not measured yet gets its first when it reports its height.
   *
   * @param id The toast's id.
   */
  function restartCountdown(id: number): void {
    const drawn = onScreen(id)
    if (drawn === undefined) {
      return
    }
    const entry = pending.get(id)
    if (entry === undefined) {
      if (drawn.measured) {
        startCountdown(drawn)
      }

      return
    }
    if (entry.timer !== null) {
      clearTimeout(entry.timer)
      entry.timer = null
    }
    entry.remainingMs = TOAST_TTL_MS
    if (ticking()) {
      arm(id, entry)
    }
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
    restartCountdown(twin.id)
  }

  /**
   * The card standing for one server-side key, on screen or waiting.
   *
   * @param key The card's server-side key.
   */
  function bySessionKey(key: string): HilosToast | undefined {
    const same = (other: HilosToast): boolean => other.sessionKey === key

    return toasts.get().find(same) ?? queued.find(same)
  }

  /**
   * Put one newly arrived card of the session up, with a full countdown of its
   * own once a host has measured it.
   *
   * @param arrival The card as the server sent it.
   */
  function admitSession(arrival: HilosSessionToast): void {
    sequence += 1
    const card: HilosToast = {
      id: sequence,
      message: arrival.message,
      severity: arrival.severity,
      scope: 'session',
      source: arrival.source,
      destination: arrival.destination,
      repeats: arrival.repeats,
      measured: false,
      sessionKey: arrival.key,
    }
    toasts.set([...toasts.get(), card])
    if (!anyHeightReported && !withinBudget()) {
      overflowed(card)
    }
  }

  /**
   * Take the server's count for a card already up, and start its time over.
   *
   * The count is TAKEN rather than incremented: the server is the one counting,
   * and a tab that had not been listening for a while would otherwise show a
   * number of its own.
   *
   * @param card The card as it stands here.
   * @param repeats The count the server now gives it.
   */
  function recountSession(card: HilosToast, repeats: number): void {
    if (onScreen(card.id) === undefined) {
      queued = queued.map((other) =>
        other.id === card.id ? { ...other, repeats } : other,
      )

      return
    }
    rewrite(card.id, { repeats })
    restartCountdown(card.id)
  }

  return {
    toasts,
    overflow,
    dismissedSessionKeys,
    expiredSessionKeys,
    reading,
    push(message, options = {}) {
      const candidate: HilosToast = {
        id: sequence + 1,
        message,
        severity: options.severity ?? 'info',
        scope: 'connection',
        source: null,
        destination: null,
        repeats: 1,
        measured: false,
        sessionKey: null,
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
      const toast = onScreen(id)
      if (toast === undefined) {
        return
      }
      if (toast.sessionKey !== null) {
        // Not optimistic, on purpose: the card leaves every tab of the session on
        // one frame or leaves none of them.
        dismissed.add(toast.sessionKey)
        publishKeys(dismissedSessionKeys, dismissed)

        return
      }
      freeSlot(id)
    },
    syncSession(arrivals) {
      const arrived = new Set(arrivals.map((arrival) => arrival.key))
      for (const card of [...toasts.get(), ...queued]) {
        const key = card.sessionKey
        if (key === null || arrived.has(key)) {
          continue
        }
        forgetAnswers(key)
        if (onScreen(card.id) === undefined) {
          queued = queued.filter((other) => other.id !== card.id)

          continue
        }
        freeSlot(card.id)
      }
      for (const arrival of arrivals) {
        const known = bySessionKey(arrival.key)
        if (known === undefined) {
          admitSession(arrival)

          continue
        }
        if (known.repeats !== arrival.repeats) {
          // Said again, so every tab starts counting afresh - including this one,
          // whose report of the previous showing is void.
          forgetAnswers(arrival.key)
          recountSession(known, arrival.repeats)
        }
      }
      publishOverflow()
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
      // The answers go with the cards they were about: nothing is left to answer
      // for, and a key kept here would be reported about a card nobody can see.
      dismissed.clear()
      expired.clear()
      publishKeys(dismissedSessionKeys, dismissed)
      publishKeys(expiredSessionKeys, expired)
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
      // Counted apart because they mean different things: both freeze the
      // countdown, only the reading one is a person and only it is reported.
      let heldReading = 0
      let heldTab = 0
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
        hold(reason) {
          if (!attached) {
            return
          }
          if (reason === 'tab') {
            heldTab += 1
          } else {
            heldReading += 1
            readingHolds += 1
            publishReading()
          }
          holds += 1
          retime()
        },
        release(reason) {
          if (!attached) {
            return
          }
          if (reason === 'tab') {
            if (heldTab === 0) {
              return
            }
            heldTab -= 1
          } else {
            if (heldReading === 0) {
              return
            }
            heldReading -= 1
            readingHolds -= 1
            publishReading()
          }
          holds -= 1
          retime()
        },
        detach() {
          if (!attached) {
            return
          }
          attached = false
          holds -= heldReading + heldTab
          readingHolds -= heldReading
          heldReading = 0
          heldTab = 0
          viewers -= 1
          publishReading()
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
