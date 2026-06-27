// HilosDashboardPage — the framework Hilos admin dashboard (HilosPages.DASHBOARD):
// the entry to the admin section reached by the shell's gear over the live socket.
// It renders the admin sections grouped from the framework admin catalog (@hilos/core
// HILOS_ADMIN_DASHBOARD_SECTIONS) as no-refresh HilosLink cards. It is self-contained
// (no project context), so it is the framework default for the dashboard key; a
// project that wants its own admin areas above the framework sections wraps this page
// and passes them as children (the `top` slot — the chat demo's bots / moderation
// cards). Bootstrap classes only (styling-rules.md).
import type { ReactNode } from 'react'
import {
  HILOS_ADMIN_DASHBOARD_SECTIONS,
  HILOS_ADMIN_PAGES,
  resolveHilosPath,
} from '@hilos/core'

import { HilosLink } from '../../HilosLink.js'

/** Props for {@link HilosDashboardPage}. */
export interface HilosDashboardPageProps {
  /**
   * Project-supplied admin areas shown above the framework sections; omitted by
   * default (the chat demo passes its bots / moderation cards here).
   */
  children?: ReactNode
}

const sections = HILOS_ADMIN_DASHBOARD_SECTIONS.map((section) => ({
  title: section.title,
  description: section.description,
  items: section.items.map((page) => ({
    page,
    title: HILOS_ADMIN_PAGES[page]?.label ?? page,
    lead: HILOS_ADMIN_PAGES[page]?.lead ?? '',
    icon: HILOS_ADMIN_PAGES[page]?.icon ?? 'bi-square',
    to: resolveHilosPath(page),
  })),
}))

/**
 * The framework admin dashboard: grouped section cards over the admin catalog.
 *
 * @param props The optional project-supplied areas shown above the sections.
 */
export function HilosDashboardPage({ children }: HilosDashboardPageProps) {
  return (
    <section data-id="dashboard-view">
      <div className="d-flex flex-column gap-1 mb-4">
        <h1 className="h4 mb-0">Hilos</h1>
        <p className="mb-0 text-body-secondary">
          Administrative sections with quick access to key project areas.
        </p>
      </div>

      {/* Project-supplied admin areas above the framework sections; empty by
      default, filled by a project wrapper (the chat demo's bots / moderation). */}
      {children}

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
                        <i className={`bi ${item.icon}`} aria-hidden="true" />
                      </span>
                      <span className="d-flex flex-column gap-1">
                        <span className="h6 mb-0">{item.title}</span>
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
