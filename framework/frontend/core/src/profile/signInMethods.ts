// The profile's own ways in (HIL-1137): the actions that change a password, add
// a phone or a password by mail, and take a way in off, and the frame that tells
// every tab of the person a password was added or changed. Framework-agnostic —
// the windows and their steps are the project's, this module is only the wire.
//
// None of the actions answers with a reply: a way in that was added or taken off
// arrives in the identities projection, and a password, which nothing projects,
// arrives as `profile_password_updated` to every tab of the person. A refusal is
// an ordinary action error.
import { z } from 'zod'

import {
  type ActionHandle,
  type ActionLifecycle,
} from '../connection/actionLifecycle.js'

/**
 * Client→server: change the password with the current one, or add one to the
 * confirmed address (PHP `HilosSignalConstants::PROFILE_SET_PASSWORD`).
 */
export const PROFILE_SET_PASSWORD_ACTION = 'profile_set_password'

/** Client→server: take one way in off (PHP `HilosSignalConstants::PROFILE_UNLINK_IDENTITY`). */
export const PROFILE_UNLINK_IDENTITY_ACTION = 'profile_unlink_identity'

/** Client→server: send a code to a phone (PHP `HilosSignalConstants::PROFILE_ADD_SMS_REQUEST`). */
export const PROFILE_ADD_SMS_REQUEST_ACTION = 'profile_add_sms_request'

/** Client→server: the code that phone received (PHP `HilosSignalConstants::PROFILE_ADD_SMS_CONFIRM`). */
export const PROFILE_ADD_SMS_CONFIRM_ACTION = 'profile_add_sms_confirm'

/**
 * Client→server: send a code to the address a password is added on (PHP
 * `HilosSignalConstants::PROFILE_ADD_PASSWORD_REQUEST`).
 */
export const PROFILE_ADD_PASSWORD_REQUEST_ACTION =
  'profile_add_password_request'

/**
 * Client→server: the code that address received and the new password (PHP
 * `HilosSignalConstants::PROFILE_ADD_PASSWORD_CONFIRM`).
 */
export const PROFILE_ADD_PASSWORD_CONFIRM_ACTION =
  'profile_add_password_confirm'

/**
 * Server→client (WS_USER, every tab of the person): the password was added or
 * changed (PHP `HilosSignalConstants::PROFILE_PASSWORD_UPDATED`).
 */
export const SIGNAL_PROFILE_PASSWORD_UPDATED = 'profile_password_updated'

/** `mode` of a first password (PHP `ProfilePasswordUpdatedSignalData::MODE_ADDED`). */
export const PROFILE_PASSWORD_MODE_ADDED = 'added'

/** `mode` of a changed password (PHP `ProfilePasswordUpdatedSignalData::MODE_CHANGED`). */
export const PROFILE_PASSWORD_MODE_CHANGED = 'changed'

/**
 * The password-updated payload: whether the password was added or changed, so a
 * surface can word its confirmation. Any unknown mode reads as "changed".
 */
export const profilePasswordUpdatedSchema = z.object({
  mode: z
    .enum([PROFILE_PASSWORD_MODE_ADDED, PROFILE_PASSWORD_MODE_CHANGED])
    .catch(PROFILE_PASSWORD_MODE_CHANGED),
})

/** The password-updated payload, as the parse boundary hands it on. */
export type HilosProfilePasswordUpdated = z.infer<
  typeof profilePasswordUpdatedSchema
>

/**
 * The password-updated schema keyed for a connection's `projectSchemas`, so the
 * parse boundary validates the frame before a surface reacts to it.
 * {@link createHilosConnection} merges it in.
 */
export const PROFILE_PASSWORD_SIGNAL_SCHEMAS = {
  [SIGNAL_PROFILE_PASSWORD_UPDATED]: profilePasswordUpdatedSchema,
}

/** The action lifecycle the profile's sign-in-method actions dispatch through. */
export interface HilosProfileSignInActionContext {
  readonly actions: ActionLifecycle
}

/** The profile's sign-in-method actions. */
export interface HilosProfileSignInActions {
  /**
   * Change the password, or add one to the confirmed address. The server
   * chooses from the account's own ways in, never from this argument.
   *
   * @param currentPassword The current password for a change, or null when adding.
   * @param newPassword The new password.
   */
  setPassword(currentPassword: string | null, newPassword: string): ActionHandle
  /**
   * Take one way in off the account; the last one stays.
   *
   * @param identityId The id of the way in.
   */
  unlinkIdentity(identityId: number): ActionHandle
  /**
   * Send a code to a phone the person wants to add.
   *
   * @param phone The phone number, in any common formatting.
   */
  requestSmsAdd(phone: string): ActionHandle
  /**
   * Add the phone with the code it received.
   *
   * @param phone The phone number the code was sent to.
   * @param code The code the phone received.
   */
  confirmSmsAdd(phone: string, code: string): ActionHandle
  /**
   * Send a code to the address a password is to be added on.
   *
   * @param email The address to prove.
   */
  requestPasswordAdd(email: string): ActionHandle
  /**
   * Add the password on the proven address.
   *
   * @param email The address the code was sent to.
   * @param code The code the address received.
   * @param newPassword The new password.
   */
  confirmPasswordAdd(
    email: string,
    code: string,
    newPassword: string,
  ): ActionHandle
}

/**
 * Build the profile's sign-in-method actions over one connection lifecycle.
 *
 * @param context The action lifecycle to dispatch through.
 */
export function createHilosProfileSignInActions(
  context: HilosProfileSignInActionContext,
): HilosProfileSignInActions {
  return {
    setPassword(currentPassword, newPassword) {
      return context.actions.dispatch(PROFILE_SET_PASSWORD_ACTION, {
        currentPassword: currentPassword ?? '',
        newPassword,
      })
    },
    unlinkIdentity(identityId) {
      return context.actions.dispatch(PROFILE_UNLINK_IDENTITY_ACTION, {
        identityId,
      })
    },
    requestSmsAdd(phone) {
      return context.actions.dispatch(PROFILE_ADD_SMS_REQUEST_ACTION, { phone })
    },
    confirmSmsAdd(phone, code) {
      return context.actions.dispatch(PROFILE_ADD_SMS_CONFIRM_ACTION, {
        phone,
        code,
      })
    },
    requestPasswordAdd(email) {
      return context.actions.dispatch(PROFILE_ADD_PASSWORD_REQUEST_ACTION, {
        email,
      })
    },
    confirmPasswordAdd(email, code, newPassword) {
      return context.actions.dispatch(PROFILE_ADD_PASSWORD_CONFIRM_ACTION, {
        email,
        code,
        newPassword,
      })
    },
  }
}
