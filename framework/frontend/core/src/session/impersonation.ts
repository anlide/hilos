// The impersonation strip: whether an administrator stands behind this session,
// and the one control that ends the takeover (HIL-1064).
//
// The strip used to be a project's — the chat demo drew its own banner and sent
// Stop untracked, with a timer standing in for the answer. It belongs to the
// shell now, in every SDK, and no project mounts or passes anything for it: a
// session that names an administrator behind it gets the strip, and one that is
// never told so never sees it. So the state is bound here, once, by `bootHilos`,
// and each SDK's shell only reads it.
//
// Stop is a tracked action on the application's ONE action lifecycle — the one
// the project hands `bootHilos` beside its connection. A second lifecycle on the
// same connection is not an option: request ids are a per-instance counter, and
// two counters would mint the same ids and mix up the replies. The server
// answers Stop off the session state frame, behind the identity it announces,
// so by the time the reply settles the strip has already gone by itself.
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
import {
  sessionImpersonating,
  sessionUserName,
  type SessionScopeOptions,
} from './sessionScope.js'

/** Client→server: leave the takeover (PHP `HilosSignalConstants::HILOS_IMPERSONATE_STOP`). */
export const IMPERSONATION_ACTION_STOP = 'hilos_impersonate_stop'

/** The strip's words; the name between them comes from the handshake. */
export const IMPERSONATION_STRIP_COPY = {
  lead: 'You are impersonating',
  stop: 'Stop',
} as const

/** What the strip draws while a takeover lasts. */
export interface ImpersonationStrip {
  /** The user the session is acting as. */
  readonly userName: string
}

const impersonation = createSignal<ImpersonationStrip | null>(null)

/** The lifecycle Stop is dispatched on; set by {@link bindImpersonation}. */
let boundActions: ActionLifecycle | null = null

/** The live binding's own release, so a stale unbind cannot undo a newer bind. */
let boundRelease: (() => void) | null = null

/**
 * The strip this session is owed, or `null` while the session is inside no
 * takeover.
 *
 * One per loaded SDK and read by the shell, exactly as the toast stack is. It
 * follows the handshake: it appears when a response names an administrator
 * behind the session and leaves when one no longer does — in every tab of the
 * session alike.
 */
export const hilosImpersonation: ReadonlySignal<ImpersonationStrip | null> =
  impersonation

/**
 * Derive {@link hilosImpersonation} from the session scope and remember the
 * lifecycle {@link stopImpersonation} dispatches on.
 *
 * Bound by `bootHilos` before the socket opens, beside the send-progress line,
 * so the first handshake is never missed. The SDK shell tests bind it
 * themselves over a scope fed with a handshake.
 *
 * @param scopes The application's scope-partitioned stores.
 * @param actions The application's one action reply lifecycle.
 * @param options Current-user and impersonated-by slot overrides.
 * @returns Unbind: stops following the session and forgets the lifecycle.
 */
export function bindImpersonation(
  scopes: ScopeManager,
  actions: ActionLifecycle,
  options: SessionScopeOptions = {},
): () => void {
  const impersonating = sessionImpersonating(scopes, options)
  const userName = sessionUserName(scopes, options)
  const strip = computedSignal<ImpersonationStrip | null>(() =>
    impersonating.get() ? { userName: userName.get() } : null,
  )
  boundActions = actions
  impersonation.set(strip.get())
  const unsubscribe = subscribeSignal(strip, (next) => impersonation.set(next))
  const release = (): void => {
    unsubscribe()
    if (boundRelease !== release) {
      return
    }
    boundRelease = null
    boundActions = null
    impersonation.set(null)
  }
  boundRelease = release

  return release
}

/**
 * Leave the takeover: dispatch Stop on the bound lifecycle.
 *
 * The handle settles on the server's own answer, which leaves behind the
 * restored identity; a refusal ('Session is not impersonating' when another tab
 * already stopped) settles it as a failure.
 *
 * @returns The tracked handle of the Stop action.
 * @throws Error When nothing is bound — a programming error, since the only
 *   strip offering Stop exists after the bind.
 */
export function stopImpersonation(): ActionHandle {
  if (boundActions === null) {
    throw new Error(
      'stopImpersonation() before bindImpersonation(): bootHilos binds the strip.',
    )
  }

  return boundActions.dispatch(IMPERSONATION_ACTION_STOP, {})
}
