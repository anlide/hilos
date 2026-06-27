// HilosAdminPage — the admin section page shell: the breadcrumb, heading, and
// lead common to every Hilos admin page, plus a default body. A page passes only
// its key (props.page); the shell reads the live route params from the navigator
// to keep the breadcrumb and child links in context, and resolves label / lead /
// parent from the framework admin tree (HILOS_ADMIN_PAGES). It is page-agnostic —
// it renders whichever key it is given, never choosing the page itself (that is
// the app shell's page->view map). The default body is the section's
// sub-navigation cards, or a stub empty-state for a leaf; a real page overrides
// the body (children) with its own content while keeping the shell. Pass children
// as a function to receive the resolved admin children. Bootstrap classes only.
import { useContext } from 'react'
import type { ReactNode } from 'react'
import {
  HILOS_ADMIN_PAGES,
  hilosAdminBreadcrumb,
  hilosAdminChildren,
} from '@hilos/core'
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
}

/**
 * The admin section shell: breadcrumb, heading, lead, and a default body.
 *
 * @param props The admin page key and the optional body override.
 */
export function HilosAdminPage({ page, children }: HilosAdminPageProps) {
  const router = useContext(HilosRouterContext)
  if (!router) {
    throw new Error('HilosAdminPage requires a HilosRouterContext provider.')
  }

  const route = useSignal(router.currentRoute)
  const meta = HILOS_ADMIN_PAGES[page]
  const crumbs = hilosAdminBreadcrumb(page, route.params)
  const adminChildren = hilosAdminChildren(page, route.params)

  const body =
    typeof children === 'function'
      ? children({ adminChildren })
      : children === undefined
        ? defaultBody(adminChildren)
        : children

  return (
    <section data-id="hilos-admin-page" data-page={page}>
      <HilosBreadcrumb crumbs={crumbs} />
      <h1 className="h4 mb-1" data-id="hilos-admin-title">
        {meta?.label ?? page}
      </h1>
      {meta?.lead ? <p className="text-body-secondary">{meta.lead}</p> : null}
      {body}
    </section>
  )
}

/** The default body: the section's sub-navigation cards, or a leaf empty state. */
function defaultBody(adminChildren: HilosAdminChild[]): ReactNode {
  if (adminChildren.length === 0) {
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
