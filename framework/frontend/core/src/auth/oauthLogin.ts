// The OAuth login client flow (HIL-281, HIL-633): one TRIP machine that drives the
// whole journey to a provider in a separate browser window, so the page that
// started it is never unloaded. It is the OAuth analog of `authActions`' magic-link
// relay — the surface's "Continue with GitHub" button and the profile's "Link"
// button both start a trip here — and the redirect that starts it and the result
// that ends it arrive as WS_USER signals (`oauthSignals`), never as an action's own
// reply. This is the "client action = loading + signal, never fire-forget" pattern
// applied end to end:
//
//   click → open the window SYNCHRONOUSLY → hilos_oauth_start ── ack = "accepted" ─┐
//        └ hilos_oauth_authorize → the window replaces its location with it        │
//   provider → the window lands on /auth/callback → postMessage home → it closes ──┤
//   THIS window dispatches hilos_oauth_callback over its OWN live connection ──────┤
//        ├ currentUser handshake fan-out (HIL-161) → signed_in                     │
//        └ hilos_oauth_result → linked / reauth_pending / error                    │
//
// The main window keeps its connection, its subscriptions and its screen for the
// whole trip, which is the point: the exchange is answered on the `acceptKey` of
// the connection that sent the callback, and that connection is now one that was
// never torn down.
//
// TWO CHANNELS, TWO MOMENTS. `startOAuthLogin` / `startOAuthLink` reject when the
// trip NEVER BEGAN — the browser refused the window, or the start action was
// refused — because that is the click's own answer and the caller is still standing
// at the button. Everything that happens to a trip that DID begin is reported to
// {@link HilosOAuthLogin.subscribeOAuthOutcome}, because by then the caller may be a
// different screen than the one that clicked.
//
// The window is opened in the click handler, before anything is awaited: a window
// opened after an await is no longer a gesture as far as the browser is concerned,
// and the pop-up blocker eats it. There is no fallback to a full-page redirect when
// it is blocked — that fallback is exactly the page-unload this ticket removes.
//
// The provider key still rides session storage, but only for the COLD path: a
// callback that finds no opener (the main window was closed while the person was at
// the provider) finishes the exchange in its own document, and the callback URL
// carries only `code` + `state`.
import { ActionError } from '../connection/actionLifecycle.js'
import { type ProjectSignal } from '../protocol/parseSignal.js'
import { sessionUserId } from '../session/sessionScope.js'
import {
  createSignal,
  subscribeSignal,
  type ReadonlySignal,
} from '../state/signal.js'
import { whenPageReady } from '../subscription/pageReadyGate.js'
import { type HilosAuthContext } from './authContext.js'
import {
  AUTH_ACTION_LINK_OAUTH_AFTER_REAUTH,
  AUTH_ACTION_LINK_OAUTH_START,
  AUTH_ACTION_OAUTH_CALLBACK,
  AUTH_ACTION_OAUTH_START,
} from './authProtocol.js'
import {
  OAUTH_AUTHORIZE_SIGNAL,
  OAUTH_REASON_LINK_DUPLICATE,
  OAUTH_REASON_LINK_FAILED,
  OAUTH_REASON_LINK_OK,
  OAUTH_REASON_REAUTH_REQUIRED,
  OAUTH_RESULT_SIGNAL,
  oauthAuthorizeSignalSchema,
  oauthResultSignalSchema,
  type OAuthResultSignalData,
} from './oauthSignals.js'

/** Which half of the product a trip was started from. */
export type OAuthTripIntent = 'login' | 'link'

/**
 * Where a trip is. `authorizing` is the person's time at the provider and has no
 * deadline; `exchanging` is our own round trip to the daemon and does.
 */
export type OAuthTripPhase = 'authorizing' | 'exchanging'

/** The trip this window is currently running, as a waiting surface reads it. */
export interface OAuthTrip {
  /** Which leg of the journey is running. */
  phase: OAuthTripPhase
  /** The provider key the trip is for, e.g. `oauth:github`. */
  provider: string
  /** The provider's short name for the waiting copy, e.g. `GitHub`. */
  providerName: string
  /** Whether the trip signs somebody in or attaches a method to a live session. */
  intent: OAuthTripIntent
}

/**
 * How a trip that began ended. `canceled` covers every quiet ending — the person
 * pressed Cancel, closed the provider window, or declined at the provider — and
 * carries no message, because none of them is a failure to report.
 */
export interface OAuthTripOutcome {
  /** The ending. */
  kind: 'signed_in' | 'linked' | 'reauth_pending' | 'canceled' | 'error'
  /** The sentence to show, empty on every arm but `error`. */
  message: string
}

/** What the waiting surface says while the person is at the provider. */
const OAUTH_TRIP_CONFIRM_MESSAGE =
  "Confirm in the window that just opened. Don't see it? It may be behind this one."

