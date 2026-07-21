// The OAuth login client flow (HIL-281): the two client→server actions and the
// browser navigation the daemon's reply signals drive. It is the OAuth analog of
// `authActions`' magic-link relay — the surface's "Continue with GitHub" button
// and the dedicated `/auth/callback` route both dispatch through here, and the
// redirect that starts the flow and the failure that ends it arrive as WS_USER
// signals (`oauthSignals`), never as the action's own reply. This is the "client
// action = loading + signal, never fire-forget" pattern applied end to end:
//
//   click → oauth_start ── ack means "accepted" ─────────────────────────┐
//        └ hilos_oauth_authorize → navigate to provider                  │
//   provider → /auth/callback → oauth_callback ── ack means "working" ────┤
//        ├ currentUser handshake fan-out (HIL-161) → success, go home     │
//        └ hilos_oauth_result → failure, show error                       │
//
// The provider key is stashed before the redirect and read back on return,
// because the callback URL the provider (or the offline stub) bounces to carries
// only `code` + `state`, not which provider they belong to.
import { ActionError, subscribeSignal } from '@hilos/core'
import type { ProjectSignal, ReadonlySignal } from '@hilos/core'

import { actions, connection } from '../bootstrap/connection'
import {
  OAUTH_AUTHORIZE_SIGNAL,
  OAUTH_RESULT_SIGNAL,
  oauthAuthorizeSignalSchema,
  oauthResultSignalSchema,
  type OAuthResultSignalData,
} from './oauthSignals'

/** Backend action: begin an OAuth redirect (PHP `ChatSignalConstants::OAUTH_START`). */
const OAUTH_START_ACTION = 'oauth_start'

/** Backend action: hand back the provider callback (PHP `ChatSignalConstants::OAUTH_CALLBACK`). */
const OAUTH_CALLBACK_ACTION = 'oauth_callback'

/** Backend action: redeem an email-collision link token after re-auth (PHP `ChatSignalConstants::LINK_OAUTH_AFTER_REAUTH`). */
const LINK_OAUTH_AFTER_REAUTH_ACTION = 'link_oauth_after_reauth'

/**
 * Session-storage key holding the provider a redirect was started for, so the
 * callback route knows which provider the bounced `code`/`state` belong to. Session
 * storage (not local) so it is scoped to the tab and cleared when it closes.
 */
const OAUTH_PROVIDER_STORAGE_KEY = 'hilos.oauth.provider'

/** The generic message shown when an OAuth login cannot be completed. */
const OAUTH_FAILED_MESSAGE = 'OAuth login failed. Please try again.'

/**
 * Begin an OAuth login for a provider: stash the provider for the return leg and
 * dispatch `oauth_start`. The resolved `done` is the "accepted" ack — the browser
 * is then navigated by the {@link bindOAuthAuthorizeRedirect} handler off the
 * authorize signal — so the caller keeps its loading state through the redirect;
 * a rejection (unknown provider, timeout, disconnect) surfaces inline instead.
 *
 * @param provider The provider key to authenticate with, e.g. `oauth:github`.
 */
export function startOAuthLogin(provider: string): Promise<void> {
  sessionStorage.setItem(OAUTH_PROVIDER_STORAGE_KEY, provider)

  return actions.dispatch(OAUTH_START_ACTION, { provider }).done
}

/**
 * Register the browser-navigation reaction to the authorize signal: on
 * `hilos_oauth_authorize`, leave for the provider's authorize URL. Register it
 * once at boot (before the socket opens) so the reply to a later `oauth_start`
 * lands. Returns an unsubscribe.
 *
 * @returns Unsubscribe for the registered signal handler.
 */
export function bindOAuthAuthorizeRedirect(): () => void {
  return connection.on('projectSignal', (signal: ProjectSignal) => {
    if (signal.type !== OAUTH_AUTHORIZE_SIGNAL) {
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
 * was opened without a preceding start (a direct navigation that cannot succeed).
 *
 * @returns The stashed provider key, or an empty string when absent.
 */
export function takeOAuthProvider(): string {
  const provider = sessionStorage.getItem(OAUTH_PROVIDER_STORAGE_KEY) ?? ''
  sessionStorage.removeItem(OAUTH_PROVIDER_STORAGE_KEY)

  return provider
}

/**
 * Dispatch the provider callback and resolve the synchronous outcome. A resolved
 * `done` means only "accepted, working" — the login completes asynchronously — so
 * the callback route keeps its spinner and resolves on either the current-user
 * update (success) or {@link subscribeOAuthFailure}. A rejection is the
 * synchronous CSRF/state gate (a bad, expired, or foreign state), shown at once.
 *
 * @param provider The provider key the callback belongs to.
 * @param code The authorization code the provider returned.
 * @param state The signed state the provider returned.
 */
export function dispatchOAuthCallback(
  provider: string,
  code: string,
  state: string,
): Promise<void> {
  return actions.dispatch(OAUTH_CALLBACK_ACTION, { provider, code, state }).done
}

/**
 * Subscribe to the async OAuth failure signal for the callback route. Invokes the
 * handler once a `hilos_oauth_result` lands; returns an unsubscribe the route
 * calls the moment it resolves (on failure, success, or timeout) so a late signal
 * cannot fire into a torn-down view.
 *
 * @param handler Called with the (generic) failure payload when the login fails.
 * @returns Unsubscribe for the registered signal handler.
 */
export function subscribeOAuthFailure(
  handler: (data: OAuthResultSignalData) => void,
): () => void {
  return connection.on('projectSignal', (signal: ProjectSignal) => {
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
interface PendingOAuthLink {
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
export function armOAuthLink(email: string, linkToken: string): void {
  pendingLink = { email, linkToken }
}

/**
 * Read the armed pending link without consuming it, for the sign-in surface to
 * pre-fill the email and show its "finish linking" prompt.
 *
 * @returns The armed pending link, or null when none is pending.
 */
export function peekOAuthLink(): PendingOAuthLink | null {
  return pendingLink
}

/**
 * Dispatch the link redemption after re-auth: hand the signed token back so the
 * backend can bind the OAuth identity to the now-authenticated account. The
 * resolved `done` is the `::success` ack; a rejection is the generic backend
 * refusal (expired, foreign, or already-linked token).
 *
 * @param token The signed link token captured by the collision callback.
 */
export function dispatchLinkOAuthAfterReauth(token: string): Promise<void> {
  return actions.dispatch(LINK_OAUTH_AFTER_REAUTH_ACTION, { token }).done
}

/**
 * Register the global replay of a pending OAuth link (HIL-282): when the session
 * upgrades (`userId` turns non-null) with a link armed, consume it and redeem the
 * token to bind the OAuth identity. Bound once at boot, outside any component, so
 * it survives the sign-in surface unmounting when the auth gate closes on the
 * upgrade. A no-op for a normal login (nothing armed). Returns an unsubscribe.
 *
 * @param userId The current-user signal whose turn to non-null carries the upgrade.
 * @returns Unsubscribe for the registered signal subscription.
 */
export function bindOAuthLinkReplay(
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
    dispatchLinkOAuthAfterReauth(token).catch(ignoreLinkFailure)
  })
}

/**
 * Swallow a link-replay rejection: the re-auth already succeeded and the user is
 * signed in, so a bad or already-redeemed token must not disturb that session.
 */
function ignoreLinkFailure(): void {
  // Intentionally empty — see the doc comment.
}
