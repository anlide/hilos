// The action lifecycle (wire-protocol.md, step 7.4): the requestId-correlated
// reply machine over a connection. `dispatch` mints a per-connection requestId,
// sends the tracked action, and returns a handle whose `loading` signal turns
// on only after a ~0.3s deferral (fast ops never flash a spinner) and whose
// `done` promise settles on the action's own `::success` / `::fail` — or a ~30s
// timeout. A reply that arrives after its action timed out (the orphan) is
// reconciled to a late-reply callback rather than silently dropped, and a
// connection drop fails every in-flight action so a modal never hangs forever.
//
// The error half ships separately as ActionErrorStore (action-name keyed, no
// correlation); this is the full request-correlated lifecycle the modals'
// authoritative-backend close depends on.

import { type ZodType } from 'zod'
import {
  type ActionErrorSignal,
  type ActionSuccessSignal,
} from '../protocol/parseSignal.js'
import {
  createSignal,
  type ReadonlySignal,
  type WritableSignal,
} from '../state/signal.js'
import { type ConnectionState } from './HilosConnection.js'

/** Default deferral before a tracked action shows loading. */
const DEFAULT_DEFERRED_LOADING_MS = 300

/** Default time a tracked action waits for its reply before timing out. */
const DEFAULT_TIMEOUT_MS = 30000

/** Why a dispatched action settled, other than a plain success. */
export type ActionFailureOutcome =
  | 'fail'
  | 'timeout'
  | 'disconnected'
  | 'invalid-reply'

/**
 * A failure of a dispatched action: the action name, the outcome, and the reason.
 *
 * `errorType` and `errorDetail` are the two fields only an admin surface is sent
 * (see `actionErrorSignalDataSchema`): the class name of the failure the generic
 * `message` stands for, and that failure's own text. Both are undefined on every
 * other surface, and on an admin surface too whenever the backend held nothing
 * back — so having them is exactly the sign that it did.
 */
export class ActionError extends Error {
  constructor(
    readonly action: string,
    readonly outcome: ActionFailureOutcome,
    reason: string,
    readonly errorType?: string,
    readonly errorDetail?: string,
  ) {
    super(reason)
    this.name = 'ActionError'
  }
}

/**
 * What a tracked action resolves with on success: the optional backend-authored
 * toast `message` and the optional domain `reply` — parsed against the caller's
 * schema when one was passed to {@link ActionLifecycle.dispatch}, else the raw
 * `unknown` reply value. Either field is absent when the action did not carry it.
 */
export interface ActionResult<T = unknown> {
  /** Backend-authored success sentence for a toast, or undefined when the action stays silent. */
  readonly message?: string
  /** Domain reply the action returned, or undefined when it answered with nothing. */
  readonly reply?: T
}

/** The live handle {@link ActionLifecycle.dispatch} returns for one tracked action. */
export interface ActionHandle<T = unknown> {
  /** The client-minted id correlating this action with its reply. */
  readonly requestId: string
  /** Loading: stays false until the deferral elapses while the action is still pending. */
  readonly loading: ReadonlySignal<boolean>
  /**
   * Resolves on `::success` with the action's {@link ActionResult} (the success
   * message and/or domain reply, each absent when not carried); rejects with an
   * {@link ActionError} on fail, timeout, disconnect, or a reply that failed the
   * caller's schema (`invalid-reply`).
   */
  readonly done: Promise<ActionResult<T>>
}

/** The connection events {@link ActionLifecycle} subscribes to. */
interface ActionLifecycleEventMap {
  actionSuccess: ActionSuccessSignal
  actionError: ActionErrorSignal
  state: ConnectionState
}

/**
 * The slice of the connection {@link ActionLifecycle} drives and observes — the
 * generic `on` mirrors `HilosConnection` so the real connection is a structural
 * fit while a test can pass a lightweight fake.
 */
