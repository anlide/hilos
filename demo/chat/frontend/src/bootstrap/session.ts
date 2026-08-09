// The chat session identity (the project's session singleton): own the
// scope-partitioned stores and expose the current user. The daemon issues the
// httpOnly session cookie on the WebSocket handshake, so the client no longer
// mints it; the handshake-response plumbing and the current-user selector come
// from @hilos/core; this file holds only the project's session state.
import {
  ScopeManager,
  sessionImpersonatedByName,
  sessionImpersonating,
  sessionUserId,
  sessionUserIsAdmin,
  sessionUserName,
} from '@hilos/core'

/** The application's scope-partitioned stores. */
export const scopes = new ScopeManager()

/** The current user's display name; empty until the handshake response lands. */
export const currentUserName = sessionUserName(scopes)

/** The current user's id; null until the handshake response lands. */
export const currentUserId = sessionUserId(scopes)

/** Whether the current user holds the admin privilege; false until the handshake says so. */
export const currentUserIsAdmin = sessionUserIsAdmin(scopes)

/** Whether this session is currently being impersonated by an admin. */
export const impersonating = sessionImpersonating(scopes)

/** The impersonating admin's display name; empty unless impersonated. */
export const impersonatedByName = sessionImpersonatedByName(scopes)
