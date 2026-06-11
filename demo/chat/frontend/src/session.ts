// The chat session identity (session bootstrap): a client-minted persistent
// token rides a cookie into the WebSocket handshake; the backend
// finds-or-registers the user by it and answers with handshake_response
// carrying the session scope payload. The normalizer ingests it here and the
// current user resolves reactively. The cookie name is project-owned — the
// backend counterpart is the CookieNames constant.
import {
  computedSignal,
  ingest,
  ScopeManager,
  scopePayloadSchema,
  type EntityRef,
  type HilosConnection,
  type ReadonlySignal,
  type ScopePayloadWire,
} from '@hilos/core'

export const SESSION_TOKEN_COOKIE = 'hilos_session_token'

const SIGNAL_HANDSHAKE_RESPONSE = 'handshake_response'

const CURRENT_USER_SLOT = 'currentUser'

/** Two years — the absolute session cap of the wire protocol. */
const SESSION_TOKEN_MAX_AGE_SECONDS = 63072000

/** Concrete chat signal schemas for the core parse boundary. */
export const chatSignalSchemas = {
  [SIGNAL_HANDSHAKE_RESPONSE]: scopePayloadSchema,
}

/** The application's scope-partitioned stores. */
export const scopes = new ScopeManager()

/**
 * Mint the persistent session token cookie unless the browser already holds
 * one. Must run before the connection opens so the token rides the handshake.
 * No Secure attribute: the local dev stack serves plain http; the server-owned
 * httpOnly cookie replaces this client-minted one at the cookie-auth step.
 */
export function ensureSessionTokenCookie(): void {
  const exists = document.cookie
    .split('; ')
    .some((entry) => entry.startsWith(`${SESSION_TOKEN_COOKIE}=`))
  if (exists) {
    return
  }
  const bytes = crypto.getRandomValues(new Uint8Array(16))
  const token = Array.from(bytes, (byte) =>
    byte.toString(16).padStart(2, '0'),
  ).join('')
  document.cookie =
    `${SESSION_TOKEN_COOKIE}=${token}; path=/;` +
    ` max-age=${SESSION_TOKEN_MAX_AGE_SECONDS}; SameSite=Strict`
}

/** Ingest every handshake response into the session scope. */
export function bindSessionScope(connection: HilosConnection): void {
  connection.on('projectSignal', (signal) => {
    if (signal.type === SIGNAL_HANDSHAKE_RESPONSE) {
      // Validated against scopePayloadSchema at the parse boundary; this cast
      // is the declared typed selector for that schema's output.
      ingest(scopes.session, signal.data as ScopePayloadWire, {
        entityTypes: { [CURRENT_USER_SLOT]: 'user' },
      })
    }
  })
}

// The normalizer leaves an EntityRef under the slot's sourceKey — the typed
// selector for the session's current-user reference.
const currentUserRef = scopes.session.data.signal(
  CURRENT_USER_SLOT,
) as ReadonlySignal<EntityRef | undefined>

/** The current user's display name; empty until the handshake response lands. */
export const currentUserName: ReadonlySignal<string> = computedSignal(() => {
  const ref = currentUserRef.get()
  if (!ref) {
    return ''
  }
  const name = scopes.entitySignal(ref).get()?.fields.name

  return typeof name === 'string' ? name : ''
})
