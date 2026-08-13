// The auth surface's client-to-server submits: map the active mode to its backend
// action and dispatch it over the request-correlated action lifecycle (core
// ActionLifecycle), returning the outcome the surface state machine (HIL-364)
// renders. Unlike a generic tracked action, auth surfaces the backend reason: the
// login "Invalid email or password" IS the intended generic message, and register
// legitimately reveals a taken email — so a failure maps the ActionError reason to
// the inline message, except for a timeout/disconnect where the reason is a
// framework string and a generic phrasing is shown instead.
//
// login → ChatSignalConstants::LOGIN, register → ChatSignalConstants::REGISTER
// (both routed by MainPage). Success needs no return payload: the backend upgrades
// the session and the auth gate (HIL-165) closes the surface off the current-user
// signal. Recovery is deferred to HIL-365 (no backend action yet), so its modes
// report unavailable rather than dispatching a non-existent action.
//
// The registration actions are the exception, and the reason this file reads
// replies at all (HIL-415): a register submit no longer creates anybody, so the
// backend answers where the surface goes next as a domain reply on the success ack
// (PHP `Hilos\Auth\Flow\AuthFlowOutcome`). A REJECTED registration rides that same
// ack — a taken address is a move to sign-in, not a transport error — so a reply
// saying `ok: false` has to be read off a resolved dispatch, or the surface would
// step to the code screen for an address it never reserved.
import { ActionError } from '@hilos/core'
import type { AuthFormState, AuthMode, AuthSubmitOutcome } from '@hilos/core'

import { actions } from '../bootstrap/connection'
import { runPasskeyLogin } from './passkeyCeremony'

/** Backend action: email+password login (PHP `ChatSignalConstants::LOGIN`). */
const LOGIN_ACTION = 'login'

/** Backend action: email+password registration (PHP `ChatSignalConstants::REGISTER`). */
const REGISTER_ACTION = 'register'

/** Backend action: confirm a reserved registration (PHP `ChatSignalConstants::CONFIRM_REGISTER`). */
const CONFIRM_REGISTER_ACTION = 'confirm_register'

/** Backend action: re-send a pending registration's code (PHP `ChatSignalConstants::REQUEST_REGISTER_CONFIRM`). */
const REQUEST_REGISTER_CONFIRM_ACTION = 'request_register_confirm'

/** Backend action: request an SMS login code (PHP `ChatSignalConstants::REQUEST_SMS_CODE`). */
const REQUEST_SMS_CODE_ACTION = 'request_sms_code'

/** Backend action: submit an SMS login code (PHP `ChatSignalConstants::CONFIRM_SMS_CODE`). */
const CONFIRM_SMS_CODE_ACTION = 'confirm_sms_code'

/** Backend action: request an email magic-link (PHP `ChatSignalConstants::REQUEST_MAGIC_LINK`). */
const REQUEST_MAGIC_LINK_ACTION = 'request_magic_link'

/** Backend action: submit an email magic-link token (PHP `ChatSignalConstants::CONFIRM_MAGIC_LINK`). */
const CONFIRM_MAGIC_LINK_ACTION = 'confirm_magic_link'

/**
 * Dispatch the active mode's submit and resolve the surface outcome. Login and
 * register dispatch their action and resolve `ok` on the correlated `::success`;
 * a failure carries the backend reason. Recovery modes have no backend yet
 * (HIL-365) and resolve an unavailable message.
 *
 * @param mode The mode being submitted.
 * @param form The current form values.
 */
export async function submitAuth(
  mode: AuthMode,
  form: AuthFormState,
): Promise<AuthSubmitOutcome> {
  switch (mode) {
    case 'login':
      return dispatch(LOGIN_ACTION, {
        email: form.email,
        password: form.password,
      })
    case 'register':
      // Reserve-on-submit (HIL-415): this holds the address and mails one code,
      // so success advances to the code step rather than upgrading the session.
      // The password rides along and is kept with the hold until the code
      // redeems it, which is why it is not asked for again on the next step.
      return dispatch(
        REGISTER_ACTION,
        { email: form.email, password: form.password },
        'register_confirm',
      )
    case 'register_confirm':
      // The code is what creates the account: on success the session upgrades and
      // the auth gate closes the surface, so no next mode. A wrong code is a plain
      // action failure and leaves the person on this step to try again.
      return dispatch(CONFIRM_REGISTER_ACTION, {
        email: form.email,
        code: form.code,
      })
    case 'sms_request':
      // Success advances to the code step; the backend always answers generically
      // (a well-formed number issues a code whether or not it has an account).
      return dispatch(
        REQUEST_SMS_CODE_ACTION,
        { phone: form.phone },
        'sms_confirm',
      )
    case 'sms_confirm':
      // Success upgrades the session (find-or-create by phone) and the auth gate
      // closes the surface off the current-user signal, so no next mode.
      return dispatch(CONFIRM_SMS_CODE_ACTION, {
        phone: form.phone,
        code: form.code,
      })
    case 'magic_link_request':
      // Success does not advance a mode: the confirm is a clicked email link
      // handled on the /auth/magic route, not a form step. The backend always
      // answers generically (login-only, anti-enumeration), so the view shows a
      // "check your email" acknowledgement on the ok outcome.
      return dispatch(REQUEST_MAGIC_LINK_ACTION, { email: form.email })
    case 'passkey':
      // Username-first passkey login: the whole options → assertion → confirm
      // round-trip runs in the ceremony driver (passkeyCeremony); success upgrades
      // the session (HIL-161) and the auth gate closes the surface, so no next mode.
      return runPasskeyLogin(form.email)
    case 'recovery_request':
    case 'recovery_confirm':
    case 'recovery_set':
    case 'done':
      // Recovery backend is HIL-365, not yet landed; the path stays reachable
      // (the switcher entry) but its submit reports unavailable for now.
      return { ok: false, message: 'Password recovery is not available yet.' }
  }
}

