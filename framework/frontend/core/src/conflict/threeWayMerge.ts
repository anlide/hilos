// The framework-agnostic 3-way merge: the headless core of edit-in-modal
// conflict resolution (docs/agents/frontend/conflict-resolution.md). A modal
// freezes a baseline of the entity when it opens, the user edits a draft, and
// the live subscription delivers an incoming committed value. This compares the
// three per field and classifies the outcome; the view layer (ConflictActions)
// renders the resolution and the project applies it. Pure data in, pure result
// out — no Vue, no DOM, so every view layer shares one merge.

/** The classification of one field's baseline / draft / incoming values. */
export type ThreeWayMergeStatus =
  // Neither the user nor the server changed the field since baseline.
  | 'unchanged'
  // Only the user changed it — keep the draft.
  | 'user'
  // Only the server changed it — take incoming (a safe auto-merge).
  | 'incoming'
  // Both changed it to the same value — agreement, no conflict.
  | 'converged'
  // Both changed it to different values — needs explicit resolution.
  | 'conflict'

/** The result of merging one field across baseline, draft, and incoming. */
export interface ThreeWayMergeResult<T> {
  /** The classification driving how the view treats the field. */
  readonly status: ThreeWayMergeStatus
  /** True only for `conflict` — the one status needing user resolution. */
  readonly conflict: boolean
  /**
   * The value to show: the auto-resolved value when there is no conflict
   * (incoming when only the server moved, otherwise the draft), and the draft
   * when there is a conflict so the user keeps editing from their own text.
   */
  readonly value: T
}

/**
 * Merge one field across the modal's open-time `baseline`, the user's `draft`,
 * and the latest `incoming` committed value, classifying the outcome.
 *
 * Auto-merges every non-conflicting case — only a both-sides-diverged edit is a
 * `conflict` the user must resolve. Absence is not handled here: pass concrete
 * values (the entity store treats a missing field as untouched upstream).
 *
 * @param baseline The value captured when the modal opened.
 * @param draft The user's current edited value.
 * @param incoming The latest committed value from the live subscription.
 * @param equals Field equality; defaults to `Object.is` (fine for primitives).
 * @returns The classification and the value the view should display.
 */
export function threeWayMerge<T>(
  baseline: T,
  draft: T,
  incoming: T,
  equals: (a: T, b: T) => boolean = Object.is,
): ThreeWayMergeResult<T> {
  const userChanged = !equals(draft, baseline)
  const serverChanged = !equals(incoming, baseline)

  if (!userChanged && !serverChanged) {
    return { status: 'unchanged', conflict: false, value: incoming }
  }
  if (userChanged && !serverChanged) {
    return { status: 'user', conflict: false, value: draft }
  }
  if (!userChanged && serverChanged) {
    return { status: 'incoming', conflict: false, value: incoming }
  }
  if (equals(draft, incoming)) {
    return { status: 'converged', conflict: false, value: draft }
  }

  return { status: 'conflict', conflict: true, value: draft }
}
