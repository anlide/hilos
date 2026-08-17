// The OAuth login client flow (HIL-281): the two client→server actions and the
// browser navigation the daemon's reply signals drive. It is the OAuth analog of
// `authActions`' magic-link relay — the surface's "Continue with GitHub" button
// and the dedicated `/auth/callback` route both dispatch through here, and the
// redirect that starts the flow and the failure that ends it arrive as WS_USER
// signals (`oauthSignals`), never as the action's own reply. This is the "client
// action = loading + signal, never fire-forget" pattern applied end to end:
//
//   click → hilos_oauth_start ── ack means "accepted" ───────────────────────┐
//        └ hilos_oauth_authorize → navigate to provider                      │
//   provider → /auth/callback → hilos_oauth_callback ── ack means "working" ─┤
//        ├ currentUser handshake fan-out (HIL-161) → success, go home        │
//        └ hilos_oauth_result → failure, show error                          │
//
// The provider key is stashed before the redirect and read back on return,
// because the callback URL the provider (or the offline stub) bounces to carries
// only `code` + `state`, not which provider they belong to.
//
// The stretch between the click and the navigation is cancelable (HIL-419): it is
// the one part of an OAuth login that is still ours, and a Cancel landing inside it
// stops the redirect for good rather than merely orphaning it.
import { ActionError } from '../connection/actionLifecycle.js'
import { type ProjectSignal } from '../protocol/parseSignal.js'
import { SIGNAL_HANDSHAKE_RESPONSE } from '../session/sessionScope.js'
import { subscribeSignal, type ReadonlySignal } from '../state/signal.js'
import { type HilosAuthContext } from './authContext.js'
import {
  AUTH_ACTION_LINK_OAUTH_AFTER_REAUTH,
  AUTH_ACTION_LINK_OAUTH_START,
  AUTH_ACTION_OAUTH_CALLBACK,
  AUTH_ACTION_OAUTH_START,
} from './authProtocol.js'
import {
  OAUTH_AUTHORIZE_SIGNAL,
  OAUTH_RESULT_SIGNAL,
  oauthAuthorizeSignalSchema,
  oauthResultSignalSchema,
  type OAuthResultSignalData,
} from './oauthSignals.js'

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
   * @param signal Aborted when the person cancels before the browser leaves.
   */
  startOAuthLogin(provider: string, signal?: AbortSignal): Promise<void>
  /**
   * Begin linking a provider to the signed-in account (HIL-401).
   *
   * @param provider The provider key to link, e.g. `oauth:github`.
   * @param signal Aborted when the person cancels before the browser leaves.
   */
  startOAuthLink(provider: string, signal?: AbortSignal): Promise<void>
  /** Register the browser navigation off the authorize signal; call once at boot. */
  bindOAuthAuthorizeRedirect(): () => void
  /** Read and clear the provider a redirect was started for, on the return leg. */
  takeOAuthProvider(): string
  /**
   * Hand the provider's callback back to the daemon, once the session is ready.
   *
   * @param provider The provider key the callback belongs to.
   * @param code The authorization code the provider returned.
   * @param state The signed state the provider returned.
   */
  dispatchOAuthCallback(
    provider: string,
    code: string,
    state: string,
  ): Promise<void>
  /** Latch the session handshake the callback relay waits on; call once at boot. */
  bindSessionReady(): () => void
  /**
   * Subscribe to the async OAuth failure signal for a callback route.
   *
   * @param handler Called with the (generic) failure payload when the login fails.
   */
  subscribeOAuthFailure(
    handler: (data: OAuthResultSignalData) => void,
  ): () => void
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
 * Session-storage key holding the provider a redirect was started for, so the
 * callback route knows which provider the bounced `code`/`state` belong to. Session
 * storage (not local) so it is scoped to the tab and cleared when it closes.
 */
const OAUTH_PROVIDER_STORAGE_KEY = 'hilos.oauth.provider'

/** The generic message shown when an OAuth login cannot be completed. */
const OAUTH_FAILED_MESSAGE = 'OAuth login failed. Please try again.'

/**
 * The redirect currently between the click and the browser leaving this page
 * (HIL-419): which provider it is for, and whether the person has since canceled.
 *
 * Module scope, because the two ends of the window are two different callbacks —
 * the start dispatch and the authorize signal — and nothing else joins them.
 */
interface OAuthAttempt {
  provider: string
  canceled: boolean
}

let attempt: OAuthAttempt | null = null

/**
 * Open the cancelable window for one redirect: stash the provider for the return
 * leg and record the attempt, so a Cancel arriving before the authorize signal can
 * stop the navigation that signal would otherwise perform.
 *
 * A canceled attempt never navigates — there is no grace window for a late
 * success, as there is for a passkey. The two are asymmetric on purpose: a
 * passkey's late success is a session already raised, a fact, while an OAuth
 * redirect's is only the START of a trip to a provider, and taking somebody off
 * the page after they pressed Cancel takes their decision away from them.
 *
 * @param provider The provider key the redirect is for.
 * @param signal Aborted when the person cancels the parked external step.
 */
