// The row-edit session: the one edit window every modal over a row is
// (docs/agents/frontend/conflict-resolution.md, "The row-edit session").
// rowEdit.ts holds the pure merge — the snapshot, the verdict, the step; this
// holds everything a window built on it used to assemble by hand, in every
// view layer and every project: the snapshot taken on open, the live row read
// from the table's focus with its key checked, the step applied as it comes,
// Keep mine / Take theirs, the one line of notice, the one verdict "can save"
// that the Save button and the form's submit both read, and one door for the
// send with "nothing changed — close". A window's own part — how its row
// projects onto fields and onto its form, its words, its check of the input,
// what the form forgets on close — comes in as options. A framework window
// wraps this once in a factory beside its module (hilosSettingEdit.ts and its
// kin); a project window takes createHilosRowEdit directly. Nobody assembles
// a window from the four merge functions any more — the linter refuses it.
//
// Core signals only (state/signal.ts): the three view layers mirror them
// through their own bridge and keep the markup, nothing else.

import { type ActionHandle } from '../connection/actionLifecycle.js'
import { isHiddenValue } from '../state/hiddenValue.js'
import {
  computedSignal,
  createSignal,
  subscribeSignal,
  type ReadonlySignal,
  type Unsubscribe,
  type WritableSignal,
} from '../state/signal.js'
import { type TableViewportController } from '../table/TableViewportController.js'
import {
  keepMineRowEdit,
  openRowEdit,
  resolveRowEdit,
  takeTheirsRowEdit,
  type RowEditBaseline,
  type RowEditState,
  type RowEditStep,
} from './rowEdit.js'

/** Where an edit window takes its row from, and reads it live while it is open. */
export interface HilosRowEditSource<R> {
  /**
   * Take the row the window opens on.
   *
   * @param key The row's key, for a source that holds many rows.
   * @returns The fresh row, or null when there is none to open on.
   */
  open(key?: string): R | null
  /** The row as it stands now; undefined once it is gone. */
  readonly live: ReadonlySignal<R | undefined>
  /** Let the row go — the window closed. */
  close(): void
}

/**
 * The row of a table window: taken into the table's focus on open, read from
 * the focus while the window is open, released on close. The focus follows the
 * row for the tab wherever it goes (table-subscription.md, "A row an open
 * dialog holds in focus"); the row reads live only while the focus still holds
 * the key the window opened on — another dialog over the same table replaces
 * the focus, and from then on this window's row is gone to it.
 *
 * @param controller The table whose row the window edits.
 * @param keyOf The row's key, as the table addresses it.
 */
export function hilosTableRowEditSource<R>(
  controller: TableViewportController<R>,
  keyOf: (row: R) => string,
): HilosRowEditSource<R> {
  const key = createSignal<string | null>(null)

  return {
    open(rowKey) {
      // A table holds many rows: without a key there is nothing to open on.
      if (rowKey === undefined) {
        return null
      }
      const fresh = controller.focusRow(rowKey)
      if (fresh !== null) {
        key.set(rowKey)
      }

      return fresh
    },
    live: computedSignal(() => {
      const focused = controller.focusedRow.get()
      const held = key.get()

      return focused !== undefined && held !== null && keyOf(focused) === held
        ? focused
        : undefined
    }),
    close() {
      key.set(null)
      controller.releaseFocus()
    },
  }
}

/**
 * The row a signal already carries — a card's own row, the person's own name:
 * nothing to take into focus, nothing to release, and no key to check.
 *
 * @param row The row as it stands now, undefined while there is none.
 */
export function hilosSignalRowEditSource<R>(
  row: ReadonlySignal<R | undefined>,
): HilosRowEditSource<R> {
  return {
    open: () => row.get() ?? null,
    live: row,
    close() {
      // Nothing holds the row for the window: there is nothing to let go.
    },
  }
}

/** The words of the one line the window says about the other side. */
export interface HilosRowEditNotice<R, T extends object> {
  /** The line for a row deleted under the window; {@link HILOS_ROW_EDIT_COPY} by default. */
  readonly deleted?: string
  /**
   * The line for a conflict: what the other side changed the field to, in the
   * window's own words.
   *
   * @param state The verdict, with every field's incoming value.
   * @param live The live row, or undefined when it is gone.
   */
  conflict(state: RowEditState<T>, live: R | undefined): string
  /**
   * The line for a value taken silently from the other side;
   * {@link HILOS_ROW_EDIT_COPY} by default.
   *
   * @param state The verdict, naming the fields taken.
   */
  updated?(state: RowEditState<T>): string
}

