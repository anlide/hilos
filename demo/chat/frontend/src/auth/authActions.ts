// The auth surface's client-to-server submits: map the active mode to its backend
// action and dispatch it over the request-correlated action lifecycle (core
// ActionLifecycle), returning the outcome the surface state machine (HIL-364)
// renders. Unlike a generic tracked action, auth surfaces the backend reason: a
// sign-in names which of the three ways it failed (HIL-414), and register
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
import { ActionError, SMS_CODE_CHANNEL } from '@hilos/core'
import type {
  AuthFormState,
  AuthMode,
  AuthSubmitOutcome,
  ProjectSignal,
} from '@hilos/core'

import { actions, connection } from '../bootstrap/connection'
import {
  AUTH_CODE_REASON_CAP_REACHED,
  AUTH_CODE_REASON_CHANNEL_UNAVAILABLE,
  AUTH_CODE_REASON_RATE_LIMITED,
  AUTH_CODE_REASON_SENT,
  AUTH_CODE_RESULT_SIGNAL,
  authCodeResultSignalSchema,
} from './authCodeSignals'

/** Backend action: email+password login (PHP `ChatSignalConstants::LOGIN`). */
const LOGIN_ACTION = 'login'

/** Backend action: email+password registration (PHP `ChatSignalConstants::REGISTER`). */
const REGISTER_ACTION = 'register'

/** Backend action: confirm a reserved registration (PHP `ChatSignalConstants::CONFIRM_REGISTER`). */
const CONFIRM_REGISTER_ACTION = 'confirm_register'

/** Backend action: re-send a pending registration's code (PHP `ChatSignalConstants::REQUEST_REGISTER_CONFIRM`). */
const REQUEST_REGISTER_CONFIRM_ACTION = 'request_register_confirm'

/** Backend action: send a phone login code over a channel (PHP `ChatSignalConstants::REQUEST_PHONE_CODE`). */
const REQUEST_PHONE_CODE_ACTION = 'request_phone_code'

/** Backend action: submit a phone login code (PHP `ChatSignalConstants::CONFIRM_PHONE_CODE`). */
const CONFIRM_PHONE_CODE_ACTION = 'confirm_phone_code'

/** Backend action: request an email magic-link (PHP `ChatSignalConstants::REQUEST_MAGIC_LINK`). */
const REQUEST_MAGIC_LINK_ACTION = 'request_magic_link'

/** Backend action: submit an email magic-link token (PHP `ChatSignalConstants::CONFIRM_MAGIC_LINK`). */
const CONFIRM_MAGIC_LINK_ACTION = 'confirm_magic_link'

/** Backend action: give up the registration this session started (PHP `ChatSignalConstants::ABANDON_REGISTRATION`). */
const ABANDON_REGISTRATION_ACTION = 'abandon_registration'

/**
 * How long a code request waits for its outcome signal before giving up. Comfortably
 * past the agent's own whole-operation deadline (15s), so this fires only when the
 * outcome is never coming — not when the messenger is merely slow.
 */
const CODE_OUTCOME_TIMEOUT_MS = 20000

/**
 * A code request's outcome, and the channel it is ABOUT.
 *
 * The channel rides along because the surface has to name it, and the only
 * trustworthy source is the outcome itself — the click that started the request may
 * not be the click the person made last.
 */
