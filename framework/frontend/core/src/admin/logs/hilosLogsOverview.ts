// The framework Hilos logs-overview admin headless: the header view-model, the two
// empty states, the takeout verdict, and the wording of every figure the four tiles
// and the per-node table draw. It is framework-agnostic (imports no UI framework)
// and reads only @hilos/core primitives, so the Vue/React/Angular overview views
// stay thin (multiframework-core.md).
//
// Everything arrives in ONE frame — the page's own header signal, sent ahead of the
// frame that releases the page and again on the agent's tick. There is no table
// viewport here and no second wire: the rows are one per node that reported, they
// fit in the header whole, and a viewport for three rows would cost a descriptor, a
// pager and a busy state for a list that never needs paging.
//
// The screen answers "is anything wrong with the logs, and where do I go from here".
// It commands nothing: every way off it is ordinary navigation.

import { z } from 'zod'
import { type HilosConnection } from '../../connection/HilosConnection.js'
import { createSignal, type ReadonlySignal } from '../../state/signal.js'

/** Server→client signal `type` carrying the whole screen (PHP `SUBSCRIPTION_PAGE_HILOS_LOGS`). */
export const OVERVIEW_SIGNAL = 'subscription_page_hilos_logs'

// Row payload keys of the per-node table. They are declared here because this module
// owns the view-model they resolve into, and they are what the schema below is built
// from, so the wire name has one owner instead of a copy per schema and per view.
// The header's own keys are not declared this way for the reason the neighbouring
// screens do not declare theirs: they are fields of one object, not of a row.

/** Row payload key of the node the row speaks for. */
export const OVERVIEW_NODE_ID_FIELD = 'nodeId'

/** Row payload key of whether that node could read its own log store. */
export const OVERVIEW_NODE_AVAILABLE_FIELD = 'available'

/** Row payload key of the node's newest rotation, ISO 8601. */
export const OVERVIEW_NODE_LAST_ROTATION_FIELD = 'lastRotationAt'

/** Row payload key of what the node's live files weigh. */
export const OVERVIEW_NODE_LIVE_BYTES_FIELD = 'liveBytes'

/** Row payload key of what the node's archive weighs. */
export const OVERVIEW_NODE_ARCHIVE_BYTES_FIELD = 'archiveBytes'

/** Row payload key of what the node wrote over the last day. */
export const OVERVIEW_NODE_GROWTH_FIELD = 'growthBytesPerDay'

/** Row payload key of the node's batches past their retention. */
export const OVERVIEW_NODE_DUE_FIELD = 'batchesDueForTakeout'

/**
 * One row of the per-node table.
 *
 * A node that could not be read keeps its row with null in every figure: a zero
 * there would be a measurement nobody took, and dropping the row would read as a
 * node that never reported at all.
 */
const overviewNodeSchema = z.looseObject({
  [OVERVIEW_NODE_ID_FIELD]: z.string(),
  [OVERVIEW_NODE_AVAILABLE_FIELD]: z.boolean(),
  [OVERVIEW_NODE_LAST_ROTATION_FIELD]: z.string().nullable(),
  [OVERVIEW_NODE_LIVE_BYTES_FIELD]: z.number().nullable(),
  [OVERVIEW_NODE_ARCHIVE_BYTES_FIELD]: z.number().nullable(),
  [OVERVIEW_NODE_GROWTH_FIELD]: z.number().nullable(),
  [OVERVIEW_NODE_DUE_FIELD]: z.number().nullable(),
})

/**
 * Payload of the whole screen: the tiles, the takeout verdict and the per-node table
 * in one frame (PHP `HilosLogsOverviewSignalData`).
 */
const overviewSchema = z.looseObject({
  available: z.boolean().nullable(),
  totalRotationsAllTime: z.number().nullable(),
  lastRotationAt: z.string().nullable(),
  logKeysPerAgent: z.number().nullable(),
  totalWeightAgentKeysBytes: z.number().nullable(),
  logKeysPerWorker: z.number().nullable(),
  totalWeightWorkerKeysBytes: z.number().nullable(),
  growthBytesPerDay: z.number().nullable(),
  keysWithoutGrowthWindow: z.number().nullable(),
  batchesDueForTakeout: z.number().nullable(),
  nodes: z.array(overviewNodeSchema),
})

/** One node's row of the per-node table. */
export type HilosLogsOverviewNode = z.infer<typeof overviewNodeSchema>