/** The words every window shares unless it says its own. */
export const HILOS_ROW_EDIT_COPY = {
  deleted: 'Deleted elsewhere — your text stays to copy.',
  updated: 'Updated just now',
  save: 'Save',
  gone: 'Deleted',
} as const

/**
 * Who holds the form: the session, from the form it shows before the first
 * opening, or a signal the window keeps for more than the edit — a form shared
 * with a creation, a draft the window hands out as its own.
 */
export type HilosRowEditFormHolder<F> =
  | { readonly initial: F; readonly formSignal?: undefined }
  | { readonly formSignal: WritableSignal<F>; readonly initial?: undefined }

/**
 * What is the window's own: how its row projects onto the edited fields and
 * onto its form, its words, its check of the input, and what the form forgets
 * on close. When the form is the fields themselves (`F` is `T`), the three
 * projections default to the identity and to laying the taken values over the
 * form.
 */
export interface HilosRowEditProjection<R, T extends object, F = T> {
  /**
   * The live row projected onto the edited fields.
   *
   * @param row The row, fresh or live.
   */
  fields(row: R): T
  /**
   * The form the window opens with on a fresh row; on a row hidden from a
   * viewer, an empty form that says so.
   *
   * @param row The row the window opens on.
   */
  form?(row: R): F
  /**
   * The form projected onto the draft of the edited fields.
   *
   * @param form The form as it stands.
   * @param row The row the window opened on.
   */
  draft?(form: F, row: R): T
  /**
   * The form with the values a step took from the other side laid into it. A
   * hidden value never reaches here: it moves the snapshot and nothing else.
   *
   * @param form The form as it stands.
   * @param taken The fields taken, with their live values.
   * @param row The row the window opened on.
   * @param live The live row, or undefined when it is gone.
   */
  take?(form: F, taken: Partial<T>, row: R, live: R | undefined): F
  /**
   * Whether the input passes the window's own check — a name within its
   * bounds, a required field filled; everything passes by default.
   *
   * @param form The form as it stands.
   * @param row The row the window opened on.
   */
  valid?(form: F, row: R): boolean
  /**
   * What the form keeps once the window closes — a secret typed into it is
   * forgotten; by default the form stays as it was, so nothing flickers while
   * the modal leaves the screen.
   *
   * @param form The form as it stands.
   */
  forget?(form: F): F
  /** The words of the notice line. */
  readonly notice: HilosRowEditNotice<R, T>
}

/** Everything a window hands the session: its projection and who holds its form. */
export type HilosRowEditOptions<
  R,
  T extends object,
  F = T,
> = HilosRowEditProjection<R, T, F> & HilosRowEditFormHolder<F>

/**
 * The view layer's runner of a tracked action: resolves true on `::success`
 * and false on a failure it has already shown (the Vue and React
 * `useTrackedAction`, the Angular `createHilosTrackedAction`).
 */
export type HilosActionRun = (handle: ActionHandle) => Promise<boolean>

/** One edit window over one row: its state as signals, its moves as methods. */
export interface HilosRowEdit<R, T extends object, F = T> {
  /** Whether the window is open. */
  readonly opened: ReadonlySignal<boolean>
  /**
   * The row the window opened on; null until the first opening, and kept past
   * a close until the next one so the modal does not flicker while it leaves.
   */
  readonly row: ReadonlySignal<R | null>
  /** The form as it stands. */
  readonly form: ReadonlySignal<F>
  /** The verdict over the open edit; the idle verdict while the window is closed. */
  readonly state: ReadonlySignal<RowEditState<T>>
  /** The one line about the other side, or '' while there is nothing to say. */
  readonly noticeText: ReadonlySignal<string>
  /** True while a save is in flight. */
  readonly saving: ReadonlySignal<boolean>
  /**
   * Whether a save may leave now: the window is open, the row is still there,
   * no conflict stands, nothing is in flight, the input passes the window's
   * check, and the draft differs from the live row. The Save button and the
   * form's submit read this one verdict.
   */
  readonly canSave: ReadonlySignal<boolean>
  /** The Save button's word: "Deleted" once the row is gone. */
  readonly saveLabel: ReadonlySignal<string>
  /**
   * Open the window on a row.
   *
   * @param key The row's key, for a source that holds many rows.
   * @returns False when there is no row to open on or a save is in flight.
   */
  open(key?: string): boolean
  /**
   * Replace the form.
   *
   * @param next The form as the person has it now.
   */
  setForm(next: F): void
  /**
   * Lay a part over the form; for a form that is an object.
   *
   * @param patch The fields of the form that changed.
   */
  patchForm(patch: Partial<F>): void
  /** Keep mine: the draft stays, the snapshot moves to the live value. */
  keepMine(): void
  /** Take theirs: the live value lands in the form and the snapshot. */
  takeTheirs(): void
  /**
   * Save by the reply of an action: nothing leaves while the window is closed,
   * the row is gone, a conflict stands, a save is in flight or the input fails
   * the window's check; an unchanged draft closes without a round-trip; else
   * the draft is sent, the window is saving until the reply, and closes on
   * success. The outcome of a save whose window closed in the meantime moves
   * nothing.
   *
   * @param send Dispatch the draft and resolve true on success.
   */
  save(send: (draft: T, row: R) => Promise<boolean>): Promise<void>
  /**
   * Save by the live row: the same door, for a window whose project answers
   * over the row and not over the action's reply. The window is saving until
   * the live fields read as the draft it sent — Take theirs in the meantime
   * rewrites the draft, not what is waited for — or until the refusal turns
   * non-null; both land with the signal that brings them.
   *
   * @param send Dispatch the draft; false when nothing left, and nothing is waited for.
   * @param refusal The project's refusal of the send, null while clear.
   */
  saveLanded(
    send: (draft: T, row: R) => boolean,
    refusal: ReadonlySignal<string | null>,
  ): void
  /** Close the window: the row is let go, and the form forgets what it must. */
  close(): void
  /** Listen for the steps of the merge — on mount of the view. */
  start(): void
  /** Stop listening and close — on unmount of the view. */
  dispose(): void
}