export interface ActionLifecycleSource {
  /**
   * Send a tracked action frame, returning false when not connected.
   *
   * @param action The action name.
   * @param data The action payload.
   * @param requestId The client-minted correlation id.
   */
  sendAction(action: string, data: unknown, requestId?: string): boolean
  /**
   * Subscribe to one connection event; returns an unsubscribe.
   *
   * @param event The event name.
   * @param listener The event listener.
   */
  on<E extends keyof ActionLifecycleEventMap>(
    event: E,
    listener: (payload: ActionLifecycleEventMap[E]) => void,
  ): () => void
}

/** One in-flight tracked action awaiting its reply. */
interface PendingAction {
  readonly action: string
  readonly loading: WritableSignal<boolean>
  readonly resolve: (result: ActionResult) => void
  readonly reject: (error: ActionError) => void
  readonly deferredTimer: ReturnType<typeof setTimeout>
  readonly timeoutTimer: ReturnType<typeof setTimeout>
  /** Caller's optional reply schema; the success reply is parsed against it when set. */
  readonly replySchema: ZodType | undefined
}

/** Tuning + the late-reply hook for {@link ActionLifecycle}. */
export interface ActionLifecycleOptions {
  /** Deferral before loading shows; default 300ms. */
  deferredLoadingMs?: number
  /** Reply timeout; default 30000ms. */
  timeoutMs?: number
  /**
   * Notified when a reply arrives after its action already timed out — the
   * orphan reconciliation hook (a toast, at the project's discretion).
   *
   * @param action The action name the late reply answers.
   * @param outcome Whether the late reply was a success or a failure.
   */
  onLateReply?: (action: string, outcome: 'success' | 'fail') => void
}

/**
 * The per-connection action reply lifecycle: tracked dispatch, deferred
 * loading, reply correlation, timeout, and orphan reconciliation.
 */
export class ActionLifecycle {
  private sequence = 0
  private readonly pending = new Map<string, PendingAction>()
  /** requestId → action for actions that timed out, awaiting a possible late reply. */
  private readonly orphans = new Map<string, string>()
  private readonly deferredLoadingMs: number
  private readonly timeoutMs: number
  private readonly onLateReply:
    | ((action: string, outcome: 'success' | 'fail') => void)
    | undefined

  /**
   * Bind the lifecycle to a connection: correlate its action replies and fail
   * in-flight actions when the transport drops.
   *
   * @param source The connection the lifecycle dispatches over and observes.
   * @param options Deferral / timeout tuning and the late-reply hook.
   */
  constructor(
    private readonly source: ActionLifecycleSource,
    options: ActionLifecycleOptions = {},
  ) {
    this.deferredLoadingMs =
      options.deferredLoadingMs ?? DEFAULT_DEFERRED_LOADING_MS
    this.timeoutMs = options.timeoutMs ?? DEFAULT_TIMEOUT_MS
    this.onLateReply = options.onLateReply
    source.on('actionSuccess', (signal) =>
      this.onReply(
        signal.requestId,
        signal.action,
        'success',
        signal.message,
        signal.reply,
      ),
    )
    source.on('actionError', (signal) =>
      this.onReply(
        signal.requestId,
        signal.action,
        'fail',
        signal.reason,
        undefined,
        signal.errorType,
        signal.errorDetail,
      ),
    )
    source.on('state', (state) => {
      if (state === 'reconnecting' || state === 'disconnected') {
        this.failAll('The connection dropped before the action completed.')
      }
    })
  }

