// The wire of the framework's sign-in surface (HIL-409, lifted from the chat
// demo's own copy written at HIL-423): `detectIdentifier` looks an identifier up
// (HIL-414), `submitAuthFlow` dispatches the active step's form, and
// `runAuthMethod` runs an icon method's ceremony (HIL-417/418/419) — the three
// seams the flow machine delegates. The machine owns the debounce, the race
// guards, pending, the error and the resend gate; this file owns only the wire —
// which action a state maps to, and how a reply reads back as an outcome.
//
// Every dispatch rides the project's context (HIL-409) rather than an imported
// singleton, which is what makes one copy of this file serve all three view
// frameworks: what a deployment varies is the method registry it declares, never
// a branch here.
//
// The map is a function of (step, intent, identifier kind, action) and NEVER of
// a mode name — that is the whole point of the redesign, and the reason the old
// `submitAuth(mode, form)` is gone. The identifier kind is the first question
// asked on the code paths, because a phone signs in and registers through a code
// CHANNEL where an email goes through its own actions: one screen, two wires.
//
// Auth deliberately surfaces the backend reason: a sign-in names which way it
// failed and a registration legitimately reveals a taken address, so a failure
// carries the backend's own sentence — except for a timeout or a dropped
// connection, where the reason is a framework string and a generic phrasing is
// shown instead.
//
// Replies are validated at the boundary by the lifecycle's `replySchema` rather
// than read key by key: a submit that answers `ok: false` on its SUCCESS ack (a
// taken address is a move to sign-in, not a transport error) has to be read off
// a resolved dispatch, and a malformed one must not reach the machine as a half
// outcome.
import { z } from 'zod'

import { ActionError } from '../connection/actionLifecycle.js'
import { type ProjectSignal } from '../protocol/parseSignal.js'
import { whenPageReady } from '../subscription/pageReadyGate.js'
import {
  AUTH_CODE_REASON_CAP_REACHED,
  AUTH_CODE_REASON_CHANNEL_UNAVAILABLE,
  AUTH_CODE_REASON_RATE_LIMITED,
  AUTH_CODE_REASON_SEND_FAILED,
  AUTH_CODE_REASON_SENT,
} from './authCodeSignals.js'
import { type HilosAuthContext } from './authContext.js'
import {
  CODE_SEND_STATE_FAILED,
  CODE_SEND_STATE_NOT_SENT,
  CODE_SEND_STATE_SENT,
  SIGNAL_CODE_SEND_PROGRESS,
  type CodeSendProgressSignalData,
} from './authSendProgress.js'
import {
  MAGIC_LINK_METHOD_KEY,
  OAUTH_METHOD_PREFIX,
  PASSKEY_FLOW_METHOD,
  SMS_CODE_CHANNEL,
  type AuthFlowForm,
  type AuthFlowState,
  type AuthFlowSubmitOutcome,
  type AuthSubmitAction,
  type IdentifierDetection,
} from './authFlow.js'
import { authFlowOutcomeOf, authFlowOutcomeSchema } from './authFlowReply.js'
import {
  AUTH_ACTION_CANCEL_REGISTRATION,
  AUTH_ACTION_CANCEL_SECOND_FACTOR,
  AUTH_ACTION_COMPLETE_PASSWORD_RESET,
  AUTH_ACTION_COMPLETE_REGISTRATION,
  AUTH_ACTION_COMPLETE_REGISTRATION_PASSWORDLESS,
  AUTH_ACTION_CONFIRM_MAGIC_LINK,
  AUTH_ACTION_CONFIRM_MAGIC_LINK_CODE,
  AUTH_ACTION_CONFIRM_PASSWORD_RESET,
  AUTH_ACTION_CONFIRM_PHONE_CODE,
  AUTH_ACTION_CONFIRM_REGISTER,
  AUTH_ACTION_CONFIRM_SECOND_FACTOR,
  AUTH_ACTION_DETECT_IDENTIFIER,
  AUTH_ACTION_DISMISS_SESSION_ACK,
  AUTH_ACTION_LOGIN,
  AUTH_ACTION_REGISTER,
  AUTH_ACTION_REQUEST_MAGIC_LINK,
  AUTH_ACTION_REQUEST_PASSWORD_RESET,
  AUTH_ACTION_REQUEST_PHONE_CODE,
  AUTH_ACTION_REQUEST_REGISTER_CONFIRM,
  AUTH_ACTION_SECOND_FACTOR_RESET_CANCEL_LINK,
  AUTH_ACTION_SECOND_FACTOR_RESET_REQUEST,
  AUTH_ACTION_SECOND_FACTOR_SETUP_CONFIRM,
  AUTH_ACTION_SECOND_FACTOR_SETUP_FINISH,
  AUTH_ACTION_SECOND_FACTOR_SETUP_START,
} from './authProtocol.js'
import { describeOAuthError, startOAuthLogin } from './oauthLogin.js'
import { runPasskeyDiscoverableLogin } from './passkeyCeremony.js'

