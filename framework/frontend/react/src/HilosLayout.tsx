// HilosLayout — the tier-1 application shell (sdk-packaging.md): a slot-first
// app frame a project fills rather than re-implements. React has no named
// slots, so the brand and nav regions are node props and the routed page
// content is children. It renders the top navigation bar carrying the brand and
// nav, the framework admin entry (the gear linking to the Hilos dashboard), the
// live connection indicator the SDK owns (core-and-connection.md), the content,
// and a footer of the public framework pages (HILOS_FOOTER_LINKS). The shell is
// a fixed-height viewport column (vh-100): the nav and footer never scroll
// (flex-shrink-0) and the main region grows and scrolls its own overflow
// (min-h-0 + overflow-auto), so a page either scrolls inside main or — like the
// chat page — fills it and scrolls an inner region rather than the whole
// document. The brand, the gear, and the footer links are HilosLinks —
// no-refresh navigation that leaves the socket alive — so the shell alone moves
// between the project home, the admin section, and the public pages. Styling is
// Bootstrap classes only and the shell carries
// no CSS of its own (styling-rules.md); the status and admin icons are Bootstrap
// Icons (`bi-*`), shipped with the view layer (src/index.ts) like Bootstrap.
import type { ConnectionState, HilosConnection } from '@hilos/core'
import {
  HILOS_FOOTER_LINKS,
  HILOS_PAGE_ROUTES,
  HilosPages,
  createSignal,
} from '@hilos/core'
import { useContext, useEffect } from 'react'
import type { ReactNode } from 'react'

import { HilosLink } from './HilosLink.js'
import { HilosToastHost } from './HilosToastHost.js'
import { HilosRouterContext } from './hilosRouterContext.js'
import { useConnectionState } from './useConnectionState.js'
import { useSignal } from './useSignal.js'

/** Props for {@link HilosLayout}. */
export interface HilosLayoutProps {
  /** The connection whose live state the shell indicator mirrors. */
  connection: HilosConnection
  /** The home-linked brand region content. Defaults to `Hilos`. */
  brand?: ReactNode
  /** The navigation region placed next to the brand. */
  nav?: ReactNode
  /**
   * The right-aligned user region placed before the admin gear (the Vue shell's
   * `#user` slot): the profile link, the notification bell, sign-out. Additive —
   * omit it and the nav bar is unchanged.
   */
  user?: ReactNode
  /** The routed page content rendered in the shell body. */
  children?: ReactNode
}

// Each transport state maps to a Bootstrap Icon and a Bootstrap text color:
// green while the socket is live, amber while it is (re)connecting, red when it
// is down. `connecting` and `reconnecting` share the in-progress icon — the only
// thing that distinguishes them is the visually-hidden label.
type ConnVisual = { icon: string; color: string }
const CONN_VISUAL: Record<ConnectionState, ConnVisual> = {
  connected: { icon: 'bi-check-circle-fill', color: 'text-success' },
  connecting: { icon: 'bi-arrow-repeat', color: 'text-warning' },
  reconnecting: { icon: 'bi-arrow-repeat', color: 'text-warning' },
  disconnected: { icon: 'bi-exclamation-triangle-fill', color: 'text-danger' },
}

// The gear targets the framework's own dashboard page; its URL is owned by the
// framework page catalog, not restated here as a literal (routing/hilosPages).
const ADMIN_HREF = HILOS_PAGE_ROUTES[HilosPages.DASHBOARD]

// A stable empty fallback so useSignal always has a real signal without a router
// (tests, the hard-link fallback); the page title then stays empty.
const NO_TITLE = createSignal('')

/**
 * The application shell: top navigation with the brand, the admin gear, the
 * live connection indicator, and the routed page content.
 *
 * @param props The connection plus the brand, nav, and content regions.
 */
export function HilosLayout({
  connection,
  brand = 'Hilos',
  nav,
  user,
  children,
}: HilosLayoutProps) {
  const connectionState = useConnectionState(connection)
  const visual = CONN_VISUAL[connectionState]

  // Mirror the navigator's current page title: set it as the document title so
  // the browser tab tracks the no-refresh navigation, and render it in the live
  // region below so a screen reader announces the page change (WCAG 2.4.2).
  // Without a router (tests, the hard-link fallback) the title stays empty.
  const router = useContext(HilosRouterContext)
  const pageTitle = useSignal(router?.currentTitle ?? NO_TITLE)
  useEffect(() => {
    if (pageTitle) {
      document.title = pageTitle
    }
  }, [pageTitle])

  return (
    <div
      className="d-flex flex-column vh-100 overflow-hidden"
      data-id="app-root"
    >
      <a
        href="#hilos-main-content"
        className="visually-hidden-focusable position-absolute top-0 start-0 m-2 btn btn-primary btn-sm z-3"
        data-id="skip-to-content"
      >
        Skip to main content
      </a>
      <div
        className="visually-hidden"
        role="status"
        aria-live="polite"
        data-id="page-title"
      >
        {pageTitle}
      </div>
      <nav
        className="navbar navbar-expand bg-body-tertiary border-bottom flex-shrink-0"
        aria-label="Main"
      >
        <div className="container">
          <HilosLink
            to="/"
            className="navbar-brand mb-0 h1"
            data-id="nav-brand"
          >
            {brand}
          </HilosLink>
          <div className="navbar-nav me-auto">{nav}</div>
          <div className="d-flex align-items-center gap-3">
            {user}
            <HilosLink
              className="nav-link d-inline-flex align-items-center p-0 fs-5"
              to={ADMIN_HREF}
              data-id="nav-admin"
              aria-label="Hilos dashboard"
            >
              <i className="bi bi-gear-fill" aria-hidden="true" />
              <span className="visually-hidden">Hilos dashboard</span>
            </HilosLink>
            <span
              className={`navbar-text d-inline-flex align-items-center fs-5 ${visual.color}`}
              data-id="conn-state"
              role="status"
              aria-live="polite"
              title={connectionState}
            >
              <i className={`bi ${visual.icon}`} aria-hidden="true" />
              <span className="visually-hidden">{connectionState}</span>
            </span>
          </div>
        </div>
      </nav>
      <main
        id="hilos-main-content"
        tabIndex={-1}
        className="container flex-grow-1 min-h-0 overflow-auto py-4"
      >
        {children}
      </main>
      <footer
        className="footer flex-shrink-0 border-top bg-body-tertiary py-2"
        data-id="app-footer"
      >
        <div className="container d-flex flex-wrap justify-content-center gap-3 small">
          {HILOS_FOOTER_LINKS.map((link) => (
            <HilosLink
              key={link.page}
              className="link-secondary text-decoration-none"
              to={HILOS_PAGE_ROUTES[link.page] ?? '/'}
              data-id={`footer-link-${link.page}`}
            >
              {link.label}
            </HilosLink>
          ))}
        </div>
      </footer>
      {/* Transient notices float over the shell, so every page inside it can
      report an outcome without owning a notification surface of its own. */}
      <HilosToastHost />
    </div>
  )
}
