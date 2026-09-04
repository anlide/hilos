// The passkey ceremony client flow (HIL-284): the client→server actions and the
// browser WebAuthn calls the daemon's options signal drives. It is the passkey
// analog of `oauthLogin` — the sign-in surface's passkey mode and the profile's
// "Add a passkey" button both dispatch through here. Each ceremony is a round
// trip whose options arrive as a WS_USER signal, never as the action's own reply
// ("client action = loading + signal, never fire-forget"):
//
//   options action ── ack means "accepted" ────────────────────────────────┐
//        └ hilos_passkey_options signal → navigator.credentials.create/get │
//   confirm action ── ack means "done" ────────────────────────────────────┤
//        ├ login: currentUser handshake fan-out (HIL-161) → session up     │
//        └ register: plain ack (the credential is stored)                  │
//
// The options and confirm actions are correlated by holding the signed challenge
// token from the options signal and handing it back on confirm; the ceremony
// discriminator matches the signal to the in-flight request (single-flight — the
// UI blocks a second ceremony while one runs).
import { ActionError } from '../connection/actionLifecycle.js'
import { type ProjectSignal } from '../protocol/parseSignal.js'
import { type HilosAuthContext } from './authContext.js'
import { type AuthFlowSubmitOutcome } from './authFlow.js'
import {
  AUTH_ACTION_PASSKEY_DISCOVERABLE_LOGIN_OPTIONS,
  AUTH_ACTION_PASSKEY_LOGIN_CONFIRM,
  AUTH_ACTION_PASSKEY_REGISTER_CONFIRM,
  AUTH_ACTION_PASSKEY_REGISTER_OPTIONS,
} from './authProtocol.js'
import {
  createPasskey,
  getPasskey,
  isPasskeySupported,
  type PasskeyCreationOptions,
  type PasskeyRequestOptions,
} from './passkey.js'
import {
  PASSKEY_CEREMONY_LOGIN,
  PASSKEY_CEREMONY_REGISTER,
  PASSKEY_OPTIONS_SIGNAL,
  passkeyOptionsSignalSchema,
  type PasskeyCeremony,
  type PasskeyOptionsSignalData,
} from './passkeySignals.js'

/** The passkey ceremonies one context is bound to. */
export interface HilosPasskeyCeremony {
  /**
   * Run the usernameless (discoverable) login ceremony (HIL-400).
   *
   * @param abort Aborted when the person cancels the parked external step.
   */
  runPasskeyDiscoverableLogin(
    abort?: AbortSignal,
  ): Promise<AuthFlowSubmitOutcome>
  /** Run the register ceremony for the signed-in user (HIL-284/418). */
  runPasskeyRegister(): Promise<AuthFlowSubmitOutcome>
}

/** Shown when the browser has no WebAuthn support. */
const PASSKEY_UNSUPPORTED_MESSAGE = 'This browser does not support passkeys.'

/** The generic message when a passkey ceremony cannot be completed. */
const PASSKEY_FAILED_MESSAGE =
  'Could not complete the passkey request. Please try again.'

// The browser reports a dialog the person closed and a dialog that had nothing to
// offer as the same DOMException, so one phrasing has to cover both (HIL-658).
// What it must NOT also cover is both ceremonies: the advice ends by naming what
// the person was doing, and on the profile's "Add a passkey" button the sign-in
// wording told someone already signed in how to sign in (HIL-725).
/** Shown when the login ceremony was cancelled or had nothing to offer. */
const PASSKEY_CANCELLED_LOGIN_MESSAGE =
  'The passkey request was cancelled, or this device had no matching way to sign in — try a security key or your phone.'

/** Shown when the register ceremony was cancelled or had nothing to offer. */
const PASSKEY_CANCELLED_REGISTER_MESSAGE =
  'The passkey request was cancelled, or this device had no way to add one — try a security key or your phone.'

/**
 * Dispatch an options action and resolve the options signal it triggers.
 * Subscribes before dispatching so the WS_USER signal cannot be missed, then
 * matches the ceremony discriminator to this request (single-flight). Rejects if
 * the action itself fails before any signal lands.
 *
 * The abort has to reach THIS wait too, not only the browser call after it
 * (HIL-418): the options round-trip is where a slow server leaves the ceremony
 * parked longest, and a cancel that only unsubscribed would leave the caller
 * awaiting a promise nothing will ever settle.
 *
 * @param context The project auth context the wire dispatches over.
 * @param action The options action name.
 * @param payload The options action payload.
 * @param ceremony The ceremony discriminator the reply must carry.
 * @param abort Aborted when the user cancels the ceremony, if the caller passes one.
 * @returns The parsed options signal payload.
 */
function requestOptions(
  context: HilosAuthContext,
  action: string,
  payload: Record<string, string>,
  ceremony: PasskeyCeremony,
  abort?: AbortSignal,
): Promise<PasskeyOptionsSignalData> {
  return new Promise((resolve, reject) => {
    if (abort?.aborted === true) {
      reject(abort.reason as unknown)

      return
    }
    let settled = false

    /** Close the wait once and drop both listeners; the first outcome wins. */
    function claim(): boolean {
      if (settled) {
        return false
      }
      settled = true
      unsubscribe()
      abort?.removeEventListener('abort', onAbort)

      return true
    }

    function onAbort(): void {
      if (claim()) {
        reject(abort?.reason as unknown)
      }
    }

    const unsubscribe = context.connection.on(
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
        if (claim()) {
          resolve(data)
        }
      },
    )
    abort?.addEventListener('abort', onAbort)
    context.actions.dispatch(action, payload).done.catch((error: unknown) => {
      if (claim()) {
        reject(error)
      }
    })
  })
}

