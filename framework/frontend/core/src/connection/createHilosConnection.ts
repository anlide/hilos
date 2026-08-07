// One-call connection factory: the single WebSocket every Hilos SPA opens, with
// the framework session and page schemas already merged, the action-error store
// attached, and the stale-build welcome wired to a reload. Lifted from every
// project's connection bootstrap (docs/agents/frontend/bootstrap-structure.md).
import { NOTIFICATION_SIGNAL_SCHEMAS } from '../notifications/notificationCenter.js'
import { NOTIFICATION_PREFERENCE_SIGNAL_SCHEMAS } from '../notifications/notificationPreferences.js'
import { type ProjectSignalSchemas } from '../protocol/parseSignal.js'
import { SESSION_SIGNAL_SCHEMAS } from '../session/sessionScope.js'
import { PAGE_SIGNAL_SCHEMAS } from '../subscription/bindPageScope.js'
import { ActionErrorStore } from './ActionErrorStore.js'
import { ActionLifecycle } from './actionLifecycle.js'
import { HilosConnection, type WebSocketLike } from './HilosConnection.js'

/** Options for {@link createHilosConnection}. */
export interface CreateHilosConnectionOptions {
  /**
   * WebSocket endpoint. Defaults to the same-origin `/ws` route, which nginx
   * proxies to the daemon. A Vite dev stack passes `import.meta.env.VITE_WS_URL`
   * (which may be undefined, falling back to same-origin).
   */
  url?: string
  /**
   * Extra project signal schemas, merged after the framework's session and page
   * schemas. A project adds its own signal types here; most pass nothing.
   */
  projectSchemas?: ProjectSignalSchemas
  /**
   * Handler for a welcome carrying a different build than the latched one — this
   * bundle is stale. Defaults to a full-page reload (wire-protocol.md
   * forced-refresh check).
   */
  onBuildMismatch?: () => void
  /**
   * Handler for protected mode lifting. Defaults to a full-page reload, and that
   * is not cosmetic: a full snapshot is sent once, on page_subscribe, and after
   * it the client only receives deltas — so a page that merely sat through the
   * freeze would keep pre-restore rows forever. The session, the runtime baseline
   * and even the build can all differ on the other side of a restore; reloading
   * is the only outcome that is honest about all of them at once.
   */
  onProtectedModeLift?: () => void
  /** Socket constructor seam; tests inject a mock. Default `new WebSocket(url)`. */
  webSocketFactory?: (url: string) => WebSocketLike
}

/** The connection plus the action-error store and the action lifecycle bound to it. */
export interface HilosConnectionBundle {
  connection: HilosConnection
  actionErrors: ActionErrorStore
  /** The requestId-correlated reply lifecycle: `actions.dispatch(...)` for tracked actions. */
  actions: ActionLifecycle
}

/** The same-origin `/ws` endpoint: `wss` under https, `ws` otherwise. */
function sameOriginWebSocketUrl(): string {
  const scheme = location.protocol === 'https:' ? 'wss' : 'ws'

  return `${scheme}://${location.host}/ws`
}

/**
 * Create the application's single Hilos connection with the framework schemas
 * merged, an {@link ActionErrorStore} attached, and both forced-refresh checks
 * wired — the stale build and the lifting of protected mode. The connection is returned unopened — {@link bootHilos} (or the caller)
 * opens it once the subscriptions are bound.
 *
 * @param options Endpoint, extra schemas, reload handlers, socket seam.
 */
export function createHilosConnection(
  options: CreateHilosConnectionOptions = {},
): HilosConnectionBundle {
  const connection = new HilosConnection({
    url: options.url ?? sameOriginWebSocketUrl(),
    projectSchemas: {
      ...SESSION_SIGNAL_SCHEMAS,
      ...PAGE_SIGNAL_SCHEMAS,
      ...NOTIFICATION_SIGNAL_SCHEMAS,
      ...NOTIFICATION_PREFERENCE_SIGNAL_SCHEMAS,
      ...options.projectSchemas,
    },
    webSocketFactory: options.webSocketFactory,
  })
  const actionErrors = new ActionErrorStore(connection)
  const actions = new ActionLifecycle(connection)
  connection.on(
    'buildMismatch',
    options.onBuildMismatch ??
      (() => {
        location.reload()
      }),
  )
  const onLift =
    options.onProtectedModeLift ??
    (() => {
      location.reload()
    })
  connection.on('protectedMode', (status) => {
    // An inactive state reaches this event only as a lift: a pushed frame saying so
    // is the daemon leaving the mode, and a welcome saying so is emitted only when
    // it changes what the connection held. Either way there is no catch-up snapshot,
    // so the document has to come back from the server.
    if (!status.active) {
      onLift()
    }
  })

  return { connection, actionErrors, actions }
}
