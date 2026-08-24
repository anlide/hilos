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
// between the project home, the admin section, and the public pages. While the
// connection reports protected mode the shell becomes the maintenance surface
// (HilosMaintenance) and keeps only the connection indicator — every other
// region of the shell links to a page the freeze has shut. Styling is Bootstrap
// classes only and the shell carries no CSS of its own (styling-rules.md); the
// status and admin icons are Bootstrap Icons (`bi-*`), shipped with the view
// layer (src/index.ts) like Bootstrap.
import type {
  ConnectionState,
  HilosConnection,
  PageRouteMatch,
} from '@hilos/core'
import {
  HILOS_FOOTER_LINKS,
  HILOS_PAGE_ROUTES,
  HilosPages,
  createSignal,
} from '@hilos/core'
import { useContext, useEffect } from 'react'
import type { ReactNode } from 'react'

import { HilosLink } from './HilosLink.js'
import { HilosMaintenance } from './HilosMaintenance.js'
import { HilosToastHost } from './HilosToastHost.js'
import { HilosOAuthWaitModal } from './auth/HilosOAuthWaitModal.js'
import { HilosRouterContext } from './hilosRouterContext.js'
import { useConnectionState } from './useConnectionState.js'
import { useProtectedMode } from './useProtectedMode.js'
import { useSignal } from './useSignal.js'

/** Props for {@link HilosLayout}. */
export interface HilosLayoutProps {
  /** The connection whose live state the shell indicator mirrors. */
  connection: HilosConnection
  /**
   * Whether the signed-in user holds the admin privilege. The admin entry is
   * drawn for an admin and for nobody else, so a project that answers no admin
   * identity (the default) shows no way into a surface the gate would refuse.
   */
  isAdmin?: boolean
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

// The same for the route: without a router there is no route and therefore no
// administrative surface, so the maintenance surface hides the verifier's code
// field. That is the safe way round — a missing field is fixed by typing the
// admin url, a field shown where it should not be is the defect this closes.
const NO_ROUTE = createSignal<PageRouteMatch>({
  page: '',
  params: {},
  admin: false,
})

/**
 * The application shell: top navigation with the brand, the admin gear, the
 * live connection indicator, and the routed page content.
 *
 * @param props The connection plus the brand, nav, and content regions.
 */
export function HilosLayout({
  connection,
  isAdmin = false,
  brand = 'Hilos',
  nav,
  user,
  children,
}: HilosLayoutProps) {
  const connectionState = useConnectionState(connection)
  const visual = CONN_VISUAL[connectionState]

  // While the backend holds the node in protected mode the shell shows the
  // maintenance surface instead of the routed page, and drops everything that
  // leads anywhere: the brand, the nav, the user region, the admin gear, and
  // the footer all point at pages the freeze has shut. The connection indicator
  // is the one thing that stays — during planned work it is the only status
  // worth telling the visitor. The state is read from the connection, not from
  // a page store, so it outlives routing and subscription lifecycles.
  const protectedMode = useProtectedMode(connection)
  const underMaintenance = protectedMode.active

  // Mirror the navigator's current page title: set it as the document title so
  // the browser tab tracks the no-refresh navigation, and render it in the live
  // region below so a screen reader announces the page change (WCAG 2.4.2).
  // Without a router (tests, the hard-link fallback) the title stays empty.
  const router = useContext(HilosRouterContext)
  const pageTitle = useSignal(router?.currentTitle ?? NO_TITLE)
  const currentRoute = useSignal(router?.currentRoute ?? NO_ROUTE)
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
          {!underMaintenance && (
            <HilosLink
              to="/"
              className="navbar-brand mb-0 h1"
              data-id="nav-brand"
            >
              {brand}
            </HilosLink>
          )}
          {/* The auto margin lives on this region whether or not it holds
          links, so the connection indicator keeps its place on the right while
          the maintenance surface is up. */}
          <div className="navbar-nav me-auto">
            {underMaintenance ? null : nav}
          </div>
          <div className="d-flex align-items-center gap-3">
            {!underMaintenance && (
              <>
                {user}
                {isAdmin ? (
                  <HilosLink
                    className="nav-link d-inline-flex align-items-center p-0 fs-5"
                    to={ADMIN_HREF}
                    data-id="nav-admin"
                    aria-label="Hilos dashboard"
                  >
                    <i className="bi bi-gear-fill" aria-hidden="true" />
                    <span className="visually-hidden">Hilos dashboard</span>
                  </HilosLink>
                ) : null}
              </>
            )}
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
        className={`container flex-grow-1 min-h-0 overflow-auto py-4${
          underMaintenance ? ' d-flex flex-column' : ''
        }`}
      >
        {underMaintenance ? (
          <HilosMaintenance
            status={protectedMode}
            connection={connection}
            adminSurface={currentRoute.admin}
          />
        ) : (
          children
        )}
      </main>
      {!underMaintenance && (
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
      )}
      {/* Transient notices float over the shell, so every page inside it can
      report an outcome without owning a notification surface of its own. */}
      <HilosToastHost />
      {/* An OAuth trip runs in another window over whatever page started it, so
      the wait belongs to the shell too: the page underneath stays subscribed and
      alive, and no project mounts anything (HIL-633). */}
      <HilosOAuthWaitModal />
    </div>
  )
}
