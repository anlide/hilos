// The profile root's name window: the framework's standard self-rename binding
// and the window's steps over it. A project can also supply its own binding:
// the send, refusal, confirmation operation and length bounds.
//
// The standard window follows the session holder’s authoritative attempt.
// Project bindings without that capability retain their live-echo lifecycle.
// The form is the core row-edit session over the live name
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
import { onBrowserValueErased } from '../browser/browserValueChanges.js'
import {
  hilosProfileNameAccess,
  hilosProfileNameIdentity,
  hilosProfileNameState,
  type HilosProfileNameState,
} from './profileNameState.js'
import {
  clearProfileRenameMemory,
  PROFILE_RENAME_MEMORY_STORAGE_KEY,
  readProfileRenameMemory,
  writeProfileRenameMemory,
  type HilosProfileRenameMemory,
} from './profileRenameMemory.js'
import { type ActionErrorStore } from '../connection/ActionErrorStore.js'
import { type ActionLifecycle } from '../connection/actionLifecycle.js'
import { type HilosConnection } from '../connection/HilosConnection.js'
import {
  computedSignal,
  createSignal,
  subscribeSignal,
  type ReadonlySignal,
  type WritableSignal,
} from '../state/signal.js'

/** The binding the profile page uses to let a person change their own name. */
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
  /** Clear the action refusal before sending; legacy flows also clear on open. */
  clearRefusal(): void
  /** The standard holder capability; absent on legacy project bindings. */
  readonly state?: HilosProfileRenameState
  /** The protected operation whose confirmation the window asks first. */
  readonly stepUpOperation: string
  /** The shortest name the binding accepts. */
  readonly minLength: number
  /** The longest name the binding accepts. */
  readonly maxLength: number
}

/** The session holder's answer, independent of any one window's visibility. */
export interface HilosProfileRenameState {
  readonly identity: ReadonlySignal<number | null | undefined>
  readonly snapshot: ReadonlySignal<HilosProfileNameState | null>
  readonly access: ReadonlySignal<boolean>
}

/** Tab memory is opt-in and applies only to a binding with authoritative STATE. */
export interface HilosProfileRenameFlowOptions {
  /** Remember this tab's window and restore it on start; false by default. */
  readonly remember?: boolean
  /** Additional page access verdict; false discards the checkpoint and window. */
  readonly access?: ReadonlySignal<boolean>
}

/** The framework action that changes the person's own name. */
export const PROFILE_NAME_SET_ACTION = 'hilos_profile_name_set'

/**
 * Bind the standard self-rename to an existing connection and error store.
 * The caller owns the store's listener and lifetime.
 *
 * @param connection The connection that sends the rename.
 * @param errors The existing store carrying server-authored refusal words.
 */
export function createHilosProfileRename(
  connection: HilosConnection,
  errors: ActionErrorStore,
): HilosProfileRename {
  return {
    send: (name) => connection.sendAction(PROFILE_NAME_SET_ACTION, { name }),
    refusal: errors.signal(PROFILE_NAME_SET_ACTION),
    clearRefusal: () => errors.clear(PROFILE_NAME_SET_ACTION),
    state: {
      identity: hilosProfileNameIdentity,
      snapshot: hilosProfileNameState,
      access: hilosProfileNameAccess,
    },
    stepUpOperation: 'change_name',
    minLength: 2,
    maxLength: 64,
  }
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
  /** Whether the trimmed draft fits the binding's bounds. */
  readonly valid: ReadonlySignal<boolean>
  /** Full operation pending, including the interval before STATE accepts a send. */
  readonly pending: ReadonlySignal<boolean>
  /** Only the server's checking phase; applying and pre-ack are separate. */
  readonly checking: ReadonlySignal<boolean>
  /** Compatibility alias of pending; never use it to lock the modal's body. */
  readonly busy: ReadonlySignal<boolean>
  /** One verdict for Save and Enter, including operation pending and step-up. */
  readonly canSave: ReadonlySignal<boolean>
  /** The server's refusal of the rename, or null. */
  readonly refusal: ReadonlySignal<string | null>
  /** Whether closing asks first: the form shows an edit not yet saved. */
  readonly asksBeforeClosing: ReadonlySignal<boolean>
  /** The binding's shortest name. */
  readonly minLength: number
  /** The binding's longest name. */
  readonly maxLength: number
  /** Bind listeners and restore tab intent; idempotent, including after disposal. */
  start(): void
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
  /** Close explicitly and persist open=false; accepted work continues. */
  close(): void
  /** Detach listeners and forget secrets while preserving visibility intent. */
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
  checking: 'Checking your name…',
} as const

