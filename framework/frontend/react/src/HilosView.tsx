// HilosView — the routed outlet (the React Router `<Outlet>` of the SDK). It
// mirrors the core navigator's current route and renders the component the
// project mapped to that page key, swapping it in place as navigation changes
// the route. A page subscription error (subscription_page_error) takes
// precedence over the mapped component: the full-page ErrorPage shows instead.
// An unmapped page renders nothing. The router must be in context.
//
// A page is held back until its subscription answers (router.pageLoading):
// showing it earlier means showing a page the server may be about to deny, and
// taking it away again one round trip later. The `hilos-page-state` marker names
// the outcome — loading, error or ready — so the state is readable from the DOM
// rather than guessed from whichever element happened to render first.
//
// It also hosts the auth gate (HIL-165): when the project registers an
// `authSurface`, an anonymous 401 mounts that surface IN PLACE of ErrorPage, and
// the `authGate`'s modal shows the same surface over the live page for a gated
// action. Both dismiss and resume through the core gate — no navigation. Omit
// the pair and behavior is unchanged: a 401 renders ErrorPage like any status.
import { useContext } from 'react'
import type { ComponentType, ReactNode } from 'react'
import {
  AUTH_SURFACE_HEADING_ID,
  createSignal,
  type AuthGate,
} from '@hilos/core'

import { ErrorPage } from './ErrorPage.js'
import { HilosModal } from './HilosModal.js'
import { HilosRouterContext } from './hilosRouterContext.js'
import { useSignal } from './useSignal.js'

/** A stable closed signal for the modal state when no auth gate is registered. */
const MODAL_CLOSED = createSignal(false)

/** Props for {@link HilosView}: the page-key → component map and the auth slot. */
export interface HilosViewProps {
  /** Maps a page key to the component rendered while that page is current. */
  pages: Record<string, ComponentType>
  /**
   * The project's sign-in surface (HIL-364), mounted in place of ErrorPage on an
   * anonymous 401 and inside the auth modal for a gated action. Omit it and a
   * 401 renders ErrorPage.
   */
  authSurface?: ComponentType
  /** The auth gate driving the sign-in modal and the resume-on-auth. */
  authGate?: AuthGate
}

/**
 * Render the component mapped to the navigator's current page, the sign-in
 * surface when the page is gated by an anonymous 401, or the full-page error
 * surface for any other subscription error.
 *
 * @param props The page-key → component map and the optional auth slot.
 */
export function HilosView({ pages, authSurface, authGate }: HilosViewProps) {
  const router = useContext(HilosRouterContext)
  if (!router) {
    throw new Error('HilosView requires a HilosRouterContext provider.')
  }

  const route = useSignal(router.currentRoute)
  const pageError = useSignal(router.pageError)
  const pageLoading = useSignal(router.pageLoading)
  const modalOpen = useSignal(authGate?.modalOpen ?? MODAL_CLOSED)
  const View = pages[route.page]
  const AuthSurface = authSurface

  // The one place the three outcomes of a navigation are named. The marker
  // element carries it so a test waits for the page to be settled instead of
  // polling for an element that renders before the answer and disappears when
  // it lands.
  const pageState = pageError ? 'error' : pageLoading ? 'loading' : 'ready'
  const showAuthInPlace =
    !!pageError && pageError.httpCode === 401 && !!AuthSurface

  // The same three-way choice the Vue peer makes in its template: the surface in
  // place of a 401'd page, the error surface for any other denial, the mapped
  // page otherwise. (`AuthSurface` is re-tested only so TypeScript narrows it.)
  let content: ReactNode
  if (showAuthInPlace && AuthSurface) {
    content = <AuthSurface />
  } else if (pageError) {
    content = <ErrorPage error={pageError} />
  } else {
    content = View && !pageLoading ? <View /> : null
  }

  return (
    <>
      <div data-id="hilos-page-state" data-state={pageState} hidden />
      {content}
      {/* No title of its own: the sign-in surface is identifier-first (HIL-423),
          so what the screen is called changes with the step the person is on,
          and only the surface knows that. It renders its own heading in the
          body. The dialog is still NAMED — the mandated rule
          (docs/agents/frontend/accessibility.md) has every modal expose
          role=dialog + aria-modal + an accessible name — and it takes that name
          from the very heading the surface draws (HIL-832), so the name a
          screen reader announces is the text a sighted person reads, on every
          step. The fixed ariaLabel stays behind it as the fallback:
          authSurface is a public extension point, and a project surface that
          carries no such heading has to degrade to a dialog named "Sign in",
          not to a dialog named nothing.

          Never while the same surface is already shown IN PLACE: the gate opens
          the modal for an ack as well as for a gated action (HIL-422), and on a
          401'd page that would draw a second copy of the surface over the first
          — two machines, two subscriptions, and every control on screen twice.
          The gate's resume closes both states in one move, so nothing is left
          holding a modal nobody can see. */}
      {AuthSurface && authGate && !showAuthInPlace ? (
        <HilosModal
          open={modalOpen}
          ariaLabel="Sign in"
          ariaLabelledby={AUTH_SURFACE_HEADING_ID}
          onClose={() => authGate.dismiss()}
          showFooter={false}
        >
          <AuthSurface />
        </HilosModal>
      ) : null}
    </>
  )
}