export interface PhoneCodeOutcome extends AuthSubmitOutcome {
  /** The channel the outcome reports, which is the one the code went over. */
  readonly channel: string
}

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
      // Asynchronous for every channel (HIL-492): the ack only means "accepted",
      // so the advance waits for the outcome signal that says a code really went
      // out - and over which channel. The primary channel is what this step's
      // single button sends over; the icon row that offers the rest is HIL-423.
      return requestPhoneCode(form.phone, SMS_CODE_CHANNEL.key)
    case 'sms_confirm':
      // Success upgrades the session (find-or-create by phone) and the auth gate
      // closes the surface off the current-user signal, so no next mode. No
      // channel rides along: a code is verified against the challenge for the
      // number, and which channel carried it there changes nothing.
      return dispatch(CONFIRM_PHONE_CODE_ACTION, {
        phone: form.phone,
        code: form.code,
      })
    case 'magic_link_request':
      // Success does not advance a mode: the confirm is a clicked email link
      // handled on the /auth/magic route, not a form step. The backend answers the
      // same either way: the request is login-only today (HIL-417 is what makes a
      // link register too), and an address it cannot mail is a silent no-op rather
      // than a "no" — so the view shows a "check your email" acknowledgement.
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
 * Give up the registration this session started — the "not that address?" way out
 * (HIL-486).
 *
 * It drops the session's own memory of the unfinished step and takes every tab of
 * this session back to the identifier field; the hold on the address itself is left
 * alone, so a stranger's session cannot free an address somebody else is registering.
 *
 * Fire-and-forget by design: the surface has already gone back to the identifier
 * step by the time the ack lands, and a failure to forget something is nothing the
 * person can act on. What matters is that the server hears it, so the next handshake
 * does not put them back on the code screen.
 */
export function abandonRegistration(): Promise<AuthSubmitOutcome> {
  return dispatch(ABANDON_REGISTRATION_ACTION, {})
}

/**
 * Ask the backend to send a login code to a phone over one channel, and resolve
 * only once it says what became of it (HIL-492).
 *
 * The one submit on this surface whose outcome does NOT ride its own ack. Deciding
 * whether a channel can reach a number is a network round-trip for a messenger, so
 * the page action validates what costs nothing, hands the rest to the code agent
 * and acks "accepted"; the real answer lands later on
 * {@link AUTH_CODE_RESULT_SIGNAL}. Advancing on the ack would open a code screen
 * before any code existed — and, once channels can fail, a screen naming a message
 * that was never sent.
 *
 * Ordering is why the subscription is registered BEFORE the dispatch: the outcome
 * can arrive while the ack is still in flight, and a listener attached afterwards
 * would miss it and leave the surface waiting forever.
 *
 * A dispatch that fails outright (the channel was refused up front, the connection
 * dropped) rejects and is shown inline; no signal is coming for it.
 *
 * It also gives up on its own after {@link CODE_OUTCOME_TIMEOUT_MS}. Waiting on a
 * signal forever is not patience but a dead surface: a reconnect gives the socket a
 * new accept key and an agent restart drops its in-flight ops, so in both cases the
 * outcome is addressed to somebody who no longer exists and nothing will ever
 * arrive. Without the deadline the promise never settles, `pending` never clears,
 * and every later press is a silent no-op — the exact opposite of the recovery the
 * flow is designed around, which is the person pressing the button again.
 *
 * @param phone The number the code is asked for.
 * @param channel The code channel key to send over (see `CodeChannelDescriptor`).
 */
export function requestPhoneCode(
  phone: string,
  channel: string,
): Promise<PhoneCodeOutcome> {
  return new Promise<PhoneCodeOutcome>((resolve) => {
    let settled = false
    const settle = (outcome: PhoneCodeOutcome): void => {
      if (settled) {
        return
      }
      settled = true
      clearTimeout(deadline)
      unsubscribe()
      resolve(outcome)
    }

    const unsubscribe = connection.on(
      'projectSignal',
      (signal: ProjectSignal) => {
        if (signal.type !== AUTH_CODE_RESULT_SIGNAL) {
          return
        }
        const data = signal.data as ReturnType<
          typeof authCodeResultSignalSchema.parse
        >
        // The channel is taken from the OUTCOME, never from the click: naming the
        // clicked one would put "via Telegram" on a screen holding an SMS code the
        // moment a second press changed the choice mid-request.
        settle({
          ...describeCodeOutcome(data.reason),
          channel: data.channel,
          expiresAt: data.expiresAt ?? undefined,
        })
      },
    )

    const deadline = setTimeout(() => {
      settle({
        ok: false,
        message: 'The code did not go out in time. Please try again.',
        channel,
      })
    }, CODE_OUTCOME_TIMEOUT_MS)

    actions
      .dispatch(REQUEST_PHONE_CODE_ACTION, { phone, channel })
      .done.catch((error: unknown) => {
        settle({ ok: false, message: describeAuthError(error), channel })
      })
  })
}

