// The profile page actions: the client-to-server submits the view fires. The
// rename is the chat's own (ChatSignalConstants::RENAME → RenameActionDTO
// {newName}); the new name only appears once the backend moderates and renames
// the user, never optimistically here. A rejected rename comes back as a
// framework action_error, handled by the core ActionErrorStore; success is
// state-driven — the committed name arrives over the self-connection data
// (profilePage.ts).
//
// The ways in and the email change are the framework's (HIL-1137): their names,
// payloads and the password-updated signal come from the core modules, and this
// file only adapts their handles to what the view reacts to.
import {
  ActionError,
  computedSignal,
  createHilosProfileEmailChangeActions,
  createHilosProfileSignInActions,
  createHilosStepUpActions,
  createHilosStepUpStep,
  createSignal,
  PROFILE_SET_PASSWORD_ACTION,
  PROFILE_UNLINK_IDENTITY_ACTION,
  SIGNAL_PROFILE_PASSWORD_UPDATED,
  type ActionHandle,
  type HilosProfilePasswordUpdated,
  type ProjectSignal,
  type ReadonlySignal,
  type WritableSignal,
} from '@hilos/core'

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

/** The framework's sign-in-method actions over the chat's connection. */
const signInActions = createHilosProfileSignInActions({ actions })

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

/** Set when the last unlink got no verdict, so the row spinner is released. */
const unlinkIdentityUnreached = createSignal<string | null>(null)

/** The latest unlink error reason, or null when clear: the server's refusal, or no verdict at all. */
export const unlinkIdentityError: ReadonlySignal<string | null> =
  computedSignal(
    () =>
      actionErrors.signal(PROFILE_UNLINK_IDENTITY_ACTION).get() ??
      unlinkIdentityUnreached.get(),
  )

/**
 * Submit an unlink of one login identity: clear any prior error and dispatch
 * the framework's unlink carrying the identity id. The row disappears only once
 * the backend deletes it and the identities projection re-emits, never
 * optimistically here; a refusal, or a submit that got no verdict, lands on
 * {@link unlinkIdentityError}.
 *
 * @param identityId The id of the identity to unlink.
 */
export function sendUnlinkIdentity(identityId: number): void {
  actionErrors.clear(PROFILE_UNLINK_IDENTITY_ACTION)
  unlinkIdentityUnreached.set(null)
  reportUnreached(
    signInActions.unlinkIdentity(identityId),
    unlinkIdentityUnreached,
  )
}

/** Set when the last set-password got no verdict, so the button is released. */
const setPasswordUnreached = createSignal<string | null>(null)

/** The latest set-password error reason, or null when clear: the server's refusal, or no verdict at all. */
export const setPasswordError: ReadonlySignal<string | null> = computedSignal(
  () =>
    actionErrors.signal(PROFILE_SET_PASSWORD_ACTION).get() ??
    setPasswordUnreached.get(),
)

/**
 * Submit an add or change of the current user's password: clear any prior error
 * and dispatch the framework's set-password. `currentPassword` is null on the add
 * flow (nothing to re-verify — the proven email is the authority) and the current
 * password on a change; the server decides add vs change from the user's
 * identities, never from this argument. Success is not optimistic — it arrives as
 * the {@link subscribePasswordUpdated} signal; a refusal, or a submit that got no
 * verdict, lands on {@link setPasswordError}.
 *
 * @param currentPassword The current password for a change, or null on the add flow.
 * @param newPassword The new password to set.
 */
export function sendSetPassword(
  currentPassword: string | null,
  newPassword: string,
): void {
  actionErrors.clear(PROFILE_SET_PASSWORD_ACTION)
  setPasswordUnreached.set(null)
  reportUnreached(
    signInActions.setPassword(currentPassword, newPassword),
    setPasswordUnreached,
  )
}

/** Clear the set-password error — the view does this when a form re-opens. */
export function clearSetPasswordError(): void {
  actionErrors.clear(PROFILE_SET_PASSWORD_ACTION)
  setPasswordUnreached.set(null)
}

/**
 * Step 1 of adding a phone identity: dispatch the framework's `profile_add_sms_request`
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
  return settleWizardStep(signInActions.requestSmsAdd(phone))
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
  return settleWizardStep(signInActions.confirmSmsAdd(phone, code))
}

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
  return settleWizardStep(signInActions.requestPasswordAdd(email))
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
  return settleWizardStep(
    signInActions.confirmPasswordAdd(email, code, newPassword),
  )
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

/**
 * Watch a fire-and-react submit for the failures the action-error store never
 * hears of. A refusal (`fail`) is already on the store under the action's name;
 * no connection or no reply in time is written to `unreached`, so the view that
 * waits on the error signal releases its spinner either way.
 *
 * @param handle The dispatched submit.
 * @param unreached Where a submit that got no verdict is reported.
 */
function reportUnreached(
  handle: ActionHandle,
  unreached: WritableSignal<string | null>,
): void {
  handle.done.catch((error: unknown) => {
    if (error instanceof ActionError && error.outcome === 'fail') {
      return
    }
    unreached.set(UNREACHED_MESSAGE)
  })
}

/**
 * Subscribe to the set-password success signal (HIL-402). Invokes the handler
 * once a `profile_password_updated` lands (fanned WS_USER to all the user's connections),
 * so the initiating tab clears its form and any tab can toast the change. Returns
 * an unsubscribe the view calls on unmount so a late signal cannot fire into a
 * torn-down component.
 *
 * @param handler Called with the success payload (its add/change mode).
 * @returns Unsubscribe for the registered signal handler.
 */
export function subscribePasswordUpdated(
  handler: (data: HilosProfilePasswordUpdated) => void,
): () => void {
  return connection.on('projectSignal', (signal: ProjectSignal) => {
    if (signal.type !== SIGNAL_PROFILE_PASSWORD_UPDATED) {
      return
    }
    handler(signal.data as HilosProfilePasswordUpdated)
  })
}