/**
 * The states a send can close in (HIL-1044). The code agent's closing step is the
 * one that carries a reason, and it lands in one of these.
 */
const CODE_SEND_CLOSING_STATES: readonly string[] = [
  CODE_SEND_STATE_SENT,
  CODE_SEND_STATE_FAILED,
  CODE_SEND_STATE_NOT_SENT,
]

/** What the server answers an order for a phone code with: the ticket of the send it opened. */
const codeSendOrderReplySchema = z.object({ ticket: z.string() })

/**
 * The live lookup reply (PHP `Hilos\Auth\Detection\IdentifierDetection`). The
 * identifier is echoed verbatim beside its normalized form because the machine
 * matches a reply to the field by what it ASKED — a phone normalized to E.164
 * would otherwise orphan the answer its own keystroke asked for.
 */
const identifierDetectionSchema = z.object({
  identifier: z.string(),
  normalized: z.string(),
  kind: z.enum(['email', 'phone']),
  status: z.enum(['none', 'pending', 'proven', 'active']),
  methods: z.array(z.string()),
  registerable: z.array(z.string()),
  // Present on every reply and non-null only for `none` (HIL-830), so it is read
  // as a nullable rather than an optional: an absent key would mean a backend
  // that predates the field, and the surface has nothing to say about that.
  registrationBlock: z.enum(['closed', 'no_channel']).nullable(),
  // The sign-in twin (HIL-973), non-null only for `active`; nullable and not
  // optional for the same reason.
  signInBlock: z.enum(['no_channel']).nullable(),
})

/**
 * A code request's outcome, and the channel it is ABOUT.
 *
 * The channel rides along because the code screen has to name it, and the only
 * trustworthy source is the outcome itself — the click that started the request may
 * not be the click the person made last.
 */
interface PhoneCodeOutcome {
  /** Whether a code is now on its way (or already was) to the number. */
  readonly ok: boolean
  /** The inline reason a send was refused, or undefined on success. */
  readonly message?: string
  /** The channel the outcome reports, which is the one the code went over. */
  readonly channel: string
  /** The server moment a re-send is allowed again, or undefined when none was named. */
  readonly resendAt?: number
  /** The server moment the code in play dies, or undefined when none is. */
  readonly expiresAt?: number
}

/**
 * The wire of one sign-in surface: the three seams {@link createAuthFlow} takes,
 * plus the dispatches a surface makes outside the machine's own steps.
 *
 * The three seams come first because they are the machine's contract; the rest are
 * the surface's own errands — giving up a registration, relaying a link a mail
 * client opened, watching a channel go unreachable — and they need the same
 * connection and the same action lifecycle, so they are bound from the same
 * context rather than reaching for singletons a project would have to expose.
 */
export interface HilosAuthActions {
  /**
   * Look an identifier up live, debounced by the machine (HIL-414).
   *
   * @param identifier The identifier as it stands in the field.
   */
  onDetect(identifier: string): Promise<IdentifierDetection>
  /**
   * Dispatch the active step's form, or a re-send of its code.
   *
   * @param action Whether this is the step's submit or a re-send.
   * @param flow The current flow state (step, intent, identifier kind, channel).
   * @param form The current form values.
   */
  onSubmit(
    action: AuthSubmitAction,
    flow: AuthFlowState,
    form: AuthFlowForm,
  ): Promise<AuthFlowSubmitOutcome>
  /**
   * Run an icon method's ceremony — its OAuth redirect or WebAuthn ceremony.
   *
   * @param key The chosen method key.
   * @param form The current form values.
   * @param signal Aborted when the person cancels the parked external step.
   */
  onMethodAction(
    key: string,
    form: AuthFlowForm,
    signal: AbortSignal,
  ): Promise<AuthFlowSubmitOutcome>
  /** Leave the code screen and free what this session held (HIL-486, HIL-829). */
  cancelRegistration(): Promise<AuthFlowSubmitOutcome>
  /**
   * Relay a magic-link token the /auth/magic route was opened with.
   *
   * @param email The account email carried in the link.
   * @param token The one-time sign-in token carried in the link.
   */
  confirmMagicLink(email: string, token: string): Promise<AuthFlowSubmitOutcome>
  /**
   * Relay the token of a second factor's "it was not me" link the
   * /auth/second-factor/cancel route was opened with (HIL-494).
   *
   * @param token The one-time cancel token carried in the link.
   */
  cancelSecondFactorReset(token: string): Promise<AuthFlowSubmitOutcome>
  /**
   * Watch for a channel reporting it cannot reach the number being typed.
   *
   * @param handler Called with the channel key that reported itself unreachable.
   */
  subscribeCodeChannelUnavailable(
    handler: (channel: string) => void,
  ): () => void
}

