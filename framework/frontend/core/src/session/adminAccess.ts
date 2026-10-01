// What the admin section is to this browser (HIL-1253): full for an admin,
// view for a non-admin on a node in the admin view mode, none otherwise. The
// one source every surface of the mode reads — the shell's admin gear, the
// view-mode strip (HIL-1260) and the view-mode controls (HIL-1261) — so none of
// them restates the rule. It is derived from two facts of one session response,
// the admin flag and the node's mode, and bound by bootHilos, so a project
// neither feeds nor wires it.
//
// It decides what is DRAWN, never what is allowed: the server answers every
// admin page and action by its own verdict (HIL-1251), and a browser that
// believes it may look is still shown only what the server sends.
//
// Inside a takeover the session acts as the person taken over, whose admin flag
// is what the handshake carries; when the installation lets the administrator
// carry their own rights in (HIL-1170), the section is full there too, as the
// server's page gate then opens it.
import { type ScopeManager } from '../state/ScopeManager.js'
import {
  computedSignal,
  createSignal,
  type ReadonlySignal,
  subscribeSignal,
} from '../state/signal.js'
import {
  sessionAdminViewMode,
  sessionImpersonating,
  sessionImpersonationPolicy,
  sessionUserIsAdmin,
  type SessionScopeOptions,
} from './sessionScope.js'

/**
 * What the admin section is to this browser: `full` — an admin, `view` — a
 * viewer who may look and not act, `none` — no way in.
 */
export type HilosAdminAccess = 'full' | 'view' | 'none'

const access = createSignal<HilosAdminAccess>('none')

/** The live binding's own release, so a stale unbind cannot undo a newer bind. */
let boundRelease: (() => void) | null = null

/** What the admin section is to this browser; `none` before the first bind. */
export const hilosAdminAccess: ReadonlySignal<HilosAdminAccess> = access

/**
 * Follow the session's admin flag and the node's admin view mode, recomputing
 * the access on every session response. Bound by `bootHilos` before the socket
 * opens, so the first handshake is never missed.
 *
 * @param scopes The application's scope-partitioned stores.
 * @param options Current-user slot override.
 * @returns Unbind: stops following the session and falls back to `none`.
 */
export function bindAdminAccess(
  scopes: ScopeManager,
  options: SessionScopeOptions = {},
): () => void {
  const isAdmin = sessionUserIsAdmin(scopes, options)
  const viewMode = sessionAdminViewMode(scopes)
  const impersonating = sessionImpersonating(scopes, options)
  const policy = sessionImpersonationPolicy(scopes)
  const derived = computedSignal((): HilosAdminAccess => {
    if (isAdmin.get() || (impersonating.get() && policy.get().carryAdmin)) {
      return 'full'
    }

    return viewMode.get() ? 'view' : 'none'
  })
  access.set(derived.get())
  const unsubscribe = subscribeSignal(derived, (next) => access.set(next))
  const release = (): void => {
    unsubscribe()
    if (boundRelease !== release) {
      return
    }
    boundRelease = null
    access.set('none')
  }
  boundRelease = release

  return release
}
