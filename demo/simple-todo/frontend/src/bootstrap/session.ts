// The todo session identity (the project's session singleton): mint the
// persistent session-token cookie before the socket opens, own the
// scope-partitioned stores, and expose the current user. The cookie minting,
// the handshake-response plumbing, and the current-user selector come from
// @hilos/core; this file holds only the project's session state.
import {
  ensureSessionTokenCookie,
  ScopeManager,
  sessionUserName,
} from '@hilos/core'

/** The application's scope-partitioned stores. */
export const scopes = new ScopeManager()

// The token must be in place before the socket opens — it rides the handshake
// cookies — so this runs at module load, before bootHilos opens the connection.
ensureSessionTokenCookie()

/** The current user's display name; empty until the handshake response lands. */
export const currentUserName = sessionUserName(scopes)
