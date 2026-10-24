// Framework headless cron table for one Daemon node. Rows come from the
// worker-local picture mirror through a server viewport, in picture order.
// The page header is drawn by the per-framework view; the empty-state and
// idle-notice choices live here.

import {
  DAEMON_NODE_STATE_SILENT,
  DAEMON_NODE_STATE_STANDBY,
  type HilosDaemonNodeHeading,
} from './hilosDaemonNode.js'
import { type HilosConnection } from '../../connection/HilosConnection.js'
import { formatMoment } from '../../format/moment.js'
import { HilosPages } from '../../routing/hilosPages.js'
import { offsetMs } from '../../session/serverClock.js'
import {
  readNumberOrNull,
  readString,
  readStringOrNull,
} from '../../state/fieldReaders.js'
import { type ScopeManager } from '../../state/ScopeManager.js'
import { computedSignal, type ReadonlySignal } from '../../state/signal.js'
import { type TableRow } from '../../state/TableRowsStore.js'
import { bindTableViewport } from '../../subscription/bindTableViewport.js'
import { type HilosTableFrame } from '../../table/tableFrame.js'
import { TableViewportController } from '../../table/TableViewportController.js'

const DAEMON_CRON_TABLE = 'hilosDaemonCron'
const DAEMON_CRON_SLOT = 'rule'

/** Page-data key recording whether the node has reported its cron section. */
export const DAEMON_CRON_REPORTED_DATA = 'cronReported'

/** Viewport filter key identifying the node shown by the page. */
export const DAEMON_CRON_FILTER_NODE = 'node'

/** Row payload key of the rule name. */
export const DAEMON_CRON_NAME_FIELD = 'name'
/** Row payload key of the agent owner, null for a daemon rule. */
export const DAEMON_CRON_AGENT_FIELD = 'agentId'
/** Row payload key of the cron expression. */
export const DAEMON_CRON_EXPRESSION_FIELD = 'expression'
/** Row payload key of the last actual firing time. */
export const DAEMON_CRON_LAST_RUN_FIELD = 'lastRunAt'
/** Row payload key of the next matching minute. */
export const DAEMON_CRON_NEXT_RUN_FIELD = 'nextRunAt'
/** Row payload key of the daemon's idle reason. */
export const DAEMON_CRON_IDLE_FIELD = 'idleReason'

/** Daemon rules on a follower do not run there. */
export const DAEMON_CRON_IDLE_NOT_LEADER = 'not_leader'

/** One rule in the selected node's cron picture. */
export interface HilosDaemonCronRow {
  readonly rowKey: string
  readonly agentId: string | null
  readonly name: string
  readonly expression: string
  /** Last actual firing in epoch milliseconds. */
  readonly lastRunAt: number | null
  /** Next matching minute in epoch milliseconds. */
  readonly nextRunAt: number | null
  readonly idleReason: string | null
}

/** Connection and page scope used by the cron table. */
export interface HilosDaemonCronContext {
  readonly connection: HilosConnection
  readonly scopes: ScopeManager
}

/** Table controller and its view mount lifecycle. */
export interface HilosDaemonCronTable {
  readonly controller: TableViewportController<HilosDaemonCronRow>
  start(): void
  dispose(): void
}

/** Empty-table explanation for a node's cron rules. */
export type HilosDaemonCronEmptyState = 'waiting' | 'silent' | 'none'

/** Notice explaining why daemon rules do not run on this node. */
export type HilosDaemonCronIdleNotice = 'none' | 'idle' | 'idle_until_leader'

/** @param scopes The page scope manager. */
export function readDaemonCronReported(
  scopes: ScopeManager,
): ReadonlySignal<boolean> {
  const source = scopes.pageDataSignal(DAEMON_CRON_REPORTED_DATA)
  return computedSignal(() => source.get() === true)
}

/**
 * Choose the message for an empty cron table.
 *
 * @param heading The current node heading, if it has arrived.
 * @param cronReported Whether the node has reported its cron section.
 */
export function daemonCronEmptyState(
  heading: HilosDaemonNodeHeading | null,
  cronReported: boolean,
): HilosDaemonCronEmptyState {
  if (cronReported) {
    return 'none'
  }
  return heading?.state === DAEMON_NODE_STATE_SILENT ? 'silent' : 'waiting'
}

/**
 * Choose the notice for daemon rules that are idle on this node.
 *
 * @param rows Rules in the current table window.
 * @param heading The current node heading, if it has arrived.
 */
export function daemonCronIdleNotice(
  rows: readonly HilosDaemonCronRow[],
  heading: HilosDaemonNodeHeading | null,
): HilosDaemonCronIdleNotice {
  if (!rows.some((row) => row.agentId === null && row.idleReason !== null)) {
    return 'none'
  }
  return heading?.state === DAEMON_NODE_STATE_STANDBY
    ? 'idle_until_leader'
    : 'idle'
}

/**
 * Hold room for the notice wherever daemon rules can become idle.
 *
 * @param rows Rules in the current table window.
 * @param heading The current node heading, if it has arrived.
 */
