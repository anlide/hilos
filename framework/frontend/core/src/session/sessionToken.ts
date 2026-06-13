// Session-token bootstrap: the client mints a persistent random token and rides
// it into the WebSocket handshake as a cookie; the backend finds-or-registers
// the user by it. Lifted from every project's session module so the token is
// minted one way (docs/agents/frontend/bootstrap-structure.md). Browser glue
// like browserNavigationEnvironment — exercised in the demos, not the no-DOM
// core suite.

/** Default session-token cookie name; the backend counterpart is its `CookieNames` constant. */
export const SESSION_TOKEN_COOKIE = 'hilos_session_token'

/** Two years — the absolute session cap of the wire protocol. */
const SESSION_TOKEN_MAX_AGE_SECONDS = 63072000

/** Overrides for {@link ensureSessionTokenCookie}. */
export interface SessionTokenOptions {
  /** Cookie name; defaults to {@link SESSION_TOKEN_COOKIE}. */
  cookieName?: string
  /** Cookie max-age in seconds; defaults to two years. */
  maxAgeSeconds?: number
}

/**
 * Mint the persistent session-token cookie unless the browser already holds one.
 * Must run before the connection opens so the token rides the handshake. No
 * Secure attribute: the local dev stack serves plain http; the server-owned
 * httpOnly cookie replaces this client-minted one at the cookie-auth step.
 *
 * @param options Cookie name and max-age overrides.
 */
export function ensureSessionTokenCookie(
  options: SessionTokenOptions = {},
): void {
  const cookieName = options.cookieName ?? SESSION_TOKEN_COOKIE
  const maxAgeSeconds = options.maxAgeSeconds ?? SESSION_TOKEN_MAX_AGE_SECONDS
  const exists = document.cookie
    .split('; ')
    .some((entry) => entry.startsWith(`${cookieName}=`))
  if (exists) {
    return
  }
  const bytes = crypto.getRandomValues(new Uint8Array(16))
  const token = Array.from(bytes, (byte) =>
    byte.toString(16).padStart(2, '0'),
  ).join('')
  document.cookie =
    `${cookieName}=${token}; path=/;` +
    ` max-age=${maxAgeSeconds}; SameSite=Strict`
}
