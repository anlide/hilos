// The single application boot sequence: bind the session and page scopes, build
// the navigator, open the socket, and apply the URL — the order every Hilos SPA
// repeats, lifted so a project's entry only configures and mounts
// (docs/agents/frontend/bootstrap-structure.md). The connection and scopes are
// created by the project (so its modules import them as singletons); bootHilos
// wires and starts them.
import { type HilosConnection } from '../connection/HilosConnection.js'
import {
  bindNotificationsScope,
  hilosNotifications,
} from '../notifications/notificationCenter.js'
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
  SIGNAL_HANDSHAKE_RESPONSE,
  sessionUserId,
  sessionUserIsAdmin,
  type SessionScopeOptions,
} from '../session/sessionScope.js'
import { bindSessionToasts } from '../session/sessionToasts.js'
import { hilosToasts } from '../state/toasts.js'
import { type ScopeManager } from '../state/ScopeManager.js'
import { bindAccessReaction } from '../subscription/bindAccessReaction.js'
import { bindPageScope } from '../subscription/bindPageScope.js'
import { bindPageReady } from '../subscription/pageReadyGate.js'

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
   * Activate the notification center: bind the shared {@link hilosNotifications}
   * store to the connection (join the notification group, whose answer carries the
   * snapshot, and react to live signals). Set only where the backend registers the
   * framework notification group; a demo without it leaves this off so no join
   * reaches a group nobody serves. Default off.
   */
  notifications?: boolean
  /**
   * Browser binding for the navigator; defaults to the window-backed one. Tests
   * inject a fake to stay DOM-free.
   */
  navigationEnvironment?: NavigationEnvironment
}

/**
 * Boot the application: bind the session scope, the page scope, and the
 * page-ready gate to the connection, create the navigator over the page
 * subscription, open the socket, and apply the current URL. The session-token
 * cookie must already be minted (the project's session module does this at
 * import) so it rides the handshake.
 *
 * @param config The connection, scopes, router, and optional overrides.
 * @returns The navigator the app provides to `HilosView` / `HilosLink`.
 */
export function bootHilos(config: BootHilosConfig): HilosRouter {
  bindSessionScope(config.connection, config.scopes, config.session)
  // No option to switch on: a project that carries sessions carries their toasts
  // (HIL-768), and a project without them is never sent a frame. One behavior
  // rather than a flag nobody would ever want off.
  bindSessionToasts(config.connection, hilosToasts)
  if (config.notifications === true) {
    bindNotificationsScope(
      config.connection,
      hilosNotifications,
      sessionUserId(config.scopes, config.session),
    )
  }
  const pages = bindPageScope(config.connection, config.scopes, {
    entityTypes: config.pageEntityTypes,
  })
  // The page-ready gate (HIL-607): latch the first page answer so a relay route
  // that loads cold — /auth/magic, /auth/callback — can hold its dispatch until
  // the connection can carry one. Bound here, before the socket opens, so the
  // first answer is never missed.
  bindPageReady(config.connection)
  const hilosRouter = createHilosRouter(
    config.router,
    pages,
    config.navigationEnvironment ?? browserNavigationEnvironment(),
    (pageKey) =>
      resolvePageTitle(pageKey, config.pageTitles ?? {}, config.appName ?? ''),
  )
  // A rights change reaches the open tab (HIL-621): the handshake response the
  // grant re-sends moves this marker, and the page on screen is judged again -
  // here by its surface type, on the server by the whole access verdict. Bound
  // beside the handshake reaction below because both read the same answer.
  // Signing out moves the person on that same answer (HIL-652), which is the
  // second input: the marker falling says "no longer allowed", the identity
  // going says "no longer anybody", and the two are drawn differently.
  bindAccessReaction(
    hilosRouter,
    sessionUserIsAdmin(config.scopes, config.session),
    sessionUserId(config.scopes, config.session),
  )
  // The page subscribe is held until the session answers: the connection's
  // identity is established by the handshake and reaches the other workers on
  // its own, so a subscribe that overtook it was judged against a connection
  // nobody had heard of yet and refused as anonymous. The route still resolves
  // now — only the frame waits.
  // TODO(HIL-599): the server now holds a frame from a connection it has not been
  // told about yet and judges it once the identity lands, so this hold is a second
  // lock rather than the only one. It stays until the server side has been in the
  // wild long enough to trust alone - revisit after 2027-12-28, and only on the
  // owner's word, as a leaf of its own.
  config.connection.on('projectSignal', (signal) => {
    if (signal.type === SIGNAL_HANDSHAKE_RESPONSE) {
      pages.releaseOnSession()
    }
  })
  config.connection.connect()
  hilosRouter.start()

  return hilosRouter
}
