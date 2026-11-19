import {
  hilosAdminAccess,
  type HilosAdminAccess,
} from '../../session/adminAccess.js'
import {
  computedSignal,
  createSignal,
  type ReadonlySignal,
  subscribeSignal,
} from '../../state/signal.js'
import type { HilosAccountMergeProgress } from './hilosUsers.js'
import {
  HILOS_USER_MERGE_COPY,
  hilosUserMergeSection,
  type HilosUserMergePartLine,
  type HilosUserMergeWaitLine,
} from './hilosUserMergeSection.js'

/** Words belonging to the observation window rather than the shared merge section. */
export const HILOS_ACCOUNT_MERGE_WINDOW_COPY = {
  view: 'View merge',
  close: 'Close',
  unavailable: 'Merge progress is unavailable.',
  changed:
    'The merge operation has changed. Close this window and open it again.',
  survivor: 'Survivor',
  loser: 'Other account',
} as const

/** Current content of an open observation window; a closed window has no view. */
export type HilosAccountMergeWindowView =
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

/** Local observation of one operation on one shown person's card. */
export interface HilosAccountMergeWindow {
  readonly opened: ReadonlySignal<boolean>
  readonly view: ReadonlySignal<HilosAccountMergeWindowView | null>
  /** Open from current data, returning false when the card or progress is unavailable. */
  open(): boolean
  /** Forget the operation and release local observers; this does not cancel a merge. */
  close(): void
  /** Close permanently, releasing observers even if the card continues updating. */
  dispose(): void
}

/**
 * Accept the server's projection whole. A viewer must wait for its withheld
 * replacement if the session verdict arrives before the page frame; the browser
 * never manufactures its own field-by-field privacy projection.
 *
 * @param progress The current safe reader result.
 * @param access The live admin-section verdict.
 */
function readableProgress(
  progress: HilosAccountMergeProgress | null,
  access: HilosAdminAccess,
): HilosAccountMergeProgress | null {
  if (progress === null || access === 'none') return null
  if (access === 'full') return progress
  if (
    progress.operationId !== null ||
    progress.survivorUserId !== null ||
    progress.loserUserId !== null ||
    progress.stage !== null ||
    progress.outcome !== null ||
    progress.choice !== null ||
    progress.refusal !== null ||
    progress.parts.some((part) => part.userId !== null) ||
    progress.waits.some((wait) => wait.userId !== null)
  ) {
    return null
  }
  return progress
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
  const binding = createSignal<{
    readonly userId: number
    readonly operationId: number | null
    readonly changed: boolean
    readonly fullFresh: boolean
  } | null>(null)
  let stop: (() => void) | null = null
  let disposed = false

  const view = computedSignal<HilosAccountMergeWindowView | null>(() => {
    const bound = binding.get()
    if (!opened.get() || bound === null) return null
    if (bound.changed) {
      return {
        kind: 'changed',
        message: HILOS_ACCOUNT_MERGE_WINDOW_COPY.changed,
      }
    }
    const access = hilosAdminAccess.get()
    const progress = readableProgress(merge.get(), access)
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

  function close(): void {
    stop?.()
    stop = null
    opened.set(false)
    binding.set(null)
    // Release the computed value and its source dependencies even without a view subscriber.
    view.get()
  }

  return {
    opened,
    view,
    open() {
      if (disposed) return false
      if (opened.get()) return true
      const userId = shownUserId.get()
      const progress = readableProgress(merge.get(), hilosAdminAccess.get())
      if (userId === undefined || progress === null) return false
      binding.set({
        userId,
        operationId: progress.operationId,
        changed: false,
        fullFresh: true,
      })
      const offPerson = subscribeSignal(shownUserId, (next) => {
        if (next !== userId) close()
      })
      const offMerge = subscribeSignal(merge, (next) => {
        const bound = binding.get()
        if (bound === null) return
        const current = readableProgress(next, hilosAdminAccess.get())
        const operationId = current?.operationId ?? null
        binding.set({
          ...bound,
          operationId: bound.operationId ?? operationId,
          changed:
            bound.changed ||
            (bound.operationId !== null &&
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
      return true
    },
    close,
    dispose() {
      disposed = true
      close()
    },
  }
}
