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
  /**
   * The ack the session still owes, from `sessionPendingAck`; null when it owes
   * none. Omit it and the gate behaves exactly as it did before HIL-422 — the
   * resume fires on the upgrade alone.
   */
  pendingAck?: ReadonlySignal<string | null>
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
  /**
   * Close the modal without authenticating; the user may retry the action. When a
   * resume is being held back by a pending ack, this settles it instead — the
   * surface is going away either way, and a page must not stay gated behind it.
   */
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
 * With `pendingAck` wired, the resume is HELD while the session owes an ack
 * (HIL-422): a flow that ends by signing somebody in has a sentence left to say,
 * and un-gating on the upgrade alone would close the surface over it. The held
 * resume is not cancelled, only postponed — the ack turning empty plays it out,
 * which is what makes the Continue button both dismiss the panel and let the
 * page through in one move.
 *
 * @param options The navigator, current-user signal, optional action source and ack.
 */
export function createAuthGate(options: AuthGateOptions): AuthGate {
  const { router, currentUserId, actionErrors, pendingAck } = options
  const modalOpen = createSignal(false)
  // A resume the ack is holding back. It survives until the ack clears rather
  // than being re-derived from the user id, because by then the upgrade is old
  // news and nothing would fire to re-decide it.
  let heldResume = false

  const resume = (): void => {
    // Other error codes (403/404/500) are left to their ErrorPage — only a 401
    // is auth-fixable.
    const pageError = router.pageError.get()
    if (pageError?.httpCode === 401) {
      router.clearPageError()
    }
    modalOpen.set(false)
  }

  // Resume on upgrade: a non-null user id means the session authenticated, so
  // un-gate a 401'd page and close the modal — unless an ack is standing there.
  subscribeSignal(currentUserId, (userId) => {
    if (userId === null) {
      // The session went back to anonymous — a force-logout from another device
      // (HIL-416) does exactly this while LEAVING the ack in place. Whatever
      // resume that upgrade owed is void: playing it out later would un-gate a
      // 401'd page for somebody who is no longer signed in.
      heldResume = false

      return
    }
    if (pendingAck?.get() != null) {
      heldResume = true

      return
    }
    resume()
  })

  // The ack is a surface trigger in its own right: it can land in a tab that was
  // not signing anybody in (the other window of the same session), and there the
  // panel is the only thing that would ever open the surface.
  if (pendingAck !== undefined) {
    subscribeSignal(pendingAck, (ack) => {
      if (ack !== null) {
        modalOpen.set(true)

        return
      }
      if (heldResume) {
        heldResume = false
        resume()
      }
    })
  }

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
    dismiss: () => {
      // Closing the surface by hand settles a held resume rather than leaving it
      // held: the ack signal only fires when its value CHANGES, so a resume still
      // waiting on it after the surface is gone would leave a 401'd page gated
      // with nothing on screen to fix it.
      if (heldResume) {
        heldResume = false
        resume()

        return
      }
      modalOpen.set(false)
    },
  }
}
