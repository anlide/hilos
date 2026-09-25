// The profile page actions: the client-to-server submits the view fires. The
// rename mirrors the backend ProfilePage ACTIONS entry (ChatSignalConstants::RENAME
// → RenameActionDTO {newName}); the new name only appears once the backend
// moderates and renames the user, never optimistically here. A rejected rename
// comes back as a framework action_error (the page no longer sends a bespoke
// ack), handled by the core ActionErrorStore; success is state-driven — the
// committed name arrives over the self-connection data (profilePage.ts).
import {
  ActionError,
  createHilosStepUpActions,
  createHilosStepUpStep,
  type ProjectSignal,
  type ReadonlySignal,
} from '@hilos/core'

import {
  PASSWORD_UPDATED_SIGNAL,
  passwordUpdatedSignalSchema,
  type PasswordUpdatedSignalData,
} from '../../auth/passwordSignals'
import { actionErrors, actions, connection } from '../../bootstrap/connection'

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

/** Backend action name routed by ProfilePage (PHP `ChatSignalConstants::RENAME`). */
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

/** Backend action name routed by ProfilePage (PHP `ChatSignalConstants::UNLINK_IDENTITY`). */
const UNLINK_IDENTITY_ACTION = 'unlink_identity'

/** The latest unlink error reason, or null when clear (framework action_error). */
export const unlinkIdentityError: ReadonlySignal<string | null> =
  actionErrors.signal(UNLINK_IDENTITY_ACTION)

/**
 * Submit an unlink of one login identity: clear any prior error and send the
 * `unlink_identity` action carrying the identity id. The row disappears only
 * once the backend deletes it and the identities projection re-emits, never
 * optimistically here. Returns false, sending nothing, when the connection is
 * not `connected`.
 *
 * @param identityId The id of the identity to unlink.
 */
export function sendUnlinkIdentity(identityId: number): boolean {
  actionErrors.clear(UNLINK_IDENTITY_ACTION)

  return connection.sendAction(UNLINK_IDENTITY_ACTION, { identityId })
}

/** Backend action name routed by ProfilePage (PHP `ChatSignalConstants::SET_PASSWORD`). */
const SET_PASSWORD_ACTION = 'set_password'

/** The latest set-password error reason, or null when clear (framework action_error). */
export const setPasswordError: ReadonlySignal<string | null> =
  actionErrors.signal(SET_PASSWORD_ACTION)

/**
 * Submit an add or change of the current user's password: clear any prior error
 * and send the `set_password` action. `currentPassword` is null on the add flow
 * (nothing to re-verify — the proven email is the authority) and the current
 * password on a change; the server decides add vs change from the user's
 * identities, never from this argument. Success is not optimistic — it arrives as
 * the {@link subscribePasswordUpdated} signal; a rejection is a framework
 * action_error on {@link setPasswordError}. Returns false, sending nothing, when
 * the connection is not `connected`.
 *
 * @param currentPassword The current password for a change, or null on the add flow.
 * @param newPassword The new password to set.
 */
export function sendSetPassword(
  currentPassword: string | null,
  newPassword: string,
): boolean {
  actionErrors.clear(SET_PASSWORD_ACTION)

  return connection.sendAction(SET_PASSWORD_ACTION, {
    currentPassword: currentPassword ?? '',
    newPassword,
  })
}

/** Clear the set-password error — the view does this when a form re-opens. */
export function clearSetPasswordError(): void {
  actionErrors.clear(SET_PASSWORD_ACTION)
}

/** Backend action name routed by ProfilePage (PHP `ChatSignalConstants::ADD_SMS_REQUEST`). */
const ADD_SMS_REQUEST_ACTION = 'profile_add_sms_request'

/** Backend action name routed by ProfilePage (PHP `ChatSignalConstants::ADD_SMS_CONFIRM`). */
const ADD_SMS_CONFIRM_ACTION = 'profile_add_sms_confirm'

/**
 * Step 1 of adding a phone identity: dispatch the `profile_add_sms_request` action
 * over the request-correlated action lifecycle and resolve its outcome. Unlike the
 * fire-and-forget profile actions, the add-phone wizard advances a step on the
 * backend `::success` (there is no bespoke success signal and nothing changes in
 * the identity projection yet), so it needs the correlated ack — the same
 * mechanism the auth SMS-login wizard uses. The backend always answers generically
 * (a well-formed number issues a code whether or not the resend cooldown suppresses
 * a duplicate), so `ok` means "advance to the code step". A malformed number
 * rejects with its inline reason.
 *
 * @param phone The phone number to attach.
 */
export async function sendAddSmsRequest(
  phone: string,
): Promise<WizardStepOutcome> {
  return dispatchWizardStep(ADD_SMS_REQUEST_ACTION, { phone })
}

/**
 * Step 2 of adding a phone identity: dispatch the `profile_add_sms_confirm` action
 * and resolve its outcome. On `::success` the verified `sms` identity is attached
 * server-side and arrives over the identities projection re-emit (no optimistic
 * row here); a wrong/expired code or a phone already in use rejects with its inline
 * reason.
 *
 * @param phone The phone number to attach.
 * @param code The delivered verification code.
 */
export async function sendAddSmsConfirm(
  phone: string,
  code: string,
): Promise<WizardStepOutcome> {
  return dispatchWizardStep(ADD_SMS_CONFIRM_ACTION, { phone, code })
}

