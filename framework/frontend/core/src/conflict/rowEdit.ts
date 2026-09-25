// The shared row-edit helper: the headless core of every modal that edits one
// row against its live copy (docs/agents/frontend/conflict-resolution.md, "The
// shared row-edit helper"). threeWayMerge classifies one field; this holds the
// whole edit — the snapshot the person last saw as saved, which of its fields
// arrived from the other side, and the verdict over every field at once: what
// the form takes silently, whether a conflict stands, whether Save has anything
// to send, and which one message the modal shows. Pure data in, pure data out
// — no Vue, no DOM — so the three view layers and every project modal share one
// merge instead of a copy each: resolveSettingEdit was the copy this replaced,
// and the two windows that held no merge at all took this instead of a third.
//
// The view keeps two things: its own form and one RowEditBaseline. It projects
// the form into a draft of the edited fields, and the live row into the same
// shape, and reads everything else off the state this returns. A field is a
// primitive — string, number, boolean, null — compared with Object.is; a form
// richer than its fields projects them itself (a setting's switch and text fold
// into one `overrideValue`).

import { threeWayMerge, type ThreeWayMergeResult } from './threeWayMerge.js'

/** Which one message the edit modal shows, highest precedence first. */
export type RowEditNoticeKind = 'deleted' | 'conflict' | 'updated'

/**
 * The snapshot an edit compares against: the saved values the person last saw,
 * and which of them the form took from the other side.
 */
export interface RowEditBaseline<T extends object> {
  /**
   * The saved values of the edited fields. Frozen on open, then moved by every
   * silent take and by the person's choice, so the next comparison is always
   * against the last value the person saw as saved.
   */
  readonly values: T
  /**
   * The fields whose value in the form came from the other side — a silent
   * take or Take theirs — and which the person has not touched since.
   */
  readonly refreshed: readonly (keyof T & string)[]
}

/** One field's merge, plus the live value and whether the form shows a taken one. */
export interface RowEditField<V> extends ThreeWayMergeResult<V> {
  /** The live value; the snapshot's when the row is gone. */
  readonly incoming: V
  /** True while the form shows a value taken from the other side, untouched since. */
  readonly refreshed: boolean
}

/** A move of the snapshot, with what the view writes into its form. */
export interface RowEditStep<T extends object> {
  /** The snapshot after the move. */
  readonly baseline: RowEditBaseline<T>
  /** The fields the view puts into the form; empty when only the snapshot moves. */
  readonly take: Partial<T>
}

/** The one message the modal shows, and the fields it is about. */
export interface RowEditNotice<T extends object> {
  readonly kind: RowEditNoticeKind
  /** The conflicting fields, or the refreshed ones; empty for `deleted`. */
  readonly fields: readonly (keyof T & string)[]
}

/** The verdict over an open edit: every field, and what follows from them. */
export interface RowEditState<T extends object> {
  /** True when the live row is no longer there — deleted under the modal. */
  readonly gone: boolean
  /** Every edited field, merged. */
  readonly fields: { readonly [K in keyof T]: RowEditField<T[K]> }
  /** True while at least one field conflicts and the row is still there. */
  readonly conflict: boolean
  /** True when the draft differs from the live row in at least one field. */
  readonly dirty: boolean
  /**
   * The move the view applies as soon as it sees one: a field only the other
   * side changed goes into the form and the snapshot; a field both sides
   * changed alike only moves the snapshot. Null when there is nothing to move
   * — the view applies a step whenever it is there, so a step with nothing in
   * it would run the view in a circle.
   */
  readonly settle: RowEditStep<T> | null
  /** The one message to show, by precedence deleted › conflict › updated; null for none. */
  readonly notice: RowEditNotice<T> | null
}

/** The edited fields — the keys of the snapshot, whatever the draft or the row carry. */
function fieldsOf<T extends object>(values: T): (keyof T & string)[] {
  return Object.keys(values) as (keyof T & string)[]
}

function withField<K>(fields: readonly K[], field: K): readonly K[] {
  return fields.includes(field) ? fields : [...fields, field]
}

function withoutField<K>(fields: readonly K[], field: K): readonly K[] {
  return fields.filter((other) => other !== field)
}

/**
 * Open an edit on the row as it is saved right now.
 *
 * @param values The saved values of the fields the modal edits.
 * @returns The snapshot the edit compares against, with nothing refreshed yet.
 */
export function openRowEdit<T extends object>(values: T): RowEditBaseline<T> {
  return { values, refreshed: [] }
}

