// HilosAdminPage — the admin section page shell: the breadcrumb, heading, and
// lead common to every Hilos admin page, plus the cards to the page's children. A
// page passes only its key (props.page); the shell reads the live route params
// from the navigator to keep the breadcrumb and child links in context, and takes
// the heading, the lead, the chain and the subsection cards from the navigator's
// pageIdentity — what the page's own subscription answered with, not a frontend
// constant. It is page-agnostic — it renders whichever key it is given, never
// choosing the page itself (that is the app shell's page->view map).
// A page's local childParams override only the cards' route parameters, leaving
// the breadcrumb on the current address.
//
// A page whose answer names a head owner (pageHeadOwner: its owner opted in to
// the shared head) gets HilosPageHead in place of all that — the breadcrumb, the
// owner's block and its one row of tabs, and no heading, lead or cards of the
// shell's own (docs/agents/architecture/page-head.md). The answer alone decides
// it: the head props only dress the owner's block, and on a page without an owner
// they are ignored. Until an answer arrives, a page that names its head kind
// already stands on that head's skeleton rather than on the legacy one.
//
// Under the legacy heading the shell always draws the section's sub-navigation
// cards — one for every child the live params can resolve — and a page's own
// content (children) is drawn beneath them: the way on stands above what the
// admin came for, and no page's content can cost its children their cards. A
// leaf that passes no content gets a stub empty-state instead.
//
// While the identity is still on the wire the heading is a neutral placeholder
// and neither the cards nor the stub is drawn; a page's own content already is,
// and the cards rise above it once the identity lands. The raw page key is never
// printed, and an empty h1 under the same data-id would make "the name did not
// arrive" look exactly like "the name arrived empty".
//
// The heading — the legacy one or the shared head's — carries an id the shell
// provides to what it holds: a table that declares no title of its own takes its
// accessible name from this heading, which already names it.
//
// The shell also tells what it holds whether a viewer of the admin view mode
// stands here (hilosAdminAccess is 'view', HIL-1253): the controls of the mode
// read that themselves and stand plainly disabled (HIL-1261).
//
// A page whose resource can be deleted under it opts in with
// preserveModalDraftsOnDelete (HIL-1603) and passes its dialogs as modals. Then
// the current page error replaces the head, the cards and the page's content
// with one ErrorPage, while modals stays on the same mount, under the same
// heading and view-mode providers, so an open dialog keeps its draft above the
// 404. Inside the routed outlet the shell registers, once mounted, to hold its
// page through a live deletion (HilosPageErrorBoundaryContext); outside one it
// simply draws the error. Its root offers the dialogs' focus return a fallback,
// the Back to home link of the error drawn here, for an opener that went away
// with the content. Without the opt-in nothing changes: the outlet replaces the
// whole page on any refusal. Bootstrap classes only.
import { useContext, useId, useLayoutEffect, useRef } from 'react'
import type { ReactNode } from 'react'
import {
  createSignal,
  hilosAdminAccess,
  hilosChildLinks,
  hilosCrumbLinks,
  registerFocusReturnFallback,
} from '@hilos/core/base'
import type {
  HilosAdminChild,
  HilosPageHeadAction,
  PageError,
} from '@hilos/core/base'

import { ErrorPage } from './ErrorPage.js'
import { HilosBreadcrumb } from './HilosBreadcrumb.js'
import { HilosLink } from './HilosLink.js'
import { HilosPageHead } from './HilosPageHead.js'
import { HilosAdminViewModeContext } from './hilosLookOnly.js'
import { HilosPageErrorBoundaryContext } from './hilosPageErrorBoundaryContext.js'
import { HilosPageHeadingIdContext } from './hilosPageHeadingContext.js'
import { HilosRouterContext } from './hilosRouterContext.js'
import { useSignal } from './useSignal.js'

/**
 * A stable empty page error for a shell without the opt-in, which reads nothing
 * from the navigator it did not read before.
 */
const NO_PAGE_ERROR = createSignal<PageError | null>(null)

