// HilosDashboardPage — the framework Hilos admin dashboard (HilosPages.DASHBOARD):
// the entry to the admin section reached by the shell's gear over the live socket.
// It renders the admin sections the dashboard's own subscription answered with,
// framework groups first and a project's own after them, as no-refresh HilosLink
// cards. It is self-contained (no project context), so it is the framework default
// for the dashboard key; a project declares its cards in its page catalog on the
// backend rather than wrapping this page, and the children seam is left for
// content that is not a card at all (the Vue and Angular ports offer it too). The sections are read per render, not
// captured at module load: nothing is known about them until the page answers, and
// until it does the cards are placeholders — an empty grid would jump the layout on
// every visit. Bootstrap classes only (styling-rules.md).
import { useContext } from 'react'
import type { ReactNode } from 'react'

import { HilosLink } from '../../HilosLink.js'
import { HilosRouterContext } from '../../hilosRouterContext.js'
import { useSignal } from '../../useSignal.js'

/** Props for {@link HilosDashboardPage}. */
export interface HilosDashboardPageProps {
  /**
   * Project-supplied admin areas shown above the framework sections; omitted by
   * default.
   */
  children?: ReactNode
}

/**
 * The framework admin dashboard: grouped section cards over the page catalog.
 *
 * @param props The optional project-supplied areas shown above the sections.
 */
export function HilosDashboardPage({ children }: HilosDashboardPageProps) {
  const router = useContext(HilosRouterContext)
  if (!router) {
    throw new Error(
      'HilosDashboardPage requires a HilosRouterContext provider.',
    )
  }

  const answered = useSignal(router.dashboardSections)
  const sections = (answered ?? []).map((section) => ({
    title: section.title,
    description: section.description,
    // A card with no address is left out: a card IS its target, and the shell
    // must not offer one that goes nowhere.
    items: section.items.flatMap((item) => {
      const to = router.resolvePath(item.page)

      return to === undefined ? [] : [{ ...item, to }]
    }),
  }))

  return (
    <section data-id="dashboard-view">
      <div className="d-flex flex-column gap-1 mb-4">
        <h1 className="h4 mb-0">Hilos</h1>
        <p className="mb-0 text-body-secondary">
          Administrative sections with quick access to key project areas.
        </p>
      </div>

      {/* Project-supplied admin areas above the framework sections; empty by
      default. A project's own cards ride its page catalog on the backend, so this
      seam is for content that is not a card at all. */}
      {children}

      {answered === undefined ? (
        <div
          className="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-3 placeholder-glow"
          data-id="dashboard-skeleton"
        >
          {[0, 1, 2, 3, 4, 5].map((slot) => (
            <div key={slot} className="col">
              <span className="placeholder col-12 rounded py-5 d-block" />
            </div>
          ))}
        </div>
      ) : null}

      {sections.map((section) => (
        <div key={section.title} className="mb-4">
          <div className="mb-3">
            <h2 className="h6 text-uppercase text-body-secondary mb-1">
              {section.title}
            </h2>
            <p className="mb-0 text-body-secondary">{section.description}</p>
          </div>

          <div className="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-3">
            {section.items.map((item) => (
              <div key={item.page} className="col">
                <HilosLink
                  to={item.to}
                  className="card h-100 shadow-sm border-0 text-decoration-none link-body-emphasis"
                  data-id={`dashboard-card-${item.page}`}
                >
                  <div className="card-body">
                    <div className="d-flex align-items-start gap-3">
                      <span className="bg-body-secondary rounded-circle d-inline-flex align-items-center justify-content-center flex-shrink-0 p-3 fs-3 lh-1">
                        <i
                          className={`bi ${item.icon ?? 'bi-square'}`}
                          aria-hidden="true"
                        />
                      </span>
                      <span className="d-flex flex-column gap-1">
                        <span className="h6 mb-0">{item.label}</span>
                        <span className="small text-body-secondary">
                          {item.lead}
                        </span>
                      </span>
                    </div>
                  </div>
                </HilosLink>
              </div>
            ))}
          </div>
        </div>
      ))}
    </section>
  )
}