  /**
   * Dispatch a tracked action and return its handle. Loading shows after the
   * deferral; `done` settles on the action's reply or the timeout.
   *
   * @param action The action name the subscribed page routes on.
   * @param data The action payload.
   * @param options Optional `replySchema` the success reply is validated against; when set, `done`'s `reply` is the parsed value and a reply that fails the schema rejects with `invalid-reply`.
   */
  dispatch<T = unknown>(
    action: string,
    data: unknown,
    options: { replySchema?: ZodType<T> } = {},
  ): ActionHandle<T> {
    const requestId = String(++this.sequence)
    const loading = createSignal(false)
    let resolve!: (result: ActionResult<T>) => void
    let reject!: (error: ActionError) => void
    const done = new Promise<ActionResult<T>>((resolveFn, rejectFn) => {
      resolve = resolveFn
      reject = rejectFn
    })

    if (!this.source.sendAction(action, data, requestId)) {
      reject(new ActionError(action, 'disconnected', 'Not connected.'))

      return { requestId, loading, done }
    }

    const deferredTimer = setTimeout(() => {
      loading.set(true)
    }, this.deferredLoadingMs)
    const timeoutTimer = setTimeout(() => {
      this.onTimeout(requestId)
    }, this.timeoutMs)
    this.pending.set(requestId, {
      action,
      loading,
      resolve: resolve as (result: ActionResult) => void,
      reject,
      deferredTimer,
      timeoutTimer,
      replySchema: options.replySchema,
    })

    return { requestId, loading, done }
  }

  /**
   * Settle the pending action a reply correlates to, or reconcile a late reply
   * to a timed-out orphan.
   *
   * @param requestId The reply's correlation id (undefined for a legacy reply).
   * @param action The action name the reply answers.
   * @param outcome Whether the reply was a success or a failure.
   * @param detail The success message on a success reply, or the failure reason on a fail reply; undefined when absent.
   * @param reply The domain reply on a success reply, or undefined; parsed against the pending action's schema when one was set.
   * @param errorType The failure's class name on an admin surface, or undefined.
   * @param errorDetail The failure's own message on an admin surface, or undefined.
   */
  private onReply(
    requestId: string | undefined,
    action: string,
    outcome: 'success' | 'fail',
    detail?: string,
    reply?: unknown,
    errorType?: string,
    errorDetail?: string,
  ): void {
    if (requestId === undefined) {
      return
    }
    const pending = this.pending.get(requestId)
    if (pending === undefined) {
      if (this.orphans.delete(requestId)) {
        this.onLateReply?.(action, outcome)
      }

      return
    }
    this.settle(requestId, pending)
    if (outcome !== 'success') {
      pending.reject(
        new ActionError(
          action,
          'fail',
          detail ?? 'The action failed.',
          errorType,
          errorDetail,
        ),
      )

      return
    }
    if (pending.replySchema === undefined) {
      pending.resolve({ message: detail, reply })

      return
    }
    const parsed = pending.replySchema.safeParse(reply)
    if (!parsed.success) {
      pending.reject(
        new ActionError(action, 'invalid-reply', parsed.error.message),
      )

      return
    }
    pending.resolve({ message: detail, reply: parsed.data })
  }

  /**
   * Time out a still-pending action: reject it and remember it as an orphan so a
   * later reply reconciles rather than being dropped.
   *
   * @param requestId The timed-out action's correlation id.
   */
  private onTimeout(requestId: string): void {
    const pending = this.pending.get(requestId)
    if (pending === undefined) {
      return
    }
    this.settle(requestId, pending)
    this.orphans.set(requestId, pending.action)
    pending.reject(
      new ActionError(pending.action, 'timeout', 'The action timed out.'),
    )
  }

  /**
   * Fail every in-flight action with the same reason — the transport dropped, so
   * no reply can arrive.
   *
   * @param reason The human-readable failure reason.
   */
  private failAll(reason: string): void {
    for (const [requestId, pending] of [...this.pending]) {
      this.settle(requestId, pending)
      pending.reject(new ActionError(pending.action, 'disconnected', reason))
    }
    this.orphans.clear()
  }

  /**
   * Stop an action's timers, clear its loading, and drop it from the pending map.
   *
   * @param requestId The action's correlation id.
   * @param pending The pending record to settle.
   */
  private settle(requestId: string, pending: PendingAction): void {
    clearTimeout(pending.deferredTimer)
    clearTimeout(pending.timeoutTimer)
    pending.loading.set(false)
    this.pending.delete(requestId)
  }
}
