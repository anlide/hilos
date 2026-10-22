// HilosBreadcrumb — the page-agnostic breadcrumb. It renders a trail of crumbs
// as in-place HilosLink navigation, the last crumb shown as the active page. It
// knows nothing about any specific page: the caller supplies the resolved trail
// (for the Hilos admin tree, hilosCrumbLinks in @hilos/core builds it from the
// chain the page answered with), so the same component serves any page that has a
// breadcrumb. A crumb the route map has no address for is drawn as plain text:
// the chain has to stay whole, and a link to nowhere is worse than no link. The
// last crumb alone is current; no link takes that mark from its own path match.
// The navigation's accessible name is the caller's translatable label,
// 'breadcrumb' when it gives none. The slash between two crumbs is a decorative
// span the screen reader skips, standing where Bootstrap's ::before would, which
// the hilos-breadcrumb rule switches off. Bootstrap classes only
// (styling-rules.md).
import type { HilosCrumb } from '@hilos/core/base'

import { HilosLink } from './HilosLink.js'

/** Props for {@link HilosBreadcrumb}. */
export interface HilosBreadcrumbProps {
  /** The resolved trail; the last entry renders as the active, non-link page. */
  crumbs: HilosCrumb[]
  /** The navigation's accessible name. */
  label?: string
  /** Classes the caller adds to the navigation, such as `text-break`. */
  className?: string
}

/**
 * The page-agnostic breadcrumb trail.
 *
 * @param props The resolved crumbs, the accessible name and extra classes.
 */
export function HilosBreadcrumb({
  crumbs,
  label = 'breadcrumb',
  className,
}: HilosBreadcrumbProps) {
  if (crumbs.length === 0) {
    return null
  }
  const lastIndex = crumbs.length - 1

  return (
    <nav
      aria-label={label}
      className={
        className === undefined
          ? 'hilos-breadcrumb'
          : `hilos-breadcrumb ${className}`
      }
      data-id="hilos-breadcrumb"
    >
      <ol className="breadcrumb small mb-2">
        {crumbs.map((crumb, index) => (
          <li
            key={crumb.page}
            className={
              index < lastIndex ? 'breadcrumb-item' : 'breadcrumb-item active'
            }
            aria-current={index < lastIndex ? undefined : 'page'}
          >
            {index > 0 ? (
              <span
                className="float-start pe-2 text-body-secondary"
                aria-hidden="true"
              >
                /
              </span>
            ) : null}
            {index === lastIndex || crumb.to === undefined ? (
              crumb.label
            ) : (
              <HilosLink
                to={crumb.to}
                aria-current={undefined}
                data-id={`hilos-breadcrumb-${crumb.page}`}
              >
                {crumb.label}
              </HilosLink>
            )}
          </li>
        ))}
      </ol>
    </nav>
  )
}