/** The screen as the page answers a subscription with it. */
export type HilosLogsOverview = z.infer<typeof overviewSchema>

/**
 * The overview signal schema keyed for a connection's `projectSchemas`, so the parse
 * boundary validates the frames {@link createHilosLogsOverview} ingests.
 * {@link createHilosConnection} merges it in, so a project never restates it.
 *
 * A set of its own rather than an entry in a neighbour's: two independent screens
 * must not depend on which of them landed first.
 */
export const LOGS_OVERVIEW_SIGNAL_SCHEMAS = {
  [OVERVIEW_SIGNAL]: overviewSchema,
}

/**
 * The project-supplied context the logs-overview admin reads from.
 *
 * One field, where the three neighbouring log screens carry two: they each own a
 * table window that normalizes into a page scope, and this screen has no window at
 * all. A scope manager here would be a promise that something needs it.
 */
export interface HilosLogsOverviewContext {
  /** The connection the screen's frames arrive on. */
  readonly connection: HilosConnection
}

/** The overview handle a view drives: the header signal plus its mount lifecycle. */
export interface HilosLogsOverviewHandle {
  /** The latest screen, or null before the first frame arrives. */
  readonly overview: ReadonlySignal<HilosLogsOverview | null>
  /** Start listening for frames — call on mount. */
  start(): void
  /** Stop listening — call on unmount. */
  dispose(): void
}

/** What is shown in place of a figure nobody knows. */
const NOTHING_KNOWN = '—'

/**
 * The screen, reactively: the answer to the subscription and every later push the
 * page makes when the cluster picture moves.
 *
 * It rides the connection rather than a page scope because it is the page's own
 * signal, sent ahead of the frame that releases the page and again on the agent's
 * tick. Nothing is ever re-requested — freshness arrives by push.
 *
 * @param context The project context (the connection the frames arrive on).
 */