/**
 * The heading a waiting surface shows for a trip. The provider is named for the
 * whole trip, including the exchange: it is still GitHub's answer being waited on,
 * and a heading that changed under the person would read as a second wait.
 *
 * @param trip The live trip.
 * @returns The heading sentence.
 */
export function oauthTripTitle(trip: OAuthTrip): string {
  return `Waiting for ${trip.providerName}`
}

/**
 * What a waiting surface says under the heading: where to look while the person is
 * at the provider, and what is being done once they are back.
 *
 * Framework copy rather than each view's own, because two surfaces show this — the
 * modal over a profile and the parked sign-in screen — in three view layers, and
 * six copies of one sentence drift.
 *
 * @param trip The live trip.
 * @returns The body sentence.
 */
export function oauthTripMessage(trip: OAuthTrip): string {
  if (trip.phase === 'authorizing') {
    return OAUTH_TRIP_CONFIRM_MESSAGE
  }

  return trip.intent === 'link' ? 'Linking your account…' : 'Signing you in…'
}

/** The `type` discriminator of the courier message a callback window sends home. */
export const OAUTH_RETURN_MESSAGE_TYPE = 'hilos.oauth.return'

/**
 * What the callback window posts back to its opener. All three values are always
 * present and an empty string means "absent", so a partial message is a malformed
 * one rather than a shape to guess at. The provider is deliberately NOT here: the
 * main window knows which provider its own trip is for, and taking that from a
 * message would let a message name it.
 */
export interface OAuthReturnMessage {
  /** Always {@link OAUTH_RETURN_MESSAGE_TYPE}. */
  type: typeof OAUTH_RETURN_MESSAGE_TYPE
  /** The authorization code the provider returned, or empty. */
  code: string
  /** The signed state the provider returned, or empty. */
  state: string
  /** The provider's error code (e.g. `access_denied`), or empty. */
  error: string
}

/**
 * The name given to the provider window. Named rather than anonymous so a second
 * start reuses the same window instead of leaving an orphan behind it.
 */
export const HILOS_OAUTH_WINDOW_NAME = 'hilosOAuth'

/** The provider window's shape: a real pop-up, big enough for a consent screen. */
export const OAUTH_WINDOW_FEATURES = 'popup=yes,width=600,height=700'

/**
 * How often the provider window is checked for having been closed by hand. There
 * is no event for it, so closing it is only observable by asking.
 */
export const OAUTH_WINDOW_POLL_MS = 500

/**
 * How long the exchange leg waits for its outcome before giving up. Comfortably
 * past the backend exchange deadline (EXCHANGE_TTL_MS = 15s), so it fires only when
 * the outcome is never coming. The authorizing leg has no deadline of its own: it
 * is the person's time, not ours.
 */
export const OAUTH_EXCHANGE_TIMEOUT_MS = 20000

/** Shown when the browser refused to open the provider window. */
export const OAUTH_POPUP_BLOCKED_MESSAGE =
  'Allow pop-ups for this site to continue.'

/**
 * The refusal a start raises when the browser would not open the provider window.
 * A class rather than a plain `Error`, because the sentence is the person's own
 * browser talking and {@link describeOAuthError} has to be able to tell it apart
 * from an internal failure it must keep to itself.
 */
export class OAuthWindowBlockedError extends Error {
  constructor() {
    super(OAUTH_POPUP_BLOCKED_MESSAGE)
    this.name = 'OAuthWindowBlockedError'
  }
}

/**
 * Session-storage key holding the provider a trip was started for, so a callback
 * that finds no opener knows which provider its `code`/`state` belong to. Session
 * storage (not local) so it is scoped to the tab — and a window opened from that
 * tab inherits a copy of it, which is what makes the cold path work at all.
 */
const OAUTH_PROVIDER_STORAGE_KEY = 'hilos.oauth.provider'

/** The generic message shown when an OAuth login cannot be completed. */
const OAUTH_FAILED_MESSAGE = 'OAuth login failed. Please try again.'

/** Shown when the exchange leg passes {@link OAUTH_EXCHANGE_TIMEOUT_MS}. */
const OAUTH_TIMEOUT_MESSAGE = 'OAuth login timed out. Please try again.'

/** Shown when the provider came back without the code and state to exchange. */
const OAUTH_INCOMPLETE_MESSAGE = 'This sign-in link is invalid or incomplete.'

/**
 * The messages shown when a profile link (HIL-401) does not succeed. A duplicate is
 * a distinct, non-sensitive outcome (the provider is tied to another account); any
 * other link failure is generic (no provider/network detail on the wire).
 */
const LINK_DUPLICATE_MESSAGE = 'That account is already linked to another user.'
const LINK_FAILED_MESSAGE = 'Could not link the account. Please try again.'

