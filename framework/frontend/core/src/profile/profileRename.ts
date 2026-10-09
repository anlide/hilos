// The profile root's name window (HIL-1169): the rename the project hands the
// page, and the window's steps over it. The wire is the project's — the
// framework has no self-rename of its own — so the project gives the send, where
// its refusal arrives, the operation its confirmation asks for, and the length
// bounds; the window is the framework's.
//
// Server-confirmed, never optimistic: the window closes once the live name has
// reached the name it SENT, and a refusal releases the button with the window
// open. The form is the core row-edit session over the live name
// (conflict/rowEditSession.ts): a snapshot taken when the form shows (HIL-1134),
// a name changed elsewhere while the window is open arriving as "Updated just
// now" or as a conflict to resolve by Keep mine or Take theirs, and the one
// door for Save and Enter.
import {
  createHilosStepUpActions,
  createHilosStepUpStep,
  type HilosStepUpStep,
} from '../auth/stepUp.js'
import { type RowEditState } from '../conflict/rowEdit.js'
import {
  createHilosRowEdit,
  hilosSignalRowEditSource,
} from '../conflict/rowEditSession.js'
import { type ActionLifecycle } from '../connection/actionLifecycle.js'
import {
  computedSignal,
  createSignal,
  subscribeSignal,
  type ReadonlySignal,
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
  const fits = (text: string): boolean => {
    const trimmed = text.trim()

    return (
      trimmed.length >= rename.minLength && trimmed.length <= rename.maxLength
    )
  }
  // The live row is the name itself; '' says the session is not known yet, and
  // there is no row to rename.
  const edit = createHilosRowEdit<string, HilosProfileRenameFields, string>(
    hilosSignalRowEditSource(
      computedSignal(() => {
        const live = name.get()

        return live === '' ? undefined : live
      }),
    ),
    {
      formSignal: draft,
      fields: (live) => ({ name: live }),
      form: (live) => live,
      draft: (form) => ({ name: form.trim() }),
      take: (form, taken) => taken.name ?? form,
      valid: fits,
      notice: {
        deleted: HILOS_PROFILE_RENAME_COPY.noticeDeleted,
        conflict: (state) =>
          HILOS_PROFILE_RENAME_COPY.noticeConflict.replace(
            '{name}',
            state.fields.name.incoming,
          ),
        updated: () => HILOS_PROFILE_RENAME_COPY.noticeUpdated,
      },
    },
  )
  let round = 0

  // The form shows on the name as it is now, not as it was at the Change click;
  // with no name known there is nothing to rename, and the window shuts.
  function showForm(): void {
    if (edit.open()) step.set('form')
    else close()
  }
  function close(): void {
    round += 1
    step.set('closed')
    // Closing the session stops its listening too: a view that disposes and
    // mounts the window again (React's strict effects) gets a live one back on
    // the next open.
    edit.dispose()
    stepUp.password.set('')
    stepUp.code.set('')
  }
  // The session closes itself on the landing of the name it sent, and on a save
  // of an unchanged draft: the window follows it shut.
  subscribeSignal(edit.opened, (open) => {
    if (!open && step.get() === 'form') close()
  })

  return {
    step,
    stepUp,
    draft,
    edit: edit.state,
    notice: edit.noticeText,
    valid: computedSignal(() => fits(draft.get())),
    busy: edit.saving,
    refusal: rename.refusal,
    asksBeforeClosing: computedSignal(
      () => step.get() === 'form' && edit.state.get().dirty,
    ),
    minLength: rename.minLength,
    maxLength: rename.maxLength,
    async open() {
      if (stepUp.busy.get() || step.get() !== 'closed') return
      rename.clearRefusal()
      edit.start()
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
      edit.saveLanded((sent) => {
        rename.clearRefusal()

        return rename.send(sent.name)
      }, rename.refusal)
    },
    keepMine: edit.keepMine,
    takeTheirs: edit.takeTheirs,
    close,
    dispose: close,
  }
}
