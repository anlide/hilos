// HilosLayout — the tier-1 application shell (sdk-packaging.md): a slot-first
// app frame a project fills rather than re-implements. React has no named
// slots, so the brand and nav regions are node props and the routed page
// content is children. It renders the top navigation bar carrying the brand and
// nav, the framework admin entry (the gear linking to the Hilos dashboard), the
// live connection indicator the SDK owns (core-and-connection.md), and the
// content. The brand and the gear are HilosLinks — no-refresh navigation that
// leaves the socket alive — so the shell alone can move between the project home
// and the admin section. Styling is Bootstrap classes only and the shell carries
// no CSS of its own (styling-rules.md); the status and admin icons are inline
// Bootstrap-Icons SVGs, so the shell needs no icon-font dependency.
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

// Each transport state maps to an icon shape and a Bootstrap text color: green
// while the socket is live, amber while it is (re)connecting, red when it is
// down. `connecting` and `reconnecting` share the in-progress shape — the only
// thing that distinguishes them is the visually-hidden label.
type ConnVisual = { shape: 'live' | 'progress' | 'down'; color: string }
const CONN_VISUAL: Record<ConnectionState, ConnVisual> = {
  connected: { shape: 'live', color: 'text-success' },
  connecting: { shape: 'progress', color: 'text-warning' },
  reconnecting: { shape: 'progress', color: 'text-warning' },
  disconnected: { shape: 'down', color: 'text-danger' },
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
              <svg
                width="20"
                height="20"
                viewBox="0 0 16 16"
                fill="currentColor"
                aria-hidden="true"
              >
                <path d="M9.405 1.05c-.413-1.4-2.397-1.4-2.81 0l-.1.34a1.464 1.464 0 0 1-2.105.872l-.31-.17c-1.283-.698-2.686.705-1.987 1.987l.169.311c.446.82.023 1.841-.872 2.105l-.34.1c-1.4.413-1.4 2.397 0 2.81l.34.1a1.464 1.464 0 0 1 .872 2.105l-.17.31c-.698 1.283.705 2.686 1.987 1.987l.311-.169a1.464 1.464 0 0 1 2.105.872l.1.34c.413 1.4 2.397 1.4 2.81 0l.1-.34a1.464 1.464 0 0 1 2.105-.872l.31.17c1.283.698 2.686-.705 1.987-1.987l-.169-.311a1.464 1.464 0 0 1 .872-2.105l.34-.1c1.4-.413 1.4-2.397 0-2.81l-.34-.1a1.464 1.464 0 0 1-.872-2.105l.17-.31c.698-1.283-.705-2.686-1.987-1.987l-.311.169a1.464 1.464 0 0 1-2.105-.872l-.1-.34zM8 10.93a2.929 2.929 0 1 1 0-5.858 2.929 2.929 0 0 1 0 5.858z" />
              </svg>
              <span className="visually-hidden">Hilos dashboard</span>
            </HilosLink>
            <span
              className={`navbar-text d-inline-flex align-items-center ${visual.color}`}
              data-id="conn-state"
              role="status"
              aria-live="polite"
              title={connectionState}
            >
              <svg
                width="20"
                height="20"
                viewBox="0 0 16 16"
                fill="currentColor"
                aria-hidden="true"
              >
                {visual.shape === 'live' && (
                  <path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0zm-3.97-3.03a.75.75 0 0 0-1.08.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-.01-1.05z" />
                )}
                {visual.shape === 'progress' && (
                  <>
                    <path d="M11.534 7h3.932a.25.25 0 0 1 .192.41l-1.966 2.36a.25.25 0 0 1-.384 0l-1.966-2.36a.25.25 0 0 1 .192-.41m-11 2h3.932a.25.25 0 0 0 .192-.41L2.692 6.23a.25.25 0 0 0-.384 0L.342 8.59A.25.25 0 0 0 .534 9" />
                    <path d="M8 3c-1.552 0-2.94.707-3.857 1.818a.5.5 0 1 1-.771-.636A6.002 6.002 0 0 1 13.917 7H12.9A5.002 5.002 0 0 0 8 3M3.1 9a5.002 5.002 0 0 0 8.757 2.182.5.5 0 1 1 .771.636A6.002 6.002 0 0 1 2.083 9z" />
                  </>
                )}
                {visual.shape === 'down' && (
                  <path d="M8.982 1.566a1.13 1.13 0 0 0-1.96 0L.165 13.233c-.457.778.091 1.767.98 1.767h13.713c.889 0 1.438-.99.98-1.767zM8 5c.535 0 .954.462.9.995l-.35 3.507a.552.552 0 0 1-1.1 0L7.1 5.995A.905.905 0 0 1 8 5m.002 6a1 1 0 1 1 0 2 1 1 0 0 1 0-2" />
                )}
              </svg>
              <span className="visually-hidden">{connectionState}</span>
            </span>
          </div>
        </div>
      </nav>
      <main className="container flex-grow-1 py-4">{children}</main>
    </div>
  )
}