/**
 * The OAuth client one context is bound to, as a surface and the boot sequence
 * see it. `describeOAuthError` is not here: it maps an error to a sentence and
 * needs no context, so it stays a plain export.
 */
export interface HilosOAuthLogin {
  /**
   * Begin an OAuth login for a provider (HIL-281).
   *
   * @param provider The provider key to authenticate with, e.g. `oauth:github`.
   * @param signal Aborted when the person cancels the trip.
   */
  startOAuthLogin(provider: string, signal?: AbortSignal): Promise<void>
  /**
   * Begin linking a provider to the signed-in account (HIL-401).
   *
   * @param provider The provider key to link, e.g. `oauth:github`.
   * @param signal Aborted when the person cancels the trip.
   */
  startOAuthLink(provider: string, signal?: AbortSignal): Promise<void>
  /** The trip this window is running, or null when none is; drives the waiting UI. */
  trip: ReadonlySignal<OAuthTrip | null>
  /** End the live trip the way a person ends it: close the window, no error. */
  cancelOAuthTrip(): void
  /**
   * Subscribe to how trips end. Fires once per trip that began.
   *
   * @param handler Called with the outcome when a trip ends.
   */
  subscribeOAuthOutcome(
    handler: (outcome: OAuthTripOutcome) => void,
  ): () => void
  /**
   * Finish a provider return in THIS document, for a callback that found no
   * opener to hand it to (the cold path).
   *
   * @param code The authorization code the provider returned, or empty.
   * @param state The signed state the provider returned, or empty.
   * @param error The provider's error code, or empty.
   */
  resumeOAuthReturn(code: string, state: string, error: string): void
  /** Register the trip's three boot-time bindings; call once at boot. */
  bindOAuthTrip(): () => void
  /**
   * Arm the pending account-link a collision callback captured (HIL-282).
   *
   * @param email The colliding account email, to pre-fill the re-auth form.
   * @param linkToken The signed capability token to redeem once re-authenticated.
   */
  armOAuthLink(email: string, linkToken: string): void
  /** Read the armed pending link without consuming it, for the re-auth prompt. */
  peekOAuthLink(): PendingOAuthLink | null
  /**
   * Register the replay that redeems an armed link when the session upgrades.
   *
   * @param userId The current-user signal whose turn to non-null carries the upgrade.
   */
  bindOAuthLinkReplay(userId: ReadonlySignal<number | null>): () => void
}

/**
 * The trip in flight in THIS window: what it is for, and the window carrying it.
 *
 * Module scope, because the legs of a trip are separate callbacks — a surface
 * clicks, a boot-time binding substitutes the authorize URL, a message brings the
 * return, a watch sees the session upgrade — and a per-closure copy would leave
 * each of them talking to its own memory of a trip only one of them saw.
 */
interface OAuthTripAttempt {
  provider: string
  providerName: string
  intent: OAuthTripIntent
  phase: OAuthTripPhase
  window: Window
  /**
   * This document's name for the trip, sent with the start and echoed back on the
   * authorize signal. Not part of {@link OAuthTrip}: nothing a person is shown
   * depends on it, and the only reader is the signal reaction below.
   */
  tripId: string
}

let attempt: OAuthTripAttempt | null = null

/**
 * Numbers the trips this document starts, so a trip can recognize its own answer
 * (HIL-707). A counter rather than a random token for the same reason
 * `actionLifecycle` numbers its requests: this is not a secret and not a key —
 * the frame is already addressed by accept key, and a second tab reads its own
 * frames on its own connection, so the number only has to be unique among the
 * trips of ONE document.
 */
let tripSequence = 0

/** The live trip as the waiting surfaces read it; mirrors {@link attempt}. */
const tripSignal = createSignal<OAuthTrip | null>(null)

/**
 * The trip this window is running, or null when none is. The read side of
 * {@link tripSignal}, exported on its own so the application shell — which mounts
 * the waiting modal and holds no auth context — can read it.
 */
export const oauthTrip: ReadonlySignal<OAuthTrip | null> = tripSignal

/** Everyone listening for how trips end. */
const outcomeHandlers = new Set<(outcome: OAuthTripOutcome) => void>()

/**
 * Tears down the exchange leg's three arms (the result signal, the current-user
 * watch, the deadline). Non-null exactly while an exchange is in flight — which is
 * also how the cold path, where there is no {@link attempt} at all, is known to
 * have something to settle.
 */
let releaseExchange: (() => void) | null = null

/**
 * Mirror the live attempt into the signal the waiting surfaces read. A trip is
 * published as a new object every time, because change detection is `Object.is`.
 */
function publishTrip(): void {
  tripSignal.set(
    attempt === null
      ? null
      : {
          phase: attempt.phase,
          provider: attempt.provider,
          providerName: attempt.providerName,
          intent: attempt.intent,
        },
  )
}

