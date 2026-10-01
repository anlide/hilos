// The signed-in person's password change (HIL-300). The server owns every
// transition; a refused submit keeps its step and input, and a closed window
// cannot be reopened by an answer that was already on its way.
//
// The step past the opening is the SESSION's record (HIL-1182): "the code went
// out" and "the code matched" are told to every tab of the browser on the profile
// flows frame, so the window opens on the step already reached in any tab and
// after a reload, and an open window moves when another tab moves the flow. The
// save no longer carries the code; discarding the window ends the flow for the
// whole session.
import { z } from 'zod'
import {
  createHilosStepUpActions,
  createHilosStepUpStep,
  type HilosStepUpStep,
} from '../auth/stepUp.js'
import {
  ActionError,
  type ActionHandle,
  type ActionLifecycle,
} from '../connection/actionLifecycle.js'
import {
  computedSignal,
  createSignal,
  subscribeSignal,
  type ReadonlySignal,
  type Unsubscribe,
  type WritableSignal,
} from '../state/signal.js'
import {
  hilosProfileFlowFor,
  PROFILE_FLOW_CANCEL_ACTION,
  PROFILE_FLOW_STEP_CODE_PROVEN,
  PROFILE_FLOW_STEP_CODE_SENT,
  type HilosProfileFlowState,
} from './profileFlows.js'

export const PROFILE_CHANGE_PASSWORD_OPEN_ACTION =
  'profile_change_password_open'
export const PROFILE_CHANGE_PASSWORD_CODE_REQUEST_ACTION =
  'profile_change_password_code_request'
export const PROFILE_CHANGE_PASSWORD_CODE_CONFIRM_ACTION =
  'profile_change_password_code_confirm'
export const PROFILE_CHANGE_PASSWORD_ACTION = 'profile_change_password'
export const PASSWORD_CHANGE_OPERATION = 'change_password'

const openingReplySchema = z.object({
  channel: z.enum(['email', 'phone']).nullable(),
  destination: z.string().nullable(),
})
export type HilosProfilePasswordChangeOpening = z.infer<
  typeof openingReplySchema
>

/** The four server actions, bound to one browser's action lifecycle. */
export interface HilosProfilePasswordChangeActions {
  open(): ActionHandle<HilosProfilePasswordChangeOpening>
  requestCode(): ActionHandle
  confirmCode(code: string): ActionHandle
  change(newPassword: string, signOutOthers: boolean): ActionHandle
}

/** Bind the password-change wire contract to the project's lifecycle. */
export function createHilosProfilePasswordChangeActions(context: {
  readonly actions: ActionLifecycle
}): HilosProfilePasswordChangeActions {
  return {
    open: () =>
      context.actions.dispatch(
        PROFILE_CHANGE_PASSWORD_OPEN_ACTION,
        {},
        { replySchema: openingReplySchema },
      ),
    requestCode: () =>
      context.actions.dispatch(PROFILE_CHANGE_PASSWORD_CODE_REQUEST_ACTION, {}),
    confirmCode: (code) =>
      context.actions.dispatch(PROFILE_CHANGE_PASSWORD_CODE_CONFIRM_ACTION, {
        code,
      }),
    change: (newPassword, signOutOthers) =>
      context.actions.dispatch(PROFILE_CHANGE_PASSWORD_ACTION, {
        newPassword,
        signOutOthers,
      }),
  }
}

export type HilosProfilePasswordChangeStep =
  | 'closed'
  | 'opening'
  | 'step-up'
  | 'start'
  | 'code'
  | 'password'
  | 'done'
  | 'refused'

