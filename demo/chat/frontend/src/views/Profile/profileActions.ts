// The profile page actions: the client-to-server submits the view fires. The
// rename is the chat's own (ChatSignalConstants::RENAME → RenameActionDTO
// {newName}); the new name only appears once the backend moderates and renames
// the user, never optimistically here. A rejected rename comes back as a
// framework action_error, handled by the core ActionErrorStore; success is
// state-driven — the committed name arrives over the self-connection data
// (profilePage.ts).
//
// The email-change commands are the framework's; this module adapts their handles
// to the chat's existing dialog.
import {
  ActionError,
  createHilosProfileEmailChangeActions,
  createHilosStepUpActions,
  createHilosStepUpStep,
  type ActionHandle,
  type ReadonlySignal,
} from '@hilos/core'

import {
  actionErrors,
  actions,
  connection,
} from '../../bootstrap/connection.js'

/** Reusable protected-operation confirmation step shared by the profile dialogs. */
export const profileStepUp = createHilosStepUpStep(
  createHilosStepUpActions(actions),
)

/** The outcome of a profile two-step wizard step: ok, plus the inline reason on failure. */
export interface WizardStepOutcome {
  /** True on the backend `::success`; false on any rejection. */
  readonly ok: boolean
  /** The inline error to show on failure, or null on success. */
  readonly message: string | null
}

/** The framework's email-change actions over the chat's connection. */
const emailChangeActions = createHilosProfileEmailChangeActions({ actions })

/** What the view says when a submit got no verdict: no connection, or no reply in time. */
const UNREACHED_MESSAGE = 'Could not reach the server. Please try again.'

/** Backend action name routed to the users library (PHP `ChatSignalConstants::RENAME`). */
const RENAME_ACTION = 'rename'

/** The latest rename error reason, or null when clear (framework action_error). */
export const renameError: ReadonlySignal<string | null> =
  actionErrors.signal(RENAME_ACTION)

/**
 * Submit a display-name change: clear any prior error and send the `rename`
 * action carrying the new name. Returns false, sending nothing, when the
 * connection is not `connected`.
 *
 * @param newName The requested display name.
 */
export function sendRename(newName: string): boolean {
  actionErrors.clear(RENAME_ACTION)

  return connection.sendAction(RENAME_ACTION, { newName })
}

/** Clear the rename error — the view does this when opening the edit modal. */
export function clearRenameError(): void {
  actionErrors.clear(RENAME_ACTION)
}

/**
 * Step 1 of changing the account email: ask for a code to the address the account
 * holds now (HIL-299). The payload is empty on purpose — the server reads the address
 * from the account. `ok` means "advance to the code step"; a repeat pressed inside the
 * resend cooldown is a silent success, while the send cap rejects with its reason.
 */
export async function sendEmailChangeCurrentRequest(): Promise<WizardStepOutcome> {
  return settleWizardStep(emailChangeActions.requestCurrentCode())
}

/**
 * Step 2 of changing the account email: check the code from the current address
 * (HIL-299). The server does not spend it — the modal keeps it and sends it again
 * with steps 3 and 4 as the proof that this flow already answered for the mailbox.
 *
 * @param code The code the current address received.
 */
export async function sendEmailChangeCurrentConfirm(
  code: string,
): Promise<WizardStepOutcome> {
  return settleWizardStep(emailChangeActions.confirmCurrentCode(code))
}

/**
 * Step 3 of changing the account email: ask for a code to the new address (HIL-299).
 * A malformed address, the account's own, another account's, or a proof that died
 * meanwhile rejects with its inline reason and mails nothing.
 *
 * @param currentCode The current address's code proven on step 2.
 * @param email The new address.
 */
export async function sendEmailChangeNewRequest(
  currentCode: string,
  email: string,
): Promise<WizardStepOutcome> {
  return settleWizardStep(emailChangeActions.requestNewCode(currentCode, email))
}

/**
 * Step 4 of changing the account email: prove the new address and move the account
 * onto it (HIL-299). On `::success` the address has moved and the identities
 * projection re-emits it to every tab; a wrong code keeps the proof alive for a
 * retry, and a lost race asks to start again.
 *
 * @param currentCode The current address's code proven on step 2.
 * @param email The new address.
 * @param code The code the new address received.
 */
export async function sendEmailChangeNewConfirm(
  currentCode: string,
  email: string,
  code: string,
): Promise<WizardStepOutcome> {
  return settleWizardStep(
    emailChangeActions.confirmNewCode(currentCode, email, code),
  )
}

/**
 * Reduce one dispatched wizard step to a {@link WizardStepOutcome}: ok on
 * `::success`, else the backend reason for a real rejection and a generic
 * phrasing for a timeout or dropped connection.
 *
 * @param handle The dispatched step.
 */
async function settleWizardStep(
  handle: ActionHandle,
): Promise<WizardStepOutcome> {
  try {
    await handle.done

    return { ok: true, message: null }
  } catch (error) {
    const message =
      error instanceof ActionError && error.outcome === 'fail'
        ? error.message
        : UNREACHED_MESSAGE

    return { ok: false, message }
  }
}
