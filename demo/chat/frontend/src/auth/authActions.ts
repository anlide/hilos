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
import { ActionError } from '@hilos/core'
import type { AuthFormState, AuthMode, AuthSubmitOutcome } from '@hilos/core'

import { actions } from '../bootstrap/connection'

/** Backend action: email+password login (PHP `ChatSignalConstants::LOGIN`). */
const LOGIN_ACTION = 'login'

/** Backend action: email+password registration (PHP `ChatSignalConstants::REGISTER`). */
const REGISTER_ACTION = 'register'

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
      return dispatch(REGISTER_ACTION, {
        email: form.email,
        password: form.password,
        confirmPassword: form.confirmPassword,
      })
    case 'sms_request':
      // Success advances to the code step; the backend always answers generically
      // (a well-formed number issues a code whether or not it has an account).
      return dispatch(REQUEST_SMS_CODE_ACTION, { phone: form.phone }, 'sms_confirm')
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
 * Dispatch one tracked action and reduce its reply to a surface outcome.
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
    await actions.dispatch(action, payload).done

    return { ok: true, next }
  } catch (error) {
    return { ok: false, message: describeAuthError(error) }
  }
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