/** A single modal's steps, proofs and draft. */
export interface HilosProfilePasswordChangeFlow {
  readonly step: ReadonlySignal<HilosProfilePasswordChangeStep>
  readonly stepUp: HilosStepUpStep
  readonly opening: ReadonlySignal<HilosProfilePasswordChangeOpening | null>
  readonly code: WritableSignal<string>
  readonly newPassword: WritableSignal<string>
  readonly signOutOthers: WritableSignal<boolean>
  /** The choice sent with the successful save, independent of later draft edits. */
  readonly signedOutOthers: ReadonlySignal<boolean>
  readonly busy: ReadonlySignal<boolean>
  readonly refusal: ReadonlySignal<string | null>
  readonly asksBeforeClosing: ReadonlySignal<boolean>
  open(): Promise<void>
  confirmStepUp(): Promise<void>
  sendCode(): Promise<void>
  confirmCode(): Promise<void>
  save(): Promise<void>
  again(): Promise<void>
  /**
   * Close the window. On the code and new-password steps with a flow behind them -
   * after the person agreed to discard - it ends the flow for the whole session
   * first and closes on the server's word; anywhere else it closes at once.
   */
  close(): void
  /** Let the window go without touching the flow: leaving the page is not discarding it. */
  dispose(): void
}

/**
 * The step a window with a code to send stands on for the session's record.
 *
 * @param record The session's record of the password change, or null when it has none.
 */
function stepOfRecord(
  record: HilosProfileFlowState | null,
): HilosProfilePasswordChangeStep {
  switch (record?.step) {
    case PROFILE_FLOW_STEP_CODE_SENT:
      return 'code'
    case PROFILE_FLOW_STEP_CODE_PROVEN:
      return 'password'
    default:
      return 'start'
  }
}

