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
import {
  AUTH_CODE_REASON_CAP_REACHED,
  AUTH_CODE_REASON_CHANNEL_UNAVAILABLE,
  AUTH_CODE_REASON_RATE_LIMITED,
  AUTH_CODE_REASON_SENT,
  AUTH_CODE_RESULT_SIGNAL,
  authCodeResultSignalSchema,
} from './authCodeSignals.js'
import { type HilosAuthContext } from './authContext.js'
import {
  MAGIC_LINK_METHOD_KEY,
  PASSKEY_FLOW_METHOD,
  SMS_CODE_CHANNEL,
  type AuthFlowForm,
  type AuthFlowState,
  type AuthFlowSubmitOutcome,
  type AuthIntent,
  type AuthStep,
  type AuthSubmitAction,
  type IdentifierDetection,
} from './authFlow.js'
import {
  AUTH_ACTION_ABANDON_REGISTRATION,
  AUTH_ACTION_COMPLETE_PASSWORD_RESET,
  AUTH_ACTION_CONFIRM_MAGIC_LINK,
  AUTH_ACTION_CONFIRM_PASSWORD_RESET,
  AUTH_ACTION_CONFIRM_PHONE_CODE,
  AUTH_ACTION_CONFIRM_REGISTER,
  AUTH_ACTION_DETECT_IDENTIFIER,
  AUTH_ACTION_DISMISS_SESSION_ACK,
  AUTH_ACTION_LOGIN,
  AUTH_ACTION_REGISTER,
  AUTH_ACTION_REQUEST_MAGIC_LINK,
  AUTH_ACTION_REQUEST_PASSWORD_RESET,
  AUTH_ACTION_REQUEST_PHONE_CODE,
  AUTH_ACTION_REQUEST_REGISTER_CONFIRM,
} from './authProtocol.js'
import { startOAuthLogin } from './oauthLogin.js'
import { runPasskeyDiscoverableLogin } from './passkeyCeremony.js'

/** The key prefix every OAuth provider method carries, e.g. `oauth:github`. */
const OAUTH_METHOD_PREFIX = 'oauth:'

/**
 * How long a code request waits for its outcome signal before giving up. Comfortably
 * past the agent's own whole-operation deadline (15s), so this fires only when the
 * outcome is never coming — not when the messenger is merely slow.
 */
const CODE_OUTCOME_TIMEOUT_MS = 20000

/** The steps a backend reply or a converge may name (PHP `AuthFlowStep`). */
const FLOW_STEPS: readonly AuthStep[] = [
  'identifier',
  'consent',
  'code',
  'second_factor',
  'set_password',
  'external',
  'done',
]

/** The intents a backend reply or a converge may name (PHP `AuthFlowIntent`). */
const FLOW_INTENTS: readonly AuthIntent[] = ['login', 'register', 'recovery']

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
  status: z.enum(['none', 'pending', 'active']),
  methods: z.array(z.string()),
  registerable: z.array(z.string()),
})

/**
 * A submit reply (PHP `Hilos\Auth\Flow\AuthFlowOutcome`), optional because most
 * of these actions answer with nothing at all: a sign-in upgrades the session
 * and the gate closes the surface off the current-user signal, and an action
 * that answered nothing is the success its ack already made it.
 *
 * `next` rides a FAILURE too, which is the shape's one load-bearing oddity: a
 * rejected submit on this surface usually moves (a taken address becomes a
 * sign-in, an expired hold goes back to the identifier field).
 */
const authFlowOutcomeSchema = z
  .object({
    ok: z.boolean(),
    next: z
      .object({ step: z.string(), intent: z.string() })
      .partial()
      .optional(),
    code: z.string().optional(),
    message: z.string().optional(),
    resendAt: z.number().optional(),
    expiresAt: z.number().optional(),
  })
  .optional()

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
  /** Give up the registration this session was waiting on (HIL-486). */
  abandonRegistration(): Promise<AuthFlowSubmitOutcome>
  /**
   * Relay a magic-link token the /auth/magic route was opened with.
   *
   * @param email The account email carried in the link.
   * @param token The one-time sign-in token carried in the link.
   */
  confirmMagicLink(email: string, token: string): Promise<AuthFlowSubmitOutcome>
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
    abandonRegistration: () => abandonRegistration(context),
    confirmMagicLink: (email, token) => confirmMagicLink(context, email, token),
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
            password: form.password,
          })
    case 'code':
      return submitCode(context, action, flow, form)
    case 'set_password':
      // The address is deliberately absent from the payload: the backend reads it
      // off the grant the accepted code left on this session, so a payload cannot
      // name an account other than the one whose mailbox was just proven.
      return dispatchFlow(context, AUTH_ACTION_COMPLETE_PASSWORD_RESET, {
        password: form.newPassword,
      })
    case 'done':
      // Continue: the announcement is cleared on the server, the gate releases
      // the resume it was holding, and the surface closes.
      return dispatchFlow(context, AUTH_ACTION_DISMISS_SESSION_ACK, {})
    case 'second_factor':
    case 'external':
      // Neither screen has a form to send: two-step verification is HIL-68/494
      // and no backend reply moves the flow there, and an external step is
      // waiting on a ceremony, which cancels rather than submits.
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
 *
 * @param context The project auth context the wire dispatches over.
 * @returns The outcome the machine applies once the server has been told.
 */
function abandonRegistration(
  context: HilosAuthContext,
): Promise<AuthFlowSubmitOutcome> {
  return dispatchFlow(context, AUTH_ACTION_ABANDON_REGISTRATION, {})
}