/**
 * Bind the surface's wire to one project context — the form
 * {@link createHilosSettingsActions} established.
 *
 * @param context The project auth context every dispatch rides.
 * @returns The bound wire a surface hands to {@link createAuthFlow} and calls.
 */
export function createAuthActions(context: HilosAuthContext): HilosAuthActions {
  return {
    onDetect: (identifier) => detectIdentifier(context, identifier),
    onSubmit: (action, flow, form) =>
      submitAuthFlow(context, action, flow, form),
    onMethodAction: (key, form, signal) =>
      runAuthMethod(context, key, form, signal),
    cancelRegistration: () => cancelRegistration(context),
    confirmMagicLink: (email, token) => confirmMagicLink(context, email, token),
    cancelSecondFactorReset: (token) => cancelSecondFactorReset(context, token),
    subscribeCodeChannelUnavailable: (handler) =>
      subscribeCodeChannelUnavailable(context, handler),
  }
}

/**
 * Look an identifier up live — the machine's `onDetect` seam (HIL-414).
 *
 * The reply decides everything the surface reveals, so it is validated at the
 * boundary: a malformed or missing one rejects, and the machine answers a
 * rejection by revealing nothing (there is no degraded detection state — broken
 * transport belongs to the connection gate).
 *
 * The identifier goes out UNTRIMMED, exactly as typed: the reply is matched back
 * to the field by this echo, so trimming here would orphan the answer.
 *
 * @param context The project auth context the wire dispatches over.
 * @param identifier The identifier as it stands in the field.
 * @returns What is behind the identifier and what can be done with it.
 * @throws ActionError When the lookup fails, times out, or answers nothing.
 */
async function detectIdentifier(
  context: HilosAuthContext,
  identifier: string,
): Promise<IdentifierDetection> {
  const { reply } = await context.actions.dispatch(
    AUTH_ACTION_DETECT_IDENTIFIER,
    { identifier },
    { replySchema: identifierDetectionSchema },
  ).done
  if (reply === undefined) {
    throw new ActionError(
      AUTH_ACTION_DETECT_IDENTIFIER,
      'invalid-reply',
      'The identifier lookup answered nothing.',
    )
  }

  return reply
}

/**
 * Dispatch the active step's form or a code re-send — the machine's `onSubmit`
 * seam.
 *
 * The whole wire map of the surface, and the only place that knows it. A phone
 * is answered before anything else on every screen it can reach: its sign-in AND
 * its registration are one code over a channel, so the email actions never see
 * one.
 *
 * @param context The project auth context the wire dispatches over.
 * @param action Whether this is the step's submit or a re-send of its code.
 * @param flow The current flow state (step, intent, identifier kind, channel).
 * @param form The current form values.
 * @returns The outcome the machine applies (error inline, or the next step).
 */
