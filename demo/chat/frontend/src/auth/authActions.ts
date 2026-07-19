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
 */
async function dispatch(
  action: string,
  payload: Record<string, string>,
): Promise<AuthSubmitOutcome> {
  try {
    await actions.dispatch(action, payload).done

    return { ok: true }
  } catch (error) {
    return { ok: false, message: describeAuthError(error) }
  }
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