export function daemonCronHoldsIdleNotice(
  rows: readonly HilosDaemonCronRow[],
  heading: HilosDaemonNodeHeading | null,
): boolean {
  return heading?.clustered === true && rows.some((row) => row.agentId === null)
}

/**
 * Read one inline `rule` slot; identity comes from the table fragment's row key.
 *
 * @param row The raw row in the page scope.
 */
export function resolveHilosDaemonCronRow(row: TableRow): HilosDaemonCronRow {
  const raw = row.slots[DAEMON_CRON_SLOT]
  const slot: Readonly<Record<string, unknown>> =
    typeof raw === 'object' && raw !== null && !Array.isArray(raw)
      ? (raw as Record<string, unknown>)
      : {}

  return {
    rowKey: String(row.rowKey),
    agentId: readStringOrNull(slot, DAEMON_CRON_AGENT_FIELD),
    name: readString(slot, DAEMON_CRON_NAME_FIELD),
    expression: readString(slot, DAEMON_CRON_EXPRESSION_FIELD),
    lastRunAt: readNumberOrNull(slot, DAEMON_CRON_LAST_RUN_FIELD),
    nextRunAt: readNumberOrNull(slot, DAEMON_CRON_NEXT_RUN_FIELD),
    idleReason: readStringOrNull(slot, DAEMON_CRON_IDLE_FIELD),
  }
}

/**
 * Build the viewport for the node selected by the page route.
 *
 * @param context Connection and scope manager.
 * @param nodeId Node id from the route, including `standalone` on one node.
 */
export function createHilosDaemonCronTable(
  context: HilosDaemonCronContext,
  nodeId: string,
): HilosDaemonCronTable {
  const frame: HilosTableFrame = {
    columns: [
      { key: DAEMON_CRON_NAME_FIELD, label: 'Rule', card: 'title' },
      { key: DAEMON_CRON_AGENT_FIELD, label: 'Run by', card: 'badge' },
      { key: DAEMON_CRON_EXPRESSION_FIELD, label: 'Expression' },
      {
        key: DAEMON_CRON_LAST_RUN_FIELD,
        label: 'Last run',
        reads: [DAEMON_CRON_IDLE_FIELD],
      },
      {
        key: DAEMON_CRON_NEXT_RUN_FIELD,
        label: 'Next run',
        reads: [DAEMON_CRON_IDLE_FIELD],
      },
    ],
  }
  const controller = new TableViewportController<HilosDaemonCronRow>({
    resolve: resolveHilosDaemonCronRow,
    sendViewport: (descriptor) =>
      context.connection.sendTableViewport(
        HilosPages.DAEMON_CRON,
        DAEMON_CRON_TABLE,
        descriptor,
      ),
    sendRendered: (rendered) =>
      context.connection.sendTableRendered(
        HilosPages.DAEMON_CRON,
        DAEMON_CRON_TABLE,
        rendered,
      ),
    initialFilter: { [DAEMON_CRON_FILTER_NODE]: nodeId },
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
          { page: HilosPages.DAEMON_CRON, tableKey: DAEMON_CRON_TABLE },
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
 * Name the owner of a cron rule in the node's installation mode.
 *
 * @param row Rule being drawn.
 * @param cluster Whether this installation has cluster leadership.
 */
export function formatDaemonCronExecutor(
  row: HilosDaemonCronRow,
  cluster: boolean,
): string {
  return row.agentId ?? (cluster ? 'Daemon (leader)' : 'Daemon')
}

/**
 * Format the last run, including the daemon's not-on-this-node state.
 * Not yet means the rule has not fired; Never is reserved for the next run.
 *
 * @param row Rule being drawn.
 * @param nowMs Current instant in browser milliseconds.
 */
export function formatDaemonCronLastRun(
  row: HilosDaemonCronRow,
  nowMs: number,
): string {
  if (row.idleReason !== null) {
    return 'Not on this node'
  }
  return row.lastRunAt === null
    ? 'Not yet'
    : formatDaemonCronMoment(row.lastRunAt, nowMs)
}

/**
 * Format the next run, including the daemon's not-on-this-node state.
 *
 * @param row Rule being drawn.
 * @param nowMs Current instant in browser milliseconds.
 */
export function formatDaemonCronNextRun(
  row: HilosDaemonCronRow,
  nowMs: number,
): string {
  if (row.idleReason !== null) {
    return '—'
  }
  return row.nextRunAt === null
    ? 'Never'
    : formatDaemonCronMoment(row.nextRunAt, nowMs)
}

/**
 * Render an epoch millisecond moment in the reader's time zone.
 *
 * @param atMs Instant to show, in epoch milliseconds.
 * @param nowMs Current instant in browser milliseconds.
 */
export function formatDaemonCronMoment(atMs: number, nowMs: number): string {
  const day = formatMoment(atMs, {
    kind: 'calendar',
    now: nowMs + offsetMs(),
    format: { dateStyle: 'medium' },
  })
  const time = formatMoment(atMs, {
    kind: 'time',
    format: { hour: '2-digit', minute: '2-digit' },
  })

  return day === null || time === null ? '—' : `${day}, ${time}`
}