/** Props for {@link HilosAdminPage}. */
export interface HilosAdminPageProps {
  /** The admin page key whose shell is rendered. */
  page: string
  /** Local parameters for child cards; breadcrumbs keep the current route. */
  childParams?: Record<string, string>
  /**
   * The page's own content, drawn under the cards to its children. Omit it on a
   * leaf with nothing to show yet, and the shell draws its stub empty state.
   */
  children?: ReactNode
  /**
   * The shared head's kind: a section head or an entity block. It does not opt
   * the owner in; it only lets the skeleton stand before the answer arrives.
   */
  headKind?: 'section' | 'entity'
  /** The one transition the shared head offers as a button. */
  headAction?: HilosPageHeadAction
  /** The shared head's breadcrumb navigation's accessible name. */
  breadcrumbLabel?: string
  /** The shared head's tab row navigation's accessible name. */
  tabsLabel?: string
  /**
   * The shared head's block picture: an entity's tile or avatar. Omitted keeps
   * the section's icon; null turns it off.
   */
  headVisual?: ReactNode
  /** A short mark beside the shared head's title, such as the entity's code. */
  headMeta?: ReactNode
  /** Status badges beside the shared head's title. */
  headBadges?: ReactNode
  /**
   * The line under the shared head's title. Omitted keeps the section's lead;
   * null turns it off.
   */
  headDescription?: ReactNode
  /**
   * Draw the page error in place of the content and keep modals mounted under
   * it, so a dialog open when the page's resource is deleted keeps its draft.
   */
  preserveModalDraftsOnDelete?: boolean
  /** The page's dialogs: the one part an opted-in page error leaves mounted. */
  modals?: ReactNode
}

/**
 * The admin section shell: the breadcrumb, heading and lead with the cards to the
 * page's children — or the shared head when the answer names an owner — and the
 * page's own content beneath them.
 *
 * @param props The page key, optional local child params, own content and the
 *   shared head's inputs.
 */