/**
 * Drop the trip and everything holding it open, without saying anything about how
 * it went. Closing the provider window is NOT here: whether it is closed depends on
 * who ended the trip, and by the exchange leg it has closed itself.
 */
function clearTrip(): void {
  releaseExchange?.()
  releaseExchange = null
  attempt = null
  sessionStorage.removeItem(OAUTH_PROVIDER_STORAGE_KEY)
  publishTrip()
}

/**
 * End the trip and tell the subscribers how. Every caller either holds a live trip
 * or IS the ending (the cold path, which has no trip to hold), so this reports
 * unconditionally; what keeps a trip from ending twice is that settling takes the
 * exchange's arms down with it.
 *
 * @param outcome How the trip ended.
 */
function finishTrip(outcome: OAuthTripOutcome): void {
  clearTrip()
  for (const handler of [...outcomeHandlers]) {
    handler(outcome)
  }
}

/**
 * The provider's short name for the waiting copy. An unknown key falls back to
 * itself rather than to an empty string: "Waiting for oauth:github" is ugly and
 * says what is wrong, while "Waiting for " says nothing at all.
 *
 * @param context The project auth context declaring this deployment's providers.
 * @param provider The provider key a trip was started for.
 */
function providerNameOf(context: HilosAuthContext, provider: string): string {
  const option = context.oauthProviders.find((entry) => entry.key === provider)

  return option?.name ?? provider
}

/**
 * Open the provider window and record the trip it carries. Called from the click
 * handler with nothing awaited before it, so the browser still counts the window as
 * a gesture.
 *
 * @param context The project auth context the wire dispatches over.
 * @param provider The provider key the trip is for.
 * @param intent Whether the trip signs somebody in or links a method.
 * @param signal Aborted when the person cancels the trip (HIL-419).
 * @returns The recorded trip, or null when the browser refused the window.
 */
function beginTrip(
  context: HilosAuthContext,
  provider: string,
  intent: OAuthTripIntent,
  signal?: AbortSignal,
): OAuthTripAttempt | null {
  // Stashed BEFORE the window is opened, and that order is the whole of the cold
  // path: a window opened from this one starts with a COPY of this document's
  // session storage, taken at the moment it is created, so a write landing
  // afterwards is invisible inside it — and that copy is the only place a callback
  // with no opener has to read the provider back from.
  sessionStorage.setItem(OAUTH_PROVIDER_STORAGE_KEY, provider)
  const opened = window.open('', HILOS_OAUTH_WINDOW_NAME, OAUTH_WINDOW_FEATURES)
  if (opened === null) {
    // No window, no trip: a stash left behind would outlive a trip that never
    // began and name the provider of a later, unrelated cold return.
    sessionStorage.removeItem(OAUTH_PROVIDER_STORAGE_KEY)

    return null
  }
  const started: OAuthTripAttempt = {
    provider,
    providerName: providerNameOf(context, provider),
    intent,
    phase: 'authorizing',
    window: opened,
    // Numbered here rather than at the top of the call: a trip the browser
    // refused a window returned above, never went on the wire, and has no answer
    // coming that anybody would have to match.
    tripId: String(++tripSequence),
  }
  attempt = started
  publishTrip()
  signal?.addEventListener(
    'abort',
    () => {
      // An abort that arrives after a NEWER trip started belongs to the old one
      // and must not end the new one.
      if (attempt !== started) {
        return
      }
      cancelOAuthTrip()
    },
    { once: true },
  )
  if (signal?.aborted === true) {
    // `addEventListener` never fires on an already-aborted signal, and a trip
    // starting from one is over before it began.
    cancelOAuthTrip()
  }

  return started
}

/**
 * End the live trip the way a person ends it: shut the provider window and report
 * a cancellation. The same ending for Cancel, for closing the window by hand, and
 * for declining at the provider — all three are a decision, not a failure.
 *
 * A no-op when nothing is in flight. This is the one ending anybody may ask for at
 * any time — a surface unmounting, a second press of Cancel — so it is the one that
 * has to answer "there is no trip" with silence rather than with a cancellation
 * nobody made.
 *
 * Exported on its own as well as through the client, for the same reason
 * {@link describeOAuthError} is: the waiting modal is mounted by the application
 * shell, which holds no auth context to bind a client from — and a trip needs
 * none, being module state.
 */
export function cancelOAuthTrip(): void {
  if (attempt === null && releaseExchange === null) {
    return
  }
  attempt?.window.close()
  finishTrip({ kind: 'canceled', message: '' })
}

/**
 * Start a trip: open the window first, then ask the daemon for the authorize URL
 * to put in it. The resolved promise is the "accepted" ack — the URL arrives on the
 * authorize signal, and the trip's own ending arrives at the outcome subscribers —
 * so the caller keeps its loading state for the whole trip. A rejection means the
 * trip never began, and the button that was clicked is still the place to say so.
 *
 * @param context The project auth context the wire dispatches over.
 * @param provider The provider key to authenticate with, e.g. `oauth:github`.
 * @param intent Whether the trip signs somebody in or links a method.
 * @param action The backend action that answers with the authorize signal.
 * @param signal Aborted when the person cancels the trip (HIL-419).
 */
