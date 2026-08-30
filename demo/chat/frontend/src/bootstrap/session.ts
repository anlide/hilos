// The chat session identity (the project's session singleton): own the
// scope-partitioned stores and expose the current user. The daemon issues the
// httpOnly session cookie on the WebSocket handshake, so the client no longer
// mints it; the handshake-response plumbing and the current-user selector come
// from @hilos/core; this file holds only the project's session state.
import {
  ScopeManager,
  sessionImpersonatedByName,
  sessionImpersonating,
  sessionPendingAck,
  sessionPendingAuthStep,
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

/**
 * The auth step this session stands on and has not finished, or null when it
 * stands on none (HIL-486, HIL-648). Answered by the server on every handshake,
 * so the auth surface comes back to that screen after a reload, in a second tab,
 * and on another device — and nothing about it is kept in this tab.
 */
export const pendingAuthStep = sessionPendingAuthStep(scopes)

/**
 * The announcement this connection still owes its person, or null when it owes
 * none (HIL-422). What a finished auth flow leaves behind so the surface has
 * something to say before it closes — and what holds the gate's resume while it
 * stands, so the page is not un-gated over the sentence.
 */
export const pendingAck = sessionPendingAck(scopes)