/**
 * A framework window's session: the core session with the window's own send
 * built in, so a view hands save only its runner of the tracked action.
 */
export type HilosTrackedRowEdit<R, T extends object, F = T> = Omit<
  HilosRowEdit<R, T, F>,
  'save' | 'saveLanded'
> & {
  /**
   * Save through the view layer's tracked action — the same door as
   * {@link HilosRowEdit.save}, with the window's send inside.
   *
   * @param run The view layer's runner of the tracked action.
   */
  save(run: HilosActionRun): Promise<void>
}

/**
 * The verdict of a closed window: nothing gone, nothing in conflict, nothing to
 * save, nothing to say. What a view layer seeds its mirror with before the
 * session says anything.
 *
 * @param values The edited fields, as the window would hold them.
 */
export function hilosRowEditIdle<T extends object>(values: T): RowEditState<T> {
  return resolveRowEdit(values, openRowEdit(values), values)
}

/**
 * Whether the live fields read as the draft sent, field by field.
 *
 * @param live The live row projected onto the edited fields.
 * @param sent The draft a save sent.
 */
function landed<T extends object>(live: T, sent: T): boolean {
  return (Object.keys(sent) as (keyof T)[]).every((key) =>
    Object.is(live[key], sent[key]),
  )
}

/**
 * Create one edit window over a source of rows.
 *
 * @param source Where the window takes its row from and reads it live.
 * @param options What is the window's own.
 */