/**
 * Run the usernameless (discoverable) login ceremony (HIL-400): request options
 * with no email, run the WebAuthn assertion whose empty allowCredentials makes the
 * browser show the OS discoverable-passkey picker, and confirm — handing back the
 * assertion's user handle so the server resolves the account (the login named
 * none). Resolves `ok` on the confirm ack; the session upgrade (HIL-161) then
 * closes the surface through the auth gate, so no next mode. A cancelled picker
 * makes no server call (getPasskey rejects before confirm).
 *
 * Cancelling (HIL-418) reaches every stage: the options wait, the OS picker, and
 * the confirm. A signature that races the abort and lands anyway is DROPPED —
 * this is the one method whose ceremony the browser truly ends, so a session must
 * never rise from a gesture the person just took back. The machine's late-outcome
 * window is for the methods that cannot be stopped (an OAuth popup already
 * redirected), not for this one.
 *
 * The Cancel that produces the signal is the waiting screen's own button
 * (HIL-423), which is where the machine parks a ceremony it started.
 *
 * @param context The project auth context the wire dispatches over.
 * @param abort Aborted when the user cancels the parked external step.
 */
export async function runPasskeyDiscoverableLogin(
  context: HilosAuthContext,
  abort?: AbortSignal,
): Promise<AuthFlowSubmitOutcome> {
  if (!isPasskeySupported()) {
    return { ok: false, message: PASSKEY_UNSUPPORTED_MESSAGE }
  }
  try {
    const options = await requestOptions(
      context,
      AUTH_ACTION_PASSKEY_DISCOVERABLE_LOGIN_OPTIONS,
      {},
      PASSKEY_CEREMONY_LOGIN,
      abort,
    )
    const assertion = await getPasskey(
      options.publicKeyOptions as unknown as PasskeyRequestOptions,
      abort,
    )
    // The authenticator can still answer between the abort and this line; the
    // confirm is the point of no return, so it is the last place to check.
    abort?.throwIfAborted()
    await context.actions.dispatch(AUTH_ACTION_PASSKEY_LOGIN_CONFIRM, {
      signedChallenge: options.signedChallenge,
      credentialId: assertion.credentialId,
      authenticatorData: assertion.authenticatorData,
      clientDataJson: assertion.clientDataJson,
      signature: assertion.signature,
      userHandle: assertion.userHandle ?? '',
    }).done

    return { ok: true }
  } catch (error) {
    return {
      ok: false,
      message: describePasskeyError(error, PASSKEY_CEREMONY_LOGIN),
    }
  }
}

/**
 * Run the register ceremony for the signed-in user: request options, run the
 * WebAuthn attestation, and confirm it. Resolves `ok` on the confirm ack (the
 * credential is stored); the credential list refresh is HIL-404. Register errors
 * are specific (a taken credential, a rejected attestation).
 *
 * The confirm carries this device's User-Agent, which is what names the key in
 * the profile (HIL-418) — the same thing a push subscription sends, and for the
 * same reason: a person recognizes their laptop, not a credential id.
 *
 * @param context The project auth context the wire dispatches over.
 */
async function runPasskeyRegister(
  context: HilosAuthContext,
): Promise<AuthFlowSubmitOutcome> {
  if (!isPasskeySupported()) {
    return { ok: false, message: PASSKEY_UNSUPPORTED_MESSAGE }
  }
  try {
    const options = await requestOptions(
      context,
      AUTH_ACTION_PASSKEY_REGISTER_OPTIONS,
      {},
      PASSKEY_CEREMONY_REGISTER,
    )
    const attestation = await createPasskey(
      options.publicKeyOptions as unknown as PasskeyCreationOptions,
    )
    await context.actions.dispatch(AUTH_ACTION_PASSKEY_REGISTER_CONFIRM, {
      signedChallenge: options.signedChallenge,
      attestationObject: attestation.attestationObject,
      clientDataJson: attestation.clientDataJson,
      transports: attestation.transports,
      userAgent: navigator.userAgent,
    }).done

    return { ok: true }
  } catch (error) {
    return {
      ok: false,
      message: describePasskeyError(error, PASSKEY_CEREMONY_REGISTER),
    }
  }
}

/**
 * Map a failed passkey ceremony to an inline message: the backend reason for a
 * rejected action, a friendly phrasing for the two common authenticator
 * outcomes, and a generic fallback otherwise.
 *
 * @param error The caught failure from the ceremony.
 * @param ceremony The ceremony the failure belongs to, which is what the cancelled-branch copy names.
 */
function describePasskeyError(
  error: unknown,
  ceremony: PasskeyCeremony,
): string {
  if (error instanceof ActionError && error.outcome === 'fail') {
    return error.message
  }
  if (error instanceof DOMException) {
    if (error.name === 'NotAllowedError' || error.name === 'AbortError') {
      return ceremony === PASSKEY_CEREMONY_REGISTER
        ? PASSKEY_CANCELLED_REGISTER_MESSAGE
        : PASSKEY_CANCELLED_LOGIN_MESSAGE
    }
    if (error.name === 'InvalidStateError') {
      return 'This device already has a passkey for this account.'
    }
  }

  return PASSKEY_FAILED_MESSAGE
}

/**
 * The passkey half of one sign-in surface and of the profile: the usernameless
 * login the surface offers as an icon method, and the registration the profile's
 * "Add a passkey" button runs (HIL-284/400/418).
 *
 * @param context The project auth context the wire dispatches over.
 * @returns The bound ceremonies a surface and a profile view call.
 */
export function createPasskeyCeremony(
  context: HilosAuthContext,
): HilosPasskeyCeremony {
  return {
    runPasskeyDiscoverableLogin: (abort) =>
      runPasskeyDiscoverableLogin(context, abort),
    runPasskeyRegister: () => runPasskeyRegister(context),
  }
}
