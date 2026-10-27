// HilosView — the routed outlet (the React Router `<Outlet>` of the SDK). It
// mirrors the core navigator's current route and renders the component the
// project mapped to that page key, swapping it in place as navigation changes
// the route. A page subscription error (subscription_page_error) takes
// precedence over the mapped component: the full-page ErrorPage shows instead.
// An unmapped page renders nothing. The router must be in context.
//
// A page is held back until its code and subscription answer are ready: showing
// it earlier means showing a page the server may be about to deny, and taking
// it away again one round trip later. Core composes both waits. The marker names
// the outcome — loading, error or ready — so the state is readable from the DOM
// rather than guessed from whichever element happened to render first.
//
// What stands in the page's place while it waits is a skeleton, not white
// (HIL-983): the one the project declared for that page key in `pageSkeletons`,
// or the default HilosSkeleton. It rises only once the wait has outlasted
// DEFAULT_SKELETON_DELAY_MS, so a page that answers quickly never flashes grey
// bars, and the outlet announces it once for the whole page — the bars inside are
// decorative. The frame takes the page's full height, so a project skeleton can
// lay out the way its page does. Only the FIRST answer is waited for: a
// resubscription after a reconnect keeps the page on screen and raises no
// skeleton.
//
// Navigation leaves an inert copy of the old page in place until the new
// answer, error, or skeleton arrives (HIL-1146). The router announces departure
// before React replaces the live view; the core binding owns the copy.
//
// It also hosts the auth gate (HIL-165): when the project registers an
// `authSurface`, an anonymous 401 mounts that surface IN PLACE of ErrorPage, and
// the `authGate`'s modal shows the same surface over the live page for a gated
// action. Both dismiss and resume through the core gate — no navigation. Omit
// the pair and behavior is unchanged: a 401 renders ErrorPage like any status.
//
// A live session end (HIL-1572) takes precedence over every page outcome, even
// a public page's later answer. The core latch supplies the explanation and
// prevents departure copies. Its permanent polite region and the in-place auth
// surface share a focusable container.
//
// A page shell may take over the refusal of its own deleted resource (HIL-1603):
// the outlet provides its retainForResourceDeletion to what it mounts
// (HilosPageErrorBoundaryContext), and while the core state holds the page on
// such a refusal (retainedOnError) the outlet draws no ErrorPage of its own and
// leaves the mounted page where it is — the same element in the same place, so
// the same instance, and the windows it keeps open with their drafts — for the
// shell to draw the error in place of its content. Every other refusal still
// replaces the page here.
import { useContext, useEffect, useLayoutEffect, useRef, useState } from 'react'
import type { ComponentType, ReactNode } from 'react'
import {
  ACCESS_LOSS_COPY,
  AUTH_SURFACE_HEADING_ID,
  bindPageDeparture,
  computedSignal,
  createDeferredFlagState,
  createPageViewState,
  createSignal,
  DEFAULT_SKELETON_DELAY_MS,
  focusInitial,
  hilosAccessLossReason,
  type AuthGate,
  type DeferredFlagState,
  type PageView,
  type PageViewState,
} from '@hilos/core/base'

import { ErrorPage } from './ErrorPage.js'
import { HilosModal } from './HilosModal.js'
import { HilosSkeleton } from './HilosSkeleton.js'
import { HilosPageErrorBoundaryContext } from './hilosPageErrorBoundaryContext.js'
import { HilosRouterContext } from './hilosRouterContext.js'
import { useSignal } from './useSignal.js'

/** A stable closed signal for the modal state when no auth gate is registered. */
const MODAL_CLOSED = createSignal(false)

/** No component is ready before the outlet's layout effect connects core. */
const VIEW_EMPTY = createSignal<ComponentType | undefined>(undefined)

/** Nothing is held before the outlet's layout effect connects core. */
const NOT_RETAINED = createSignal(false)

/** The shared departure binding's boolean view of the document-local latch. */
const ACCESS_LOST = computedSignal(() => hilosAccessLossReason.get() !== null)

