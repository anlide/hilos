// The profile's email change (HIL-299, HIL-1137): the four actions of one
// surface, and the window's steps over them (HIL-1169). Framework-agnostic — the
// view packages draw the window, this module is the wire and the step machine.
//
// The server remembers the step (HIL-1182): how far the window got and what was
// proven on it — the current address's code matched, the new address and its
// code sent — is the SESSION's record, told to every tab of it on the profile
// flows frame. The window stands on that record: opened in any tab or after a
// reload it opens on the step reached, and an open window moves when another tab
// of the same browser moves the flow. The tab no longer carries the current
// address's code. Every step first asks the operation's confirmation (HIL-495).
// None answers with a reply; the moved address arrives in the identities
// projection. Server-confirmed, never optimistic — a step advances on the
// backend's word only, and a refusal stays on the step with the typed values
// intact. Discarding the window ends the flow for the whole session.
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
  PROFILE_FLOW_STEP_CURRENT_PROVEN,
  PROFILE_FLOW_STEP_CURRENT_SENT,
  PROFILE_FLOW_STEP_NEW_SENT,
  type HilosProfileFlowState,
} from './profileFlows.js'

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
 * Client→server: send a code to the new address; the proof of the current one is
 * the session's record (PHP `HilosSignalConstants::PROFILE_CHANGE_EMAIL_NEW_REQUEST`).
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
   * @param email The new address.
   */
  requestNewCode(email: string): ActionHandle
  /**
   * Prove the new address and move the account onto it; the address is the one
   * the session's record names.
   *
   * @param code The code the new address received.
   */
  confirmNewCode(code: string): ActionHandle
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
    requestNewCode(email) {
      return context.actions.dispatch(PROFILE_CHANGE_EMAIL_NEW_REQUEST_ACTION, {
        email,
      })
    },
    confirmNewCode(code) {
      return context.actions.dispatch(PROFILE_CHANGE_EMAIL_NEW_CONFIRM_ACTION, {
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
  /** The address the account held when the window opened - the session's record of it, when there is one. */
  readonly was: ReadonlySignal<string>
  /** The code from the current address, typed on step 2. */
  readonly currentCode: WritableSignal<string>
  /** The new address, typed on step 3; on step 4 the one the session's record names. */
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
   * Ask whether the operation needs a confirmation, then open on it or on the
   * step the session's record names (step 1 when there is none).
   *
   * @param address The verified address the account holds now.
   */
  open(address: string): Promise<void>
  /** Submit the step on screen. */
  submit(): Promise<void>
  /** Walk the window again from step 1, the address just set being the current one. */
  again(): Promise<void>
  /**
   * Close the window. On steps 2 to 4 - after the person agreed to discard - it
   * ends the flow for the whole session first and closes on the server's word;
   * anywhere else it closes at once and leaves the flow alone.
   */
  close(): void
  /** Let the window go without touching the flow: leaving the page is not discarding it. */
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

/** The steps the session's record decides, where a frame may move the window. */
const RECORD_STEPS: ReadonlySet<HilosProfileEmailChangeStep> = new Set([
  'send-current',
  'confirm-current',
  'new-address',
  'confirm-new',
])

/**
 * The step a window stands on for the session's record of its flow.
 *
 * @param record The session's record of the email change, or null when it has none.
 */
function stepOfRecord(
  record: HilosProfileFlowState | null,
): HilosProfileEmailChangeStep {
  switch (record?.step) {
    case PROFILE_FLOW_STEP_CURRENT_SENT:
      return 'confirm-current'
    case PROFILE_FLOW_STEP_CURRENT_PROVEN:
      return 'new-address'
    case PROFILE_FLOW_STEP_NEW_SENT:
      return 'confirm-new'
    default:
      return 'send-current'
  }
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
  const record = hilosProfileFlowFor(EMAIL_CHANGE_OPERATION)
  let round = 0
  // Actions of this window still waiting for their answer: while one is, the
  // record going away is its own ending, and the answer says how it ended.
  let pending = 0
  let following: Unsubscribe | null = null

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

  const asksBeforeClosing = computedSignal(
    () =>
      step.get() === 'confirm-current' ||
      step.get() === 'new-address' ||
      step.get() === 'confirm-new',
  )

  /** Send the step on screen to the server. */
  function dispatchStep(): ActionHandle {
    switch (step.get()) {
      case 'confirm-current':
        return actions.confirmCurrentCode(currentCode.get().trim())
      case 'new-address':
        return actions.requestNewCode(newEmail.get().trim())
      case 'confirm-new':
        return actions.confirmNewCode(newCode.get().trim())
      default:
        return actions.requestCurrentCode()
    }
  }

  /**
   * Stand the window on the step the session's record names, with the addresses
   * the record carries.
   *
   * @param flow The session's record of the email change, or null when it has none.
   */
  function standOn(flow: HilosProfileFlowState | null): void {
    if (flow !== null) {
      was.set(flow.address)
      if (flow.target !== null) newEmail.set(flow.target)
    }
    step.set(stepOfRecord(flow))
  }

  /**
   * Take one frame of the session's record: move the open window to the step it
   * names, or close it when the flow ended in another tab.
   *
   * @param flow The session's record of the email change, or null when it has none.
   */
  function follow(flow: HilosProfileFlowState | null): void {
    const current = step.get()
    if (!RECORD_STEPS.has(current)) return
    if (flow === null) {
      // Finished or discarded elsewhere; this window's own action, if one is
      // waiting, ends the flow itself and its answer says how.
      if (pending === 0 && current !== 'send-current') finish()
      return
    }
    const next = stepOfRecord(flow)
    if (
      next === current &&
      (next !== 'confirm-new' || flow.target === newEmail.get())
    )
      return
    refusal.set(null)
    if (next === 'confirm-current') currentCode.set('')
    if (next === 'new-address') newEmail.set('')
    if (next === 'confirm-new') newCode.set('')
    standOn(flow)
  }

  /** Stand on the session's record and keep following it while the window is open. */
  function enterFlow(): void {
    standOn(record.get())
    following ??= subscribeSignal(record, follow)
  }

  /** Close the window here, leaving the flow as it is. */
  function finish(): void {
    round += 1
    step.set('closed')
    busy.set(false)
    refusal.set(null)
    stepUp.password.set('')
    stepUp.code.set('')
    following?.()
    following = null
  }

  /** End the flow for the whole session, then close on the server's word. */
  async function discard(): Promise<void> {
    const started = ++round
    busy.set(true)
    refusal.set(null)
    pending += 1
    try {
      await context.actions.dispatch(PROFILE_FLOW_CANCEL_ACTION, {
        operation: EMAIL_CHANGE_OPERATION,
      }).done
    } catch (error) {
      if (round !== started) return
      busy.set(false)
      refusal.set(
        error instanceof ActionError && error.outcome === 'fail'
          ? error.message
          : HILOS_PROFILE_EMAIL_CHANGE_COPY.unreached,
      )
      return
    } finally {
      pending -= 1
    }
    if (round === started) finish()
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
    if (verdict === 'skip') enterFlow()
    else step.set('step-up')
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
    asksBeforeClosing,
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
        if (confirmed) enterFlow()
        return
      }
      pending += 1
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
      } finally {
        pending -= 1
      }
      if (round !== started) return
      busy.set(false)
      if (current === 'confirm-new') {
        // The server stores the address lowercased; the outcome names what was set.
        now.set(newEmail.get().trim().toLowerCase())
        step.set('done')
        return
      }
      // The frame naming the new step arrived before this answer; a send the
      // server had nothing to stand on leaves the window where it was.
      standOn(record.get())
    },
    async again() {
      if (step.get() !== 'done') return
      await open(now.get())
    },
    close() {
      if (asksBeforeClosing.get() && record.get() !== null) void discard()
      else finish()
    },
    dispose: finish,
  }
}
