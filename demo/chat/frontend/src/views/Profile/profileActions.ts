// The profile page actions: the client-to-server submits the view fires. The
// rename mirrors the backend ProfilePage ACTIONS entry (ChatSignalConstants::RENAME
// → RenameActionDTO {newName}); the new name only appears once the backend
// moderates and renames the user, never optimistically here. A rejected rename
// comes back as a framework action_error (the page no longer sends a bespoke
// ack), handled by the core ActionErrorStore; success is state-driven — the
// committed name arrives over the self-connection data (profilePage.ts).
import { ActionError, type ProjectSignal, type ReadonlySignal } from '@hilos/core'

import {
  PASSWORD_UPDATED_SIGNAL,
  passwordUpdatedSignalSchema,
  type PasswordUpdatedSignalData,
} from '../../auth/passwordSignals'
import { actionErrors, actions, connection } from '../../bootstrap/connection'

/** The outcome of an add-phone wizard step: ok, plus the inline reason on failure. */
export interface AddSmsOutcome {
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
export async function sendAddSmsRequest(phone: string): Promise<AddSmsOutcome> {
  return dispatchAddSms(ADD_SMS_REQUEST_ACTION, { phone })
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
): Promise<AddSmsOutcome> {
  return dispatchAddSms(ADD_SMS_CONFIRM_ACTION, { phone, code })
}

/**
 * Dispatch one add-phone action and reduce its reply to an {@link AddSmsOutcome}:
 * ok on `::success`, else the backend reason for a real rejection and a generic
 * phrasing for a timeout or dropped connection.
 *
 * @param action The backend action name.
 * @param payload The action payload.
 */
async function dispatchAddSms(
  action: string,
  payload: Record<string, string>,
): Promise<AddSmsOutcome> {
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