function submitAuthFlow(
  context: HilosAuthContext,
  action: AuthSubmitAction,
  flow: AuthFlowState,
  form: AuthFlowForm,
): Promise<AuthFlowSubmitOutcome> {
  // The two errands of a sign-in held on its second factor are named rather than
  // read off the step (HIL-494): neither is what the screen SENDS, and the step
  // they leave from says nothing about which one is meant.
  if (action === 'second_factor_cancel') {
    return dispatchFlow(context, AUTH_ACTION_CANCEL_SECOND_FACTOR, {})
  }
  if (action === 'second_factor_setup_start') {
    return dispatchFlow(context, AUTH_ACTION_SECOND_FACTOR_SETUP_START, {})
  }
  switch (flow.step) {
    case 'identifier':
      // A phone never submits a form from this step — picking its channel IS the
      // send, and the machine dispatches that the moment one is picked.
      return flow.identifierKind === 'phone'
        ? sendPhoneCode(context, flow, form)
        : dispatchFlow(context, AUTH_ACTION_LOGIN, {
            email: form.identifier,
            password: form.password,
          })
    case 'consent':
      // The terms screen is what sends: a registration dispatched NOTHING before
      // it, whichever way it is being made (HIL-417).
      return flow.identifierKind === 'phone'
        ? sendPhoneCode(context, flow, form)
        : dispatchFlow(context, AUTH_ACTION_REGISTER, {
            email: form.identifier,
          })
    case 'code':
      return submitCode(context, action, flow, form)
    case 'code_expired':
      // The expired screen sends only one thing, whatever the caller names it
      // (HIL-828): the flow's first send again, because the code is over and
      // with it, for a registration, the hold this address was kept under.
      return startCodeFlow(context, flow, form)
    case 'set_password':
      // The way PAST the password is asked for by name rather than by intent
      // (HIL-1008): it is the same screen and the same proved hold, but a
      // different ending, and reading it off the intent would make the branch
      // below say two things at once. It carries no payload at all — not even
      // the password the other two send.
      if (action === 'finish_without_password') {
        return dispatchFlow(
          context,
          AUTH_ACTION_COMPLETE_REGISTRATION_PASSWORDLESS,
          {},
        )
      }

      // One screen, two endings with a password in them (HIL-825): a recovery
      // writes the password of an account that exists, a registration CREATES
      // the account on the address it just proved. The address is deliberately
      // absent from both payloads: the backend reads it off what the accepted
      // code left on this session — a grant for the recovery, the proved hold
      // for the registration — so a payload cannot name an account other than
      // the one whose mailbox was just proven.
      return dispatchFlow(
        context,
        flow.intent === 'register'
          ? AUTH_ACTION_COMPLETE_REGISTRATION
          : AUTH_ACTION_COMPLETE_PASSWORD_RESET,
        { password: form.newPassword },
      )
    case 'done':
      // Continue: the announcement is cleared on the server, the gate releases
      // the resume it was holding, and the surface closes.
      return dispatchFlow(context, AUTH_ACTION_DISMISS_SESSION_ACK, {})
    case 'second_factor':
      // A code from the app or a backup code, told apart by the person rather
      // than guessed from its shape (HIL-494): the server burns a backup code
      // and checks an app code against every connected app, and one sentence
      // answers both when it matches nothing.
      return dispatchFlow(context, AUTH_ACTION_CONFIRM_SECOND_FACTOR, {
        code: form.code,
        backupCode: form.usingBackupCode,
        trustDevice: form.trustDevice,
      })
    case 'second_factor_setup':
      return dispatchFlow(context, AUTH_ACTION_SECOND_FACTOR_SETUP_CONFIRM, {
        code: form.code,
        label: form.secondFactorLabel,
      })
    case 'second_factor_codes':
      // Continue under the backup codes is what lets the person in: the
      // enrolment was confirmed a screen ago, the sign-in is still held.
      return dispatchFlow(context, AUTH_ACTION_SECOND_FACTOR_SETUP_FINISH, {})
    case 'second_factor_reset':
      return dispatchFlow(context, AUTH_ACTION_SECOND_FACTOR_RESET_REQUEST, {})
    case 'second_factor_reset_requested':
    case 'external':
      // Neither screen has a form to send: the removal is asked already and its
      // Continue is the machine's own move, and an external step is waiting on
      // a ceremony, which cancels rather than submits.
      return Promise.resolve({ ok: false })
  }
}

/**
 * Run an icon method's ceremony — the machine's `onMethodAction` seam.
 *
 * Behavior is keyed by the method key, which is the contract the core states: a
 * descriptor is pure data precisely so a project can register what a key DOES
 * here. The abort signal is passed down to the browser call every time, because
 * cancelling has to end the ceremony itself — a device dialog left open that a
 * late finger satisfies would raise a session the person just refused.
 *
 * @param context The project auth context the wire dispatches over.
 * @param key The chosen method key.
 * @param form The current form values (a magic link reuses the typed address).
 * @param signal Aborted when the person cancels the parked external step.
 * @returns The outcome the machine applies.
 */
function runAuthMethod(
  context: HilosAuthContext,
  key: string,
  form: AuthFlowForm,
  signal: AbortSignal,
): Promise<AuthFlowSubmitOutcome> {
  if (key === MAGIC_LINK_METHOD_KEY) {
    // Login-only today (HIL-417 is what lets a link register too) and an address
    // it cannot mail is answered exactly like one it can, so the screen it parks
    // on says the same thing either way.
    return dispatchFlow(context, AUTH_ACTION_REQUEST_MAGIC_LINK, {
      email: form.identifier,
    })
  }
  if (key === PASSKEY_FLOW_METHOD.key) {
    return runPasskeyDiscoverableLogin(context, signal)
  }
  if (key.startsWith(OAUTH_METHOD_PREFIX)) {
    return startOAuthProvider(context, key, signal)
  }

  return Promise.resolve({ ok: false })
}

