import { z, type ZodType } from 'zod'
import {
  ActionError,
  type ActionLifecycle,
} from '../connection/actionLifecycle.js'
import { type HilosConnection } from '../connection/HilosConnection.js'
import {
  computedSignal,
  createSignal,
  subscribeSignal,
  type ReadonlySignal,
} from '../state/signal.js'

export const STATUS_SUBSCRIBE_ACTION = 'status_subscribe'
export const STATUS_SUBSCRIPTION_CONFIRM_ACTION = 'status_subscription_confirm'
export const STATUS_UNSUBSCRIBE_PREVIEW_ACTION = 'status_unsubscribe_preview'
export const STATUS_UNSUBSCRIBE_ACTION = 'status_unsubscribe'

const subscribeReply = z.strictObject({ status: z.literal('check_inbox') })
const confirmReply = z.strictObject({ status: z.literal('subscribed') })
const previewReply = z.strictObject({ maskedEmail: z.string().min(1) })
const unsubscribeReply = z.strictObject({ status: z.literal('unsubscribed') })
const CONFIRM_FRAGMENT = 'status-confirm'
const UNSUBSCRIBE_FRAGMENT = 'status-unsubscribe'
const TOKEN_PATTERN = /^[a-f0-9]{64}$/i

export const STATUS_SUBSCRIPTION_COPY = {
  subscribe: 'Subscribe to updates',
  checkInbox: 'Check your inbox — we sent a link to confirm the address.',
  subscribed:
    'You are subscribed. Letters come on outages and scheduled maintenance.',
  unsubscribed: 'You are unsubscribed. No more letters will come.',
  keep: 'Keep',
  unsubscribe: 'Unsubscribe',
  waiting: 'Waiting for a live connection to continue.',
  // TODO(i18n): Local malformed-link and transport outcomes have no server sentence.
  invalidLink: 'This subscription link is invalid.',
  unconfirmed:
    'The result is unconfirmed. The operation may have completed. Check before submitting again.',
  localFailure: 'The action could not be completed. Please try again.',
} as const

export type StatusSubscriptionOperation =
  'subscribe' | 'confirm' | 'unsubscribe'
export type StatusSubscriptionStep =
  'idle' | 'waiting' | 'pending' | 'ready' | 'success' | 'error'
export interface StatusSubscriptionDraft {
  readonly email: string
}

/** The mounted browser address and its lifetime; no secret enters a router. */
export interface StatusSubscriptionAddress {
  readonly location: Pick<Location, 'hash' | 'pathname' | 'search'>
  readonly history: Pick<History, 'state' | 'replaceState'>
  /** Observe address changes until the returned cleanup is called. */
  onChange(listener: () => void): () => void
}
export interface StatusSubscriptionContext {
  readonly connection: HilosConnection
  readonly actions: ActionLifecycle
}
export interface StatusSubscriptionOptions {
  readonly address?: StatusSubscriptionAddress
}

/** One public mount owns its dialog, private token and tracked operation. */
export interface StatusSubscription {
  readonly operation: ReadonlySignal<StatusSubscriptionOperation | null>
  readonly step: ReadonlySignal<StatusSubscriptionStep>
  readonly dialog: ReadonlySignal<boolean>
  readonly draft: ReadonlySignal<StatusSubscriptionDraft>
  readonly usable: ReadonlySignal<boolean>
  readonly busy: ReadonlySignal<boolean>
  readonly loading: ReadonlySignal<boolean>
  readonly error: ReadonlySignal<string | null>
  readonly success: ReadonlySignal<string | null>
  readonly maskedEmail: ReadonlySignal<string | null>
  readonly unconfirmed: ReadonlySignal<boolean>
  readonly canSubmit: ReadonlySignal<boolean>
  readonly requestId: ReadonlySignal<string | null>
  start(): void
  open(): void
  setDraft(draft: StatusSubscriptionDraft): void
  close(): void
  submit(): Promise<boolean>
  dispose(): void
}

