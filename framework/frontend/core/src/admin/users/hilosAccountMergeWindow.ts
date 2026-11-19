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
import type {
  HilosAccountMergeAccepted,
  HilosAccountMergeProgress,
  HilosPasswordFate,
  HilosSecondFactorFate,
} from './hilosUsers.js'
import {
  HILOS_USER_MERGE_COPY,
  hilosUserMergeSection,
  type HilosUserMergePartLine,
  type HilosUserMergeWaitLine,
} from './hilosUserMergeSection.js'
import { readableHilosAccountMergeProgress } from './hilosAccountMergePrivacy.js'

/** Words belonging to the observation window rather than the shared merge section. */
export const HILOS_ACCOUNT_MERGE_WINDOW_COPY = {
  view: 'View merge',
  close: 'Close',
  unavailable: 'Merge progress is unavailable.',
  unanswered:
    'The merge request got no answer. If the merge started, it appears here.',
  changed:
    'The merge operation has changed. Close this window and open it again.',
  survivor: 'Survivor',
  loser: 'Other account',
} as const

/** Current content of an open observation window; a closed window has no view. */
export type HilosAccountMergeWindowView =
  | {
      readonly kind: 'pending'
      readonly status: string
      readonly success: false
    }
  | {
      readonly kind: 'progress'
      /** Current safe source, including the recorded choices, never an edit draft. */
      readonly progress: HilosAccountMergeProgress
      readonly status: string
      /** The server's refusal words, preserved for an operation that did not start. */
      readonly reason: string | null
      readonly pendingParts: readonly HilosUserMergePartLine[]
      readonly waits: readonly HilosUserMergeWaitLine[]
      readonly success: boolean
    }
  | {
      readonly kind: 'unavailable' | 'changed'
      readonly message: string
    }

/** The submitted choices used to identify a merge whose start reply was lost. */
export interface HilosAccountMergeRequest {
  readonly survivorUserId: number
  readonly loserUserId: number
  readonly passwordFate: HilosPasswordFate | null
  readonly secondFactorFate: HilosSecondFactorFate | null
  readonly expectedSurvivorHasSecondFactor: boolean
  readonly expectedLoserHasSecondFactor: boolean
}

/** Whether the request was accepted, finished synchronously, refused or unanswered. */
export type HilosAccountMergeStartOutcome =
  'accepted' | 'legacy' | 'refused' | 'unanswered'

/** Local observation of one operation on one shown person's card. */
export interface HilosAccountMergeWindow {
  readonly opened: ReadonlySignal<boolean>
  readonly view: ReadonlySignal<HilosAccountMergeWindowView | null>
  readonly startNotice: ReadonlySignal<string | null>
  /**
   * Follow acceptance, or identify the exact new operation if its reply is lost.
   *
   * @param handle The tracked merge action already dispatched by the form.
   * @param request The account pair and choices submitted with that action.
   */
  track(
    handle: ActionHandle<HilosAccountMergeAccepted | undefined>,
    request: HilosAccountMergeRequest,
  ): Promise<HilosAccountMergeStartOutcome>
  /** Open from current data, returning false when the card or progress is unavailable. */
  open(): boolean
  /** Forget the operation and release local observers; this does not cancel a merge. */
  close(): void
  /** Close permanently, releasing observers even if the card continues updating. */
  dispose(): void
}

/**
 * Observe current merge progress without sending actions or opening transport
 * subscriptions. Only the card and operation binding survive between updates;
 * private source data is never copied into a draft or retained snapshot.
 *
 * SCAFFOLD: React and Angular callers follow in HIL-2010 and HIL-2011;
 * the Vue user page already observes through this window.
 *
 * @param merge The standing reader's current safe merge projection.
 * @param shownUserId The existing card row's id, absent while the row is unavailable.
 */
