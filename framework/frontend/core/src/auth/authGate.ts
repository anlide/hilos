// The auth gate: the framework-owned "catch → show → dismiss → resume" state
// behind the in-place sign-in slot (HIL-165). A Hilos page keeps a 401'd
// subscription ALIVE (subscription/PageSubscription.pageError; the backend
// re-delivers the payload the instant its guard passes — page-access-control.md
// live-promotion), so the right frontend behavior on an anonymous 401 is not a
// redirect but to mount the project's sign-in surface in place and resume when
// the session upgrades. This controller owns only the reactive state a view
// binds: whether the modal (action-case) is open, plus the resume/close that
// fires when the session authenticates. The concrete surface component and its
// login/register form are project-owned (HIL-364); the framework decides how to
// show it (in place for a page 401 — the HilosView slot — or as this modal for a
// gated action) so sign-in looks uniform across demos.
//
// Two triggers open the surface. The page-case is passive: HilosView reads
// router.pageError and swaps the surface in for a 401. The action-case is
// imperative: a live page calls requireAuth() (e.g. the chat composer banner),
// and an action-level 401 (ActionUnauthorizedException carrying
// errorCode 'unauthorized', HIL-161) auto-opens the modal as a safety net.
// Resume is authoritative: the gate watches the current-user signal and, when it
// turns non-null, clears a 401 page error (page-case) and closes the modal
// (action-case). Action replay after login is out of scope — the user repeats
// the action (chat pre-disables the composer, so it is a non-issue).

import { type PageSubscriptionError } from '../protocol/pageError.js'
import {
  createSignal,
  subscribeSignal,
  type ReadonlySignal,
  type Unsubscribe,
} from '../state/signal.js'

/** The machine-readable action-error code an anonymous write action raises. */
const UNAUTHORIZED_ERROR_CODE = 'unauthorized'

/**
 * The router slice the gate touches: read the current page error and clear it
 * on a successful upgrade. {@link HilosRouter} satisfies it structurally.
 */
export interface AuthGateRouter {
  /** The current page's subscription error, or null while it loads cleanly. */
  readonly pageError: ReadonlySignal<PageSubscriptionError | null>
  /** Clear the current page's subscription error without navigating. */
  clearPageError(): void
}

/**
 * The connection slice the gate observes for the action-401 safety net — the
 * `actionError` event, mirroring {@link ActionErrorStore}'s source so the real
 * connection is a structural fit. Omit it to gate on the page-case alone.
 */
export interface AuthGateActionErrorSource {
  /**
   * Subscribe to action-failure frames; returns an unsubscribe.
   *
   * @param event The event name (`actionError`).
   * @param listener Notified with the failed action's machine-readable code.
   */
  on(
    event: 'actionError',
    listener: (signal: { errorCode?: string | undefined }) => void,
  ): Unsubscribe
}

/** Wiring for {@link createAuthGate}. */
export interface AuthGateOptions {
  /** The navigator, for reading and clearing the current page's 401. */
  router: AuthGateRouter
  /** The current user's id, null while anonymous; the resume trigger. */
  currentUserId: ReadonlySignal<number | null>
  /** The connection whose action-401 auto-opens the modal (the safety net). */
  actionErrors?: AuthGateActionErrorSource
}

/**
 * The auth gate a view binds: the modal open-state and the imperative controls.
 * The page-case in-place slot reads `router.pageError` directly in HilosView;
 * this drives the action-case modal and both resume paths.
 */
export interface AuthGate {
  /** Whether the sign-in surface should show as a modal (the action-case). */
  readonly modalOpen: ReadonlySignal<boolean>
  /** Open the modal — a live page's gated action or its sign-in banner calls it. */
  requireAuth(): void
  /** Close the modal without authenticating; the user may retry the action. */
  dismiss(): void
}

/**
 * Create the auth gate over a navigator and the current-user signal.
 *
 * Resume is wired at creation: the gate subscribes to `currentUserId` and, on it
 * turning non-null (a successful session upgrade), clears a 401 page error so
 * the preserved subscription resumes and closes the modal. When `actionErrors`
 * is given, an action-level 401 also opens the modal. The subscriptions live for
 * the app's lifetime, like the gate itself.
 *
 * @param options The navigator, current-user signal, and optional action source.
 */
export function createAuthGate(options: AuthGateOptions): AuthGate {
  const { router, currentUserId, actionErrors } = options
  const modalOpen = createSignal(false)

  // Resume on upgrade: a non-null user id means the session authenticated, so
  // un-gate a 401'd page and close the modal. Other error codes (403/404/500)
  // are left to their ErrorPage — only a 401 is auth-fixable.
  subscribeSignal(currentUserId, (userId) => {
    if (userId === null) {
      return
    }
    const pageError = router.pageError.get()
    if (pageError?.httpCode === 401) {
      router.clearPageError()
    }
    modalOpen.set(false)
  })

  // Safety net: an action the backend refused for lack of a session
  // (ActionUnauthorizedException → errorCode 'unauthorized') opens the modal even
  // if the page never pre-empted it with a banner.
  actionErrors?.on('actionError', (signal) => {
    if (signal.errorCode === UNAUTHORIZED_ERROR_CODE) {
      modalOpen.set(true)
    }
  })

  return {
    modalOpen,
    requireAuth: () => modalOpen.set(true),
    dismiss: () => modalOpen.set(false),
  }
}
