// Framework headless HTTP routes table for one Daemon node. Rows come from the
// worker-local picture mirror through a server viewport, in picture order.
// The page header and empty states belong to the per-framework view.

import { type HilosConnection } from '../../connection/HilosConnection.js'
import { HilosPages } from '../../routing/hilosPages.js'
import {
  readNumber,
  readNumberOrNull,
  readStringOrNull,
} from '../../state/fieldReaders.js'
import { type ScopeManager } from '../../state/ScopeManager.js'
import { type TableRow } from '../../state/TableRowsStore.js'
import { bindTableViewport } from '../../subscription/bindTableViewport.js'
import { type HilosTableColumnOf } from '../../table/hilosTableColumn.js'
import { type HilosTableFrame } from '../../table/tableFrame.js'
import { TableViewportController } from '../../table/TableViewportController.js'

const DAEMON_HTTP_ROUTES_TABLE = 'hilosDaemonHttpRoutes'
const DAEMON_HTTP_ROUTES_SLOT = 'route'

/** Viewport filter key identifying the node shown by the page. */
export const DAEMON_HTTP_ROUTES_FILTER_NODE = 'node'

/** Row payload key of the registered HTTP method. */
export const DAEMON_HTTP_ROUTE_METHOD_FIELD = 'method'
/** Row payload key of the registered path template. */
export const DAEMON_HTTP_ROUTE_PATH_FIELD = 'path'
/** Row payload key of the answering agent type. */
export const DAEMON_HTTP_ROUTE_AGENT_FIELD = 'agentType'
/** Row payload key of the request count. */
export const DAEMON_HTTP_ROUTE_REQUESTS_FIELD = 'requests'
/** Row payload key of the 5xx response count. */
export const DAEMON_HTTP_ROUTE_ERRORS_FIELD = 'serverErrors'
/** Row payload key of the longest request duration in milliseconds. */
export const DAEMON_HTTP_ROUTE_SLOWEST_FIELD = 'slowestMs'

/** One route, or the unrouted tally, in the selected node's HTTP picture. */
export interface HilosDaemonHttpRouteRow {
  readonly rowKey: string
  readonly method: string | null
  readonly path: string | null
  readonly agentType: string | null
  readonly requests: number
  readonly serverErrors: number
  readonly slowestMs: number | null
}

/** Connection and page scope used by the HTTP routes table. */
export interface HilosDaemonHttpRoutesContext {
  readonly connection: HilosConnection
  readonly scopes: ScopeManager
}

/** Table controller and its view mount lifecycle. */
export interface HilosDaemonHttpRoutesTable {
  readonly controller: TableViewportController<HilosDaemonHttpRouteRow>
  start(): void
  dispose(): void
}

/**
 * Read one inline `route` slot; identity comes from the table fragment's row key.
 *
 * @param row The raw row in the page scope.
 */
export function resolveHilosDaemonHttpRouteRow(
  row: TableRow,
): HilosDaemonHttpRouteRow {
  const raw = row.slots[DAEMON_HTTP_ROUTES_SLOT]
  const slot: Readonly<Record<string, unknown>> =
    typeof raw === 'object' && raw !== null && !Array.isArray(raw)
      ? (raw as Record<string, unknown>)
      : {}

  return {
    rowKey: String(row.rowKey),
    method: readStringOrNull(slot, DAEMON_HTTP_ROUTE_METHOD_FIELD),
    path: readStringOrNull(slot, DAEMON_HTTP_ROUTE_PATH_FIELD),
    agentType: readStringOrNull(slot, DAEMON_HTTP_ROUTE_AGENT_FIELD),
    requests: readNumber(slot, DAEMON_HTTP_ROUTE_REQUESTS_FIELD),
    serverErrors: readNumber(slot, DAEMON_HTTP_ROUTE_ERRORS_FIELD),
    slowestMs: readNumberOrNull(slot, DAEMON_HTTP_ROUTE_SLOWEST_FIELD),
  }
}

/**
 * Build the viewport for the node selected by the page route.
 *
 * @param context Connection and scope manager.
 * @param nodeId Node id from the route, including `standalone` on one node.
 */
export function createHilosDaemonHttpRoutesTable(
  context: HilosDaemonHttpRoutesContext,
  nodeId: string,
): HilosDaemonHttpRoutesTable {
  const columns: HilosTableColumnOf<HilosDaemonHttpRouteRow>[] = [
    {
      key: DAEMON_HTTP_ROUTE_PATH_FIELD,
      label: 'Route',
      card: 'title',
      reads: [DAEMON_HTTP_ROUTE_METHOD_FIELD, DAEMON_HTTP_ROUTE_AGENT_FIELD],
    },
    {
      key: DAEMON_HTTP_ROUTE_REQUESTS_FIELD,
      label: 'Requests',
      headerClass: 'text-center',
      cellClass: 'text-center',
    },
    {
      key: DAEMON_HTTP_ROUTE_ERRORS_FIELD,
      label: 'Errors',
      headerClass: 'text-center',
      cellClass: 'text-center',
    },
    { key: DAEMON_HTTP_ROUTE_SLOWEST_FIELD, label: 'Slowest' },
  ]
  const frame: HilosTableFrame = { columns }
  const controller = new TableViewportController<HilosDaemonHttpRouteRow>({
    resolve: resolveHilosDaemonHttpRouteRow,
    sendViewport: (descriptor) =>
      context.connection.sendTableViewport(
        HilosPages.DAEMON_HTTP_SERVER,
        DAEMON_HTTP_ROUTES_TABLE,
        descriptor,
      ),
    sendRendered: (rendered) =>
      context.connection.sendTableRendered(
        HilosPages.DAEMON_HTTP_SERVER,
        DAEMON_HTTP_ROUTES_TABLE,
        rendered,
      ),
    initialFilter: { [DAEMON_HTTP_ROUTES_FILTER_NODE]: nodeId },
    frame,
  })
  const teardown: Array<() => void> = []

  return {
    controller,
    start() {
      teardown.push(
        bindTableViewport(
          context.connection,
          context.scopes,
          {
            page: HilosPages.DAEMON_HTTP_SERVER,
            tableKey: DAEMON_HTTP_ROUTES_TABLE,
          },
          controller,
        ),
      )
    },
    dispose() {
      for (const off of teardown.splice(0)) {
        off()
      }
    },
  }
}

/**
 * Name a route by its path, with a method prefix only outside GET.
 *
 * @param row Route being drawn.
 */
export function formatDaemonHttpRoute(row: HilosDaemonHttpRouteRow): string {
  if (row.path === null) {
    return 'No route'
  }
  return row.method === 'GET' ? row.path : `${row.method} ${row.path}`
}

/**
 * Format a request count with the browser's digit separators.
 *
 * @param count Count to display.
 */
export function formatDaemonHttpCount(count: number): string {
  return count.toLocaleString()
}

/**
 * Format the longest request duration, or the absence of requests.
 *
 * @param row Route being drawn.
 */
export function formatDaemonHttpSlowest(row: HilosDaemonHttpRouteRow): string {
  return row.slowestMs === null ? '—' : `${row.slowestMs.toLocaleString()} ms`
}