/**
 * Leave the code screen — the one way out of it, whichever intent opened it
 * (HIL-486, HIL-829).
 *
 * It drops the session's own memory of the unfinished step, takes every tab of this
 * session back to the identifier field, and frees what THIS browser was holding on
 * the identifier. The surface sends the same thing on every path and names no
 * address: what there is to free is decided on the server, which is the only side
 * that knows a sign-in by link or by phone code holds an unknown one too.
 *
 * Fire-and-forget by design: the surface has already gone back to the identifier
 * step by the time the ack lands, and a failure to forget something is nothing the
 * person can act on. What matters is that the server hears it, so the next handshake
 * does not put them back on the code screen.
 *
 * @param context The project auth context the wire dispatches over.
 * @returns The outcome the machine applies once the server has been told.
 */
function cancelRegistration(
  context: HilosAuthContext,
): Promise<AuthFlowSubmitOutcome> {
  return dispatchFlow(context, AUTH_ACTION_CANCEL_REGISTRATION, {})
}

/**
 * Relay a magic-link token over the live connection for the /auth/magic route.
 *
 * The email link opens the static route; this dispatches the confirm the surface
 * itself never sends (its magic-link entry is request-only), resolving `ok` when
 * the backend upgrades the session — the auth gate then closes any sign-in shown
 * — and mapping the generic failure otherwise.
 *
 * The route loads cold from a click in an email client, so the connection is
 * still opening when the relay view mounts; {@link whenPageReady} holds the
 * dispatch until a page subscription has answered on it (HIL-607). Without the
 * hold the frame is dropped, not queued, and the person is told the server could
 * not be reached for a click the server never saw.
 *
 * @param context The project auth context the wire dispatches over.
 * @param email The account email carried in the link.
 * @param token The one-time sign-in token carried in the link.
 * @returns Whether the token signed the session in, with the reason when it did not.
 */
async function confirmMagicLink(
  context: HilosAuthContext,
  email: string,
  token: string,
): Promise<AuthFlowSubmitOutcome> {
  await whenPageReady()

  return dispatchFlow(context, AUTH_ACTION_CONFIRM_MAGIC_LINK, { email, token })
}

/**
 * Relay the token of a second factor's "it was not me" link over the live
 * connection for the /auth/second-factor/cancel route (HIL-494).
 *
 * No sign-in is involved and none results: the token alone names the removal, so
 * the route works in a browser that never signed in. It loads cold from a click
 * in an email client exactly as the magic-link route does, and waits for the
 * connection for the same reason ({@link whenPageReady}). The answer is shown in
 * place — `ok` is the removal canceled, a refusal is a link that no longer names
 * one.
 *
 * @param context The project auth context the wire dispatches over.
 * @param token The one-time cancel token carried in the link.
 * @returns Whether the removal was canceled, with the reason when it was not.
 */
async function cancelSecondFactorReset(
  context: HilosAuthContext,
  token: string,
): Promise<AuthFlowSubmitOutcome> {
  await whenPageReady()

  return dispatchFlow(context, AUTH_ACTION_SECOND_FACTOR_RESET_CANCEL_LINK, {
    token,
  })
}

/**
 * Watch for a channel reporting that it cannot reach the number being typed.
 *
 * A view concern, and deliberately not folded into the submit outcome: the surface
 * dims the channel that failed and leaves the rest offered, so what it needs is the
 * channel KEY, while the machine only needs "did the step advance". Two readers of
 * one line - the send-progress line, whose closing step carries the reason
 * (HIL-1044) - each taking the part it acts on.
 *
 * Nothing is dimmed permanently — the caller clears its own dimmed set when the
 * number changes, since a different number is a different question.
 *
 * @param context The project auth context the wire dispatches over.
 * @param handler Called with the channel key that reported itself unavailable.
 * @returns Unsubscribe for the registered signal handler.
 */
function subscribeCodeChannelUnavailable(
  context: HilosAuthContext,
  handler: (channel: string) => void,
): () => void {
  return context.connection.on('projectSignal', (signal: ProjectSignal) => {
    if (signal.type !== SIGNAL_CODE_SEND_PROGRESS) {
      return
    }
    const data = signal.data as CodeSendProgressSignalData
    if (
      data.reason === AUTH_CODE_REASON_CHANNEL_UNAVAILABLE &&
      data.channel !== null
    ) {
      handler(data.channel)
    }
  })
}

/**
 * Dispatch what a code screen sends: the code itself, or another send of it.
 *
 * @param context The project auth context the wire dispatches over.
 * @param action Whether this is the step's submit or a re-send.
 * @param flow The current flow state.
 * @param form The current form values.
 * @returns The outcome the machine applies.
 */