export function createHilosRowEdit<R, T extends object, F = T>(
  source: HilosRowEditSource<R>,
  options: HilosRowEditOptions<R, T, F>,
): HilosRowEdit<R, T, F> {
  const projectForm =
    options.form ?? ((row: R) => options.fields(row) as unknown as F)
  const projectDraft = options.draft ?? ((form: F) => form as unknown as T)
  const layTaken =
    options.take ??
    ((form: F, taken: Partial<T>) => ({ ...form, ...taken }) as F)
  const opened = createSignal(false)
  const row = createSignal<R | null>(null)
  const form = options.formSignal ?? createSignal<F>(options.initial as F)
  const baseline = createSignal<RowEditBaseline<T> | null>(null)
  const saving = createSignal(false)
  // The number of the opening a save belongs to: an outcome that arrives after
  // the window closed — or closed and opened again — is of another round and
  // moves nothing.
  let round = 0
  let stop: Unsubscribe | null = null
  let landing: Unsubscribe[] = []

  const state = computedSignal<RowEditState<T>>(() => {
    const snapshot = baseline.get()
    const current = row.get()
    if (!opened.get() || snapshot === null || current === null) {
      // A window never opened has no fields to speak of; its verdict is read
      // for its flags alone.
      return hilosRowEditIdle(snapshot?.values ?? ({} as T))
    }
    const live = source.live.get()

    return resolveRowEdit(
      live === undefined ? undefined : options.fields(live),
      snapshot,
      projectDraft(form.get(), current),
    )
  })
  const noticeText = computedSignal(() => {
    const verdict = state.get()
    switch (verdict.notice?.kind) {
      case 'deleted':
        return options.notice.deleted ?? HILOS_ROW_EDIT_COPY.deleted
      case 'conflict':
        return options.notice.conflict(verdict, source.live.get())
      case 'updated':
        return options.notice.updated?.(verdict) ?? HILOS_ROW_EDIT_COPY.updated
      default:
        return ''
    }
  })
  const valid = computedSignal(() => {
    const current = row.get()

    return current !== null && (options.valid?.(form.get(), current) ?? true)
  })
  const canSave = computedSignal(() => {
    const verdict = state.get()

    return (
      opened.get() &&
      !verdict.gone &&
      !verdict.conflict &&
      !saving.get() &&
      valid.get() &&
      verdict.dirty
    )
  })
  const saveLabel = computedSignal(() =>
    state.get().gone ? HILOS_ROW_EDIT_COPY.gone : HILOS_ROW_EDIT_COPY.save,
  )

  // A step of the merge: the snapshot moves, and what it took lands in the
  // form — except a value hidden from a viewer, which has no field on the
  // screen to land in.
  function apply(step: RowEditStep<T>): void {
    baseline.set(step.baseline)
    const current = row.get()
    if (current === null) {
      return
    }
    const taken: Partial<T> = {}
    let any = false
    for (const key of Object.keys(step.take) as (keyof T & string)[]) {
      if (!isHiddenValue(step.take[key])) {
        taken[key] = step.take[key]
        any = true
      }
    }
    if (any) {
      form.set(layTaken(form.get(), taken, current, source.live.get()))
    }
  }
  function stopLanding(): void {
    for (const off of landing.splice(0)) off()
  }
  function close(): void {
    if (!opened.get()) {
      return
    }
    round += 1
    stopLanding()
    opened.set(false)
    saving.set(false)
    source.close()
    if (options.forget !== undefined) {
      form.set(options.forget(form.get()))
    }
  }
  // The one door Save and Enter share. Null when nothing leaves: the window is
  // closed, the row is gone, a conflict stands, a save is in flight, the input
  // fails the window's check — or the draft is unchanged, which closes the
  // window without a round-trip.
  function passSave(): { readonly draft: T; readonly row: R } | null {
    const current = row.get()
    const verdict = state.get()
    if (
      current === null ||
      !opened.get() ||
      verdict.gone ||
      verdict.conflict ||
      saving.get() ||
      !valid.get()
    ) {
      return null
    }
    if (!verdict.dirty) {
      close()

      return null
    }

    return { draft: projectDraft(form.get(), current), row: current }
  }

  return {
    opened,
    row,
    form,
    state,
    noticeText,
    saving,
    canSave,
    saveLabel,
    open(key) {
      if (saving.get()) {
        return false
      }
      const fresh = source.open(key)
      if (fresh === null) {
        return false
      }
      row.set(fresh)
      baseline.set(openRowEdit(options.fields(fresh)))
      form.set(projectForm(fresh))
      opened.set(true)

      return true
    },
    setForm(next) {
      form.set(next)
    },
    patchForm(patch) {
      form.set({ ...form.get(), ...patch } as F)
    },
    keepMine() {
      const snapshot = baseline.get()
      if (!opened.get() || snapshot === null) {
        return
      }
      baseline.set(keepMineRowEdit(state.get(), snapshot))
    },
    takeTheirs() {
      const snapshot = baseline.get()
      if (!opened.get() || snapshot === null) {
        return
      }
      apply(takeTheirsRowEdit(state.get(), snapshot))
    },
    async save(send) {
      const pass = passSave()
      if (pass === null) {
        return
      }
      const started = round
      saving.set(true)
      let saved: boolean
      try {
        saved = await send(pass.draft, pass.row)
      } finally {
        if (round === started) {
          saving.set(false)
        }
      }
      if (saved && round === started) {
        close()
      }
    },
    saveLanded(send, refusal) {
      const pass = passSave()
      if (pass === null || !send(pass.draft, pass.row)) {
        return
      }
      const started = round
      saving.set(true)
      const settle = (saved: boolean): void => {
        stopLanding()
        if (round !== started) {
          return
        }
        saving.set(false)
        if (saved) {
          close()
        }
      }
      landing = [
        subscribeSignal(source.live, (live) => {
          if (live !== undefined && landed(options.fields(live), pass.draft)) {
            settle(true)
          }
        }),
        subscribeSignal(refusal, (reason) => {
          if (reason !== null) {
            settle(false)
          }
        }),
      ]
    },
    close,
    start() {
      stop?.()
      stop = subscribeSignal(state, (next) => {
        if (opened.get() && next.settle !== null) {
          apply(next.settle)
        }
      })
    },
    dispose() {
      stop?.()
      stop = null
      close()
    },
  }
}
