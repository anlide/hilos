// The account-merge reading shared by the person card and the observation
// window. It names the state, the unfinished duties and the waits, and the
// server's refusal. It reads no connection, store or clock. The card's scope
// already holds the progress; the window passes that same safe progress in.

import { type HilosAccountMergeProgress } from './hilosUsers.js'

/**
 * What the card of an account being merged says: the section title, each
 * progress state, and the headings of its parts, waits and refusal.
 */
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

/**
 * Display names of the nine merge duties. Any other participant is already
 * the server's words and is shown unchanged.
 */
const MERGE_DUTY_LABEL = {
  prepare_survivor: 'Prepare receiving account',
  prepare_loser: 'Prepare source account',
  give: 'Transfer account data',
  receive: 'Accept account data',
  sessions: 'Close source sessions',
  survivor_sessions: 'Close receiving sessions',
  cleanup: 'Remove transfer copy',
  release_loser: 'Release source reservation',
  release_survivor: 'Release receiving reservation',
} as const

type MergeDuty = keyof typeof MERGE_DUTY_LABEL

/** One unfinished merge duty as the card's merge section lists it. */
export interface HilosUserMergePartLine {
  /** The line's key: the JSON tuple of participant, owner and person. */
  readonly key: string
  /** The duty's name, or the server's own words for any other participant. */
  readonly participant: string
  /**
   * The other account the part is about, or `null` when it is the card's own
   * or the person is hidden.
   */
  readonly account: number | null
}

/** One awaited participant as the card's merge section lists it. */
export interface HilosUserMergeWaitLine {
  /** The line's key: the JSON tuple of participant and person. */
  readonly key: string
  /** The duty's name, or the server's own words for any other participant. */
  readonly participant: string
  /**
   * The other account the wait is about, or `null` when it is the card's own
   * or the person is hidden.
   */
  readonly account: number | null
}

/** The card's section about a merge. */
export interface HilosUserMergeSection {
  /** The progress state in words. */
  readonly status: string
  /** Whether the merge finished by completing. Every other state is not a success. */
  readonly successful: boolean
  /** The duties that have not finished, in the progress order. */
  readonly pendingParts: readonly HilosUserMergePartLine[]
  /** The participants still awaited, in the progress order. */
  readonly waits: readonly HilosUserMergeWaitLine[]
  /** The server's refusal words, or `null` unless the merge is refused. */
  readonly refusal: string | null
}

/**
 * The card's merge section, or `null` while no merge progress stands or the
 * card has no person yet. A line about the card's own person, and a line
 * whose person is hidden, names no account. A known duty is named from the
 * closed map; any other participant is the server's own words, unchanged.
 * Success is only a finished merge whose outcome is completed. Given work,
 * received work and finished parts do not make it so. The refusal is the
 * server's reason, and only while the merge is refused; unfinished parts
 * and waits stay listed beside it.
 *
 * @param merge The merge progress, or `null` when none stands.
 * @param shownUserId The person the card shows, absent until the card has one.
 */
export function hilosUserMergeSection(
  merge: HilosAccountMergeProgress | null,
  shownUserId: number | undefined,
): HilosUserMergeSection | null {
  if (merge === null || shownUserId === undefined) {
    return null
  }

  return {
    status: mergeStatus(merge),
    successful: merge.state === 'end' && merge.outcome === 'completed',
    pendingParts: merge.parts
      .filter((part) => !part.done)
      .map((part) => ({
        key: JSON.stringify([part.participant, part.owner, part.userId]),
        participant: mergeDutyLabel(part.participant),
        account: shownAccount(part.userId, shownUserId),
      })),
    waits: merge.waits.map((wait) => ({
      key: JSON.stringify([wait.participant, wait.userId]),
      participant: mergeDutyLabel(wait.participant),
      account: shownAccount(wait.userId, shownUserId),
    })),
    refusal: merge.state === 'refusal' ? (merge.refusal?.reason ?? null) : null,
  }
}

/** The section's status line for one merge progress. */
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
      if (merge.outcome === 'completed') return copy.completed
      if (merge.outcome === 'not_started') return copy.notStarted
      return copy.ended
  }
}

/** A known duty's display name, or the participant words as the server sent them. */
function mergeDutyLabel(participant: string): string {
  return isMergeDuty(participant) ? MERGE_DUTY_LABEL[participant] : participant
}

/** Whether this participant is one of the nine merge duties. */
function isMergeDuty(participant: string): participant is MergeDuty {
  return Object.hasOwn(MERGE_DUTY_LABEL, participant)
}

/**
 * The other account a line names, or `null` for the card's own person and
 * for a person the progress left hidden.
 */
function shownAccount(
  userId: number | null,
  shownUserId: number,
): number | null {
  return userId === null || userId === shownUserId ? null : userId
}