function submitCode(
  context: HilosAuthContext,
  action: AuthSubmitAction,
  flow: AuthFlowState,
  form: AuthFlowForm,
): Promise<AuthFlowSubmitOutcome> {
  if (flow.identifierKind === 'phone') {
    // A channel picked ON the code screen arrives here as a submit with nothing
    // typed yet — choosing one is always a send — and so does the resend link.
    return action === 'resend' || form.code.trim() === ''
      ? sendPhoneCode(context, flow, form)
      : dispatchFlow(context, AUTH_ACTION_CONFIRM_PHONE_CODE, {
          phone: form.identifier,
          code: form.code,
        })
  }
  if (flow.methodKey === MAGIC_LINK_METHOD_KEY) {
    // The waiting screen of a magic link is a code screen too (HIL-606): the
    // letter carries digits beside the URL for the person whose mail is open on
    // another device. Its submit is a door of its own — the link and the code are
    // separate challenges — while its resend is the ordinary send, which mints
    // BOTH halves again under one cooldown.
    return action === 'resend'
      ? dispatchFlow(context, AUTH_ACTION_REQUEST_MAGIC_LINK, {
          email: form.identifier,
        })
      : dispatchFlow(context, AUTH_ACTION_CONFIRM_MAGIC_LINK_CODE, {
          email: form.identifier,
          code: form.code,
        })
  }
  if (flow.intent === 'recovery') {
    // The first send and every re-send travel one path: the key icon starts the
    // recovery locally and then asks for the code exactly as the link does.
    return action === 'resend'
      ? dispatchFlow(context, AUTH_ACTION_REQUEST_PASSWORD_RESET, {
          email: form.identifier,
        })
      : dispatchFlow(context, AUTH_ACTION_CONFIRM_PASSWORD_RESET, {
          email: form.identifier,
          code: form.code,
        })
  }

  // Everything left is the registration confirmation. An email that signs in by
  // code does so through the magic-link branch above, on its own action; this one
  // is the address proving itself for an account being made.
  return action === 'resend'
    ? dispatchFlow(context, AUTH_ACTION_REQUEST_REGISTER_CONFIRM, {
        email: form.identifier,
      })
    : dispatchFlow(context, AUTH_ACTION_CONFIRM_REGISTER, {
        email: form.identifier,
        code: form.code,
      })
}

/**
 * Dispatch the send that STARTED this flow, for a code screen whose code ran out
 * (HIL-828).
 *
 * Three of its four arms are what the same flow's re-send already dispatches
 * ({@link submitCode}); the fourth is the difference the whole leaf turns on. An
 * email registration's re-send ({@link AUTH_ACTION_REQUEST_REGISTER_CONFIRM})
 * begins by demanding a live hold of this browser on the address and refuses
 * without one — and the hold died at the same instant as the code, deliberately,
 * because an address freed under a live code or held after a dead one would both
 * be bugs nobody could configure their way out of. So there is nothing to top up
 * and the address is taken AGAIN: an ordinary registration on the same address,
 * byte-identical to what the terms screen sends. Since HIL-825 that carries the
 * address alone, so a reloaded tab holding no password can order it too.
 *
 * @param context The project auth context the wire dispatches over.
 * @param flow The current flow state (the intent, kind and method that name the
 *   send).
 * @param form The current form values (the identifier).
 * @returns The outcome the machine applies — the code step again, with a fresh
 *   gate and a fresh deadline.
 */
function startCodeFlow(
  context: HilosAuthContext,
  flow: AuthFlowState,
  form: AuthFlowForm,
): Promise<AuthFlowSubmitOutcome> {
  if (flow.identifierKind === 'phone') {
    return sendPhoneCode(context, flow, form)
  }
  if (flow.methodKey === MAGIC_LINK_METHOD_KEY) {
    return dispatchFlow(context, AUTH_ACTION_REQUEST_MAGIC_LINK, {
      email: form.identifier,
    })
  }
  if (flow.intent === 'recovery') {
    return dispatchFlow(context, AUTH_ACTION_REQUEST_PASSWORD_RESET, {
      email: form.identifier,
    })
  }

  return dispatchFlow(context, AUTH_ACTION_REGISTER, {
    email: form.identifier,
  })
}

