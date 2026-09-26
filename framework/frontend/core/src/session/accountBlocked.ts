// The "Access closed" card: the account this browser lost, or was refused,
// because it is blocked, and the one control that takes the card down (HIL-289).
//
// The card is the shell's, in every SDK, and no project mounts or passes
// anything for it: the server stamps it on every handshake of a session that
// holds one, and a session that never met a block is never told about one. So
// the state is bound here, once, by `bootHilos`, and each SDK's shell only
// reads it — the same shape as the impersonation strip.
//
// Sign out is a tracked action on the application's ONE action lifecycle — the
// one the project hands `bootHilos` beside its connection. The server answers it
// off the session state frame that carries the card away, so by the time the
// reply settles the card has already gone by itself; pressing it where another
// tab already closed the card is a quiet success, never a refusal.
import {
  type ActionHandle,
  type ActionLifecycle,
} from '../connection/actionLifecycle.js'
import { type ScopeManager } from '../state/ScopeManager.js'
import {
  createSignal,
  type ReadonlySignal,
  subscribeSignal,
} from '../state/signal.js'
import {
  type AccountBlockedNotice,
  sessionAccountBlocked,
} from './sessionScope.js'

/** Client→server: take the card down (PHP `HilosSignalConstants::HILOS_DISMISS_ACCOUNT_BLOCKED`). */
export const ACCOUNT_BLOCKED_ACTION_DISMISS = 'hilos_dismiss_account_blocked'

/** The card's words; the address between the account lines comes from the handshake. */
export const ACCOUNT_BLOCKED_COPY = {
  title: 'Access closed',
  accountLead: 'The account',
  accountTail: 'has been blocked by the project administration.',
  accountUnnamed:
    'This account has been blocked by the project administration.',
  reasonTitle: 'No reason given',
  reasonText:
    'The project does not keep one, and this screen does not make one up.',
  contact:
    'If you think this is a mistake, contact the project administration.',
  signOut: 'Sign out',
} as const

const accountBlocked = createSignal<AccountBlockedNotice | null>(null)

/** The lifecycle Sign out is dispatched on; set by {@link bindAccountBlocked}. */
let boundActions: ActionLifecycle | null = null

/** The live binding's own release, so a stale unbind cannot undo a newer bind. */
let boundRelease: (() => void) | null = null

/**
 * The card this session holds, or `null` while it holds none.
 *
 * One per loaded SDK and read by the shell, exactly as the impersonation strip
 * is. It follows the handshake: it appears when a response carries a blocked
 * account and leaves when one carries none — in every tab of the browser alike.
 */
export const hilosAccountBlocked: ReadonlySignal<AccountBlockedNotice | null> =
  accountBlocked

/**
 * Derive {@link hilosAccountBlocked} from the session scope and remember the
 * lifecycle {@link dismissAccountBlocked} dispatches on.
 *
 * Bound by `bootHilos` before the socket opens, beside the impersonation strip,
 * so the first handshake is never missed. The SDK shell tests bind it
 * themselves over a scope fed with a handshake.
 *
 * @param scopes The application's scope-partitioned stores.
 * @param actions The application's one action reply lifecycle.
 * @returns Unbind: stops following the session and forgets the lifecycle.
 */
export function bindAccountBlocked(
  scopes: ScopeManager,
  actions: ActionLifecycle,
): () => void {
  const card = sessionAccountBlocked(scopes)
  boundActions = actions
  accountBlocked.set(card.get())
  const unsubscribe = subscribeSignal(card, (next) => accountBlocked.set(next))
  const release = (): void => {
    unsubscribe()
    if (boundRelease !== release) {
      return
    }
    boundRelease = null
    boundActions = null
    accountBlocked.set(null)
  }
  boundRelease = release

  return release
}

/**
 * Take the card down: dispatch Sign out on the bound lifecycle.
 *
 * The handle settles on the server's own answer, which leaves behind the state
 * frame that carries the card away.
 *
 * @returns The tracked handle of the Sign out action.
 * @throws Error When nothing is bound — a programming error, since the only
 *   card offering Sign out exists after the bind.
 */
export function dismissAccountBlocked(): ActionHandle {
  if (boundActions === null) {
    throw new Error(
      'dismissAccountBlocked() before bindAccountBlocked(): bootHilos binds the card.',
    )
  }

  return boundActions.dispatch(ACCOUNT_BLOCKED_ACTION_DISMISS, {})
}
