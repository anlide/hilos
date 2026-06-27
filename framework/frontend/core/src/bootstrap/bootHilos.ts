// The single application boot sequence: bind the session and page scopes, build
// the navigator, open the socket, and apply the URL — the order every Hilos SPA
// repeats, lifted so a project's entry only configures and mounts
// (docs/agents/frontend/bootstrap-structure.md). The connection and scopes are
// created by the project (so its modules import them as singletons); bootHilos
// wires and starts them.
import { type HilosConnection } from '../connection/HilosConnection.js'
import {
  browserNavigationEnvironment,
  createHilosRouter,
  type HilosRouter,
  type NavigationEnvironment,
} from '../routing/HilosRouter.js'
import { resolvePageTitle } from '../routing/pageTitle.js'
import { type PageRouter } from '../routing/PageRouter.js'
import {
  bindSessionScope,
  type SessionScopeOptions,
} from '../session/sessionScope.js'
import { type ScopeManager } from '../state/ScopeManager.js'
import { bindPageScope } from '../subscription/bindPageScope.js'

/** Configuration for {@link bootHilos}. */
export interface BootHilosConfig {
  /** The application's connection (e.g. from `createHilosConnection`). */
  connection: HilosConnection
  /** The application's scope-partitioned stores. */
  scopes: ScopeManager
  /** The page router resolving a URL to its page key and route params. */
  router: PageRouter
  /** Per-slot canonical entity types for the page payloads. */
  pageEntityTypes?: Record<string, string>
  /**
   * Project page key → document title, for the project's own pages. The
   * framework's admin and footer pages are titled from their own catalogs, so a
   * project lists only its own pages here. Merged with {@link BootHilosConfig.appName}
   * into `HilosRouter.currentTitle` for the browser tab and the page-change
   * announcement (WCAG 2.4.2).
   */
  pageTitles?: Record<string, string>
  /**
   * The application name composed into every document title as `"<page> ·
   * <app>"`. Omit it and a title is the bare page label.
   */
  appName?: string
  /** Current-user slot, entity-type, and name-field overrides. */
  session?: SessionScopeOptions
  /**
   * Browser binding for the navigator; defaults to the window-backed one. Tests
   * inject a fake to stay DOM-free.
   */
  navigationEnvironment?: NavigationEnvironment
}

/**
 * Boot the application: bind the session scope and the page scope to the
 * connection, create the navigator over the page subscription, open the socket,
 * and apply the current URL. The session-token cookie must already be minted
 * (the project's session module does this at import) so it rides the handshake.
 *
 * @param config The connection, scopes, router, and optional overrides.
 * @returns The navigator the app provides to `HilosView` / `HilosLink`.
 */
export function bootHilos(config: BootHilosConfig): HilosRouter {
  bindSessionScope(config.connection, config.scopes, config.session)
  const pages = bindPageScope(config.connection, config.scopes, {
    entityTypes: config.pageEntityTypes,
  })
  const hilosRouter = createHilosRouter(
    config.router,
    pages,
    config.navigationEnvironment ?? browserNavigationEnvironment(),
    (pageKey) =>
      resolvePageTitle(pageKey, config.pageTitles ?? {}, config.appName ?? ''),
  )
  config.connection.connect()
  hilosRouter.start()

  return hilosRouter
}
