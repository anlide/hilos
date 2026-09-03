// HilosBreadcrumb — the page-agnostic breadcrumb. It renders a trail of crumbs
// as in-place HilosLink navigation, the last crumb shown as the active page. It
// knows nothing about any specific page: the caller supplies the resolved trail
// (for the Hilos admin tree, hilosCrumbLinks in @hilos/core builds it from the
// chain the page answered with), so the same component serves any page that has a
// breadcrumb. A crumb the route map has no address for is drawn as plain text:
// the chain has to stay whole, and a link to nowhere is worse than no link.
// Bootstrap classes only (styling-rules.md).
import type { HilosCrumb } from '@hilos/core'

import { HilosLink } from './HilosLink.js'

/** Props for {@link HilosBreadcrumb}. */
export interface HilosBreadcrumbProps {
  /** The resolved trail; the last entry renders as the active, non-link page. */
  crumbs: HilosCrumb[]
}

/**
 * The page-agnostic breadcrumb trail.
 *
 * @param props The resolved crumbs to render.
 */
export function HilosBreadcrumb({ crumbs }: HilosBreadcrumbProps) {
  if (crumbs.length === 0) {
    return null
  }
  const lastIndex = crumbs.length - 1

  return (
    <nav aria-label="breadcrumb" data-id="hilos-breadcrumb">
      <ol className="breadcrumb small mb-2">
        {crumbs.map((crumb, index) =>
          index < lastIndex ? (
            <li key={crumb.page} className="breadcrumb-item">
              {crumb.to === undefined ? (
                crumb.label
              ) : (
                <HilosLink to={crumb.to}>{crumb.label}</HilosLink>
              )}
            </li>
          ) : (
            <li
              key={crumb.page}
              className="breadcrumb-item active"
              aria-current="page"
            >
              {crumb.label}
            </li>
          ),
        )}
      </ol>
    </nav>
  )
}
