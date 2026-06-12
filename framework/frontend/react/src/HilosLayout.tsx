// HilosLayout — the tier-1 application shell (sdk-packaging.md): a slot-first
// app frame a project fills rather than re-implements. React has no named
// slots, so the brand and nav regions are node props and the routed page
// content is children. It renders the top navigation bar carrying the brand and
// nav, the framework admin entry (the gear linking to the Hilos dashboard), the
// live connection indicator the SDK owns (core-and-connection.md), and the
// content. The brand and the gear are HilosLinks — no-refresh navigation that
// leaves the socket alive — so the shell alone can move between the project home
// and the admin section. Styling is Bootstrap classes only and the shell carries
// no CSS of its own (styling-rules.md); the status and admin icons are Bootstrap
// Icons (`bi-*`), shipped with the view layer (src/index.ts) like Bootstrap.
import type { ConnectionState, HilosConnection } from '@hilos/core'
import { HILOS_PAGE_ROUTES, HilosPages } from '@hilos/core'
import type { ReactNode } from 'react'

import { HilosLink } from './HilosLink.js'
import { useConnectionState } from './useConnectionState.js'

/** Props for {@link HilosLayout}. */
export interface HilosLayoutProps {
  /** The connection whose live state the shell indicator mirrors. */
  connection: HilosConnection
  /** The home-linked brand region content. Defaults to `Hilos`. */
  brand?: ReactNode
  /** The navigation region placed next to the brand. */
  nav?: ReactNode
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
  children,
}: HilosLayoutProps) {
  const connectionState = useConnectionState(connection)
  const visual = CONN_VISUAL[connectionState]

  return (
    <div className="d-flex flex-column min-vh-100" data-id="app-root">
      <nav
        className="navbar navbar-expand bg-body-tertiary border-bottom"
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
      <main className="container flex-grow-1 py-4">{children}</main>
    </div>
  )
}
