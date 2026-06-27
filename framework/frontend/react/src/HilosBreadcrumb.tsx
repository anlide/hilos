// HilosBreadcrumb — the page-agnostic breadcrumb. It renders a trail of crumbs
// as in-place HilosLink navigation, the last crumb shown as the active page. It
// knows nothing about any specific page: the caller supplies the resolved trail
// (for the Hilos admin tree, hilosAdminBreadcrumb in @hilos/core builds it), so
// the same component serves any page that has a breadcrumb. Bootstrap classes
// only (styling-rules.md).
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
            <li key={crumb.to} className="breadcrumb-item">
              <HilosLink to={crumb.to}>{crumb.label}</HilosLink>
            </li>
          ) : (
            <li
              key={crumb.to}
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