function beginAttempt(provider: string, signal?: AbortSignal): void {
  sessionStorage.setItem(OAUTH_PROVIDER_STORAGE_KEY, provider)
  const started: OAuthAttempt = {
    provider,
    canceled: signal?.aborted ?? false,
  }
  attempt = started
  signal?.addEventListener(
    'abort',
    () => {
      started.canceled = true
      // A signal that aborts after a NEWER attempt started belongs to the old one
      // and must not clear the new one's stashed provider.
      if (attempt !== started) {
        return
      }
      sessionStorage.removeItem(OAUTH_PROVIDER_STORAGE_KEY)
    },
    { once: true },
  )
}

/**
 * Begin an OAuth login for a provider: stash the provider for the return leg and
 * dispatch `hilos_oauth_start`. The resolved `done` is the "accepted" ack — the browser
 * is then navigated by the {@link bindOAuthAuthorizeRedirect} handler off the
 * authorize signal — so the caller keeps its loading state through the redirect;
 * a rejection (unknown provider, timeout, disconnect) surfaces inline instead.
 *
 * @param context The project auth context the wire dispatches over.
 * @param provider The provider key to authenticate with, e.g. `oauth:github`.
 * @param signal Aborted when the person cancels before the browser leaves (HIL-419).
 */
export function startOAuthLogin(
  context: HilosAuthContext,
  provider: string,
  signal?: AbortSignal,
): Promise<void> {
  beginAttempt(provider, signal)

  return context.actions
    .dispatch(AUTH_ACTION_OAUTH_START, { provider })
    .done.then(() => undefined)
}

/**
 * Begin linking a provider to the signed-in account from the profile (HIL-401):
 * the link-mode analog of {@link startOAuthLogin}. It stashes the provider for the
 * return leg and dispatches `hilos_link_oauth_start`; the resolved `done` is the
 * "accepted" ack (the browser is then navigated by {@link bindOAuthAuthorizeRedirect}
 * off the authorize signal, so the caller keeps its loading through the redirect),
 * while a rejection (unknown provider, timeout, disconnect) surfaces inline. The
 * callback returns on the same `/auth/callback` route, where a link outcome arrives
 * as an explicit result signal rather than a current-user update.
 *
 * @param context The project auth context the wire dispatches over.
 * @param provider The provider key to link, e.g. `oauth:github`.
 * @param signal Aborted when the person cancels before the browser leaves (HIL-419).
 */
function startOAuthLink(
  context: HilosAuthContext,
  provider: string,
  signal?: AbortSignal,
): Promise<void> {
  beginAttempt(provider, signal)

  return context.actions
    .dispatch(AUTH_ACTION_LINK_OAUTH_START, { provider })
    .done.then(() => undefined)
}

/**
 * Register the browser-navigation reaction to the authorize signal: on
 * `hilos_oauth_authorize`, leave for the provider's authorize URL — but only while
 * an attempt is live and uncanceled (HIL-419). Register it once at boot (before
 * the socket opens) so the reply to a later `hilos_oauth_start` lands. Returns an
 * unsubscribe.
 *
 * @param context The project auth context the wire dispatches over.
 * @returns Unsubscribe for the registered signal handler.
 */
function bindOAuthAuthorizeRedirect(context: HilosAuthContext): () => void {
  return context.connection.on('projectSignal', (signal: ProjectSignal) => {
    if (signal.type !== OAUTH_AUTHORIZE_SIGNAL) {
      return
    }
    if (attempt === null || attempt.canceled) {
      return
    }
    // Validated against the schema at the parse boundary; this is the typed
    // selector for that schema's output.
    const data = signal.data as ReturnType<
      typeof oauthAuthorizeSignalSchema.parse
    >
    window.location.assign(data.authorizeUrl)
  })
}

/**
 * The provider a redirect was started for, read back on the callback route and
 * cleared so a stale value cannot leak into a later attempt. Empty when the route
 * was opened without a preceding start (a direct navigation that cannot succeed, or
 * a start the person canceled before the browser left).
 *
 * The in-flight attempt closes here too: the trip is over, and what happens next is
 * the callback's business.
 *
 * @returns The stashed provider key, or an empty string when absent.
 */
function takeOAuthProvider(): string {
  const provider = sessionStorage.getItem(OAUTH_PROVIDER_STORAGE_KEY) ?? ''
  sessionStorage.removeItem(OAUTH_PROVIDER_STORAGE_KEY)
  attempt = null

  return provider
}