function startTrip(
  context: HilosAuthContext,
  provider: string,
  intent: OAuthTripIntent,
  action: string,
  signal?: AbortSignal,
): Promise<void> {
  const started = beginTrip(context, provider, intent, signal)
  if (started === null) {
    return Promise.reject(new OAuthWindowBlockedError())
  }

  const payload = { provider, tripId: started.tripId }

  return context.actions.dispatch(action, payload).done.then(
    () => undefined,
    (error: unknown) => {
      // Refused before the trip could start: shut the window we opened on
      // speculation and let the click's own caller show the reason.
      //
      // Only OUR trip, though. A refusal is answered up to the action timeout,
      // which is long enough for the person to have started another one — and
      // ending that one here would shut a window they are standing in front of.
      // The same guard the abort listener carries, for the same reason.
      if (attempt === started) {
        started.window.close()
        clearTrip()
      }

      throw error
    },
  )
}

/**
 * Begin an OAuth login for a provider (HIL-281): the sign-in half of
 * {@link startTrip}. Success arrives as the current-user handshake fan-out, which
 * the trip machine turns into a `signed_in` outcome.
 *
 * @param context The project auth context the wire dispatches over.
 * @param provider The provider key to authenticate with, e.g. `oauth:github`.
 * @param signal Aborted when the person cancels the trip (HIL-419).
 */
export function startOAuthLogin(
  context: HilosAuthContext,
  provider: string,
  signal?: AbortSignal,
): Promise<void> {
  return startTrip(context, provider, 'login', AUTH_ACTION_OAUTH_START, signal)
}

/**
 * Begin linking a provider to the signed-in account from the profile (HIL-401):
 * the link half of {@link startTrip}. The session never changes, so success arrives
 * as an explicit result signal, which the trip machine turns into a `linked`
 * outcome.
 *
 * @param context The project auth context the wire dispatches over.
 * @param provider The provider key to link, e.g. `oauth:github`.
 * @param signal Aborted when the person cancels the trip (HIL-419).
 */
function startOAuthLink(
  context: HilosAuthContext,
  provider: string,
  signal?: AbortSignal,
): Promise<void> {
  return startTrip(
    context,
    provider,
    'link',
    AUTH_ACTION_LINK_OAUTH_START,
    signal,
  )
}

/**
 * Register the three bindings a trip needs from the document it runs in: the
 * authorize URL going into the provider window, the courier message coming back,
 * and the poll that notices the window being closed by hand. Register them once at
 * boot (before the socket opens) so the reply to a later start lands. Returns an
 * unsubscribe.
 *
 * @param context The project auth context the wire dispatches over.
 * @returns Unsubscribe for all three registrations.
 */
function bindOAuthTrip(context: HilosAuthContext): () => void {
  const stopAuthorize = context.connection.on(
    'projectSignal',
    (signal: ProjectSignal) => {
      if (signal.type !== OAUTH_AUTHORIZE_SIGNAL) {
        return
      }
      // Validated against the schema at the parse boundary; this is the typed
      // selector for that schema's output. Read BEFORE the decision, not after:
      // a frame that turns out to belong to somebody else is the one the log has
      // to name, and naming it needs the frame.
      const data = signal.data as ReturnType<
        typeof oauthAuthorizeSignalSchema.parse
      >
      // Three ways an authorize URL is not this window's to follow, and the same
      // answer to all three. No trip at all, or a trip that is not the one the
      // frame names: the frame belongs to a trip that was abandoned, and the
      // window it would steer is one the person is standing in front of right
      // now. The frame's own trip past the authorizing leg: the window has closed
      // itself, and an authorize URL answers a question nobody is still asking.
      if (
        attempt === null ||
        attempt.tripId !== data.tripId ||
        attempt.phase !== 'authorizing'
      ) {
        console.warn(
          `[hilos] dropped an authorize URL for ${data.provider} trip ${data.tripId}: this window is not waiting on it`,
        )

        return
      }
      // `replace`, not `assign`: the blank page the window opened on is not
      // somewhere its Back button should be able to return to.
      attempt.window.location.replace(data.authorizeUrl)
    },
  )
  const onMessage = (event: MessageEvent): void => {
    receiveOAuthReturn(context, event)
  }
  window.addEventListener('message', onMessage)
  const poll = setInterval(() => {
    if (attempt === null || attempt.phase !== 'authorizing') {
      return
    }
    if (attempt.window.closed) {
      cancelOAuthTrip()
    }
  }, OAUTH_WINDOW_POLL_MS)

  return () => {
    stopAuthorize()
    window.removeEventListener('message', onMessage)
    clearInterval(poll)
  }
}

