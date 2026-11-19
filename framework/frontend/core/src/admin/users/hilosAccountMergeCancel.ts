import {
  ActionError,
  type ActionHandle,
} from '../../connection/actionLifecycle.js'
import { hilosAdminAccess } from '../../session/adminAccess.js'
import {
  computedSignal,
  createSignal,
  type ReadonlySignal,
  subscribeSignal,
} from '../../state/signal.js'
import { hilosToasts } from '../../state/toasts.js'
import type { HilosAccountMergeWindow } from './hilosAccountMergeWindow.js'
import type { HilosAccountMerge, HilosUsersContext } from './hilosUsers.js'

/** Words belonging to a requested merge cancellation and its live outcome. */
export const HILOS_ACCOUNT_MERGE_CANCEL_COPY = {
  cancel: 'Cancel merge',
  canceled: 'Merge canceled',
} as const

/** Cancellation intent correlated with the observation window's live operation. */
export interface HilosAccountMergeCancel {
  readonly available: ReadonlySignal<boolean>
  readonly pending: ReadonlySignal<boolean>
  /** Send one tracked request, or nothing when cancellation is unavailable. */
  cancel(): ActionHandle | null
  /** Forget the intent and ignore late replies without cancelling server work. */
  dispose(): void
}

/**
 * Keep cancellation intent local to this window. An ack only answers acceptance;
 * the existing standing projection supplies the outcome, without polling.
 *
 * Vue's user-card window binds this API; React and Angular callers follow in
 * HIL-2010 and HIL-2011.
 *
 * @param context The project context enabling browser merge actions.
 * @param window The observation window bound to the current merge.
 * @param merge The tracked merge actions whose handle the view renders.
 */
export function createHilosAccountMergeCancel(
  context: HilosUsersContext,
  window: HilosAccountMergeWindow,
  merge: HilosAccountMerge,
): HilosAccountMergeCancel {
  const pending = createSignal(false)
  const accepted = createSignal<number | null>(null)
  const refused = createSignal<number | null>(null)
  const disposed = createSignal(false)
  let armed: number | null = null
  let round = 0

  const available = computedSignal(() => {
    const view = window.view.get()
    if (
      disposed.get() ||
      context.accountMerge !== true ||
      hilosAdminAccess.get() !== 'full' ||
      view?.kind !== 'progress'
    )
      return false
    const progress = view.progress
    return (
      progress.operationId !== null &&
      (progress.state === 'preparing' || progress.state === 'wait') &&
      (progress.stage === 'preparing' || progress.stage === 'go') &&
      progress.given === false &&
      progress.outcome === null &&
      !pending.get() &&
      refused.get() !== progress.operationId &&
      accepted.get() !== progress.operationId
    )
  })

  function reset(): void {
    round += 1
    armed = null
    accepted.set(null)
    refused.set(null)
    pending.set(false)
  }

  const observation = computedSignal(() => ({
    view: window.view.get(),
    access: hilosAdminAccess.get(),
  }))
  const stop = subscribeSignal(observation, ({ view, access }) => {
    if (access !== 'full' || view?.kind !== 'progress') {
      reset()
      return
    }
    const progress = view.progress
    if (
      (armed !== null && armed !== progress.operationId) ||
      (accepted.get() !== null && accepted.get() !== progress.operationId) ||
      (refused.get() !== null && refused.get() !== progress.operationId)
    ) {
      reset()
      return
    }
    if (progress.stage === 'cancelling') accepted.set(null)
    if (armed === null) return
    if (progress.outcome === 'not_started') {
      armed = null
      if (progress.refusal === null) {
        window.close()
        hilosToasts.push(HILOS_ACCOUNT_MERGE_CANCEL_COPY.canceled, {
          severity: 'success',
        })
      }
    } else if (progress.outcome === 'completed') {
      armed = null
    }
  })

  return {
    available,
    pending,
    cancel() {
      if (!available.get()) return null
      const view = window.view.get()
      if (view?.kind !== 'progress' || view.progress.operationId === null)
        return null
      const operationId = view.progress.operationId
      const started = ++round
      armed = operationId
      pending.set(true)
      const handle = merge.cancel(operationId)
      void handle.done.then(
        () => {
          if (started !== round) return
          if (armed === operationId) {
            const current = window.view.get()
            if (
              current?.kind === 'progress' &&
              current.progress.stage !== 'cancelling'
            )
              accepted.set(operationId)
          }
          pending.set(false)
        },
        (caught: unknown) => {
          if (started !== round) return
          if (caught instanceof ActionError && caught.outcome === 'fail') {
            refused.set(operationId)
            armed = null
          }
          pending.set(false)
        },
      )
      return handle
    },
    dispose() {
      stop()
      disposed.set(true)
      reset()
    },
  }
}