/**
 * Dispatch the provider callback and resolve the synchronous outcome. A resolved
 * `done` means only "accepted, working" — the login completes asynchronously — so
 * the callback route keeps its spinner and resolves on either the current-user
 * update (success) or {@link subscribeOAuthFailure}. A rejection is the
 * synchronous CSRF/state gate (a bad, expired, or foreign state), shown at once.
 *
 * The callback route loads cold from the provider's full-page redirect, so the
 * connection is still opening — and unregistered — when this fires;
 * {@link whenSessionReady} holds the dispatch until the daemon has registered the
 * connection against its session (the handshake response has landed). Transport
 * `connected` alone is not enough: a frame is dropped (not queued) before the
 * socket connects, and — the subtler race — a callback dispatched after `connected`
 * but before the handshake builds its op from a connection with no session yet, so
 * the completed login binds nothing back to this browser and the spinner hangs.
 * Gating on session-ready closes both; a session that never establishes leaves this
 * pending, which the callback route's own timeout backstops.
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
  await whenSessionReady()

  return context.actions
    .dispatch(AUTH_ACTION_OAUTH_CALLBACK, { provider, code, state })
    .done.then(() => undefined)
}

/**
 * Whether the session handshake response has landed on this connection, i.e. the
 * daemon has registered it against its session. Latched by {@link bindSessionReady}
 * and read by {@link whenSessionReady}.
 */
let sessionReady = false

/** Resolvers parked by {@link whenSessionReady} before the handshake landed. */
const sessionReadyWaiters: Array<() => void> = []

/**
 * Latch the session-ready state from the handshake-response signal, so the OAuth
 * callback can hold its dispatch until the daemon has registered this connection
 * against its session (HIL-281). Register once at boot, before the socket opens,
 * so the first handshake response is never missed; the latch stays set across a
 * later reconnect (the connection is re-registered before any re-handshake). A
 * no-op for every other signal. Returns an unsubscribe.
 *
 * The signal name comes from {@link SIGNAL_HANDSHAKE_RESPONSE}, the one the
 * framework's own session scope listens on: this is the same arrival read for a
 * second purpose, not a second name that happens to match.
 *
 * @param context The project auth context the wire dispatches over.
 * @returns Unsubscribe for the registered signal handler.
 */
function bindSessionReady(context: HilosAuthContext): () => void {
  return context.connection.on('projectSignal', (signal: ProjectSignal) => {
    if (signal.type !== SIGNAL_HANDSHAKE_RESPONSE) {
      return
    }
    sessionReady = true
    while (sessionReadyWaiters.length > 0) {
      sessionReadyWaiters.shift()?.()
    }
  })
}

/**
 * Resolve once the session handshake has landed (the daemon has registered this
 * connection against its session). Resolves immediately when it already has;
 * otherwise parks until {@link bindSessionReady} latches the next handshake
 * response. A session that never establishes leaves this pending, which the
 * callback route's own timeout backstop resolves so the spinner cannot wedge.
 *
 * @returns A promise that settles when the session is first established.
 */
function whenSessionReady(): Promise<void> {
  if (sessionReady) {
    return Promise.resolve()
  }

  return new Promise<void>((resolve) => {
    sessionReadyWaiters.push(resolve)
  })
}

/**
 * Subscribe to the async OAuth failure signal for the callback route. Invokes the
 * handler once a `hilos_oauth_result` lands; returns an unsubscribe the route
 * calls the moment it resolves (on failure, success, or timeout) so a late signal
 * cannot fire into a torn-down view.
 *
 * @param context The project auth context the wire dispatches over.
 * @param handler Called with the (generic) failure payload when the login fails.
 * @returns Unsubscribe for the registered signal handler.
 */
function subscribeOAuthFailure(
  context: HilosAuthContext,
  handler: (data: OAuthResultSignalData) => void,
): () => void {
  return context.connection.on('projectSignal', (signal: ProjectSignal) => {
    if (signal.type !== OAUTH_RESULT_SIGNAL) {
      return
    }
    handler(signal.data as ReturnType<typeof oauthResultSignalSchema.parse>)
  })
}

/**
 * Map a failed OAuth action or the failure signal to an inline message. Post-start
 * OAuth failures are deliberately generic (no provider/network detail on the wire);
 * a synchronous rejection carrying a real backend reason surfaces that reason.
 *
 * @param error The caught failure, or undefined for the generic failure arm.
 */
export function describeOAuthError(error?: unknown): string {
  if (error instanceof ActionError && error.outcome === 'fail') {
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
 * The OAuth half of one sign-in surface: the two starts, the callback relay, the
 * boot-time bindings, and the account-link handoff (HIL-281/282/401/419).
 *
 * What the redirect leaves behind — the attempt in flight, the latched session
 * handshake, the armed link — stays at MODULE scope and not in this closure on
 * purpose: the two ends of an OAuth trip are two different callers (a surface
 * clicks, a boot-time binding navigates, a callback route arms, a replay redeems),
 * and a per-closure copy would leave each of them talking to its own memory of a
 * trip only one of them saw.
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
    bindOAuthAuthorizeRedirect: () => bindOAuthAuthorizeRedirect(context),
    takeOAuthProvider,
    dispatchOAuthCallback: (provider, code, state) =>
      dispatchOAuthCallback(context, provider, code, state),
    bindSessionReady: () => bindSessionReady(context),
    subscribeOAuthFailure: (handler) => subscribeOAuthFailure(context, handler),
    armOAuthLink,
    peekOAuthLink,
    bindOAuthLinkReplay: (userId) => bindOAuthLinkReplay(context, userId),
  }
}