/** Never opens a socket or gates the independent public file reader. */
export function createStatusSubscription(
  context: StatusSubscriptionContext,
  options: StatusSubscriptionOptions = {},
): StatusSubscription {
  const operation = createSignal<StatusSubscriptionOperation | null>(null)
  const step = createSignal<StatusSubscriptionStep>('idle')
  const dialog = createSignal(false)
  const draft = createSignal<StatusSubscriptionDraft>({ email: '' })
  const usable = createSignal(false)
  const busy = createSignal(false)
  const loading = createSignal(false)
  const error = createSignal<string | null>(null)
  const success = createSignal<string | null>(null)
  const maskedEmail = createSignal<string | null>(null)
  const unconfirmed = createSignal(false)
  const requestId = createSignal<string | null>(null)
  const active = createSignal(false)
  // Hilos::$rt cannot own a mounted browser's secret or modal lifetime.
  let token: string | null = null
  let tokenDispatched = false
  let disposed = false
  let round = 0
  let offLoading: (() => void) | undefined
  const teardown: Array<() => void> = []
  const isUsable = (): boolean =>
    context.connection.state === 'connected' &&
    !context.connection.protectedMode.active
  const canSubmit = computedSignal(
    () =>
      active.get() &&
      dialog.get() &&
      usable.get() &&
      !busy.get() &&
      success.get() === null &&
      (operation.get() === 'subscribe'
        ? draft.get().email.trim().length > 0
        : operation.get() === 'unsubscribe' && maskedEmail.get() !== null),
  )
  const reset = (): void => {
    round++
    offLoading?.()
    offLoading = undefined
    token = null
    tokenDispatched = false
    operation.set(null)
    step.set('idle')
    dialog.set(false)
    draft.set({ email: '' })
    busy.set(false)
    loading.set(false)
    error.set(null)
    success.set(null)
    maskedEmail.set(null)
    unconfirmed.set(false)
    requestId.set(null)
  }
  const run = async <T>(
    action: string,
    payload: unknown,
    schema: ZodType<T>,
    accept: (reply: T) => void,
  ): Promise<boolean> => {
    const started = ++round
    busy.set(true)
    step.set('pending')
    error.set(null)
    unconfirmed.set(false)
    const handle = context.actions.dispatch(action, payload, {
      replySchema: schema,
    })
    requestId.set(handle.requestId)
    loading.set(handle.loading.get())
    offLoading = subscribeSignal(handle.loading, (value) => loading.set(value))
    try {
      const result = await handle.done
      if (disposed || started !== round) return false
      accept(result.reply!)
      return true
    } catch (caught) {
      if (disposed || started !== round) return false
      const failure = caught instanceof ActionError ? caught : null
      const uncertain = failure !== null && failure.outcome !== 'fail'
      unconfirmed.set(uncertain)
      error.set(
        failure?.outcome === 'fail'
          ? failure.message
          : uncertain
            ? STATUS_SUBSCRIPTION_COPY.unconfirmed
            : STATUS_SUBSCRIPTION_COPY.localFailure,
      )
      step.set('error')
      return false
    } finally {
      if (!disposed && started === round) {
        offLoading?.()
        offLoading = undefined
        busy.set(false)
        loading.set(false)
      }
    }
  }
  const finish = (message: string): void => {
    token = null
    draft.set({ email: '' })
    dialog.set(false)
    success.set(message)
    step.set('success')
  }
  const continueToken = (): void => {
    if (disposed || !active.get()) return
    usable.set(isUsable())
    if (token === null || tokenDispatched) return
    if (!usable.get()) {
      step.set('waiting')
      return
    }
    tokenDispatched = true
    if (operation.get() === 'confirm') {
      const captured = token
      token = null
      void run(
        STATUS_SUBSCRIPTION_CONFIRM_ACTION,
        { token: captured },
        confirmReply,
        () => finish(STATUS_SUBSCRIPTION_COPY.subscribed),
      )
    } else {
      void run(
        STATUS_UNSUBSCRIBE_PREVIEW_ACTION,
        { token },
        previewReply,
        (reply) => {
          maskedEmail.set(reply.maskedEmail)
          step.set('ready')
        },
      )
    }
  }
  return {
    operation,
    step,
    dialog,
    draft,
    usable,
    busy,
    loading,
    error,
    success,
    maskedEmail,
    unconfirmed,
    canSubmit,
    requestId,
    start() {
      if (disposed || active.get()) return
      active.set(true)
      const address =
        options.address ??
        (typeof window === 'undefined'
          ? undefined
          : {
              location: window.location,
              history: window.history,
              onChange(listener: () => void) {
                window.addEventListener('hashchange', listener)
                window.addEventListener('popstate', listener)
                return () => {
                  window.removeEventListener('hashchange', listener)
                  window.removeEventListener('popstate', listener)
                }
              },
            })
      teardown.push(
        context.connection.on('state', continueToken),
        context.connection.on('protectedMode', continueToken),
      )
      if (address !== undefined) {
        const pathname = address.location.pathname
        const consumeAddress = (): void => {
          if (
            disposed ||
            !active.get() ||
            address.location.pathname !== pathname
          )
            return
          const fragment = new URLSearchParams(address.location.hash.slice(1))
          const confirms = fragment.getAll(CONFIRM_FRAGMENT)
          const unsubscribes = fragment.getAll(UNSUBSCRIBE_FRAGMENT)
          if (confirms.length + unsubscribes.length === 0) return
          fragment.delete(CONFIRM_FRAGMENT)
          fragment.delete(UNSUBSCRIBE_FRAGMENT)
          const rest = fragment.toString()
          address.history.replaceState(
            address.history.state,
            '',
            address.location.pathname +
              address.location.search +
              (rest === '' ? '' : `#${rest}`),
          )
          // Scrub every arrival, but keep the current request or retained link.
          // A second capability is not queued behind this mounted operation.
          if (busy.get() || token !== null) return
          reset()
          const value = confirms[0] ?? unsubscribes[0] ?? ''
          if (
            confirms.length + unsubscribes.length !== 1 ||
            !TOKEN_PATTERN.test(value)
          ) {
            // A broken link has no window: the page's slot says so.
            error.set(STATUS_SUBSCRIPTION_COPY.invalidLink)
            step.set('error')
          } else {
            token = value
            const confirming = confirms.length === 1
            operation.set(confirming ? 'confirm' : 'unsubscribe')
            // Confirming asks nothing of the person, so it waits and answers in
            // the page's slot; only unsubscribing opens its window to ask.
            dialog.set(!confirming)
          }
          continueToken()
        }
        teardown.push(address.onChange(consumeAddress))
        consumeAddress()
      }
      continueToken()
    },
    open() {
      if (!active.get() || disposed || busy.get() || !isUsable()) return
      reset()
      operation.set('subscribe')
      dialog.set(true)
      step.set('ready')
    },
    setDraft(value) {
      if (
        !disposed &&
        operation.get() === 'subscribe' &&
        dialog.get() &&
        !busy.get()
      )
        draft.set({ email: value.email })
    },
    close() {
      if (disposed || busy.get()) return
      // Hiding the waiting screen must not discard an unspent link.
      if (token !== null && !tokenDispatched) dialog.set(false)
      else reset()
    },
    async submit() {
      if (!canSubmit.get() || !isUsable()) return false
      if (operation.get() === 'subscribe')
        return run(
          STATUS_SUBSCRIBE_ACTION,
          { email: draft.get().email.trim().toLowerCase() },
          subscribeReply,
          () => finish(STATUS_SUBSCRIPTION_COPY.checkInbox),
        )
      if (token === null) return false
      return run(STATUS_UNSUBSCRIBE_ACTION, { token }, unsubscribeReply, () =>
        finish(STATUS_SUBSCRIPTION_COPY.unsubscribed),
      )
    },
    dispose() {
      if (disposed) return
      disposed = true
      active.set(false)
      for (const off of teardown.splice(0)) off()
      reset()
      usable.set(false)
    },
  }
}
