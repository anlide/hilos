// The step machine of the profile email change (HIL-299), lifted out of the
// Profile view so the view keeps only the markup of the five steps. One modal walks
// them: a code to the current address, that code typed back, the new address, the
// code from the new address, and the outcome. Server-confirmed, never optimistic —
// a step advances on the backend `::success` only, and a refusal stays on the step
// with the typed values intact. The server keeps nothing between the steps: the
// current address's code, proven on step 2, is held here and sent again with steps
// 3 and 4, and it is spent only when the address moves.
import { computed, ref, type ComputedRef, type Ref } from 'vue'

import {
  sendEmailChangeCurrentConfirm,
  sendEmailChangeCurrentRequest,
  sendEmailChangeNewConfirm,
  sendEmailChangeNewRequest,
  type WizardStepOutcome,
} from './profileActions'

/** The five steps, in the order the modal walks them. */
export type EmailChangeStep = 1 | 2 | 3 | 4 | 5

/** The first step: a code to the address the account holds now. */
const STEP_SEND_CURRENT: EmailChangeStep = 1
/** The step that types back the current address's code. */
const STEP_CONFIRM_CURRENT: EmailChangeStep = 2
/** The step that names the new address. */
const STEP_NEW_ADDRESS: EmailChangeStep = 3
/** The step that proves the new address and moves the account. */
const STEP_CONFIRM_NEW: EmailChangeStep = 4
/** The outcome: what the address was and what it is now. */
const STEP_DONE: EmailChangeStep = 5

/**
 * The step a successful submit leads to.
 *
 * @param current The step that was just accepted.
 * @returns The step to show next.
 */
function stepAfter(current: EmailChangeStep): EmailChangeStep {
  switch (current) {
    case STEP_SEND_CURRENT:
      return STEP_CONFIRM_CURRENT
    case STEP_CONFIRM_CURRENT:
      return STEP_NEW_ADDRESS
    case STEP_NEW_ADDRESS:
      return STEP_CONFIRM_NEW
    default:
      return STEP_DONE
  }
}

/** The reactive state and handlers the email-change modal binds to. */
export interface EmailChange {
  /** Whether the modal is open (the HilosModal v-model). */
  open: Ref<boolean>
  /** The step on screen. */
  step: Ref<EmailChangeStep>
  /** The address the account held when the flow started. */
  was: Ref<string>
  /** The code from the current address, typed on step 2 and carried after it. */
  currentCode: Ref<string>
  /** The new address, typed on step 3. */
  newEmail: Ref<string>
  /** The code from the new address, typed on step 4. */
  newCode: Ref<string>
  /** The address the account holds after step 4, as the outcome shows it. */
  now: Ref<string>
  /** The refusal of the current step, or null. */
  error: Ref<string | null>
  /** True while a step's submit waits for the server. */
  loading: Ref<boolean>
  /** Whether the step's own field is filled enough to submit. */
  canSubmit: ComputedRef<boolean>
  /** Whether closing asks first: a code is already out on steps 2 to 4. */
  asksBeforeClosing: ComputedRef<boolean>
  /** Open the modal on step 1 for the given current address. */
  start: (address: string) => void
  /** Submit the step on screen. */
  submit: () => Promise<void>
  /** Walk the flow again from step 1, the address just set now being the current one. */
  again: () => void
}

/**
 * Build the email-change step machine the Profile view binds its modal to.
 *
 * @returns The modal's state and handlers.
 */
export function useEmailChange(): EmailChange {
  const open = ref(false)
  const step = ref<EmailChangeStep>(STEP_SEND_CURRENT)
  const was = ref('')
  const currentCode = ref('')
  const newEmail = ref('')
  const newCode = ref('')
  const now = ref('')
  const error = ref<string | null>(null)
  const loading = ref(false)

  const canSubmit = computed(() => {
    switch (step.value) {
      case STEP_CONFIRM_CURRENT:
        return currentCode.value.trim() !== ''
      case STEP_NEW_ADDRESS:
        return newEmail.value.trim() !== ''
      case STEP_CONFIRM_NEW:
        return newCode.value.trim() !== ''
      default:
        return true
    }
  })

  const asksBeforeClosing = computed(
    () => step.value > STEP_SEND_CURRENT && step.value < STEP_DONE,
  )

  function start(address: string): void {
    step.value = STEP_SEND_CURRENT
    was.value = address
    currentCode.value = ''
    newEmail.value = ''
    newCode.value = ''
    now.value = ''
    error.value = null
    loading.value = false
    open.value = true
  }

  /**
   * Send the step on screen to the server.
   *
   * @returns The server's verdict on the step.
   */
  function dispatchStep(): Promise<WizardStepOutcome> {
    switch (step.value) {
      case STEP_CONFIRM_CURRENT:
        return sendEmailChangeCurrentConfirm(currentCode.value.trim())
      case STEP_NEW_ADDRESS:
        return sendEmailChangeNewRequest(
          currentCode.value.trim(),
          newEmail.value.trim(),
        )
      case STEP_CONFIRM_NEW:
        return sendEmailChangeNewConfirm(
          currentCode.value.trim(),
          newEmail.value.trim(),
          newCode.value.trim(),
        )
      default:
        return sendEmailChangeCurrentRequest()
    }
  }

  async function submit(): Promise<void> {
    if (step.value === STEP_DONE || !canSubmit.value || loading.value) {
      return
    }
    loading.value = true
    error.value = null
    const outcome = await dispatchStep()
    loading.value = false
    if (!outcome.ok) {
      error.value = outcome.message

      return
    }
    if (step.value === STEP_CONFIRM_NEW) {
      // The server stores the address lowercased; the outcome names what was set.
      now.value = newEmail.value.trim().toLowerCase()
    }
    step.value = stepAfter(step.value)
  }

  function again(): void {
    start(now.value)
  }

  return {
    open,
    step,
    was,
    currentCode,
    newEmail,
    newCode,
    now,
    error,
    loading,
    canSubmit,
    asksBeforeClosing,
    start,
    submit,
    again,
  }
}
