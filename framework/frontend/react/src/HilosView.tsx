// HilosView — the routed outlet (the React Router `<Outlet>` of the SDK). It
// mirrors the core navigator's current route and renders the component the
// project mapped to that page key, swapping it in place as navigation changes
// the route. A page subscription error (subscription_page_error) takes
// precedence over the mapped component: the full-page ErrorPage shows instead.
// An unmapped page renders nothing. The router must be in context.
//
// It also hosts the auth gate (HIL-165): when the project registers an
// `authSurface`, an anonymous 401 mounts that surface IN PLACE of ErrorPage, and
// the `authGate`'s modal shows the same surface over the live page for a gated
// action. Both dismiss and resume through the core gate — no navigation. Omit
// the pair and behavior is unchanged: a 401 renders ErrorPage like any status.
import { useContext } from 'react'
import type { ComponentType, ReactNode } from 'react'
import { createSignal, type AuthGate } from '@hilos/core'

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
  const modalOpen = useSignal(authGate?.modalOpen ?? MODAL_CLOSED)
  const View = pages[route.page]
  const AuthSurface = authSurface

  let content: ReactNode
  if (pageError) {
    content =
      pageError.httpCode === 401 && AuthSurface ? (
        <AuthSurface />
      ) : (
        <ErrorPage error={pageError} />
      )
  } else {
    content = View ? <View /> : null
  }

  return (
    <>
      {content}
      {AuthSurface && authGate ? (
        <HilosModal
          open={modalOpen}
          title="Sign in"
          onClose={() => authGate.dismiss()}
          actions={() => null}
        >
          <AuthSurface />
        </HilosModal>
      ) : null}
    </>
  )
}
