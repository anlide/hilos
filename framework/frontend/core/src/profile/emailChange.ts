// The profile's email change (HIL-299, HIL-1137): the four actions of one
// surface, and the window's steps over them (HIL-1169). Framework-agnostic — the
// view packages draw the window, this module is the wire and the step machine.
//
// The server keeps nothing between the steps: the current address's code, proven
// on step 2, is carried by the flow into steps 3 and 4 as the proof. Every step
// first asks the operation's confirmation (HIL-495). None answers with a reply;
// the moved address arrives in the identities projection. Server-confirmed, never
// optimistic — a step advances on the backend `::success` only, and a refusal
// stays on the step with the typed values intact.
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

/**
 * Client→server: send a code to the address the account holds now (PHP
 * `HilosSignalConstants::PROFILE_CHANGE_EMAIL_CURRENT_REQUEST`).
 */
export const PROFILE_CHANGE_EMAIL_CURRENT_REQUEST_ACTION =
  'profile_change_email_current_request'

/**
 * Client→server: check the current address's code without spending it (PHP
 * `HilosSignalConstants::PROFILE_CHANGE_EMAIL_CURRENT_CONFIRM`).
 */
export const PROFILE_CHANGE_EMAIL_CURRENT_CONFIRM_ACTION =
  'profile_change_email_current_confirm'

/**
 * Client→server: send a code to the new address, carrying the current address's
 * code (PHP `HilosSignalConstants::PROFILE_CHANGE_EMAIL_NEW_REQUEST`).
 */
export const PROFILE_CHANGE_EMAIL_NEW_REQUEST_ACTION =
  'profile_change_email_new_request'

/**
 * Client→server: prove the new address and move the account onto it (PHP
 * `HilosSignalConstants::PROFILE_CHANGE_EMAIL_NEW_CONFIRM`).
 */
export const PROFILE_CHANGE_EMAIL_NEW_CONFIRM_ACTION =
  'profile_change_email_new_confirm'

/** The protected operation the window confirms first (PHP `StepUpOperationKey::CHANGE_EMAIL`). */
export const EMAIL_CHANGE_OPERATION = 'change_email'

/** The action lifecycle the email-change actions dispatch through. */
export interface HilosProfileEmailChangeActionContext {
  readonly actions: ActionLifecycle
}

/** The four actions of the profile's email change. */
export interface HilosProfileEmailChangeActions {
  /** Send a code to the address the account holds now; the server reads it from the account. */
  requestCurrentCode(): ActionHandle
  /**
   * Check the current address's code without spending it.
   *
   * @param code The code the current address received.
   */
  confirmCurrentCode(code: string): ActionHandle
  /**
   * Send a code to the new address.
   *
   * @param currentCode The current address's code proven on step 2.
   * @param email The new address.
   */
  requestNewCode(currentCode: string, email: string): ActionHandle
  /**
   * Prove the new address and move the account onto it.
   *
   * @param currentCode The current address's code proven on step 2.
   * @param email The new address.
   * @param code The code the new address received.
   */
  confirmNewCode(currentCode: string, email: string, code: string): ActionHandle
}

/**
 * Build the email-change actions over one connection lifecycle.
 *
 * @param context The action lifecycle to dispatch through.
 */
export function createHilosProfileEmailChangeActions(
  context: HilosProfileEmailChangeActionContext,
): HilosProfileEmailChangeActions {
  return {
    requestCurrentCode() {
      return context.actions.dispatch(
        PROFILE_CHANGE_EMAIL_CURRENT_REQUEST_ACTION,
        {},
      )
    },
    confirmCurrentCode(code) {
      return context.actions.dispatch(
        PROFILE_CHANGE_EMAIL_CURRENT_CONFIRM_ACTION,
        { code },
      )
    },
    requestNewCode(currentCode, email) {
      return context.actions.dispatch(PROFILE_CHANGE_EMAIL_NEW_REQUEST_ACTION, {
        currentCode,
        email,
      })
    },
    confirmNewCode(currentCode, email, code) {
      return context.actions.dispatch(PROFILE_CHANGE_EMAIL_NEW_CONFIRM_ACTION, {
        currentCode,
        email,
        code,
      })
    },
  }
}

/**
 * The window's steps: shut, the confirmation, a code to the address the account
 * holds now, that code typed back, the new address, the code from the new
 * address, and the outcome.
 */
export type HilosProfileEmailChangeStep =
  | 'closed'
  | 'step-up'
  | 'send-current'
  | 'confirm-current'
  | 'new-address'
  | 'confirm-new'
  | 'done'

/** One email-change window: its steps, typed values and the step in flight. */
export interface HilosProfileEmailChangeFlow {
  readonly step: ReadonlySignal<HilosProfileEmailChangeStep>
  readonly stepUp: HilosStepUpStep
  /** The address the account held when the window opened. */
  readonly was: ReadonlySignal<string>
  /** The code from the current address, typed on step 2 and carried after it. */
  readonly currentCode: WritableSignal<string>
  /** The new address, typed on step 3. */
  readonly newEmail: WritableSignal<string>
  /** The code from the new address, typed on step 4. */
  readonly newCode: WritableSignal<string>
  /** The address the account holds after step 4, as the outcome shows it. */
  readonly now: ReadonlySignal<string>
  /** True while the window opens or a step waits for the server. */
  readonly busy: ReadonlySignal<boolean>
  /** The refusal of the step on screen, or null. */
  readonly refusal: ReadonlySignal<string | null>
  /** Whether the step's own field is filled enough to submit. */
  readonly canSubmit: ReadonlySignal<boolean>
  /** Whether closing asks first: a code is already out on steps 2 to 4. */
  readonly asksBeforeClosing: ReadonlySignal<boolean>
  /**
   * Ask whether the operation needs a confirmation, then open on it or on step 1.
   *
   * @param address The verified address the account holds now.
   */
  open(address: string): Promise<void>
  /** Submit the step on screen. */
  submit(): Promise<void>
  /** Walk the window again from step 1, the address just set being the current one. */
  again(): Promise<void>
  close(): void
  dispose(): void
}

