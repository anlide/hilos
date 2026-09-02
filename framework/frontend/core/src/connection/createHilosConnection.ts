// One-call connection factory: the single WebSocket every Hilos SPA opens, with
// the framework session and page schemas already merged, the action-error store
// attached, and the stale-build welcome wired to a reload. Lifted from every
// project's connection bootstrap (docs/agents/frontend/bootstrap-structure.md).
import { BACKUP_SIGNAL_SCHEMAS } from '../admin/backup/hilosBackups.js'
import { LOGS_KEYS_SIGNAL_SCHEMAS } from '../admin/logs/hilosLogKeys.js'
import { LOGS_OVERVIEW_SIGNAL_SCHEMAS } from '../admin/logs/hilosLogsOverview.js'
import { LOGS_SIGNAL_SCHEMAS } from '../admin/logs/hilosLogRotations.js'
import { LOGS_VIEWER_SIGNAL_SCHEMAS } from '../admin/logs/hilosLogViewer.js'
import { LOGS_WORKERS_SIGNAL_SCHEMAS } from '../admin/logs/hilosLogWorkers.js'
import { NOTIFICATION_SIGNAL_SCHEMAS } from '../notifications/notificationCenter.js'
import { NOTIFICATION_PREFERENCE_SIGNAL_SCHEMAS } from '../notifications/notificationPreferences.js'
import { type ProjectSignalSchemas } from '../protocol/parseSignal.js'
import { SESSION_SIGNAL_SCHEMAS } from '../session/sessionScope.js'
import { GROUP_SIGNAL_SCHEMAS } from '../protocol/groupError.js'
import { PAGE_SIGNAL_SCHEMAS } from '../subscription/bindPageScope.js'
import { ActionErrorStore } from './ActionErrorStore.js'
import { ActionLifecycle } from './actionLifecycle.js'
import {
  HilosConnection,
  type SessionRotation,
  type WebSocketLike,
} from './HilosConnection.js'

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
  /**
   * Handler for a session-token rotation (HIL-582). Defaults to writing the ticket into
   * the auxiliary cookie the master trades it back for on the reconnect that follows —
   * the connection starts that reconnect itself, so a handler that does not write the
   * cookie costs the session. Overridden only by tests, which have no document.
   */
  onSessionRotate?: (rotation: SessionRotation) => void
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

/** Seconds the auxiliary cookie lives: the ticket's own lifetime (PHP `SessionRotationTicket`). */
const ROTATE_COOKIE_MAX_AGE_SECONDS = 30

/**
 * Write the rotation ticket where the master reads it on the next handshake.
 *
 * Plain (not HttpOnly) because this is the one cookie the client itself has to write, and
 * short-lived because a ticket outliving the reconnect it was minted for protects nothing.
 * `SameSite=Strict` costs nothing here — the socket is same-origin — and `Secure` follows
 * the page, so an https document never hands the ticket to a plain-http request.
 *
 * @param rotation Ticket to hand back and the cookie name to hand it back in.
 */
function writeRotationCookie(rotation: SessionRotation): void {
  const secure = location.protocol === 'https:' ? '; Secure' : ''
  document.cookie =
    `${rotation.cookieName}=${rotation.ticket}` +
    `; Path=/; SameSite=Strict; Max-Age=${ROTATE_COOKIE_MAX_AGE_SECONDS}${secure}`
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
      ...GROUP_SIGNAL_SCHEMAS,
      ...BACKUP_SIGNAL_SCHEMAS,
      ...LOGS_SIGNAL_SCHEMAS,
      ...LOGS_KEYS_SIGNAL_SCHEMAS,
      ...LOGS_OVERVIEW_SIGNAL_SCHEMAS,
      ...LOGS_VIEWER_SIGNAL_SCHEMAS,
      ...LOGS_WORKERS_SIGNAL_SCHEMAS,
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
  connection.on('sessionRotate', options.onSessionRotate ?? writeRotationCookie)
  connection.on('protectedMode', (status) => {
    // The mode is over when it locks nobody out AND has no window left open. Both
    // halves are needed, because an admitted verifier is told "not locked out" in the
    // same words a lift uses — `acceptsPass` is the row's own bit and is what keeps
    // that verifier from being reloaded out of the window it was let into. Once the
    // mode really is over there is no catch-up snapshot, so the document has to come
    // back from the server; a pushed frame brings that news, and for a tab that was
    // disconnected at the time, the welcome of the socket that comes back does.
    if (!status.active && !status.acceptsPass) {
      onLift()
    }
  })

  return { connection, actionErrors, actions }
}
