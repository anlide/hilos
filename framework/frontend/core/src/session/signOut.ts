// The sign-out control belongs to the shell in every SDK (HIL-1063): no
// project mounts or wires it. A session with a person behind it gets the
// control, derived from the user's id rather than their display name.
//
// Sign out runs on the application's ONE action lifecycle, bound by bootHilos.
// The server answers behind the session state frame announcing the anonymous
// identity, so by the time the action settles the control has already left.
import {
  type ActionHandle,
  type ActionLifecycle,
} from '../connection/actionLifecycle.js'
import { type ScopeManager } from '../state/ScopeManager.js'
import {
  computedSignal,
  createSignal,
  type ReadonlySignal,
  subscribeSignal,
} from '../state/signal.js'
import { sessionUserId, type SessionScopeOptions } from './sessionScope.js'

/** Client→server: sign out (PHP `HilosSignalConstants::HILOS_LOGOUT`). */
export const SIGN_OUT_ACTION = 'hilos_logout'

/** The navbar control's accessible name and title. */
export const SIGN_OUT_COPY = { label: 'Sign out' } as const

const signedIn = createSignal(false)

/** The lifecycle Sign out is dispatched on; set by {@link bindSignOut}. */
let boundActions: ActionLifecycle | null = null

/** The live binding's own release, so a stale unbind cannot undo a newer bind. */
let boundRelease: (() => void) | null = null

/** Whether a person stands behind this session; false before the first bind. */
export const hilosSignedIn: ReadonlySignal<boolean> = signedIn

/**
 * Follow the session's identity and remember the lifecycle {@link signOut}
 * dispatches on. Bound by `bootHilos` before the socket opens, so the first
 * handshake is never missed.
 *
 * @param scopes The application's scope-partitioned stores.
 * @param actions The application's one action reply lifecycle.
 * @param options Current-user slot override.
 * @returns Unbind: stops following the session and forgets the lifecycle.
 */
export function bindSignOut(
  scopes: ScopeManager,
  actions: ActionLifecycle,
  options: SessionScopeOptions = {},
): () => void {
  const userId = sessionUserId(scopes, options)
  const hasUser = computedSignal(() => userId.get() !== null)
  boundActions = actions
  signedIn.set(hasUser.get())
  const unsubscribe = subscribeSignal(hasUser, (next) => signedIn.set(next))
  const release = (): void => {
    unsubscribe()
    if (boundRelease !== release) {
      return
    }
    boundRelease = null
    boundActions = null
    signedIn.set(false)
  }
  boundRelease = release

  return release
}

/**
 * Sign out on the bound lifecycle and wait for the server's own answer.
 *
 * @returns The tracked handle of the Sign out action.
 * @throws Error When nothing is bound — a programming error, since the only
 *   control offering Sign out exists after the bind.
 */
export function signOut(): ActionHandle {
  if (boundActions === null) {
    throw new Error(
      'signOut() before bindSignOut(): bootHilos binds the control.',
    )
  }

  return boundActions.dispatch(SIGN_OUT_ACTION, {})
}