/**
 * Read a courier message, or refuse it. A message is ours only when it names
 * itself and carries all three fields as strings; anything else is somebody else's
 * traffic on the same window.
 *
 * @param data The `data` of a received message event.
 * @returns The message, or null when it is not one of ours.
 */
function readReturnMessage(data: unknown): OAuthReturnMessage | null {
  if (typeof data !== 'object' || data === null) {
    return null
  }
  const message = data as Partial<OAuthReturnMessage>
  if (
    message.type !== OAUTH_RETURN_MESSAGE_TYPE ||
    typeof message.code !== 'string' ||
    typeof message.state !== 'string' ||
    typeof message.error !== 'string'
  ) {
    return null
  }

  return {
    type: OAUTH_RETURN_MESSAGE_TYPE,
    code: message.code,
    state: message.state,
    error: message.error,
  }
}

/**
 * Take the provider's return from the courier window and move the trip onto its
 * exchange leg.
 *
 * TWO gates, and both are needed. The origin gate keeps a message from any other
 * site out; the source gate keeps out a message from another window of THIS site,
 * including one this page opened for something else. Neither alone is a check: the
 * provider key is read from the trip we started rather than from the message, so
 * even a message that passes both cannot name a provider of its own.
 *
 * @param context The project auth context the wire dispatches over.
 * @param event The received message event.
 */
function receiveOAuthReturn(
  context: HilosAuthContext,
  event: MessageEvent,
): void {
  if (attempt === null || attempt.phase !== 'authorizing') {
    return
  }
  if (
    event.origin !== window.location.origin ||
    event.source !== attempt.window
  ) {
    return
  }
  const message = readReturnMessage(event.data)
  if (message === null) {
    return
  }
  if (message.error !== '') {
    // The person declined at the provider (`access_denied`) or the provider
    // refused: a quiet ending, not a red one.
    cancelOAuthTrip()

    return
  }
  if (message.code === '' || message.state === '') {
    finishTrip({ kind: 'error', message: OAUTH_INCOMPLETE_MESSAGE })

    return
  }
  const provider = attempt.provider
  attempt.phase = 'exchanging'
  publishTrip()
  runExchange(context, provider, message.code, message.state)
}

/**
 * Run the exchange leg: arm the three ways it can end, then hand the callback to
 * the daemon over THIS window's connection.
 *
 * The arms go up BEFORE the dispatch, so an early current-user update or result
 * signal cannot slip past. They come down together the moment one of them wins, so
 * a late one cannot fire into a trip that is over.
 *
 * @param context The project auth context the wire dispatches over.
 * @param provider The provider key the callback belongs to.
 * @param code The authorization code the provider returned.
 * @param state The signed state the provider returned.
 */
function runExchange(
  context: HilosAuthContext,
  provider: string,
  code: string,
  state: string,
): void {
  const stopResult = context.connection.on(
    'projectSignal',
    (signal: ProjectSignal) => {
      if (signal.type !== OAUTH_RESULT_SIGNAL) {
        return
      }
      applyResult(
        signal.data as ReturnType<typeof oauthResultSignalSchema.parse>,
      )
    },
  )
  // A login succeeds by the session becoming somebody's (HIL-161). A link never
  // changes the session, so this stays silent for one and `link_ok` answers it.
  const stopUser = subscribeSignal(sessionUserId(context.scopes), (id) => {
    if (id !== null) {
      finishTrip({ kind: 'signed_in', message: '' })
    }
  })
  const deadline = setTimeout(() => {
    finishTrip({ kind: 'error', message: OAUTH_TIMEOUT_MESSAGE })
  }, OAUTH_EXCHANGE_TIMEOUT_MS)
  const release = (): void => {
    stopResult()
    stopUser()
    clearTimeout(deadline)
  }
  releaseExchange = release

  dispatchOAuthCallback(context, provider, code, state).catch(
    (error: unknown) => {
      // A rejection landing after the trip already ended belongs to a trip that is
      // over; unlike the other two arms, a promise cannot be unsubscribed from.
      if (releaseExchange !== release) {
        return
      }
      finishTrip({ kind: 'error', message: describeOAuthError(error) })
    },
  )
}

/**
 * Turn an OAuth result signal into the trip's ending.
 *
 * @param data The result payload the daemon sent.
 */