/**
 * Relay a magic-link token over the live connection for the /auth/magic route.
 *
 * The email link opens the static route; this dispatches the confirm the surface
 * itself never sends (its magic-link entry is request-only), resolving `ok` when
 * the backend upgrades the session — the auth gate then closes any sign-in shown
 * — and mapping the generic failure otherwise.
 *
 * @param context The project auth context the wire dispatches over.
 * @param email The account email carried in the link.
 * @param token The one-time sign-in token carried in the link.
 * @returns Whether the token signed the session in, with the reason when it did not.
 */
function confirmMagicLink(
  context: HilosAuthContext,
  email: string,
  token: string,
): Promise<AuthFlowSubmitOutcome> {
  return dispatchFlow(context, AUTH_ACTION_CONFIRM_MAGIC_LINK, { email, token })
}

/**
 * Watch for a channel reporting that it cannot reach the number being typed.
 *
 * A view concern, and deliberately not folded into the submit outcome: the surface
 * dims the channel that failed and leaves the rest offered, so what it needs is the
 * channel KEY, while the machine only needs "did the step advance". Two readers of
 * one signal, each taking the part it acts on.
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
    if (signal.type !== AUTH_CODE_RESULT_SIGNAL) {
      return
    }
    const data = signal.data as ReturnType<
      typeof authCodeResultSignalSchema.parse
    >
    if (data.reason === AUTH_CODE_REASON_CHANNEL_UNAVAILABLE) {
      handler(data.channel)
    }
  })
}

/**
 * Narrow a step/intent pair off the wire into the patch the machine merges, or
 * null when the step is one this build has no screen for.
 *
 * The one narrowing both inbound halves of the converge property go through: a
 * submit reply names where the flow goes next, and a converge signal names the
 * same thing for a session that submitted nothing. A server one deploy ahead may
 * name a step that does not exist here, and ignoring it is a better answer than
 * a surface stuck on a screen it cannot draw.
 *
 * @param step The step name off the wire.
 * @param intent The intent name off the wire, which may be absent.
 * @returns The patch to merge, or null when the step is unknown.
 */
export function toFlowPatch(
  step: unknown,
  intent: unknown,
): Partial<AuthFlowState> | null {
  const known = FLOW_STEPS.find((candidate) => candidate === step)
  if (known === undefined) {
    return null
  }
  const knownIntent = FLOW_INTENTS.find((candidate) => candidate === intent)

  return knownIntent === undefined
    ? { step: known }
    : { step: known, intent: knownIntent }
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

  // Everything left is the registration confirmation: an EMAIL never signs in by
  // code (its passwordless way is the magic link, which parks on check_inbox), so
  // the login intent cannot reach this line with one.
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
 * Ask the backend to send a code to a phone over the chosen channel, and resolve
 * only once it says what became of it (HIL-492).
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
    // cannot name one that carried nothing.
    next: { step: 'code', intent: flow.intent, channelKey: outcome.channel },
    resendAt: outcome.resendAt,
    expiresAt: outcome.expiresAt,
  }
}

/**
 * Dispatch a phone code request and wait for the outcome signal it triggers.
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
 * dropped) settles inline; no signal is coming for it.
 *
 * It also gives up on its own after {@link CODE_OUTCOME_TIMEOUT_MS}. Waiting on a
 * signal forever is not patience but a dead surface: a reconnect gives the socket a
 * new accept key and an agent restart drops its in-flight ops, so in both cases the
 * outcome is addressed to somebody who no longer exists and nothing will ever
 * arrive. Without the deadline the promise never settles, `pending` never clears,
 * and every later press is a silent no-op — the exact opposite of the recovery the
 * flow is designed around, which is the person pressing the button again.
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
    const settle = (outcome: PhoneCodeOutcome): void => {
      if (settled) {
        return
      }
      settled = true
      clearTimeout(deadline)
      unsubscribe()
      resolve(outcome)
    }

    const unsubscribe = context.connection.on(
      'projectSignal',
      (signal: ProjectSignal) => {
        if (signal.type !== AUTH_CODE_RESULT_SIGNAL) {
          return
        }
        const data = signal.data as ReturnType<
          typeof authCodeResultSignalSchema.parse
        >
        settle({
          ...describeCodeOutcome(data.reason),
          channel: data.channel,
          resendAt: data.resendAt ?? undefined,
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

    context.actions
      .dispatch(AUTH_ACTION_REQUEST_PHONE_CODE, { phone, channel })
      .done.catch((error: unknown) => {
        settle({ ok: false, message: describeAuthError(error), channel })
      })
  })
}

/**
 * Turn a code-request outcome reason into what the surface does about it.
 *
 * TWO arms advance to the code screen, not one. A fresh send obviously does — and
 * so does a send the cooldown held back, because that refusal means a code went to
 * this number moments ago and is still live: stranding the person on the identifier
 * step would hide the very code they are waiting to type.
 *
 * The genuine refusals stay where the person can act — pick another channel, fix
 * the number, or wait out the window. The wording is the client's: the backend
 * deliberately sends a stable reason code and no prose, so no provider or network
 * detail reaches a guest.
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
    return { ok: false, message: describeAuthError(error) }
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
  payload: Record<string, string>,
): Promise<AuthFlowSubmitOutcome> {
  try {
    const { reply } = await context.actions.dispatch(action, payload, {
      replySchema: authFlowOutcomeSchema,
    }).done
    if (reply === undefined) {
      return { ok: true }
    }
    const next = reply.next

    return {
      ok: reply.ok,
      message: reply.message,
      code: reply.code,
      next:
        next === undefined
          ? undefined
          : (toFlowPatch(next.step, next.intent) ?? undefined),
      resendAt: reply.resendAt,
      expiresAt: reply.expiresAt,
    }
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
