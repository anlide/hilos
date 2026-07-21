// The passkey ceremony client flow (HIL-284): the client→server actions and the
// browser WebAuthn calls the daemon's options signal drives. It is the passkey
// analog of `oauthLogin` — the sign-in surface's passkey mode and the profile's
// "Add a passkey" button both dispatch through here. Each ceremony is a round
// trip whose options arrive as a WS_USER signal, never as the action's own reply
// ("client action = loading + signal, never fire-forget"):
//
//   options action ── ack means "accepted" ─────────────────────────────┐
//        └ passkey_options signal → navigator.credentials.create/get     │
//   confirm action ── ack means "done" ─────────────────────────────────┤
//        ├ login: currentUser handshake fan-out (HIL-161) → session up   │
//        └ register: plain ack (the credential is stored)                 │
//
// The options and confirm actions are correlated by holding the signed challenge
// token from the options signal and handing it back on confirm; the ceremony
// discriminator matches the signal to the in-flight request (single-flight — the
// UI blocks a second ceremony while one runs).
import {
  ActionError,
  createPasskey,
  getPasskey,
  isPasskeySupported,
  type AuthSubmitOutcome,
  type PasskeyCreationOptions,
  type PasskeyRequestOptions,
  type ProjectSignal,
} from '@hilos/core'

import { actions, connection } from '../bootstrap/connection'
import {
  PASSKEY_CEREMONY_LOGIN,
  PASSKEY_CEREMONY_REGISTER,
  PASSKEY_OPTIONS_SIGNAL,
  passkeyOptionsSignalSchema,
  type PasskeyOptionsSignalData,
} from './passkeySignals'

/** Backend action: mint register-ceremony options (PHP `ChatSignalConstants::PASSKEY_REGISTER_OPTIONS`). */
const PASSKEY_REGISTER_OPTIONS_ACTION = 'passkey_register_options'

/** Backend action: verify a register attestation (PHP `ChatSignalConstants::PASSKEY_REGISTER_CONFIRM`). */
const PASSKEY_REGISTER_CONFIRM_ACTION = 'passkey_register_confirm'

/** Backend action: mint login-ceremony options (PHP `ChatSignalConstants::PASSKEY_LOGIN_OPTIONS`). */
const PASSKEY_LOGIN_OPTIONS_ACTION = 'passkey_login_options'

/** Backend action: verify a login assertion (PHP `ChatSignalConstants::PASSKEY_LOGIN_CONFIRM`). */
const PASSKEY_LOGIN_CONFIRM_ACTION = 'passkey_login_confirm'

/** Shown when the browser has no WebAuthn support. */
const PASSKEY_UNSUPPORTED_MESSAGE = 'This browser does not support passkeys.'

/** The generic message when a passkey ceremony cannot be completed. */
const PASSKEY_FAILED_MESSAGE =
  'Could not complete the passkey request. Please try again.'

/**
 * Dispatch an options action and resolve the options signal it triggers.
 * Subscribes before dispatching so the WS_USER signal cannot be missed, then
 * matches the ceremony discriminator to this request (single-flight). Rejects if
 * the action itself fails before any signal lands.
 *
 * @param action The options action name.
 * @param payload The options action payload.
 * @param ceremony The ceremony discriminator the reply must carry.
 * @returns The parsed options signal payload.
 */
function requestOptions(
  action: string,
  payload: Record<string, string>,
  ceremony: string,
): Promise<PasskeyOptionsSignalData> {
  return new Promise((resolve, reject) => {
    let settled = false
    const unsubscribe = connection.on(
      'projectSignal',
      (signal: ProjectSignal) => {
        if (signal.type !== PASSKEY_OPTIONS_SIGNAL) {
          return
        }
        const data = signal.data as ReturnType<
          typeof passkeyOptionsSignalSchema.parse
        >
        if (data.ceremony !== ceremony) {
          return
        }
        settled = true
        unsubscribe()
        resolve(data)
      },
    )
    actions.dispatch(action, payload).done.catch((error: unknown) => {
      if (settled) {
        return
      }
      settled = true
      unsubscribe()
      reject(error)
    })
  })
}

/**
 * Run the login ceremony for an email: request options, run the WebAuthn
 * assertion, and confirm it. Resolves `ok` on the confirm ack — the session
 * upgrade (HIL-161) then closes the surface through the auth gate, so no next
 * mode — and maps any failure to an inline message.
 *
 * @param email The account email to look up candidate passkeys for.
 */
export async function runPasskeyLogin(
  email: string,
): Promise<AuthSubmitOutcome> {
  if (!isPasskeySupported()) {
    return { ok: false, message: PASSKEY_UNSUPPORTED_MESSAGE }
  }
  try {
    const options = await requestOptions(
      PASSKEY_LOGIN_OPTIONS_ACTION,
      { email },
      PASSKEY_CEREMONY_LOGIN,
    )
    const assertion = await getPasskey(
      options.publicKeyOptions as unknown as PasskeyRequestOptions,
    )
    await actions.dispatch(PASSKEY_LOGIN_CONFIRM_ACTION, {
      signedChallenge: options.signedChallenge,
      credentialId: assertion.credentialId,
      authenticatorData: assertion.authenticatorData,
      clientDataJson: assertion.clientDataJson,
      signature: assertion.signature,
    }).done

    return { ok: true }
  } catch (error) {
    return { ok: false, message: describePasskeyError(error) }
  }
}

/**
 * Run the register ceremony for the signed-in user: request options, run the
 * WebAuthn attestation, and confirm it. Resolves `ok` on the confirm ack (the
 * credential is stored); the credential list refresh is HIL-404. Register errors
 * are specific (a taken credential, a rejected attestation).
 */
export async function runPasskeyRegister(): Promise<AuthSubmitOutcome> {
  if (!isPasskeySupported()) {
    return { ok: false, message: PASSKEY_UNSUPPORTED_MESSAGE }
  }
  try {
    const options = await requestOptions(
      PASSKEY_REGISTER_OPTIONS_ACTION,
      {},
      PASSKEY_CEREMONY_REGISTER,
    )
    const attestation = await createPasskey(
      options.publicKeyOptions as unknown as PasskeyCreationOptions,
    )
    await actions.dispatch(PASSKEY_REGISTER_CONFIRM_ACTION, {
      signedChallenge: options.signedChallenge,
      attestationObject: attestation.attestationObject,
      clientDataJson: attestation.clientDataJson,
      transports: attestation.transports,
    }).done

    return { ok: true }
  } catch (error) {
    return { ok: false, message: describePasskeyError(error) }
  }
}

/**
 * Map a failed passkey ceremony to an inline message: the backend reason for a
 * rejected action, a friendly phrasing for the two common authenticator
 * outcomes, and a generic fallback otherwise.
 *
 * @param error The caught failure from the ceremony.
 */
function describePasskeyError(error: unknown): string {
  if (error instanceof ActionError && error.outcome === 'fail') {
    return error.message
  }
  if (error instanceof DOMException) {
    if (error.name === 'NotAllowedError') {
      return 'The passkey request was cancelled or timed out.'
    }
    if (error.name === 'InvalidStateError') {
      return 'This device already has a passkey for this account.'
    }
  }

  return PASSKEY_FAILED_MESSAGE
}