/**
 * Re-send the confirmation code of a pending registration.
 *
 * The code step's resend link, dispatched outside the surface machine because it
 * is not the step's submit — the form still holds the code being typed. The hold
 * on the address decides whether anything is re-sent: inside the cooldown the
 * backend answers ok and mails nothing, and a hold that ran out answers a failure
 * whose message the caller shows inline. Drawing the countdown is HIL-423.
 *
 * @param email The address the pending registration holds.
 */
export function resendRegisterCode(email: string): Promise<AuthSubmitOutcome> {
  return dispatch(REQUEST_REGISTER_CONFIRM_ACTION, { email })
}

/**
 * Dispatch one tracked action and reduce its reply to a surface outcome.
 *
 * A resolved dispatch is read before it is called a success: the registration
 * actions answer a refusal ON the success ack, so a reply saying `ok: false` is a
 * failure with a sentence and no advance. An action that answers with nothing —
 * every other one here — has no reply to read and resolves as the success it is.
 *
 * @param action The backend action name.
 * @param payload The action payload.
 * @param next The mode to advance to on success (multi-step flows); omit to stay.
 */
async function dispatch(
  action: string,
  payload: Record<string, string>,
  next?: AuthMode,
): Promise<AuthSubmitOutcome> {
  try {
    const result = await actions.dispatch(action, payload).done

    return readFlowRefusal(result.reply) ?? { ok: true, next }
  } catch (error) {
    return { ok: false, message: describeAuthError(error) }
  }
}

/**
 * Read a refusal out of a flow reply, or null when the reply is not one.
 *
 * The reply is `unknown` (no schema is passed to the dispatch), so the two keys
 * this surface acts on are read defensively: an absent or malformed reply means
 * the action simply answered nothing and the dispatch stands as the success it
 * already is. The step the backend names alongside it (`next`) is not applied
 * here — rolling the surface back to the address field belongs to the redesigned
 * screens (HIL-423); until then a refusal shows inline where it happened.
 *
 * @param reply The domain reply the action ack carried.
 */
function readFlowRefusal(reply: unknown): AuthSubmitOutcome | null {
  if (typeof reply !== 'object' || reply === null || !('ok' in reply)) {
    return null
  }

  const { ok, message } = reply as { ok: unknown; message?: unknown }
  if (ok !== false) {
    return null
  }

  // No message of its own falls through to the machine's generic phrasing.
  return typeof message === 'string' ? { ok: false, message } : { ok: false }
}

/**
 * Relay a magic-link token over the live connection for the /auth/magic route.
 *
 * The email link opens the static route; this dispatches the confirm the surface
 * itself never sends (its magic-link entry is request-only), resolving `ok` when
 * the backend upgrades the session — the auth gate then closes any sign-in shown
 * — and mapping the generic failure otherwise.
 *
 * @param email The account email carried in the link.
 * @param token The one-time sign-in token carried in the link.
 */
export function confirmMagicLink(
  email: string,
  token: string,
): Promise<AuthSubmitOutcome> {
  return dispatch(CONFIRM_MAGIC_LINK_ACTION, { email, token })
}

/**
 * Map a failed auth action to an inline message: the backend reason for a real
 * rejection, a generic phrasing for a timeout or a dropped connection.
 *
 * @param error The caught failure from the action lifecycle.
 */
function describeAuthError(error: unknown): string {
  if (error instanceof ActionError && error.outcome === 'fail') {
    return error.message
  }

  return 'Could not reach the server. Please try again.'
}
