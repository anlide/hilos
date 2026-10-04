// The profile root's name window (HIL-1169): the rename the project hands the
// page, and the window's steps over it. The wire is the project's — the
// framework has no self-rename of its own — so the project gives the send, where
// its refusal arrives, the operation its confirmation asks for, and the length
// bounds; the window is the framework's.
//
// Server-confirmed, never optimistic: the window closes once the live name has
// reached the name it SENT, and a refusal releases the button with the window
// open. The form edits against a snapshot taken when it shows (HIL-1134): a name
// changed elsewhere while the window is open arrives by the row-edit helper as
// "Updated just now", or as a conflict to resolve by Keep mine or Take theirs.
import {
  createHilosStepUpActions,
  createHilosStepUpStep,
  type HilosStepUpStep,
} from '../auth/stepUp.js'
import {
  keepMineRowEdit,
  openRowEdit,
  resolveRowEdit,
  takeTheirsRowEdit,
  type RowEditBaseline,
  type RowEditState,
  type RowEditStep,
} from '../conflict/rowEdit.js'
import { type ActionLifecycle } from '../connection/actionLifecycle.js'
import {
  computedSignal,
  createSignal,
  subscribeSignal,
  type ReadonlySignal,
  type Unsubscribe,
  type WritableSignal,
} from '../state/signal.js'

/** What a project hands the profile page when it lets a person change their own name. */
export interface HilosProfileRename {
  /**
   * Send the new name.
   *
   * @param name The trimmed name the person saves.
   * @returns False when nothing left — no connection; the window then waits for nothing.
   */
  send(name: string): boolean
  /** The latest refusal of the rename, or null when clear. */
  readonly refusal: ReadonlySignal<string | null>
  /** Forget the refusal — before a window opens and before a send. */
  clearRefusal(): void
  /** The protected operation whose confirmation the window asks first. */
  readonly stepUpOperation: string
  /** The shortest name the project accepts. */
  readonly minLength: number
  /** The longest name the project accepts. */
  readonly maxLength: number
}

/** The window's steps: shut, the confirmation, the form. */
export type HilosProfileRenameStep = 'closed' | 'step-up' | 'form'

/** The one field the name window edits. */
export interface HilosProfileRenameFields {
  name: string
}

/** One name window: its steps, draft, snapshot and the rename in flight. */
export interface HilosProfileRenameFlow {
  readonly step: ReadonlySignal<HilosProfileRenameStep>
  readonly stepUp: HilosStepUpStep
  readonly draft: WritableSignal<string>
  /** The live name merged against the snapshot and the draft. */
  readonly edit: ReadonlySignal<RowEditState<HilosProfileRenameFields>>
  /** The one line the form says about the other side, or ''. */
  readonly notice: ReadonlySignal<string>
  /** Whether the trimmed draft fits the project's bounds. */
  readonly valid: ReadonlySignal<boolean>
  /** True while a sent rename waits for the live name. */
  readonly busy: ReadonlySignal<boolean>
  /** The project's refusal of the rename, or null. */
  readonly refusal: ReadonlySignal<string | null>
  /** Whether closing asks first: the form shows an edit not yet saved. */
  readonly asksBeforeClosing: ReadonlySignal<boolean>
  /** The project's shortest name. */
  readonly minLength: number
  /** The project's longest name. */
  readonly maxLength: number
  /** Ask whether the operation needs a confirmation, then show it or the form. */
  open(): Promise<void>
  /** Confirm the person, then show the form. */
  confirmStepUp(): Promise<void>
  /** Send the draft; an unchanged draft closes without a round-trip. */
  save(): void
  /** Keep the draft over the other side's name. */
  keepMine(): void
  /** Take the other side's name into the draft. */
  takeTheirs(): void
  close(): void
  dispose(): void
}

/** The name window's words, as the chat profile had them. */
export const HILOS_PROFILE_RENAME_COPY = {
  title: 'Change name',
  label: 'Display name',
  bounds: 'Between {min} and {max} characters.',
  save: 'Save',
  deleted: 'Deleted',
  cancel: 'Cancel',
  noticeDeleted: 'Deleted elsewhere — your text stays to copy.',
  noticeConflict: 'Changed elsewhere to "{name}".',
  noticeUpdated: 'Updated just now',
} as const

/**
 * The line the form says about the other side, for what the helper found.
 *
 * @param live The merged edit.
 */
function noticeText(live: RowEditState<HilosProfileRenameFields>): string {
  switch (live.notice?.kind) {
    case 'deleted':
      return HILOS_PROFILE_RENAME_COPY.noticeDeleted
    case 'conflict':
      return HILOS_PROFILE_RENAME_COPY.noticeConflict.replace(
        '{name}',
        live.fields.name.incoming,
      )
    case 'updated':
      return HILOS_PROFILE_RENAME_COPY.noticeUpdated
    default:
      return ''
  }
}

