// The profile's email change (HIL-299, HIL-1137): the four actions of one
// surface. Framework-agnostic — the window and its steps are the project's, this
// module is only the wire.
//
// The server keeps nothing between the steps: the current address's code, proven
// on step 2, is carried by the surface into steps 3 and 4 as the proof. Every
// step first asks the operation's confirmation (HIL-495). None answers with a
// reply; the moved address arrives in the identities projection.
import {
  type ActionHandle,
  type ActionLifecycle,
} from '../connection/actionLifecycle.js'

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