/**
 * Ask the backend to send a code to a phone over the chosen channel, and resolve
 * once it says what became of it (HIL-492).
 *
 * The code screen is already open by the time this resolves (HIL-826): the machine
 * opens it when the send is ordered, and the send-progress line on it says where the
 * code has got to. So what this outcome still carries is the resend gate, the code's
 * life, and the channel the code REALLY went over - and, when the send is refused,
 * the message the person reads on the step the machine takes them back to.
 *
 * @param context The project auth context the wire dispatches over.
 * @param flow The current flow state (the chosen channel and the intent to keep).
 * @param form The current form values (the number).
 * @returns The outcome, carrying the DELIVERED channel into the flow state.
 */
async function sendPhoneCode(
  context: HilosAuthContext,
  flow: AuthFlowState,
  form: AuthFlowForm,
): Promise<AuthFlowSubmitOutcome> {
  // Unset only before anything was picked, which the primary channel is the
  // answer to: it is the one the registry marks as reaching a stranger's number.
  const channel = flow.channelKey ?? SMS_CODE_CHANNEL.key
  const outcome = await requestPhoneCode(context, form.identifier, channel)
  if (!outcome.ok) {
    return { ok: false, message: outcome.message }
  }

  return {
    ok: true,
    // The channel comes off the OUTCOME and not off the click, so the code screen
    // cannot name one that carried nothing. The step is named again rather than
    // left out because a converge or a resume may have moved the flow while the
    // messenger was being asked.
    next: { step: 'code', intent: flow.intent, channelKey: outcome.channel },
    resendAt: outcome.resendAt,
    expiresAt: outcome.expiresAt,
  }
}

/**
 * Dispatch a phone code request and wait for the closing step of its send.
 *
 * The one submit on this surface whose outcome does NOT ride its own ack. Deciding
 * whether a channel can reach a number is a network round-trip for a messenger, so
 * the page action validates what costs nothing, hands the rest to the code agent
 * and answers with the send's ticket; the real answer lands later on the session's
 * send-progress line ({@link SIGNAL_CODE_SEND_PROGRESS}), whose closing step
 * carries the reason (HIL-1044). What the wait buys is no longer the code screen's
 * opening - that happens at once now (HIL-826) - but everything the outcome alone
 * knows: the channel the code went over, the resend gate, and the refusal.
 *
 * The ticket is what tells THIS send's ending from a line replayed about another
 * one, and the latest frame is kept because the ending may arrive before the
 * reply that names the ticket. The subscription goes up BEFORE the dispatch for
 * the same reason.
 *
 * A dispatch that fails outright (the channel was refused up front, the connection
 * dropped) settles inline; no signal is coming for it.
 *
 * It no longer gives up on a clock (HIL-1044). The two reasons it used to are
 * gone: the outcome rides the session's line, which a reconnect replays rather
 * than losing with the old accept key, and an agent that stops or dies has its
 * sends ended with a refusal by itself or by the session holder. A connection that
 * drops between the order and its reply fails the order as it always has.
 *
 * @param context The project auth context the wire dispatches over.
 * @param phone The number the code is asked for.
 * @param channel The code channel key to send over (see `CodeChannelDescriptor`).
 * @returns What became of the request, and over which channel.
 */
function requestPhoneCode(
  context: HilosAuthContext,
  phone: string,
  channel: string,
): Promise<PhoneCodeOutcome> {
  return new Promise<PhoneCodeOutcome>((resolve) => {
    let settled = false
    let ticket: string | null = null
    let latest: CodeSendProgressSignalData | null = null
    const settle = (outcome: PhoneCodeOutcome): void => {
      if (settled) {
        return
      }
      settled = true
      unsubscribe()
      resolve(outcome)
    }
    // Whether the line has been seen following THIS send. Only after that does a
    // frame about another send - or no send at all - mean ours was replaced: until
    // then the line may still be the previous send's, replayed or not yet moved on.
    let seenOwn = false
    const settleOnLine = (): void => {
      if (ticket === null || latest === null) {
        return
      }
      if (latest.ticket !== ticket) {
        if (seenOwn) {
          // Another order of this session took the line over (another tab, an
          // email code): the closing step of ours will be dropped as stale, so
          // nothing more is coming for it.
          settle({
            ...describeCodeOutcome(AUTH_CODE_REASON_SEND_FAILED),
            channel,
          })
        }

        return
      }
      seenOwn = true
      if (
        latest.reason === null ||
        latest.state === null ||
        !CODE_SEND_CLOSING_STATES.includes(latest.state)
      ) {
        return
      }
      settle({
        ...describeCodeOutcome(latest.reason),
        channel: latest.channel ?? channel,
        resendAt: latest.resendAt ?? undefined,
        expiresAt: latest.expiresAt ?? undefined,
      })
    }

    const unsubscribe = context.connection.on(
      'projectSignal',
      (signal: ProjectSignal) => {
        if (signal.type !== SIGNAL_CODE_SEND_PROGRESS) {
          return
        }
        latest = signal.data as CodeSendProgressSignalData
        settleOnLine()
      },
    )

    context.actions
      .dispatch(
        AUTH_ACTION_REQUEST_PHONE_CODE,
        { phone, channel },
        { replySchema: codeSendOrderReplySchema },
      )
      .done.then(({ reply }) => {
        if (reply === undefined) {
          settle({
            ok: false,
            message: 'Could not send the code. Please try again.',
            channel,
          })

          return
        }
        ticket = reply.ticket
        settleOnLine()
      })
      .catch((error: unknown) => {
        settle({ ok: false, message: describeAuthError(error), channel })
      })
  })
}