export function createHilosAccountMergeWindow(
  merge: ReadonlySignal<HilosAccountMergeProgress | null>,
  shownUserId: ReadonlySignal<number | undefined>,
): HilosAccountMergeWindow {
  const opened = createSignal(false)
  const startNotice = createSignal<string | null>(null)
  const binding = createSignal<{
    readonly userId: number
    readonly operationId: number | null
    readonly changed: boolean
    readonly fullFresh: boolean
    readonly pending: boolean
  } | null>(null)
  let stop: (() => void) | null = null
  let stopTracking: (() => void) | null = null
  let disposed = false

  const view = computedSignal<HilosAccountMergeWindowView | null>(() => {
    const bound = binding.get()
    if (!opened.get() || bound === null) return null
    if (bound.pending) {
      return {
        kind: 'pending',
        status: HILOS_USER_MERGE_COPY.preparing,
        success: false,
      }
    }
    if (bound.changed) {
      return {
        kind: 'changed',
        message: HILOS_ACCOUNT_MERGE_WINDOW_COPY.changed,
      }
    }
    const access = hilosAdminAccess.get()
    const progress = readableHilosAccountMergeProgress(merge.get(), access)
    if (progress === null || (access === 'full' && !bound.fullFresh)) {
      return {
        kind: 'unavailable',
        message: HILOS_ACCOUNT_MERGE_WINDOW_COPY.unavailable,
      }
    }
    const notStarted = progress.outcome === 'not_started'
    const section = hilosUserMergeSection(
      {
        ...progress,
        parts: notStarted
          ? []
          : progress.stage === 'cancelling' && progress.outcome === null
            ? progress.parts.filter(
                (part) =>
                  part.participant === 'release_loser' ||
                  part.participant === 'release_survivor',
              )
            : progress.parts,
        waits: notStarted ? [] : progress.waits,
      },
      bound.userId,
    )!
    return {
      kind: 'progress',
      progress,
      status: notStarted ? HILOS_USER_MERGE_COPY.notStarted : section.status,
      reason: notStarted ? (progress.refusal?.reason ?? null) : section.refusal,
      pendingParts: section.pendingParts,
      waits: section.waits,
      success: section.successful,
    }
  })

  function clearTracking(): void {
    stopTracking?.()
    stopTracking = null
    startNotice.set(null)
  }

  function close(): void {
    clearTracking()
    stop?.()
    stop = null
    opened.set(false)
    binding.set(null)
    // Release the computed value and its source dependencies even without a view subscriber.
    view.get()
  }

  /**
   * Bind to one operation using the existing privacy and recovery rules.
   *
   * @param userId The shown person's card to keep observing.
   * @param operationId The current or accepted operation to bind.
   * @param pending Whether the accepted operation still needs its first frame.
   */
  function observe(
    userId: number,
    operationId: number | null,
    pending: boolean,
  ): void {
    stop?.()
    binding.set({
      userId,
      operationId,
      changed: false,
      fullFresh: true,
      pending,
    })
    const offPerson = subscribeSignal(shownUserId, (next) => {
      if (next !== userId) close()
    })
    const offMerge = subscribeSignal(merge, (next) => {
      const bound = binding.get()
      if (bound === null) return
      const current = readableHilosAccountMergeProgress(
        next,
        hilosAdminAccess.get(),
      )
      const operationId = current?.operationId ?? null
      binding.set({
        ...bound,
        operationId: bound.operationId ?? operationId,
        pending: bound.pending && operationId !== bound.operationId,
        changed:
          bound.changed ||
          (!bound.pending &&
            bound.operationId !== null &&
            operationId !== null &&
            bound.operationId !== operationId),
        fullFresh: true,
      })
    })
    const offAccess = subscribeSignal(hilosAdminAccess, (access) => {
      if (access === 'none') {
        close()
      } else {
        const bound = binding.get()
        if (bound !== null) binding.set({ ...bound, fullFresh: false })
      }
    })
    stop = () => {
      offPerson()
      offMerge()
      offAccess()
    }
    opened.set(true)
  }

  return {
    opened,
    view,
    startNotice,
    async track(handle, request) {
      clearTracking()
      const userId = shownUserId.get()
      const previousOperationId =
        readableHilosAccountMergeProgress(merge.get(), hilosAdminAccess.get())
          ?.operationId ?? null
      let active =
        !disposed && userId !== undefined && hilosAdminAccess.get() === 'full'
      let offMerge: (() => void) | null = null
      if (active) {
        const offPerson = subscribeSignal(shownUserId, () => clearTracking())
        const offAccess = subscribeSignal(hilosAdminAccess, () =>
          clearTracking(),
        )
        stopTracking = () => {
          active = false
          offPerson()
          offAccess()
          offMerge?.()
        }
      }
      try {
        const { reply } = await handle.done
        if (reply === undefined) {
          // SCAFFOLD: legacy synchronous producer until HIL-1999.
          if (active) clearTracking()
          return 'legacy'
        }
        if (active && userId !== undefined) {
          close()
          observe(
            userId,
            reply.operationId,
            readableHilosAccountMergeProgress(
              merge.get(),
              hilosAdminAccess.get(),
            )?.operationId !== reply.operationId,
          )
        }
        return 'accepted'
      } catch (error) {
        if (!(error instanceof ActionError)) {
          if (active) clearTracking()
          throw error
        }
        if (error.outcome === 'fail') {
          if (active) clearTracking()
          return 'refused'
        }
        if (active && userId !== undefined) {
          const adopt = (next: HilosAccountMergeProgress | null): void => {
            const current = readableHilosAccountMergeProgress(
              next,
              hilosAdminAccess.get(),
            )
            if (
              !active ||
              current === null ||
              current.operationId === null ||
              current.operationId === previousOperationId ||
              current.survivorUserId !== request.survivorUserId ||
              current.loserUserId !== request.loserUserId ||
              current.choice === null ||
              current.choice.passwordFate !== request.passwordFate ||
              current.choice.secondFactorFate !== request.secondFactorFate ||
              current.choice.expectedSurvivorHasSecondFactor !==
                request.expectedSurvivorHasSecondFactor ||
              current.choice.expectedLoserHasSecondFactor !==
                request.expectedLoserHasSecondFactor
            ) {
              return
            }
            close()
            observe(userId, current.operationId, false)
          }
          offMerge = subscribeSignal(merge, adopt)
          startNotice.set(HILOS_ACCOUNT_MERGE_WINDOW_COPY.unanswered)
          adopt(merge.get())
        }
        return 'unanswered'
      }
    },
    open() {
      if (disposed) return false
      if (opened.get()) return true
      const userId = shownUserId.get()
      const progress = readableHilosAccountMergeProgress(
        merge.get(),
        hilosAdminAccess.get(),
      )
      if (userId === undefined || progress === null) return false
      observe(userId, progress.operationId, false)
      return true
    },
    close,
    dispose() {
      disposed = true
      close()
    },
  }
}