/**
 * Create one name window over the project's rename; the page owns its lifetime.
 *
 * @param context The action lifecycle the confirmation dispatches over.
 * @param name The person's live name, '' while unknown.
 * @param rename The project's rename.
 */
export function createHilosProfileRenameFlow(
  context: { readonly actions: ActionLifecycle },
  name: ReadonlySignal<string>,
  rename: HilosProfileRename,
): HilosProfileRenameFlow {
  const stepUp = createHilosStepUpStep(
    createHilosStepUpActions(context.actions),
    () => {
      if (step.get() === 'step-up') showForm()
    },
  )
  const step = createSignal<HilosProfileRenameStep>('closed')
  const draft = createSignal('')
  const baseline = createSignal<RowEditBaseline<HilosProfileRenameFields>>(
    openRowEdit({ name: '' }),
  )
  const busy = createSignal(false)
  // The name the rename in flight sent — what success waits for; null while
  // nothing is in flight. Take theirs rewrites the draft, not this.
  let sentName: string | null = null
  let round = 0
  const edit = computedSignal(() => {
    const live = name.get()
    return resolveRowEdit(
      live === '' ? undefined : { name: live },
      baseline.get(),
      {
        name: draft.get().trim(),
      },
    )
  })
  const valid = computedSignal(() => {
    const trimmed = draft.get().trim()
    return (
      trimmed.length >= rename.minLength && trimmed.length <= rename.maxLength
    )
  })

  function apply(next: RowEditStep<HilosProfileRenameFields>): void {
    baseline.set(next.baseline)
    if (next.take.name !== undefined) draft.set(next.take.name)
  }
  // The form shows: the draft and the snapshot are the live name now, not at
  // the Change click.
  function showForm(): void {
    draft.set(name.get())
    baseline.set(openRowEdit({ name: name.get() }))
    step.set('form')
  }
  function settle(): void {
    busy.set(false)
    sentName = null
  }
  // The window listens only while it is open, so a view that disposes and
  // mounts it again (React's strict effects) gets a live window back.
  let stops: Unsubscribe[] = []
  function listen(): void {
    if (stops.length > 0) return
    stops = [
      // The helper hands a step whenever only the other side moved the name,
      // or both arrived at the same one; the form applies it at once.
      subscribeSignal(edit, (next) => {
        if (step.get() === 'form' && next.settle !== null) apply(next.settle)
      }),
      // Success is state-driven: the live name reached the one sent.
      subscribeSignal(name, (live) => {
        if (busy.get() && live === sentName) close()
      }),
      // A refusal releases the button and keeps the window open for a retry.
      subscribeSignal(rename.refusal, (reason) => {
        if (reason !== null) settle()
      }),
    ]
  }
  function close(): void {
    round += 1
    step.set('closed')
    settle()
    stepUp.password.set('')
    stepUp.code.set('')
    for (const stop of stops) stop()
    stops = []
  }

  return {
    step,
    stepUp,
    draft,
    edit,
    notice: computedSignal(() => noticeText(edit.get())),
    valid,
    busy,
    refusal: rename.refusal,
    asksBeforeClosing: computedSignal(
      () => step.get() === 'form' && edit.get().dirty,
    ),
    minLength: rename.minLength,
    maxLength: rename.maxLength,
    async open() {
      if (stepUp.busy.get() || step.get() !== 'closed') return
      rename.clearRefusal()
      settle()
      listen()
      const started = ++round
      const verdict = await stepUp.open(rename.stepUpOperation)
      if (round !== started) return
      if (verdict === 'skip') showForm()
      else step.set('step-up')
    },
    async confirmStepUp() {
      if (stepUp.busy.get() || step.get() !== 'step-up') return
      const started = round
      if ((await stepUp.confirm()) && round === started) showForm()
    },
    save() {
      const live = edit.get()
      if (
        step.get() !== 'form' ||
        !valid.get() ||
        busy.get() ||
        live.gone ||
        live.conflict
      )
        return
      if (!live.dirty) {
        close()
        return
      }
      const next = draft.get().trim()
      rename.clearRefusal()
      const sent = rename.send(next)
      busy.set(sent)
      sentName = sent ? next : null
    },
    keepMine() {
      baseline.set(keepMineRowEdit(edit.get(), baseline.get()))
    },
    takeTheirs() {
      apply(takeTheirsRowEdit(edit.get(), baseline.get()))
    },
    close,
    dispose: close,
  }
}
