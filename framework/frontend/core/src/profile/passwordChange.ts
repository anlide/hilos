// The signed-in person's password change (HIL-300). The server owns every
// transition; a refused submit keeps its step and input, and a closed window
// cannot be reopened by an answer that was already on its way.
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
  type ReadonlySignal,
  type WritableSignal,
} from '../state/signal.js'

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
  change(
    code: string,
    newPassword: string,
    signOutOthers: boolean,
  ): ActionHandle
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
    change: (code, newPassword, signOutOthers) =>
      context.actions.dispatch(PROFILE_CHANGE_PASSWORD_ACTION, {
        code,
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
  close(): void
  dispose(): void
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
  let round = 0

  async function run(submit: () => Promise<void>): Promise<boolean> {
    const started = round
    busy.set(true)
    refusal.set(null)
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
      busy.set(false)
    }
  }

  async function openSteps(): Promise<void> {
    const started = round
    const opened = await run(async () => {
      const result = await actions.open().done
      if (round === started)
        opening.set(result.reply as HilosProfilePasswordChangeOpening)
    })
    if (round !== started) return
    step.set(
      opened
        ? opening.get()?.channel == null
          ? 'password'
          : 'start'
        : 'refused',
    )
  }

  function resetDraft(): void {
    opening.set(null)
    code.set('')
    newPassword.set('')
    signOutOthers.set(true)
    refusal.set(null)
  }

  function close(): void {
    round += 1
    step.set('closed')
    resetDraft()
    stepUp.password.set('')
    stepUp.code.set('')
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
    asksBeforeClosing: computedSignal(
      () => step.get() === 'code' || step.get() === 'password',
    ),
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
        step.set('code')
    },
    async confirmCode() {
      if (busy.get() || step.get() !== 'code' || code.get().trim() === '')
        return
      if (
        await run(async () => {
          await actions.confirmCode(code.get()).done
        })
      )
        step.set('password')
    },
    async save() {
      if (busy.get() || step.get() !== 'password' || newPassword.get() === '')
        return
      const choice = signOutOthers.get()
      if (
        await run(async () => {
          await actions.change(
            opening.get()?.channel == null ? '' : code.get(),
            newPassword.get(),
            choice,
          ).done
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
    close,
    dispose: close,
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