/**
 * Turn a code-request outcome reason into what the surface does about it.
 *
 * TWO arms KEEP the code screen, not one. A fresh send obviously does — and so does
 * a send the cooldown held back, because that refusal means a code went to this
 * number moments ago and is still live: taking the person off the code screen would
 * hide the very code they are waiting to type.
 *
 * The genuine refusals send them back to where they can act — pick another channel,
 * fix the number, or wait out the window. The wording is the client's: the outcome
 * signal deliberately carries a stable reason code and no prose. The provider's own
 * sentence has a road of its own now (HIL-826) and it is the send-progress line, not
 * this one.
 *
 * @param reason The stable reason code the outcome signal carried.
 * @returns Whether a code is in play, and the sentence to show when none is.
 */
function describeCodeOutcome(reason: string): {
  ok: boolean
  message?: string
} {
  switch (reason) {
    case AUTH_CODE_REASON_SENT:
    case AUTH_CODE_REASON_RATE_LIMITED:
      return { ok: true }
    case AUTH_CODE_REASON_CHANNEL_UNAVAILABLE:
      return { ok: false, message: 'That number cannot be reached this way.' }
    case AUTH_CODE_REASON_CAP_REACHED:
      return {
        ok: false,
        message:
          'Too many codes have been sent to this number. Please try again later.',
      }
    default:
      return {
        ok: false,
        message: 'Could not send the code. Please try again.',
      }
  }
}

/**
 * Begin a redirect sign-in with one provider (HIL-419).
 *
 * The resolved dispatch is the "accepted" ack — the browser is navigated by the
 * authorize signal — so the flow stays parked on the waiting screen rather than
 * moving anywhere; only a refusal has anything to say.
 *
 * @param context The project auth context the wire dispatches over.
 * @param provider The provider method key, e.g. `oauth:github`.
 * @param signal Aborted when the person cancels before the browser leaves.
 * @returns The outcome the machine applies.
 */
async function startOAuthProvider(
  context: HilosAuthContext,
  provider: string,
  signal: AbortSignal,
): Promise<AuthFlowSubmitOutcome> {
  try {
    await startOAuthLogin(context, provider, signal)

    return { ok: true }
  } catch (error) {
    // The OAuth describer, not the generic one: a trip that never began was
    // refused by the browser as often as by the server, and only the OAuth
    // describer knows how to say the first of those out loud (HIL-633).
    return { ok: false, message: describeOAuthError(error) }
  }
}

/**
 * Dispatch one tracked action and reduce its reply to a flow outcome.
 *
 * A resolved dispatch is READ before it is called a success: these actions answer
 * a refusal on the success ack, so a reply saying `ok: false` is a failure with a
 * sentence and a step to roll back to. An action that answers nothing — a
 * sign-in, a confirmed code — has no reply to read and resolves as the success it
 * already is.
 *
 * @param context The project auth context the wire dispatches over.
 * @param action The backend action name.
 * @param payload The action payload.
 * @returns The outcome the machine applies.
 */
async function dispatchFlow(
  context: HilosAuthContext,
  action: string,
  payload: Record<string, string | boolean>,
): Promise<AuthFlowSubmitOutcome> {
  try {
    const { reply } = await context.actions.dispatch(action, payload, {
      replySchema: authFlowOutcomeSchema,
    }).done

    return authFlowOutcomeOf(reply)
  } catch (error) {
    return { ok: false, message: describeAuthError(error) }
  }
}

/**
 * Map a failed auth action to an inline message: the backend reason for a real
 * rejection, a generic phrasing for a timeout or a dropped connection.
 *
 * @param error The caught failure from the action lifecycle.
 * @returns The sentence to show inline.
 */
function describeAuthError(error: unknown): string {
  if (error instanceof ActionError && error.outcome === 'fail') {
    return error.message
  }

  return 'Could not reach the server. Please try again.'
}