/** The steps past the confirmation, in the order the window lists them. */
export const HILOS_PROFILE_EMAIL_CHANGE_STEPS = [
  'send-current',
  'confirm-current',
  'new-address',
  'confirm-new',
  'done',
] as const satisfies readonly HilosProfileEmailChangeStep[]

/** The window's words, as the chat profile had them; `steps` follows the order above. */
export const HILOS_PROFILE_EMAIL_CHANGE_COPY = {
  steps: [
    'Code to your current address',
    'Enter the code',
    'New address',
    'Code from the new address',
    'Done',
  ],
  title: 'Change your email',
  doneTitle: 'Email changed',
  sendCurrentLead: 'We will send a code to',
  sendCurrentTail: 'to make sure it is you.',
  code: 'Code',
  sentTo: 'Sent to {address}.',
  newEmail: 'New email',
  newEmailHint: 'A notice of the change will go to your old address.',
  changed: 'Address changed',
  outcomeWas: 'Was',
  outcomeNow: 'now',
  outcomeTail: 'A notice went to both.',
  sendCode: 'Send code',
  continue: 'Continue',
  changeEmail: 'Change email',
  again: 'Change again',
  done: 'Done',
  cancel: 'Cancel',
  unreached: 'Could not reach the server. Please try again.',
} as const

/** The step each step past the confirmation leads to on its `::success`. */
const STEP_AFTER: Readonly<
  Partial<Record<HilosProfileEmailChangeStep, HilosProfileEmailChangeStep>>
> = {
  'send-current': 'confirm-current',
  'confirm-current': 'new-address',
  'new-address': 'confirm-new',
  'confirm-new': 'done',
}

/**
 * Create one email-change window; the page opens it and owns its lifetime.
 *
 * @param context The action lifecycle the steps and the confirmation dispatch over.
 */
export function createHilosProfileEmailChangeFlow(
  context: HilosProfileEmailChangeActionContext,
): HilosProfileEmailChangeFlow {
  const actions = createHilosProfileEmailChangeActions(context)
  const stepUp = createHilosStepUpStep(
    createHilosStepUpActions(context.actions),
  )
  const step = createSignal<HilosProfileEmailChangeStep>('closed')
  const was = createSignal('')
  const currentCode = createSignal('')
  const newEmail = createSignal('')
  const newCode = createSignal('')
  const now = createSignal('')
  const busy = createSignal(false)
  const refusal = createSignal<string | null>(null)
  let round = 0

  const canSubmit = computedSignal(() => {
    switch (step.get()) {
      case 'confirm-current':
        return currentCode.get().trim() !== ''
      case 'new-address':
        return newEmail.get().trim() !== ''
      case 'confirm-new':
        return newCode.get().trim() !== ''
      default:
        return true
    }
  })

  /** Send the step on screen to the server. */
  function dispatchStep(): ActionHandle {
    switch (step.get()) {
      case 'confirm-current':
        return actions.confirmCurrentCode(currentCode.get().trim())
      case 'new-address':
        return actions.requestNewCode(
          currentCode.get().trim(),
          newEmail.get().trim(),
        )
      case 'confirm-new':
        return actions.confirmNewCode(
          currentCode.get().trim(),
          newEmail.get().trim(),
          newCode.get().trim(),
        )
      default:
        return actions.requestCurrentCode()
    }
  }

  function close(): void {
    round += 1
    step.set('closed')
    busy.set(false)
    refusal.set(null)
    stepUp.password.set('')
    stepUp.code.set('')
  }

  async function open(address: string): Promise<void> {
    if (busy.get()) return
    was.set(address)
    currentCode.set('')
    newEmail.set('')
    newCode.set('')
    now.set('')
    refusal.set(null)
    busy.set(true)
    const started = ++round
    const verdict = await stepUp.open(EMAIL_CHANGE_OPERATION)
    if (round !== started) return
    busy.set(false)
    step.set(verdict === 'skip' ? 'send-current' : 'step-up')
  }

  return {
    step,
    stepUp,
    was,
    currentCode,
    newEmail,
    newCode,
    now,
    busy,
    refusal,
    canSubmit,
    asksBeforeClosing: computedSignal(
      () =>
        step.get() === 'confirm-current' ||
        step.get() === 'new-address' ||
        step.get() === 'confirm-new',
    ),
    open,
    async submit() {
      const current = step.get()
      if (
        current === 'closed' ||
        current === 'done' ||
        !canSubmit.get() ||
        busy.get()
      )
        return
      const started = round
      busy.set(true)
      refusal.set(null)
      if (current === 'step-up') {
        const confirmed = await stepUp.confirm()
        if (round !== started) return
        busy.set(false)
        if (confirmed) step.set('send-current')
        return
      }
      try {
        await dispatchStep().done
      } catch (error) {
        if (round !== started) return
        busy.set(false)
        refusal.set(
          error instanceof ActionError && error.outcome === 'fail'
            ? error.message
            : HILOS_PROFILE_EMAIL_CHANGE_COPY.unreached,
        )
        return
      }
      if (round !== started) return
      busy.set(false)
      // The server stores the address lowercased; the outcome names what was set.
      if (current === 'confirm-new')
        now.set(newEmail.get().trim().toLowerCase())
      step.set(STEP_AFTER[current] ?? 'done')
    },
    async again() {
      if (step.get() !== 'done') return
      await open(now.get())
    },
    close,
    dispose: close,
  }
}
