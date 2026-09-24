// HilosAdminPage — the admin section page shell: the breadcrumb, heading, and
// lead common to every Hilos admin page, plus the cards to the page's children. A
// page passes only its key (props.page); the shell reads the live route params
// from the navigator to keep the breadcrumb and child links in context, and takes
// the heading, the lead, the chain and the subsection cards from the navigator's
// pageIdentity — what the page's own subscription answered with, not a frontend
// constant. It is page-agnostic — it renders whichever key it is given, never
// choosing the page itself (that is the app shell's page->view map).
//
// Under the heading the shell always draws the section's sub-navigation cards —
// one for every child the live params can resolve — and a page's own content
// (children) is drawn beneath them: the way on stands above what the admin came
// for, and no page's content can cost its children their cards. A leaf that
// passes no content gets a stub empty-state instead.
//
// While the identity is still on the wire the heading is a neutral placeholder
// and neither the cards nor the stub is drawn; a page's own content already is,
// and the cards rise above it once the identity lands. The raw page key is never
// printed, and an empty h1 under the same data-id would make "the name did not
// arrive" look exactly like "the name arrived empty".
//
// The heading carries an id the shell provides to what it holds: a table that
// declares no title of its own takes its accessible name from this heading, which
// already names it. Bootstrap classes only.
import { useContext, useId } from 'react'
import type { ReactNode } from 'react'
import { hilosChildLinks, hilosCrumbLinks } from '@hilos/core'
import type { HilosAdminChild } from '@hilos/core'

import { HilosBreadcrumb } from './HilosBreadcrumb.js'
import { HilosLink } from './HilosLink.js'
import { HilosPageHeadingIdContext } from './hilosPageHeadingContext.js'
import { HilosRouterContext } from './hilosRouterContext.js'
import { useSignal } from './useSignal.js'

/** Props for {@link HilosAdminPage}. */
export interface HilosAdminPageProps {
  /** The admin page key whose shell is rendered. */
  page: string
  /**
   * The page's own content, drawn under the cards to its children. Omit it on a
   * leaf with nothing to show yet, and the shell draws its stub empty state.
   */
  children?: ReactNode
}

/**
 * The admin section shell: breadcrumb, heading, lead, the cards to the page's
 * children, and the page's own content beneath them.
 *
 * @param props The admin page key and the page's optional own content.
 */
export function HilosAdminPage({ page, children }: HilosAdminPageProps) {
  const router = useContext(HilosRouterContext)
  if (!router) {
    throw new Error('HilosAdminPage requires a HilosRouterContext provider.')
  }

  const headingId = useId()
  const route = useSignal(router.currentRoute)
  const identity = useSignal(router.pageIdentity)
  const crumbs = hilosCrumbLinks(
    identity?.breadcrumb ?? [],
    route.params,
    router.resolvePath,
  )
  const adminChildren = hilosChildLinks(
    identity?.children ?? [],
    route.params,
    router.resolvePath,
  )

  return (
    <HilosPageHeadingIdContext.Provider value={headingId}>
      <section data-id="hilos-admin-page" data-page={page}>
        {identity === undefined ? (
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
            <h1 id={headingId} className="h4 mb-1" data-id="hilos-admin-title">
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
      </section>
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