/**
 * Merge an open edit against the live row, field by field.
 *
 * @param live The live row projected onto the edited fields; undefined when it is gone.
 * @param baseline The snapshot the edit compares against.
 * @param draft The form projected onto the edited fields.
 * @returns The verdict: every field, the flags, the step to apply, the message to show.
 */
export function resolveRowEdit<T extends object>(
  live: T | undefined,
  baseline: RowEditBaseline<T>,
  draft: T,
): RowEditState<T> {
  const keys = fieldsOf(baseline.values)
  const fields = {} as { [K in keyof T]: RowEditField<T[K]> }

  if (live === undefined) {
    // The row is gone: nothing to merge against, and nothing to send. The draft
    // stays on screen to copy, with the snapshot as the last value it had.
    for (const key of keys) {
      fields[key] = {
        status: 'unchanged',
        conflict: false,
        value: draft[key],
        incoming: baseline.values[key],
        refreshed: false,
      }
    }

    return {
      gone: true,
      fields,
      conflict: false,
      dirty: false,
      settle: null,
      notice: { kind: 'deleted', fields: [] },
    }
  }

  const conflicting: (keyof T & string)[] = []
  const updated: (keyof T & string)[] = []
  const take: Partial<T> = {}
  let values = baseline.values
  let refreshed = baseline.refreshed
  let moved = false
  let dirty = false
  for (const key of keys) {
    const merge = threeWayMerge(baseline.values[key], draft[key], live[key])
    // A taken value stays "refreshed" only while the form still shows it: the
    // moment the person types over it, the message about it goes out.
    const isRefreshed =
      baseline.refreshed.includes(key) &&
      Object.is(draft[key], baseline.values[key])
    fields[key] = { ...merge, incoming: live[key], refreshed: isRefreshed }
    if (merge.conflict) {
      conflicting.push(key)
    }
    if (isRefreshed) {
      updated.push(key)
    }
    if (!Object.is(draft[key], live[key])) {
      dirty = true
    }
    if (merge.status === 'incoming') {
      take[key] = live[key]
      values = { ...values, [key]: live[key] }
      refreshed = withField(refreshed, key)
      moved = true
    } else if (merge.status === 'converged') {
      // Both sides arrived at the same value: the snapshot catches up, the
      // form already shows it, and nothing was taken from anyone.
      values = { ...values, [key]: live[key] }
      refreshed = withoutField(refreshed, key)
      moved = true
    }
  }

  let notice: RowEditNotice<T> | null = null
  if (conflicting.length > 0) {
    notice = { kind: 'conflict', fields: conflicting }
  } else if (updated.length > 0) {
    notice = { kind: 'updated', fields: updated }
  }

  return {
    gone: false,
    fields,
    conflict: conflicting.length > 0,
    dirty,
    settle: moved ? { baseline: { values, refreshed }, take } : null,
    notice,
  }
}

/**
 * Keep mine: the person keeps the draft of every conflicting field, and the
 * snapshot moves to the live value so the conflict is over and Save has the
 * draft to send.
 *
 * @param state The verdict the choice answers.
 * @param baseline The snapshot the verdict was made against.
 * @returns The moved snapshot; the draft is not touched.
 */
export function keepMineRowEdit<T extends object>(
  state: RowEditState<T>,
  baseline: RowEditBaseline<T>,
): RowEditBaseline<T> {
  let values = baseline.values
  let refreshed = baseline.refreshed
  for (const key of fieldsOf(baseline.values)) {
    if (state.fields[key].conflict) {
      values = { ...values, [key]: state.fields[key].incoming }
      refreshed = withoutField(refreshed, key)
    }
  }

  return { values, refreshed }
}

/**
 * Take theirs: every conflicting field takes the live value into the form and
 * the snapshot alike, and is marked refreshed so the modal says so.
 *
 * @param state The verdict the choice answers.
 * @param baseline The snapshot the verdict was made against.
 * @returns The moved snapshot and what the view writes into the form.
 */
export function takeTheirsRowEdit<T extends object>(
  state: RowEditState<T>,
  baseline: RowEditBaseline<T>,
): RowEditStep<T> {
  const take: Partial<T> = {}
  let values = baseline.values
  let refreshed = baseline.refreshed
  for (const key of fieldsOf(baseline.values)) {
    if (state.fields[key].conflict) {
      take[key] = state.fields[key].incoming
      values = { ...values, [key]: state.fields[key].incoming }
      refreshed = withField(refreshed, key)
    }
  }

  return { baseline: { values, refreshed }, take }
}