/** Props for {@link HilosView}: the page-key → component map and the auth slot. */
export interface HilosViewProps {
  /** Maps a page key to the component rendered while that page is current. */
  pages: Record<string, PageView<ComponentType>>
  /**
   * Maps a page key to the skeleton that page shows while it waits for its first
   * answer; a page not in the map shows HilosSkeleton.
   */
  pageSkeletons?: Record<string, ComponentType>
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
 * @param props The page-key → component map, the optional skeleton map, and the
 *   optional auth slot.
 */
export function HilosView({
  pages,
  pageSkeletons,
  authSurface,
  authGate,
}: HilosViewProps) {
  const router = useContext(HilosRouterContext)
  if (!router) {
    throw new Error('HilosView requires a HilosRouterContext provider.')
  }

  const route = useSignal(router.currentRoute)
  // Connect only after mount: an abandoned render must leave no core listeners.
  // Each effect setup owns its controller, including StrictMode's reconnect
  // after cleanup; a disposed controller is never attached to a live outlet.
  const [pageViewState, setPageViewState] =
    useState<PageViewState<ComponentType> | null>(null)
  useLayoutEffect(() => {
    const state = createPageViewState<ComponentType>(router)
    setPageViewState(state)
    return () => state.dispose()
  }, [router])
  const page = route.page
  const selected = page === null ? undefined : pages[page]
  useLayoutEffect(() => {
    pageViewState?.setPages(pages)
  }, [pageViewState, pages, selected])
  const pageError = useSignal(pageViewState?.error ?? router.pageError)
  const pageLoading = useSignal(pageViewState?.loading ?? router.pageLoading)
  const View = useSignal(pageViewState?.view ?? VIEW_EMPTY)
  const retainedOnError = useSignal(
    pageViewState?.retainedOnError ?? NOT_RETAINED,
  )
  const modalOpen = useSignal(authGate?.modalOpen ?? MODAL_CLOSED)
  const accessLossReason = useSignal(hilosAccessLossReason)
  const sessionEnded = accessLossReason === 'session_ended'
  const AuthSurface = authSurface

  const skeletonFlagRef = useRef<DeferredFlagState | null>(null)
  if (skeletonFlagRef.current === null) {
    skeletonFlagRef.current = createDeferredFlagState(DEFAULT_SKELETON_DELAY_MS)
  }
  const skeletonFlag = skeletonFlagRef.current
  const showSkeleton = useSignal(skeletonFlag.shown)
  const slotRef = useRef<HTMLDivElement>(null)
  const departureRef = useRef<HTMLDivElement>(null)
  const accessLossSurfaceRef = useRef<HTMLDivElement>(null)
  useLayoutEffect(() => {
    if (pageViewState && slotRef.current && departureRef.current) {
      return bindPageDeparture({
        router,
        slot: slotRef.current,
        departure: departureRef.current,
        skeletonShown: skeletonFlag.shown,
        loading: pageViewState.loading,
        error: pageViewState.error,
        accessLost: ACCESS_LOST,
      })
    }
  }, [router, skeletonFlag, pageViewState])
  useEffect(() => {
    skeletonFlag.set(pageLoading)
  }, [skeletonFlag, pageLoading])
  useEffect(() => () => skeletonFlag.dispose(), [skeletonFlag])
  // The removed HilosModal releases its trap in a passive-effect cleanup.
  // Focus the replacement afterward, preserving a deliberate choice elsewhere.
  useEffect(() => {
    if (!sessionEnded || hilosAccessLossReason.get() !== 'session_ended') {
      return
    }
    const root = accessLossSurfaceRef.current
    if (
      root?.isConnected &&
      root.ownerDocument.activeElement === root.ownerDocument.body
    ) {
      focusInitial(root)
    }
  }, [sessionEnded])
  const Skeleton =
    page === null ? HilosSkeleton : (pageSkeletons?.[page] ?? HilosSkeleton)

  // The one place the three outcomes of a navigation are named. The marker
  // element carries it so a test waits for the page to be settled instead of
  // polling for an element that renders before the answer and disappears when
  // it lands.
  const pageState = pageError ? 'error' : pageLoading ? 'loading' : 'ready'
  const showAuthInPlace =
    sessionEnded || (!!pageError && pageError.httpCode === 401 && !!AuthSurface)

  // The same choice the Vue peer makes in its template: the surface in place of
  // a 401'd page, the error surface for any other denial, the mapped page once it
  // answered, its skeleton while the wait lasts. (`AuthSurface` is re-tested only
  // so TypeScript narrows it.) A held page skips the error and stays the same
  // `<View />` in the same place: that is what keeps its instance, so no wrapper
  // or key may set it apart from the page it was before the refusal.
  let content: ReactNode
  if (showAuthInPlace) {
    content = null
  } else if (pageError && !retainedOnError) {
    content = <ErrorPage error={pageError} />
  } else if (View && !pageLoading) {
    content = <View />
  } else if (pageLoading && showSkeleton) {
    content = (
      <div className="h-100" data-id="hilos-page-skeleton">
        <span className="visually-hidden" role="status">
          Loading…
        </span>
        <Skeleton />
      </div>
    )
  } else {
    content = null
  }

  return (
    <>
      <div data-id="hilos-page-state" data-state={pageState} hidden />
      <div ref={slotRef} className="hilos-page-slot">
        <HilosPageErrorBoundaryContext.Provider
          value={pageViewState?.retainForResourceDeletion}
        >
          <div ref={accessLossSurfaceRef} tabIndex={-1}>
            <div role="status" aria-live="polite">
              {sessionEnded ? (
                <p className="alert alert-warning" data-id="access-loss-notice">
                  {ACCESS_LOSS_COPY.session_ended}
                </p>
              ) : null}
            </div>
            {showAuthInPlace && AuthSurface ? <AuthSurface /> : null}
          </div>
          {content}
        </HilosPageErrorBoundaryContext.Provider>
      </div>
      <div
        ref={departureRef}
        className="hilos-page-departure position-relative h-100"
        data-id="hilos-page-departure"
        aria-hidden="true"
        inert
      />
      {/* No title of its own: the sign-in surface is identifier-first (HIL-423),
          so what the screen is called changes with the step the person is on,
          and only the surface knows that. It renders its own heading in the
          body. The dialog is still NAMED — the mandated rule
          (docs/agents/frontend/accessibility.md) has every modal expose
          role=dialog + aria-modal + an accessible name — and it takes that name
          from the very heading the surface draws (HIL-832), so the name a
          screen reader announces is the text a sighted person reads, on every
          step. The fixed aria-label stays behind it as the fallback:
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
          aria-label="Sign in"
          aria-labelledby={AUTH_SURFACE_HEADING_ID}
          initialFocus="inner"
          onClose={() => authGate.dismiss()}
        >
          <AuthSurface />
        </HilosModal>
      ) : null}
    </>
  )
}