/**
 * Watch for a channel reporting that it cannot reach the number being typed.
 *
 * A view concern, and deliberately not folded into {@link requestPhoneCode}'s
 * outcome: the surface dims the channel that failed and leaves the rest offered, so
 * what it needs is the channel KEY, while the submit machine only needs "did the
 * step advance". Two readers of one signal, each taking the part it acts on.
 *
 * Nothing is dimmed permanently — the caller clears its own dimmed set when the
 * number changes, since a different number is a different question.
 *
 * @param handler Called with the channel key that reported itself unavailable.
 * @returns Unsubscribe for the registered signal handler.
 */
export function subscribeCodeChannelUnavailable(
  handler: (channel: string) => void,
): () => void {
  return connection.on('projectSignal', (signal: ProjectSignal) => {
    if (signal.type !== AUTH_CODE_RESULT_SIGNAL) {
      return
    }
    const data = signal.data as ReturnType<typeof authCodeResultSignalSchema.parse>
    if (data.reason === AUTH_CODE_REASON_CHANNEL_UNAVAILABLE) {
      handler(data.channel)
    }
  })
}

/**
 * Turn a code-request outcome reason into what the surface does about it.
 *
 * TWO arms advance to the code screen, not one. A fresh send obviously does — and
 * so does a send the cooldown held back, because that refusal means a code went to
 * this number moments ago and is still live: stranding the person on the phone step
 * would hide the very code they are waiting to type. This is the behavior the
 * synchronous flow had (a held send answered `sent` with the remaining seconds),
 * kept deliberately.
 *
 * The genuine refusals stay on the phone step with a sentence, since that is where
 * the person can act — pick another channel, fix the number, or wait out the
 * window. The wording is the client's: the backend deliberately sends a stable
 * reason code and no prose, so no provider or network detail reaches a guest.
 *
 * @param reason The stable reason code the outcome signal carried.
 */
function describeCodeOutcome(reason: string): AuthSubmitOutcome {
  switch (reason) {
    case AUTH_CODE_REASON_SENT:
    case AUTH_CODE_REASON_RATE_LIMITED:
      return { ok: true, next: 'sms_confirm' }
    case AUTH_CODE_REASON_CHANNEL_UNAVAILABLE:
      return { ok: false, message: 'That number cannot be reached this way.' }
    case AUTH_CODE_REASON_CAP_REACHED:
      return {
        ok: false,
        message:
          'Too many codes have been sent to this number. Please try again later.',
      }
    default:
      return { ok: false, message: 'Could not send the code. Please try again.' }
  }
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

    return (
      readFlowRefusal(result.reply) ?? {
        ok: true,
        next,
        expiresAt: readExpiresAt(result.reply),
      }
    )
  } catch (error) {
    return { ok: false, message: describeAuthError(error) }
  }
}

/**
 * Read the moment a successful reply says its code or link dies, or undefined
 * when it says nothing about one (HIL-486).
 *
 * Read defensively for the same reason the refusal above is: the reply is
 * `unknown` here, and a submit that left nothing waiting — a sign-in, a confirmed
 * code — legitimately carries no moment. Undefined leaves whatever countdown is
 * already on screen alone, which is what a submit that changed nothing about it
 * should do.
 *
 * @param reply The domain reply the action ack carried.
 */
function readExpiresAt(reply: unknown): number | undefined {
  if (typeof reply !== 'object' || reply === null) {
    return undefined
  }

  const { expiresAt } = reply as { expiresAt?: unknown }

  return typeof expiresAt === 'number' ? expiresAt : undefined
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