/** Backend action name routed by ProfilePage (PHP `ChatSignalConstants::ADD_PASSWORD_REQUEST`). */
const ADD_PASSWORD_REQUEST_ACTION = 'profile_add_password_request'

/** Backend action name routed by ProfilePage (PHP `ChatSignalConstants::ADD_PASSWORD_CONFIRM`). */
const ADD_PASSWORD_CONFIRM_ACTION = 'profile_add_password_confirm'

/**
 * Step 1 of adding a password to a signed-in user with no verified email: dispatch
 * the `profile_add_password_request` action over the request-correlated action
 * lifecycle and resolve its outcome (HIL-406). Like the add-phone step it needs the
 * correlated ack — there is no bespoke success signal and nothing changes in the
 * identity projection yet, so `ok` means "advance to the code step". Unlike the
 * add-phone step the backend can reject here: a malformed email, or an email already
 * verified by another account, comes back with its inline reason.
 *
 * @param email The email to prove and key the new password on.
 */
export async function sendAddPasswordRequest(
  email: string,
): Promise<WizardStepOutcome> {
  return dispatchWizardStep(ADD_PASSWORD_REQUEST_ACTION, { email })
}

/**
 * Step 2 of adding a password: dispatch the `profile_add_password_confirm` action
 * and resolve its outcome (HIL-406). On `::success` the verified `password` identity
 * is written server-side and the reused {@link subscribePasswordUpdated} signal
 * fires (clearing the form and flipping the section to change-mode); a weak password,
 * a wrong/expired code, or an email that raced into use rejects with its inline
 * reason.
 *
 * @param email The email being proven.
 * @param code The delivered verification code.
 * @param newPassword The new password to set.
 */
export async function sendAddPasswordConfirm(
  email: string,
  code: string,
  newPassword: string,
): Promise<WizardStepOutcome> {
  return dispatchWizardStep(ADD_PASSWORD_CONFIRM_ACTION, {
    email,
    code,
    newPassword,
  })
}

/** Backend action name routed to the users library (PHP `ChatSignalConstants::CHANGE_EMAIL_CURRENT_REQUEST`). */
const CHANGE_EMAIL_CURRENT_REQUEST_ACTION =
  'profile_change_email_current_request'

/** Backend action name routed to the users library (PHP `ChatSignalConstants::CHANGE_EMAIL_CURRENT_CONFIRM`). */
const CHANGE_EMAIL_CURRENT_CONFIRM_ACTION =
  'profile_change_email_current_confirm'

/** Backend action name routed to the users library (PHP `ChatSignalConstants::CHANGE_EMAIL_NEW_REQUEST`). */
const CHANGE_EMAIL_NEW_REQUEST_ACTION = 'profile_change_email_new_request'

/** Backend action name routed to the users library (PHP `ChatSignalConstants::CHANGE_EMAIL_NEW_CONFIRM`). */
const CHANGE_EMAIL_NEW_CONFIRM_ACTION = 'profile_change_email_new_confirm'

/**
 * Step 1 of changing the account email: ask for a code to the address the account
 * holds now (HIL-299). The payload is empty on purpose — the server reads the address
 * from the account. `ok` means "advance to the code step"; a repeat pressed inside the
 * resend cooldown is a silent success, while the send cap rejects with its reason.
 */
export async function sendEmailChangeCurrentRequest(): Promise<WizardStepOutcome> {
  return dispatchWizardStep(CHANGE_EMAIL_CURRENT_REQUEST_ACTION, {})
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
  return dispatchWizardStep(CHANGE_EMAIL_CURRENT_CONFIRM_ACTION, { code })
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
  return dispatchWizardStep(CHANGE_EMAIL_NEW_REQUEST_ACTION, {
    currentCode,
    email,
  })
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
  return dispatchWizardStep(CHANGE_EMAIL_NEW_CONFIRM_ACTION, {
    currentCode,
    email,
    code,
  })
}

/**
 * Dispatch one two-step wizard action and reduce its reply to a
 * {@link WizardStepOutcome}: ok on `::success`, else the backend reason for a real
 * rejection and a generic phrasing for a timeout or dropped connection.
 *
 * @param action The backend action name.
 * @param payload The action payload.
 */
async function dispatchWizardStep(
  action: string,
  payload: Record<string, string>,
): Promise<WizardStepOutcome> {
  try {
    await actions.dispatch(action, payload).done

    return { ok: true, message: null }
  } catch (error) {
    const message =
      error instanceof ActionError && error.outcome === 'fail'
        ? error.message
        : 'Could not reach the server. Please try again.'

    return { ok: false, message }
  }
}

/**
 * Subscribe to the set-password success signal (HIL-402). Invokes the handler
 * once a `password_updated` lands (fanned WS_USER to all the user's connections),
 * so the initiating tab clears its form and any tab can toast the change. Returns
 * an unsubscribe the view calls on unmount so a late signal cannot fire into a
 * torn-down component.
 *
 * @param handler Called with the success payload (its add/change mode).
 * @returns Unsubscribe for the registered signal handler.
 */
export function subscribePasswordUpdated(
  handler: (data: PasswordUpdatedSignalData) => void,
): () => void {
  return connection.on('projectSignal', (signal: ProjectSignal) => {
    if (signal.type !== PASSWORD_UPDATED_SIGNAL) {
      return
    }
    handler(signal.data as ReturnType<typeof passwordUpdatedSignalSchema.parse>)
  })
}
