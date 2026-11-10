// One document's explanation of a live identity loss, shared by every SDK view.
// The session scope holds the current frame's reason; this latch survives later
// anonymous handshakes and is deliberately forgotten with the document.
import { type HilosRouter } from '../routing/HilosRouter.js'
import { type ScopeManager } from '../state/ScopeManager.js'
import {
  createSignal,
  subscribeSignal,
  type ReadonlySignal,
} from '../state/signal.js'
import {
  sessionAccessLossReason,
  sessionAccountBlocked,
  sessionUserId,
  type SessionScopeOptions,
} from './sessionScope.js'

/** The server's explanations for taking an active session's identity away. */
export type AccessLossReason =
  | 'session_ended'
  | 'account_blocked'
  | 'ended_by_administrator'
  | 'ended_from_another_browser'
  | 'password_changed'
  | 'password_recovered'

/** Shared copy for the live access-loss notice in all three SDK views. */
export const ACCESS_LOSS_COPY = {
  session_ended: 'Your session has ended. Any unsaved changes were lost.',
  account_blocked: 'Any unsaved changes were lost.',
  ended_by_administrator:
    'Your session was ended by an administrator. Any unsaved changes were lost.',
  ended_from_another_browser:
    'Your session was ended from another browser. Any unsaved changes were lost.',
  password_changed:
    'Your session has ended because the password was changed. Any unsaved changes were lost.',
  password_recovered:
    'Your session has ended because the password was reset. Any unsaved changes were lost.',
} as const

const accessLossReason = createSignal<AccessLossReason | null>(null)
let boundRelease: (() => void) | null = null

/**
 * A recognized live identity loss, retained for the lifetime of its surface.
 * All three SDK views read this document-scoped latch.
 */
export const hilosAccessLossReason: ReadonlySignal<AccessLossReason | null> =
  accessLossReason

/** Whether the explanation belongs to an ended session's sign-in surface. */
export function isEndedSessionReason(reason: AccessLossReason | null): boolean {
  return reason !== null && reason !== 'account_blocked'
}

/**
 * Latch only a known person becoming anonymous with a reason in that same frame.
 * Publish before dropping the page, so views can remove dialogs and departure
 * copies synchronously. The existing subscription supplies the next answer.
 *
 * @param scopes The application's scope-partitioned stores.
 * @param router The navigator whose old page data must be forgotten.
 * @param options Current-user slot and entity-type overrides.
 * @returns Release this binding without disturbing a newer one.
 */
export function bindAccessLoss(
  scopes: ScopeManager,
  router: HilosRouter,
  options: SessionScopeOptions = {},
): () => void {
  boundRelease?.()
  const userId = sessionUserId(scopes, options)
  const reason = sessionAccessLossReason(scopes)
  const card = sessionAccountBlocked(scopes)
  let previousUserId = userId.get()
  const offIdentity = subscribeSignal(userId, (id) => {
    const hadIdentity = previousUserId !== null
    previousUserId = id
    if (id !== null) {
      accessLossReason.set(null)
    } else if (hadIdentity && reason.get() !== null) {
      accessLossReason.set(reason.get())
      router.awaitPageAnswer()
    }
  })
  const offCard = subscribeSignal(card, (next) => {
    if (
      (next !== null && isEndedSessionReason(accessLossReason.get())) ||
      (next === null && accessLossReason.get() === 'account_blocked')
    ) {
      accessLossReason.set(null)
    }
  })
  const offPath = subscribeSignal(router.currentPath, () => {
    if (isEndedSessionReason(accessLossReason.get())) {
      accessLossReason.set(null)
    }
  })
  const release = (): void => {
    offIdentity()
    offCard()
    offPath()
    if (boundRelease !== release) {
      return
    }
    boundRelease = null
    accessLossReason.set(null)
  }
  boundRelease = release

  return release
}