export function createHilosLogsOverview(
  context: HilosLogsOverviewContext,
): HilosLogsOverviewHandle {
  const overview = createSignal<HilosLogsOverview | null>(null)
  const teardown: Array<() => void> = []

  return {
    overview,
    start() {
      teardown.push(
        context.connection.on('projectSignal', (signal) => {
          if (signal.type === OVERVIEW_SIGNAL) {
            // Validated against overviewSchema at the parse boundary; this cast is
            // the declared typed selector for that schema's output.
            overview.set(signal.data as HilosLogsOverview)
          }
        }),
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
 * Whether this installation names its nodes, which is what decides the whole
 * per-node table and the way the takeout banner and the unreadable notice are worded.
 *
 * An empty list is the single-node installation, where the idea of a node is absent:
 * a table of one row about "this machine" would be furniture for a distinction that
 * does not exist. A frame that has not arrived reads the same way — the screen starts
 * without the table rather than flashing one that is about to go.
 *
 * @param overview The latest screen, or null before the first frame arrives.
 */
export function hasLogsOverviewNodes(
  overview: HilosLogsOverview | null,
): boolean {
  return overview !== null && overview.nodes.length > 0
}

/** What the overview has to say instead of figures. */
export type HilosLogsOverviewState =
  /** There are figures, and the tiles carry them. */
  | 'figures'
  /** No merged picture has arrived; the figures are unknown rather than zero. */
  | 'unknown'
  /** The picture arrived and no node could read its log store. */
  | 'unreadable'

/**
 * Which of the two empty states the screen is in, or that it has figures.
 *
 * Two and not the four the by-key screen answers: there is nothing to filter here,
 * so "nothing matched" cannot happen, and an installation that has never written a
 * log reads as an ordinary picture whose batch count is zero. Zeros are shown for
 * neither of the two — a zero would claim a measurement that was never taken.
 *
 * @param overview The latest screen, or null before the first frame arrives.
 */
export function logsOverviewState(
  overview: HilosLogsOverview | null,
): HilosLogsOverviewState {
  if (overview === null || overview.available === null) {
    return 'unknown'
  }

  return overview.available ? 'figures' : 'unreadable'
}

/**
 * The nodes the takeout banner names, which are the ones actually holding batches
 * past their retention.
 *
 * There is no field for this on the wire and there does not need to be one: the
 * banner's list is the per-node table read with one question in mind, and deriving
 * it here keeps the two from ever disagreeing.
 *
 * @param overview The latest screen, or null before the first frame arrives.
 */
export function logsOverviewNodesDue(
  overview: HilosLogsOverview | null,
): string[] {
  if (overview === null) {
    return []
  }

  return overview.nodes
    .filter((node) => (node.batchesDueForTakeout ?? 0) > 0)
    .map((node) => node.nodeId)
}

/**
 * A byte figure in the largest unit that leaves a readable number.
 *
 * Null is a dash and zero is `0 B`, and the difference is the whole point: the zero
 * was measured, the dash was not.
 *
 * @param bytes The figure in bytes, or null when it is not known.
 */
export function formatLogsOverviewBytes(bytes: number | null): string {
  if (bytes === null) {
    return NOTHING_KNOWN
  }

  const units = ['B', 'KB', 'MB', 'GB', 'TB']
  let size = bytes
  let unit = 0
  while (size >= 1024 && unit < units.length - 1) {
    size /= 1024
    unit += 1
  }

  return `${unit === 0 ? size : size.toFixed(1)} ${units[unit]}`
}

/**
 * A counted figure, with the same rule the byte one keeps: the dash is "not known"
 * and a zero is a count that was taken.
 *
 * @param count The figure, or null when it is not known.
 */
export function formatLogsOverviewCount(count: number | null): string {
  return count === null ? NOTHING_KNOWN : String(count)
}

/**
 * A rotation instant in the reader's own locale, or a dash when there was none.
 *
 * An instant that cannot be read is a dash as well rather than the words `Invalid
 * Date`: we do not know when it was, which is exactly what the dash says.
 *
 * @param at The instant as ISO 8601, or null when nothing has rotated.
 */
export function formatLogsOverviewRotationAt(at: string | null): string {
  if (at === null) {
    return NOTHING_KNOWN
  }

  const parsed = new Date(at)

  return Number.isNaN(parsed.getTime())
    ? NOTHING_KNOWN
    : parsed.toLocaleString()
}

/**
 * The line under the last rotation: how many batches there have been in all.
 *
 * A phrase and not a bare number, because the noun has to agree with it — a fresh
 * installation is at one, which is exactly the count a screen written for the plural
 * gets wrong and nobody notices, since by the time anybody looks it is at three.
 * An unknown count keeps the plural: it is not a one.
 *
 * @param overview The latest screen, or null before the first frame arrives.
 */
export function logsOverviewBatchesNote(
  overview: HilosLogsOverview | null,
): string {
  const batches = overview?.totalRotationsAllTime ?? null

  return batches === 1
    ? '1 batch so far'
    : `${formatLogsOverviewCount(batches)} batches so far`
}

/**
 * The first line of the takeout banner: how much is waiting.
 *
 * The banner is drawn only when something IS waiting, so there is no zero case and
 * the count is never the unknown — a screen with no figures has no verdict to warn
 * about. What the sentence does have to agree with is the one.
 *
 * @param batchesDue Batches past their retention across the cluster.
 */
export function logsOverviewTakeoutHeadline(batchesDue: number): string {
  return batchesDue === 1
    ? '1 batch is waiting to be taken out'
    : `${batchesDue} batches are waiting to be taken out`
}

/**
 * What the growth tile shows, which has three positions and not two.
 *
 * A day's growth nobody has measured for a whole day yet is NOT zero: zero is the
 * claim that nothing was written, and this tile is read by somebody asking whether
 * the logs are running away with the disk. So it says so in words instead.
 *
 * @param overview The latest screen, or null before the first frame arrives.
 */
export function formatLogsOverviewGrowth(
  overview: HilosLogsOverview | null,
): string {
  if (logsOverviewState(overview) !== 'figures' || overview === null) {
    return NOTHING_KNOWN
  }

  return overview.growthBytesPerDay === null
    ? 'Still measuring'
    : formatLogsOverviewBytes(overview.growthBytesPerDay)
}

/**
 * The line under the growth figure, when there is something to qualify.
 *
 * It appears only beside a NUMBER: with no number at all the tile already says the
 * day is still being measured, and repeating it underneath would say one thing twice.
 *
 * @param overview The latest screen, or null before the first frame arrives.
 */
export function logsOverviewGrowthNote(
  overview: HilosLogsOverview | null,
): string | null {
  if (overview === null || overview.growthBytesPerDay === null) {
    return null
  }

  const streams = overview.keysWithoutGrowthWindow ?? 0
  if (streams === 0) {
    return null
  }

  return `No full day of data yet for ${streams} stream${streams === 1 ? '' : 's'}`
}