function applyResult(data: OAuthResultSignalData): void {
  if (
    data.reason === OAUTH_REASON_REAUTH_REQUIRED &&
    data.email !== null &&
    data.linkToken !== null
  ) {
    // The provider email collided with an existing verified account (HIL-282):
    // not a failure — arm the pending link (module state, so it outlives the
    // surface the gate later closes) and let the sign-in surface ask for the
    // password. The token is redeemed by the replay once that re-auth lands.
    armOAuthLink(data.email, data.linkToken)
    finishTrip({ kind: 'reauth_pending', message: '' })

    return
  }
  if (data.reason === OAUTH_REASON_LINK_OK) {
    finishTrip({ kind: 'linked', message: '' })

    return
  }
  if (data.reason === OAUTH_REASON_LINK_DUPLICATE) {
    finishTrip({ kind: 'error', message: LINK_DUPLICATE_MESSAGE })

    return
  }
  if (data.reason === OAUTH_REASON_LINK_FAILED) {
    finishTrip({ kind: 'error', message: LINK_FAILED_MESSAGE })

    return
  }
  finishTrip({ kind: 'error', message: OAUTH_FAILED_MESSAGE })
}

/**
 * Finish a provider return in the document that received it, because there is no
 * opener to hand it to — the main window was closed while the person was at the
 * provider, or somebody opened the callback URL directly. This is the only way to
 * finish a trip whose starting window is gone.
 *
 * No trip is published: the callback page IS the surface here, showing its own
 * progress and doing its own navigating, and a waiting modal over it would be a
 * second opinion about the same wait.
 *
 * @param context The project auth context the wire dispatches over.
 * @param code The authorization code the provider returned, or empty.
 * @param state The signed state the provider returned, or empty.
 * @param error The provider's error code, or empty.
 */
function resumeOAuthReturn(
  context: HilosAuthContext,
  code: string,
  state: string,
  error: string,
): void {
  const provider = takeOAuthProvider()
  if (error !== '') {
    finishTrip({ kind: 'canceled', message: '' })

    return
  }
  if (provider === '' || code === '' || state === '') {
    finishTrip({ kind: 'error', message: OAUTH_INCOMPLETE_MESSAGE })

    return
  }
  runExchange(context, provider, code, state)
}

/**
 * The provider a trip was started for, read back on the cold path and cleared so a
 * stale value cannot leak into a later trip. Empty when the document never started
 * one — a direct navigation to the callback URL, which cannot succeed.
 *
 * @returns The stashed provider key, or an empty string when absent.
 */
function takeOAuthProvider(): string {
  const provider = sessionStorage.getItem(OAUTH_PROVIDER_STORAGE_KEY) ?? ''
  sessionStorage.removeItem(OAUTH_PROVIDER_STORAGE_KEY)

  return provider
}

/**
 * Dispatch the provider callback and resolve the synchronous outcome. A resolved
 * `done` means only "accepted, working" — the login completes asynchronously, and
 * the trip's arms resolve it. A rejection is the synchronous CSRF/state gate (a
 * bad, expired, or foreign state).
 *
 * {@link whenPageReady} holds the dispatch until a page subscription has been
 * answered (HIL-607). On the hot path that is already true — the window that
 * dispatches never left the page — but the cold path loads a fresh document, and
 * transport `connected` alone is not enough there: a frame is dropped (not queued)
 * before the socket connects, and — the subtler race — a callback dispatched after
 * `connected` but before the handshake builds its op from a connection with no
 * session yet, so the completed login binds nothing back to this browser and the
 * spinner hangs. The page's answer closes both, and closes them strictly harder
 * than the handshake this used to wait on: a page is only answered after the
 * session that judges it. A connection that never answers leaves this pending,
 * which the trip's own deadline backstops.
 *
 * @param context The project auth context the wire dispatches over.
 * @param provider The provider key the callback belongs to.
 * @param code The authorization code the provider returned.
 * @param state The signed state the provider returned.
 */
async function dispatchOAuthCallback(
  context: HilosAuthContext,
  provider: string,
  code: string,
  state: string,
): Promise<void> {
  await whenPageReady()

  return context.actions
    .dispatch(AUTH_ACTION_OAUTH_CALLBACK, { provider, code, state })
    .done.then(() => undefined)
}

/**
 * Subscribe to how trips end. The machine, not the surface, decides what an ending
 * is — which is why a surface no longer subscribes to the raw result signal.
 *
 * @param handler Called with the outcome when a trip ends.
 * @returns Unsubscribe for the registered handler.
 */
function subscribeOAuthOutcome(
  handler: (outcome: OAuthTripOutcome) => void,
): () => void {
  outcomeHandlers.add(handler)

  return () => {
    outcomeHandlers.delete(handler)
  }
}

/**
 * Map a failed OAuth action or the failure signal to an inline message. Post-start
 * OAuth failures are deliberately generic (no provider/network detail on the wire);
 * a synchronous rejection carrying a real backend reason surfaces that reason, and
 * so does the refused pop-up, which is the person's own browser talking.
 *
 * @param error The caught failure, or undefined for the generic failure arm.
 */
export function describeOAuthError(error?: unknown): string {
  if (error instanceof ActionError && error.outcome === 'fail') {
    return error.message
  }
  if (error instanceof OAuthWindowBlockedError) {
    return error.message
  }

  return OAUTH_FAILED_MESSAGE
}

