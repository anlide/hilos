// HilosAdminPage — the admin section page shell: the breadcrumb, heading, and
// lead common to every Hilos admin page, plus a default body. A page passes only
// its key (props.page); the shell reads the live route params from the navigator
// to keep the breadcrumb and child links in context, and takes the heading, the
// lead, the chain and the subsection cards from the navigator's pageIdentity —
// what the page's own subscription answered with, not a frontend constant. It is
// page-agnostic — it renders whichever key it is given, never choosing the page
// itself (that is the app shell's page->view map). The default body is the
// section's sub-navigation cards, or a stub empty-state for a leaf; a real page
// overrides the body (children) with its own content while keeping the shell.
// Pass children as a function to receive the resolved admin children.
//
// While the identity is still on the wire the heading is a neutral placeholder
// and nothing else of the shell is drawn: the raw page key is never printed, and
// an empty h1 under the same data-id would make "the name did not arrive" look
// exactly like "the name arrived empty".
//
// A section ROOT that has content of its own passes it in the `body` prop
// instead, which is drawn after the default body: it needs both, the cards to its
// children and its own figures beneath them, and overriding the default body
// would cost it the cards. A leaf page goes on overriding the default body with
// children as before. Bootstrap classes only.
import { useContext } from 'react'
import type { ReactNode } from 'react'
import { hilosChildLinks, hilosCrumbLinks } from '@hilos/core'
import type { HilosAdminChild } from '@hilos/core'

import { HilosBreadcrumb } from './HilosBreadcrumb.js'
import { HilosLink } from './HilosLink.js'
import { HilosRouterContext } from './hilosRouterContext.js'
import { useSignal } from './useSignal.js'

/** Props for {@link HilosAdminPage}. */
export interface HilosAdminPageProps {
  /** The admin page key whose shell is rendered. */
  page: string
  /**
   * The body. Omit it for the default sub-navigation cards (or a leaf's empty
   * state); pass a node to replace it, or a function to replace it with access
   * to the resolved admin children.
   */
  children?:
    | ReactNode
    | ((args: { adminChildren: HilosAdminChild[] }) => ReactNode)
  /**
   * The content of a section root, drawn AFTER the default body rather than in
   * place of it: a root needs both, the cards to its children and its own
   * figures beneath them. Children keeps its meaning — it replaces the body.
   */
  body?: ReactNode
}

/**
 * The admin section shell: breadcrumb, heading, lead, and a default body.
 *
 * @param props The admin page key, the optional body override, and the optional
 *   section root content drawn after the body.
 */
export function HilosAdminPage({ page, children, body }: HilosAdminPageProps) {
  const router = useContext(HilosRouterContext)
  if (!router) {
    throw new Error('HilosAdminPage requires a HilosRouterContext provider.')
  }

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

  const defaultOrOverride =
    typeof children === 'function'
      ? children({ adminChildren })
      : children === undefined
        ? defaultBody(adminChildren, identity !== undefined)
        : children

  return (
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
          <h1 className="h4 mb-1" data-id="hilos-admin-title">
            {identity.label}
          </h1>
          {identity.lead ? (
            <p className="text-body-secondary">{identity.lead}</p>
          ) : null}
        </>
      )}
      {defaultOrOverride}
      {body}
    </section>
  )
}

/**
 * The default body: the section's sub-navigation cards, or a leaf empty state.
 * Neither is drawn before the page has answered — an empty state shown while the
 * cards are still on the wire says "nothing here" about a section that has five.
 *
 * @param adminChildren The resolved subsection cards.
 * @param answered Whether the page's identity has arrived.
 */
function defaultBody(
  adminChildren: HilosAdminChild[],
  answered: boolean,
): ReactNode {
  if (adminChildren.length === 0) {
    if (!answered) {
      return null
    }

    return (
      <div
        className="border rounded p-4 text-center text-body-secondary"
        data-id="hilos-admin-empty"
      >
        <i
          className="bi bi-cone-striped fs-2 d-block mb-2"
          aria-hidden="true"
        />
        <p className="mb-0">
          Stub page — real content arrives with this section's implementation.
        </p>
      </div>
    )
  }

  return (
    <div
      className="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-3"
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
