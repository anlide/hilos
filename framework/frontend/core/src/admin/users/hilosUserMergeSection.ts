import type { HilosAccountMergeProgress } from './hilosUsers.js'

// SCAFFOLD: the merge window observer and Vue window consume this presentation;
// the user card (HIL-2006) still needs its UI wiring.

/** Shared headings and progress words for an account merge. */
export const HILOS_USER_MERGE_COPY = {
  title: 'Account merge',
  preparing: 'Preparing merge',
  waiting: 'Merge in progress',
  releasing: 'Releasing merge reservations',
  refused: 'Merge refused',
  completed: 'Merge completed',
  notStarted: 'Merge not started',
  ended: 'Merge finished',
  parts: 'Unfinished parts',
  waits: 'Waiting for participants',
  refusalDetails: "Couldn't merge the accounts",
} as const

const mergeDutyLabels: ReadonlyMap<string, string> = new Map([
  ['prepare_survivor', 'Prepare receiving account'],
  ['prepare_loser', 'Prepare source account'],
  ['give', 'Transfer account data'],
  ['receive', 'Accept account data'],
  ['sessions', 'Close source sessions'],
  ['survivor_sessions', 'Close receiving sessions'],
  ['cleanup', 'Remove transfer copy'],
  ['release_loser', 'Release source reservation'],
  ['release_survivor', 'Release receiving reservation'],
])

/** One unfinished merge part, with its participant ready to display as text. */
export interface HilosUserMergePartLine {
  /** The source participant, owner and person tuple's stable key. */
  readonly key: string
  /** A standard duty label, or the project's participant text verbatim. */
  readonly participant: string
  /** The available other account id; null for the shown account or a hidden id. */
  readonly account: number | null
}

/** One actual merge wait, with its participant ready to display as text. */
export interface HilosUserMergeWaitLine {
  /** The source participant and person tuple's stable key. */
  readonly key: string
  /** A standard duty label, or the project's participant text verbatim. */
  readonly participant: string
  /** The available other account id; null for the shown account or a hidden id. */
  readonly account: number | null
}

/** Pure presentation shared by the user card and merge window. */
export interface HilosUserMergeSection {
  /** The common progress state in words. */
  readonly status: string
  /** True only for an ended merge whose explicit outcome is completed. */
  readonly successful: boolean
  /** Unfinished parts in the safe reader's order. */
  readonly pendingParts: readonly HilosUserMergePartLine[]
  /** Actual waits in the safe reader's order, independent of pending parts. */
  readonly waits: readonly HilosUserMergeWaitLine[]
  /** The server's reason verbatim in refusal state, otherwise null. */
  readonly refusal: string | null
}

/** Translate only the common state and explicit outcome into progress words. */
function mergeStatus(merge: HilosAccountMergeProgress): string {
  const copy = HILOS_USER_MERGE_COPY
  switch (merge.state) {
    case 'preparing':
      return copy.preparing
    case 'wait':
      return merge.stage === 'cancelling' ? copy.releasing : copy.waiting
    case 'refusal':
      return copy.refused
    case 'end':
      return merge.outcome === 'completed'
        ? copy.completed
        : merge.outcome === 'not_started'
          ? copy.notStarted
          : copy.ended
  }
}

/**
 * Present the current safe progress without retaining any prior operation.
 * Participant and refusal words are plain text; no account name is looked up.
 *
 * @param merge The safe progress, or null while none is available.
 * @param shownUserId The shown person, absent until the card's row is available.
 */
export function hilosUserMergeSection(
  merge: HilosAccountMergeProgress | null,
  shownUserId: number | undefined,
): HilosUserMergeSection | null {
  if (merge === null || shownUserId === undefined) {
    return null
  }
  const account = (userId: number | null): number | null =>
    userId === shownUserId ? null : userId
  const participant = (name: string): string =>
    mergeDutyLabels.get(name) ?? name

  return {
    status: mergeStatus(merge),
    successful: merge.state === 'end' && merge.outcome === 'completed',
    pendingParts: merge.parts
      .filter((part) => !part.done)
      .map((part) => ({
        key: JSON.stringify([part.participant, part.owner, part.userId]),
        participant: participant(part.participant),
        account: account(part.userId),
      })),
    waits: merge.waits.map((wait) => ({
      key: JSON.stringify([wait.participant, wait.userId]),
      participant: participant(wait.participant),
      account: account(wait.userId),
    })),
    refusal: merge.state === 'refusal' ? (merge.refusal?.reason ?? null) : null,
  }
}