/** Create one flow; the page opens it and owns its lifetime. */
export function createHilosProfilePasswordChangeFlow(context: {
  readonly actions: ActionLifecycle
}): HilosProfilePasswordChangeFlow {
  const actions = createHilosProfilePasswordChangeActions(context)
  const stepUp = createHilosStepUpStep(
    createHilosStepUpActions(context.actions),
  )
  const step = createSignal<HilosProfilePasswordChangeStep>('closed')
  const opening = createSignal<HilosProfilePasswordChangeOpening | null>(null)
  const code = createSignal('')
  const newPassword = createSignal('')
  const signOutOthers = createSignal(true)
  const signedOutOthers = createSignal(true)
  const busy = createSignal(false)
  const refusal = createSignal<string | null>(null)
  const record = hilosProfileFlowFor(PASSWORD_CHANGE_OPERATION)
  let round = 0
  // Actions of this window still waiting for their answer: while one is, the
  // record going away is its own ending, and the answer says how it ended.
  let pending = 0
  let following: Unsubscribe | null = null

  const asksBeforeClosing = computedSignal(
    () => step.get() === 'code' || step.get() === 'password',
  )

  async function run(submit: () => Promise<void>): Promise<boolean> {
    const started = round
    busy.set(true)
    refusal.set(null)
    pending += 1
    try {
      await submit()
      return round === started
    } catch (error) {
      if (round === started) {
        refusal.set(
          error instanceof ActionError ? error.message : 'The action failed.',
        )
      }
      return false
    } finally {
      pending -= 1
      if (round === started) busy.set(false)
    }
  }

  /** Whether the window's steps are the session's record: a code has somewhere to go. */
  function followsRecord(): boolean {
    return opening.get()?.channel != null
  }

  /**
   * Take one frame of the session's record: move the open window to the step it
   * names, or close it when the flow ended in another tab.
   *
   * @param flow The session's record of the password change, or null when it has none.
   */
  function follow(flow: HilosProfileFlowState | null): void {
    const current = step.get()
    if (
      !followsRecord() ||
      (current !== 'start' && current !== 'code' && current !== 'password')
    )
      return
    if (flow === null) {
      // Finished or discarded elsewhere; this window's own action, if one is
      // waiting, ends the flow itself and its answer says how.
      if (pending === 0 && current !== 'start') finish()
      return
    }
    const next = stepOfRecord(flow)
    if (next === current) return
    refusal.set(null)
    if (next === 'code') code.set('')
    if (next === 'password') newPassword.set('')
    step.set(next)
  }

  async function openSteps(): Promise<void> {
    const started = round
    const opened = await run(async () => {
      const result = await actions.open().done
      if (round === started)
        opening.set(result.reply as HilosProfilePasswordChangeOpening)
    })
    if (round !== started) return
    if (!opened) {
      step.set('refused')
      return
    }
    if (!followsRecord()) {
      step.set('password')
      return
    }
    step.set(stepOfRecord(record.get()))
    following ??= subscribeSignal(record, follow)
  }

  /** Close the window here, leaving the flow as it is. */
  function finish(): void {
    round += 1
    step.set('closed')
    busy.set(false)
    resetDraft()
    stepUp.password.set('')
    stepUp.code.set('')
    following?.()
    following = null
  }

  /** End the flow for the whole session, then close on the server's word. */
  async function discard(): Promise<void> {
    round += 1
    if (
      await run(async () => {
        await context.actions.dispatch(PROFILE_FLOW_CANCEL_ACTION, {
          operation: PASSWORD_CHANGE_OPERATION,
        }).done
      })
    )
      finish()
  }

  function resetDraft(): void {
    opening.set(null)
    code.set('')
    newPassword.set('')
    signOutOthers.set(true)
    refusal.set(null)
  }

  return {
    step,
    stepUp,
    opening,
    code,
    newPassword,
    signOutOthers,
    signedOutOthers,
    busy,
    refusal,
    asksBeforeClosing,
    async open() {
      if (busy.get()) return
      resetDraft()
      step.set('opening')
      busy.set(true)
      const started = ++round
      const verdict = await stepUp.open(PASSWORD_CHANGE_OPERATION)
      busy.set(false)
      if (round !== started) return
      if (verdict === 'skip') await openSteps()
      else {
        if (verdict === 'refused') refusal.set(stepUp.refusal.get())
        step.set(verdict === 'refused' ? 'refused' : 'step-up')
      }
    },
    async confirmStepUp() {
      if (busy.get() || step.get() !== 'step-up') return
      busy.set(true)
      const started = round
      const confirmed = await stepUp.confirm()
      busy.set(false)
      if (round === started && confirmed) await openSteps()
    },
    async sendCode() {
      if (busy.get() || step.get() !== 'start') return
      if (
        await run(async () => {
          await actions.requestCode().done
        })
      )
        // The frame naming the new step arrived before this answer; a send the
        // server had nothing to stand on leaves the window where it was.
        step.set(stepOfRecord(record.get()))
    },
    async confirmCode() {
      if (busy.get() || step.get() !== 'code' || code.get().trim() === '')
        return
      if (
        await run(async () => {
          await actions.confirmCode(code.get()).done
        })
      )
        step.set(stepOfRecord(record.get()))
    },
    async save() {
      if (busy.get() || step.get() !== 'password' || newPassword.get() === '')
        return
      const choice = signOutOthers.get()
      if (
        await run(async () => {
          await actions.change(newPassword.get(), choice).done
        })
      ) {
        signedOutOthers.set(choice)
        newPassword.set('')
        code.set('')
        step.set('done')
      }
    },
    async again() {
      if (busy.get() || step.get() !== 'done') return
      resetDraft()
      await openSteps()
    },
    close() {
      if (asksBeforeClosing.get() && followsRecord() && record.get() !== null)
        void discard()
      else finish()
    },
    dispose: finish,
  }
}

export const HILOS_PROFILE_PASSWORD_CHANGE_COPY = {
  title: 'Change your password',
  steps: ['Start', 'Code', 'New password', 'Done'],
  start:
    "We'll send a code to {destination}. Your password changes only after you confirm.",
  sendCode: 'Send code',
  code: 'Code',
  codeHint: 'Sent to {destination}.',
  continue: 'Continue',
  newPassword: 'New password',
  newPasswordHint: 'At least 8 characters.',
  signOutOthers: 'Sign out of other sessions',
  save: 'Save password',
  doneTitle: 'Password changed',
  doneSignedOut: 'Other sessions were signed out.',
  doneKept: 'Other sessions stay signed in.',
  doneResetCodes: 'Password reset codes, if any were sent, no longer work.',
  again: 'Change again',
  done: 'Done',
  cancel: 'Cancel',
} as const