/**
 * A pending OAuth account-link awaiting the collision re-auth (HIL-282): the
 * colliding account's email (to pre-fill the sign-in form) and the signed
 * capability token to redeem once the session upgrades. Held at module scope, not
 * in a component, because the auth surface that drives the re-auth is unmounted by
 * the gate the instant the session upgrades — the replay ({@link bindOAuthLinkReplay})
 * must outlive it. Only ever one at a time; a new collision overwrites an old one.
 */
export interface PendingOAuthLink {
  email: string
  linkToken: string
}

let pendingLink: PendingOAuthLink | null = null

/**
 * Arm the pending link the collision callback captured, so the sign-in surface can
 * pre-fill the email and the replay watcher can redeem the token after re-auth.
 *
 * @param email The colliding account email, to pre-fill the re-auth form.
 * @param linkToken The signed capability token to redeem once re-authenticated.
 */
function armOAuthLink(email: string, linkToken: string): void {
  pendingLink = { email, linkToken }
}

/**
 * Read the armed pending link without consuming it, for the sign-in surface to
 * pre-fill the email and show its "finish linking" prompt.
 *
 * @returns The armed pending link, or null when none is pending.
 */
function peekOAuthLink(): PendingOAuthLink | null {
  return pendingLink
}

/**
 * Dispatch the link redemption after re-auth: hand the signed token back so the
 * backend can bind the OAuth identity to the now-authenticated account. The
 * resolved `done` is the `::success` ack; a rejection is the generic backend
 * refusal (expired, foreign, or already-linked token).
 *
 * @param context The project auth context the wire dispatches over.
 * @param token The signed link token captured by the collision callback.
 */
function dispatchLinkOAuthAfterReauth(
  context: HilosAuthContext,
  token: string,
): Promise<void> {
  return context.actions
    .dispatch(AUTH_ACTION_LINK_OAUTH_AFTER_REAUTH, { token })
    .done.then(() => undefined)
}

/**
 * Register the global replay of a pending OAuth link (HIL-282): when the session
 * upgrades (`userId` turns non-null) with a link armed, consume it and redeem the
 * token to bind the OAuth identity. Bound once at boot, outside any component, so
 * it survives the sign-in surface unmounting when the auth gate closes on the
 * upgrade. A no-op for a normal login (nothing armed). Returns an unsubscribe.
 *
 * @param context The project auth context the wire dispatches over.
 * @param userId The current-user signal whose turn to non-null carries the upgrade.
 * @returns Unsubscribe for the registered signal subscription.
 */
function bindOAuthLinkReplay(
  context: HilosAuthContext,
  userId: ReadonlySignal<number | null>,
): () => void {
  return subscribeSignal(userId, (id) => {
    if (id === null || pendingLink === null) {
      return
    }
    const token = pendingLink.linkToken
    pendingLink = null
    // The user is already signed in as the matched account, so a rejected token
    // (expired, foreign, or already linked) leaves that session intact; the failure
    // is swallowed rather than surfaced, there being no re-auth surface left to show it.
    dispatchLinkOAuthAfterReauth(context, token).catch(ignoreLinkFailure)
  })
}

/**
 * Swallow a link-replay rejection: the re-auth already succeeded and the user is
 * signed in, so a bad or already-redeemed token must not disturb that session.
 */
function ignoreLinkFailure(): void {
  // Intentionally empty — see the doc comment.
}

/**
 * The OAuth half of one sign-in surface: the two starts, the trip machine, the
 * boot-time bindings, and the account-link handoff (HIL-281/282/401/419/633).
 *
 * What a trip is made of stays at MODULE scope and not in this closure on purpose:
 * the legs of a trip are separate callers (a surface clicks, a boot-time binding
 * substitutes the URL, a courier message returns, a replay redeems), and a
 * per-closure copy would leave each of them talking to its own memory of a trip
 * only one of them saw.
 *
 * @param context The project auth context the wire dispatches over.
 * @returns The bound OAuth client the surfaces and the boot sequence call.
 */
export function createOAuthLogin(context: HilosAuthContext): HilosOAuthLogin {
  return {
    startOAuthLogin: (provider, signal) =>
      startOAuthLogin(context, provider, signal),
    startOAuthLink: (provider, signal) =>
      startOAuthLink(context, provider, signal),
    trip: tripSignal,
    cancelOAuthTrip,
    subscribeOAuthOutcome,
    resumeOAuthReturn: (code, state, error) =>
      resumeOAuthReturn(context, code, state, error),
    bindOAuthTrip: () => bindOAuthTrip(context),
    armOAuthLink,
    peekOAuthLink,
    bindOAuthLinkReplay: (userId) => bindOAuthLinkReplay(context, userId),
  }
}