/**
 * Create one name window over a rename binding; the page owns its lifetime.
 *
 * @param context The action lifecycle the confirmation dispatches over.
 * @param name The person's live name, '' while unknown.
 * @param rename The standard or project-specific rename binding.
 * @param options Opt-in tab memory and the page’s additional access verdict.
 */
export function createHilosProfileRenameFlow(
  context: { readonly actions: ActionLifecycle },
  name: ReadonlySignal<string>,
  rename: HilosProfileRename,
  options: HilosProfileRenameFlowOptions = {},
): HilosProfileRenameFlow {
  if (rename.state !== undefined) {
    return createAuthoritativeRenameFlow(
      context,
      name,
      rename,
      rename.state,
      options,
    )
  }
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
    stepUp.dispose()
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
    pending: edit.saving,
    checking: computedSignal(() => false),
    canSave: computedSignal(() => edit.canSave.get() && !stepUp.busy.get()),
    refusal: rename.refusal,
    asksBeforeClosing: computedSignal(
      () => step.get() === 'form' && edit.state.get().dirty,
    ),
    minLength: rename.minLength,
    maxLength: rename.maxLength,
    start: edit.start,
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

/** Local edit correlation, never a second store of the server's attempt. */
interface RenameEditAttempt {
  readonly userId: number
  readonly requestId: string | null
  readonly name: string
  readonly sentForm: string | null
  readonly sourceName: string
  echoSeen: boolean
}

/** The standard flow reads shared truth; only visibility and edit data stay local. */
function createAuthoritativeRenameFlow(
  context: { readonly actions: ActionLifecycle },
  name: ReadonlySignal<string>,
  rename: HilosProfileRename,
  owner: HilosProfileRenameState,
  options: HilosProfileRenameFlowOptions,
): HilosProfileRenameFlow {
  const step = createSignal<HilosProfileRenameStep>('closed')
  const draft = createSignal('')
  const projected = createSignal<string | undefined>(undefined)
  const waiting = createSignal<{
    readonly userId: number
    readonly name: string
    readonly previousRequestId: string | null
    readonly previousSnapshot: HilosProfileNameState | null
  } | null>(null)
  const snapshot = computedSignal(() => {
    const state = owner.snapshot.get()
    return state?.userId === owner.identity.get() ? state : null
  })
  const change = computedSignal(() => snapshot.get()?.change ?? null)
  const access = computedSignal(
    () => owner.access.get() && (options.access?.get() ?? true),
  )
  const ready = computedSignal(
    () =>
      typeof owner.identity.get() === 'number' &&
      snapshot.get() !== null &&
      access.get() &&
      name.get() !== '',
  )
  const pending = computedSignal(() => {
    const phase = change.get()?.phase
    return (
      waiting.get() !== null || phase === 'checking' || phase === 'applying'
    )
  })
  const checking = computedSignal(() => change.get()?.phase === 'checking')
  const fits = (form: string): boolean => {
    const text = form.trim()
    return text.length >= rename.minLength && text.length <= rename.maxLength
  }
  const edit = createHilosRowEdit<string, HilosProfileRenameFields, string>(
    hilosSignalRowEditSource(projected),
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
  const stepUp = createHilosStepUpStep(
    createHilosStepUpActions(context.actions),
    () => {
      if (active && intent && ready.get() && step.get() === 'step-up') {
        showForm()
      }
    },
  )
  const preacceptRefusal = createSignal<string | null>(null)
  let active = false
  let intent = false
  let retain = true
  let memory: HilosProfileRenameMemory | null = null
  let identity: number | null | undefined
  let tracked: RenameEditAttempt | null = null
  let receiptSource: {
    readonly name: string
    readonly previous: string
  } | null = null
  let round = 0
  let transitioning = false
  let opening: Promise<void> | null = null
  let stops: Array<() => void> = []

  function persist(): void {
    const userId = owner.identity.get()
    if (!active || transitioning || !retain || typeof userId !== 'number') {
      return
    }
    if (!access.get()) return
    memory = {
      version: 1,
      userId,
      open: intent,
      step: step.get(),
      draft: edit.opened.get() ? draft.get() : (memory?.draft ?? draft.get()),
      checkpoint: edit.checkpoint() ?? memory?.checkpoint ?? null,
      requestId:
        tracked !== null ? tracked.requestId : (memory?.requestId ?? null),
    }
    if (options.remember) writeProfileRenameMemory(memory)
  }

  function shut(): void {
    round += 1
    opening = null
    stepUp.dispose()
    step.set('closed')
    edit.dispose()
  }

  function close(): void {
    if (!active) return
    persist()
    intent = false
    transitioning = true
    shut()
    transitioning = false
    persist()
  }

  function discard(): void {
    retain = false
    intent = false
    memory = null
    tracked = null
    receiptSource = null
    preacceptRefusal.set(null)
    waiting.set(null)
    transitioning = true
    shut()
    draft.set('')
    projected.set(undefined)
    transitioning = false
    clearProfileRenameMemory()
  }

  function publishName(): void {
    const live = name.get()
    if (receiptSource !== null && live !== receiptSource.previous)
      receiptSource = null
    // A matching echo may arrive before its receipt. Hold that candidate until
    // STATE confirms it, so the ordinary merge cannot eat a reverted draft.
    // Other live names still go through the ordinary external-change merge.
    const target = waiting.get()?.name ?? (pending.get() ? tracked?.name : null)
    if (live !== '' && live === target) {
      if (tracked !== null) tracked.echoSeen = true
      return
    }
    projected.set(live === '' ? undefined : live)
  }

  function showForm(): void {
    const userId = owner.identity.get()
    if (!active || !intent || !ready.get() || typeof userId !== 'number') return
    transitioning = true
    // Always open on a fresh source before restoring validated edit data.
    projected.set(receiptSource?.name ?? name.get())
    edit.start()
    if (!edit.open()) {
      transitioning = false
      close()
      return
    }
    const current = change.get()
    const checkpoint = memory?.checkpoint
    const sameAttempt =
      current !== null && memory?.requestId === current.requestId
    const saved =
      sameAttempt && current.phase === 'done'
        ? { name: current.name }
        : undefined
    if (checkpoint != null) {
      // A done receipt is authoritative even if its live-name delivery follows.
      if (
        saved !== undefined &&
        name.get() === checkpoint.baseline.values.name
      ) {
        receiptSource = { name: saved.name, previous: name.get() }
        projected.set(saved.name)
      } else if (
        name.get() ===
        (waiting.get()?.name ??
          (sameAttempt &&
          (current?.phase === 'checking' || current?.phase === 'applying')
            ? current.name
            : undefined))
      ) {
        // This is still only a candidate echo. Keep the restored baseline's
        // edit projection until its receipt arrives; never call it success.
        projected.set(checkpoint.baseline.values.name)
      }
      edit.restore(checkpoint, saved)
      if (saved !== undefined && memory !== null)
        memory = { ...memory, requestId: null }
    } else if (
      current?.phase === 'checking' ||
      current?.phase === 'applying' ||
      current?.phase === 'refused'
    ) {
      draft.set(current.name)
    }
    if (
      (current?.phase === 'checking' || current?.phase === 'applying') &&
      (sameAttempt || checkpoint == null || waiting.get() !== null)
    ) {
      // A fresh opening can continue shared work. An existing checkpoint keeps
      // its own edit correlation: observing pending never makes its draft sent.
      tracked = {
        userId,
        requestId: current.requestId,
        name: current.name,
        sentForm: draft.get().trim() === current.name ? draft.get() : null,
        sourceName: checkpoint?.baseline.values.name ?? name.get(),
        echoSeen: name.get() === current.name,
      }
    }
    step.set('form')
    transitioning = false
    if (saved !== undefined && checkpoint?.form.trim() === saved.name) close()
    else persist()
  }

  function settleState(): void {
    if (!active) return
    const userId = owner.identity.get()
    if (userId !== undefined) {
      if (
        (identity !== undefined && identity !== userId) ||
        (memory !== null && memory.userId !== userId) ||
        (waiting.get() !== null && waiting.get()?.userId !== userId) ||
        userId === null ||
        !access.get()
      ) {
        discard()
      }
      identity = userId
    }
    if (!ready.get() || typeof userId !== 'number') return
    const current = change.get()
    const sent = waiting.get()
    if (
      sent !== null &&
      snapshot.get() !== sent.previousSnapshot &&
      (current === null || current.requestId !== sent.previousRequestId)
    ) {
      if (
        current !== null &&
        sent.userId === userId &&
        current.name === sent.name
      ) {
        // Correlation includes the snapshot's owner, never data from the draft.
        tracked = {
          userId,
          requestId: current.requestId,
          name: current.name,
          sentForm: tracked?.sentForm ?? null,
          sourceName: tracked?.sourceName ?? name.get(),
          echoSeen: tracked?.echoSeen ?? false,
        }
      } else {
        tracked = null
      }
      waiting.set(null)
      preacceptRefusal.set(null)
    }
    if (
      waiting.get() === null &&
      current !== null &&
      current.phase !== 'refused'
    ) {
      preacceptRefusal.set(null)
    }
    if (
      sent === null &&
      tracked !== null &&
      tracked.requestId !== null &&
      tracked.requestId !== current?.requestId
    ) {
      tracked = null
    }
    if (
      current?.phase === 'done' &&
      tracked !== null &&
      tracked.userId === userId &&
      tracked.requestId === current.requestId &&
      tracked.name === current.name
    ) {
      const unchanged =
        tracked.sentForm !== null && draft.get() === tracked.sentForm
      const confirmed =
        name.get() === current.name ||
        (!tracked.echoSeen && name.get() === tracked.sourceName)
      if (confirmed && name.get() !== current.name) {
        receiptSource = { name: current.name, previous: name.get() }
      }
      tracked = null
      // Once applied to this checkpoint, an old done must not rebase a later
      // external change again after a reload. JSON holds correlation, not phase.
      if (memory !== null) memory = { ...memory, requestId: null }
      if (edit.opened.get()) {
        const checkpoint = edit.checkpoint()
        if (checkpoint !== null) {
          edit.rebaseAfterSave({ name: current.name }, checkpoint, () => {
            projected.set(confirmed ? current.name : name.get())
          })
        }
        if (unchanged && intent) close()
      }
    } else if (current?.phase === 'refused') {
      publishName()
    } else if (current === null && sent === null) {
      tracked = null
      publishName()
    }
    if (intent && pending.get() && step.get() === 'step-up') {
      round += 1
      stepUp.dispose()
      showForm()
    }
    persist()
    if (intent && step.get() === 'closed' && opening === null) {
      void launchOpening()
    }
  }

  function launchOpening(): Promise<void> {
    const started = begin()
    opening = started
    return started.finally(() => {
      if (opening === started) opening = null
    })
  }

  async function begin(): Promise<void> {
    if (!active || !intent || !ready.get() || step.get() !== 'closed') return
    const started = ++round
    if (pending.get()) {
      showForm()
      return
    }
    // A stored presentation step never grants confirmation. START consults the
    // existing server confirmation window; pending accepted work never STARTs.
    step.set('step-up')
    const verdict = await stepUp.open(rename.stepUpOperation)
    if (!active || !intent || round !== started || !ready.get()) return
    if (verdict === 'skip') showForm()
    persist()
  }

  function start(): void {
    if (active) return
    active = true
    memory = options.remember ? readProfileRenameMemory() : memory
    retain = true
    intent = memory?.open ?? false
    if (
      memory?.userId === owner.identity.get() &&
      memory?.requestId === null &&
      memory.checkpoint !== null
    ) {
      preacceptRefusal.set(rename.refusal.get())
    }
    if (waiting.get() === null) tracked = null
    edit.start()
    stops = [
      subscribeSignal(owner.snapshot, settleState),
      subscribeSignal(owner.identity, settleState),
      subscribeSignal(access, settleState),
      subscribeSignal(name, () => {
        if (!active) return
        publishName()
        settleState()
      }),
      subscribeSignal(rename.refusal, (reason) => {
        if (reason !== null && waiting.get() !== null) {
          preacceptRefusal.set(reason)
          waiting.set(null)
          tracked = null
          publishName()
          persist()
        }
      }),
      subscribeSignal(draft, persist),
      subscribeSignal(edit.state, persist),
      onBrowserValueErased((key) => {
        if (key === PROFILE_RENAME_MEMORY_STORAGE_KEY) discard()
      }),
    ]
    settleState()
  }

  return {
    step,
    stepUp,
    draft,
    edit: edit.state,
    notice: edit.noticeText,
    valid: computedSignal(() => fits(draft.get())),
    pending,
    checking,
    busy: pending,
    canSave: computedSignal(
      () =>
        ready.get() &&
        step.get() === 'form' &&
        !stepUp.busy.get() &&
        !pending.get() &&
        edit.canSave.get(),
    ),
    refusal: computedSignal(() => {
      if (pending.get()) return null
      if (preacceptRefusal.get() !== null) return preacceptRefusal.get()
      const current = change.get()
      if (current?.phase === 'refused') return current.refusal
      return null
    }),
    asksBeforeClosing: computedSignal(
      () => step.get() === 'form' && edit.state.get().dirty,
    ),
    minLength: rename.minLength,
    maxLength: rename.maxLength,
    start,
    async open() {
      start()
      if (step.get() !== 'closed' || opening !== null) {
        await opening
        return
      }
      if (!ready.get()) return
      retain = true
      intent = true
      // Manual opening after a completed operation starts at the actual live
      // name. A pending/refused operation may retain this tab's newer draft.
      const current = change.get()
      if (
        (current === null && preacceptRefusal.get() === null) ||
        current?.phase === 'done' ||
        (current !== null &&
          memory?.requestId !== null &&
          memory?.requestId !== current.requestId)
      ) {
        memory = null
      }
      await launchOpening()
    },
    async confirmStepUp() {
      if (
        !active ||
        !intent ||
        !ready.get() ||
        step.get() !== 'step-up' ||
        stepUp.busy.get()
      )
        return
      const started = round
      if (
        (await stepUp.confirm()) &&
        active &&
        round === started &&
        ready.get()
      )
        showForm()
    },
    save() {
      if (
        !active ||
        !ready.get() ||
        step.get() !== 'form' ||
        pending.get() ||
        stepUp.busy.get() ||
        !fits(draft.get())
      )
        return
      const verdict = edit.state.get()
      if (verdict.gone || verdict.conflict) return
      if (!verdict.dirty) {
        close()
        return
      }
      const userId = owner.identity.get()
      if (typeof userId !== 'number') return
      rename.clearRefusal()
      preacceptRefusal.set(null)
      const submitted = draft.get()
      const sent = submitted.trim()
      tracked = {
        userId,
        requestId: null,
        name: sent,
        sentForm: submitted,
        sourceName: name.get(),
        echoSeen: false,
      }
      waiting.set({
        userId,
        name: sent,
        previousRequestId: change.get()?.requestId ?? null,
        previousSnapshot: snapshot.get(),
      })
      if (!rename.send(sent)) {
        waiting.set(null)
        tracked = null
      }
      persist()
    },
    keepMine: edit.keepMine,
    takeTheirs: edit.takeTheirs,
    close,
    dispose() {
      if (!active) return
      persist()
      active = false
      for (const stop of stops.splice(0)) stop()
      transitioning = true
      shut()
      transitioning = false
    },
  }
}