export function HilosAdminPage({
  page,
  childParams,
  children,
  headKind,
  headAction,
  breadcrumbLabel,
  tabsLabel,
  headVisual,
  headMeta,
  headBadges,
  headDescription,
  preserveModalDraftsOnDelete = false,
  modals,
}: HilosAdminPageProps) {
  const router = useContext(HilosRouterContext)
  if (!router) {
    throw new Error('HilosAdminPage requires a HilosRouterContext provider.')
  }

  const headingId = useId()
  const adminAccess = useSignal(hilosAdminAccess)
  const route = useSignal(router.currentRoute)
  const identity = useSignal(router.pageIdentity)
  // The answer's owner opts the page in; before the answer, the kind the page
  // names picks the shared head's skeleton over the legacy one.
  const sharedHead =
    identity === undefined
      ? headKind !== undefined
      : identity.pageHeadOwner !== undefined
  const crumbs = hilosCrumbLinks(
    identity?.breadcrumb ?? [],
    route.params,
    router.resolvePath,
  )
  const adminChildren = sharedHead
    ? []
    : hilosChildLinks(
        identity?.children ?? [],
        { ...route.params, ...childParams },
        router.resolvePath,
      )

  // Without the opt-in the shell never draws a refusal and reads nothing it did
  // not read before: the outlet replaces the whole page with its own ErrorPage,
  // as it always has.
  const refusal = useSignal(
    preserveModalDraftsOnDelete ? router.pageError : NO_PAGE_ERROR,
  )
  const retainForResourceDeletion = useContext(HilosPageErrorBoundaryContext)
  const root = useRef<HTMLElement>(null)
  // Only once mounted: the outlet holds the component it shows at the moment of
  // registration, and a shell that registered earlier would match nothing. The
  // focus fallback is offered before any dialog of the page can open, and lives
  // and dies with this root.
  useLayoutEffect(() => {
    const section = root.current
    if (!preserveModalDraftsOnDelete || section === null) {
      return
    }
    const releaseFocus = registerFocusReturnFallback(section, () =>
      section.querySelector<HTMLElement>('[data-id="page-error-home"]'),
    )
    const releaseRetention = retainForResourceDeletion?.(page)
    return () => {
      releaseRetention?.()
      releaseFocus()
    }
  }, [preserveModalDraftsOnDelete, page, retainForResourceDeletion])

  return (
    <HilosPageHeadingIdContext.Provider value={headingId}>
      <HilosAdminViewModeContext.Provider value={adminAccess === 'view'}>
        <section ref={root} data-id="hilos-admin-page" data-page={page}>
          {refusal ? (
            <ErrorPage error={refusal} />
          ) : (
            <>
              {sharedHead ? (
                <HilosPageHead
                  identity={identity}
                  page={page}
                  params={route.params}
                  resolvePath={router.resolvePath}
                  headingId={headingId}
                  kind={headKind}
                  action={headAction}
                  breadcrumbLabel={breadcrumbLabel}
                  tabsLabel={tabsLabel}
                  visual={headVisual}
                  meta={headMeta}
                  badges={headBadges}
                  description={headDescription}
                />
              ) : identity === undefined ? (
                <div
                  className="placeholder-glow mb-3"
                  data-id="hilos-admin-title-skeleton"
                >
                  <span className="placeholder col-3 d-block mb-2 rounded" />
                  <span className="placeholder col-6 d-block rounded" />
                </div>
              ) : (
                <>
                  <HilosBreadcrumb crumbs={crumbs} />
                  <h1
                    id={headingId}
                    className="h4 mb-1"
                    data-id="hilos-admin-title"
                  >
                    {identity.label}
                  </h1>
                  {identity.lead ? (
                    <p className="text-body-secondary">{identity.lead}</p>
                  ) : null}
                </>
              )}
              {adminChildren.length > 0 ? childCards(adminChildren) : null}
              {children === undefined
                ? leafStub(adminChildren, identity !== undefined)
                : children}
            </>
          )}
          {/* After the branch, never inside it: switching to the error keeps
              modals mounted, and the dialogs portal to <body> wherever they
              stand. */}
          {modals}
        </section>
      </HilosAdminViewModeContext.Provider>
    </HilosPageHeadingIdContext.Provider>
  )
}

/**
 * The stub empty state of a leaf that passes no content of its own. It is not
 * drawn before the page has answered — an empty state shown while the cards are
 * still on the wire says "nothing here" about a section that has five.
 *
 * @param adminChildren The resolved subsection cards.
 * @param answered Whether the page's identity has arrived.
 */
function leafStub(
  adminChildren: HilosAdminChild[],
  answered: boolean,
): ReactNode {
  if (adminChildren.length > 0 || !answered) {
    return null
  }

  return (
    <div
      className="border rounded p-4 text-center text-body-secondary"
      data-id="hilos-admin-empty"
    >
      <i className="bi bi-cone-striped fs-2 d-block mb-2" aria-hidden="true" />
      <p className="mb-0">
        Stub page — real content arrives with this section's implementation.
      </p>
    </div>
  )
}

/**
 * The section's sub-navigation cards, one per resolved child page.
 *
 * @param adminChildren The resolved subsection cards.
 */
function childCards(adminChildren: HilosAdminChild[]): ReactNode {
  return (
    <div
      className="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-3 mb-4"
      data-id="hilos-admin-children"
    >
      {adminChildren.map((child) => (
        <div key={child.page} className="col">
          <HilosLink
            to={child.to}
            className="card h-100 shadow-sm border-0 text-decoration-none link-body-emphasis"
            data-id={`hilos-admin-child-${child.page}`}
          >
            <div className="card-body d-flex flex-column gap-1">
              <span className="h6 mb-0 d-flex align-items-center justify-content-between gap-2">
                <span>{child.label}</span>
                <i
                  className="bi bi-chevron-right text-body-secondary"
                  aria-hidden="true"
                />
              </span>
              <span className="small text-body-secondary">{child.lead}</span>
            </div>
          </HilosLink>
        </div>
      ))}
    </div>
  )
}
