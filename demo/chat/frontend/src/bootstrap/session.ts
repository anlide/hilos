// The chat session identity (the project's session singleton): own the
// scope-partitioned stores and expose the current user. The daemon issues the
// httpOnly session cookie on the WebSocket handshake, so the client no longer
// mints it; the handshake-response plumbing and the current-user selector come
// from @hilos/core; this file holds only the project's session state.
import { ScopeManager, sessionUserName } from '@hilos/core'

/** The application's scope-partitioned stores. */
export const scopes = new ScopeManager()

/** The current user's display name; empty until the handshake response lands. */
export const currentUserName = sessionUserName(scopes)
