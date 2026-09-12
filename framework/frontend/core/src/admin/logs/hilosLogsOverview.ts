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
import { LOG_SOURCE_LIVE, logViewerPath } from './hilosLogViewer.js'

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
 * Row payload key of the free bytes on the filesystem holding that node's log root.
 *
 * Null is "not known" — the node named no directory, or its filesystem did not answer
 * — and the forecast is then not drawn at all rather than guessed at.
 */
export const OVERVIEW_NODE_FREE_BYTES_FIELD = 'filesystemFreeBytes'

/** Row payload key of the whole size of that same filesystem. */
export const OVERVIEW_NODE_TOTAL_BYTES_FIELD = 'filesystemTotalBytes'

/** Row payload key of the share of the volume that node keeps free, in percent. */
export const OVERVIEW_NODE_THRESHOLD_FIELD = 'freeSpaceThresholdPercent'

/**
 * Row payload key of the node a failure was written on.
 *
 * Empty in an installation whose nodes have no names, and that empty string is a
 * value rather than an absence: {@link logsOverviewErrorPath} reads it as "the node
 * you are on", where a missing id would mean no file was named at all.
 */
export const OVERVIEW_ERROR_NODE_ID_FIELD = 'nodeId'

/** Row payload key of the live stream a failure was written to. */
export const OVERVIEW_ERROR_STREAM_FIELD = 'stream'

/** Row payload key of when a failure was written, ISO 8601 with milliseconds. */
export const OVERVIEW_ERROR_AT_FIELD = 'at'

/** Row payload key of the failure's text, already cut by the node that read it. */
export const OVERVIEW_ERROR_MESSAGE_FIELD = 'message'

/** Row payload key of the frames in a failure's stack trace, null when it has none. */
export const OVERVIEW_ERROR_TRACE_FRAMES_FIELD = 'traceFrames'

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
  [OVERVIEW_NODE_FREE_BYTES_FIELD]: z.number().nullable(),
  [OVERVIEW_NODE_TOTAL_BYTES_FIELD]: z.number().nullable(),
  [OVERVIEW_NODE_THRESHOLD_FIELD]: z.number().nullable(),
})

/**
 * One row of the recent-errors panel.
 *
 * Every field but the frame count is a string the node measured, so none of them is
 * nullable: a row exists because a line was really written. The frame count keeps its
 * null, which is the difference between "there is a stack to look at" and "there is
 * not" — read as a zero it would put a badge on every row.
 */
const recentErrorSchema = z.looseObject({
  [OVERVIEW_ERROR_NODE_ID_FIELD]: z.string(),
  [OVERVIEW_ERROR_STREAM_FIELD]: z.string(),
  [OVERVIEW_ERROR_AT_FIELD]: z.string(),
  [OVERVIEW_ERROR_MESSAGE_FIELD]: z.string(),
  [OVERVIEW_ERROR_TRACE_FRAMES_FIELD]: z.number().nullable(),
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
  recentErrors: z.array(recentErrorSchema),
  recentErrorsCapped: z.boolean(),
  // The header half of the same three fields the rows carry: a single-node
  // installation has no row to put its disk in, a cluster leaves these null and
  // answers per node. Exactly one half is ever filled.
  [OVERVIEW_NODE_FREE_BYTES_FIELD]: z.number().nullable(),
  [OVERVIEW_NODE_TOTAL_BYTES_FIELD]: z.number().nullable(),
  [OVERVIEW_NODE_THRESHOLD_FIELD]: z.number().nullable(),
})

/** One node's row of the per-node table. */
export type HilosLogsOverviewNode = z.infer<typeof overviewNodeSchema>

/** One failure of the recent-errors panel. */
export type HilosLogsOverviewError = z.infer<typeof recentErrorSchema>

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

/**
 * One installation's — or one node's — answer to "how long until the room runs out".
 *
 * `daysAway` null is not "unknown": a candidate exists only where all three figures
 * and a rate were there, so null is the case the arithmetic actually reached — the
 * threshold is already behind us.
 */
interface LogsOverviewForecast {
  /** The node the figures belong to, null in an installation that names no nodes. */
  readonly nodeId: string | null
  /** Whole days before the threshold is reached, rounded down; null when it is passed. */
  readonly daysAway: number | null
  /**
   * The share that node keeps free, in percent, as THAT node resolved it: with no
   * settings row written two nodes of one cluster can honestly hold two.
   */
  readonly thresholdPercent: number
}

/**
 * The forecast for one set of figures, or null when there is nothing to forecast.
 *
 * Three of the four cases are silence, and each is the honest answer. No rate yet:
 * the tile already says it is still measuring, and a second line would say it twice.
 * A rate of exactly zero: there is nothing to divide by, and "never" is not news.
 * No room figures: a guess costs more than a blank.
 *
 * @param nodeId The node these figures belong to, null when the installation names none.
 * @param freeBytes Free bytes on that filesystem, null when it is not known.
 * @param totalBytes Whole size of that filesystem, null when it is not known.
 * @param thresholdPercent Share of the volume kept free, null when it is not known.
 * @param growthBytesPerDay What that node writes over a day, null while it is measured.
 */
function logsOverviewForecastOf(
  nodeId: string | null,
  freeBytes: number | null,
  totalBytes: number | null,
  thresholdPercent: number | null,
  growthBytesPerDay: number | null,
): LogsOverviewForecast | null {
  if (
    freeBytes === null ||
    totalBytes === null ||
    thresholdPercent === null ||
    growthBytesPerDay === null ||
    growthBytesPerDay <= 0
  ) {
    return null
  }

  const room = freeBytes - Math.floor((totalBytes * thresholdPercent) / 100)

  return {
    nodeId,
    daysAway: room > 0 ? Math.floor(room / growthBytesPerDay) : null,
    thresholdPercent,
  }
}

/**
 * The node the forecast speaks for: the one with the least time left.
 *
 * A node already past its threshold outranks every node that still has days, which
 * is what makes the line worth reading in a cluster at all — the screen names the
 * machine in trouble rather than the average of the fleet. An unreadable node has no
 * figures and no right to speak for the cluster, so it is not a candidate.
 *
 * The single-node installation has no rows and answers from the header, which is the
 * same shape the per-node table itself takes (HIL-869).
 *
 * @param overview The latest screen, or null before the first frame arrives.
 */
function logsOverviewWorstForecast(
  overview: HilosLogsOverview | null,
): LogsOverviewForecast | null {
  if (overview === null || logsOverviewState(overview) !== 'figures') {
    return null
  }

  if (!hasLogsOverviewNodes(overview)) {
    return logsOverviewForecastOf(
      null,
      overview[OVERVIEW_NODE_FREE_BYTES_FIELD],
      overview[OVERVIEW_NODE_TOTAL_BYTES_FIELD],
      overview[OVERVIEW_NODE_THRESHOLD_FIELD],
      overview.growthBytesPerDay,
    )
  }

  let worst: LogsOverviewForecast | null = null
  for (const node of overview.nodes) {
    if (!node[OVERVIEW_NODE_AVAILABLE_FIELD]) {
      continue
    }
    const forecast = logsOverviewForecastOf(
      node[OVERVIEW_NODE_ID_FIELD],
      node[OVERVIEW_NODE_FREE_BYTES_FIELD],
      node[OVERVIEW_NODE_TOTAL_BYTES_FIELD],
      node[OVERVIEW_NODE_THRESHOLD_FIELD],
      node[OVERVIEW_NODE_GROWTH_FIELD],
    )
    if (forecast !== null && logsOverviewForecastIsWorse(forecast, worst)) {
      worst = forecast
    }
  }

  return worst
}

/**
 * Whether one forecast leaves less time than the one held so far.
 *
 * A passed threshold is worse than any number of days, and the first of two equals
 * wins so the line does not swap nodes between two frames that say the same thing.
 *
 * @param forecast The forecast just worked out.
 * @param worst The worst one so far, null while there is none.
 */
function logsOverviewForecastIsWorse(
  forecast: LogsOverviewForecast,
  worst: LogsOverviewForecast | null,
): boolean {
  if (worst === null || forecast.daysAway === null) {
    return worst === null || worst.daysAway !== null
  }

  return worst.daysAway !== null && forecast.daysAway < worst.daysAway
}

/**
 * How long is left, said so that a day nobody has is never promised.
 *
 * Days are rounded down, so the count can legitimately be zero — and a zero is said
 * in words, because "in 0 days" reads as a figure rather than as "very soon".
 *
 * @param daysAway Whole days left, already rounded down.
 */
function logsOverviewForecastWhen(daysAway: number): string {
  if (daysAway === 0) {
    return 'less than a day'
  }

  return daysAway === 1 ? '1 day' : `${daysAway} days`
}

/**
 * The third line of the growth tile: what the rate means for the room that is left.
 *
 * The arithmetic is done here rather than on the wire because "12 days away on
 * node-2" is a phrase of this tile and the whole of its vocabulary already lives in
 * this module — the same reason the takeout banner derives its list of nodes here
 * ({@link logsOverviewNodesDue}) instead of being told it.
 *
 * Two families of wording, and the second is not an edge case: a threshold of zero
 * is the installation that keeps no reserve and wants the days counted to a full
 * disk. The node is named only where the installation names nodes at all.
 *
 * It is shown beside the qualifying note as well: the rate is there to divide by,
 * and how complete it is has already been said by the line above.
 *
 * @param overview The latest screen, or null before the first frame arrives.
 */
export function logsOverviewForecastNote(
  overview: HilosLogsOverview | null,
): string | null {
  const forecast = logsOverviewWorstForecast(overview)
  if (forecast === null) {
    return null
  }

  const on = forecast.nodeId === null ? '' : ` on ${forecast.nodeId}`
  if (forecast.daysAway === null) {
    return forecast.thresholdPercent === 0
      ? `The log disk is full${on}`
      : `Free space is already below the ${forecast.thresholdPercent}% threshold${on}`
  }

  const when = logsOverviewForecastWhen(forecast.daysAway)

  return forecast.thresholdPercent === 0
    ? `At this rate the disk is full in ${when}${on}`
    : `At this rate the ${forecast.thresholdPercent}% threshold is ${when} away${on}`
}

/**
 * The failures the panel draws, newest first.
 *
 * Empty before the first frame, which is not the same as "no failures": the panel is
 * not drawn at all until there is a picture, because saying "nothing has gone wrong"
 * about what we have not been told would be good news made up.
 *
 * @param overview The latest screen, or null before the first frame arrives.
 */
export function logsOverviewRecentErrors(
  overview: HilosLogsOverview | null,
): HilosLogsOverviewError[] {
  return overview === null ? [] : overview.recentErrors
}

/**
 * Whether the panel has a list to draw, as opposed to its good-news plaque.
 *
 * @param overview The latest screen, or null before the first frame arrives.
 */
export function hasLogsOverviewRecentErrors(
  overview: HilosLogsOverview | null,
): boolean {
  return logsOverviewRecentErrors(overview).length > 0
}

/**
 * The counter beside the heading, which says `10+` once the list was cut.
 *
 * The plus is not a hedge: the nodes send their newest failures and the page cuts
 * the window, so a list that reached the limit says there were at least that many.
 * A list that did not reach it names its length exactly.
 *
 * @param overview The latest screen, or null before the first frame arrives.
 */
export function logsOverviewRecentErrorsBadge(
  overview: HilosLogsOverview | null,
): string {
  const errors = logsOverviewRecentErrors(overview)

  return overview?.recentErrorsCapped === true
    ? `${errors.length}+`
    : String(errors.length)
}

/**
 * When a failure was written, to the second and in the reader's own locale.
 *
 * The date is left out where the rotation tile keeps it: everything in this panel
 * happened within the last hour, and a date on every row would be the same date
 * repeated. An instant that cannot be read is a dash rather than `Invalid Date`.
 *
 * @param at The instant as ISO 8601, as the node that wrote the line read it.
 */
export function formatLogsOverviewErrorAt(at: string): string {
  const parsed = new Date(at)

  return Number.isNaN(parsed.getTime())
    ? NOTHING_KNOWN
    : parsed.toLocaleTimeString()
}

/**
 * Where a failure was written: the stream, and the node too when there is one.
 *
 * The node is named only in an installation that has node names — the same rule the
 * rest of the screen keeps, and the reason it is asked of the picture rather than of
 * the row: a row from an unnamed node carries an empty id, which is a value and not
 * a signal that this installation is single-node.
 *
 * @param overview The latest screen, or null before the first frame arrives.
 * @param error The failure the row draws.
 */
export function logsOverviewErrorOrigin(
  overview: HilosLogsOverview | null,
  error: HilosLogsOverviewError,
): string {
  return hasLogsOverviewNodes(overview)
    ? `${error.nodeId} · ${error.stream}`
    : error.stream
}

/**
 * The address a row leads to: the viewer, on the live file this line is in.
 *
 * On the FILE and not on the line — the viewer address has no anchor for a line yet.
 * The row still answers "where do I go from here" rather than dropping the reader at
 * the top of a journal to search it themselves.
 *
 * @param error The failure the row draws.
 */
export function logsOverviewErrorPath(error: HilosLogsOverviewError): string {
  return logViewerPath({
    nodeId: error.nodeId,
    source: LOG_SOURCE_LIVE,
    stream: error.stream,
  })
}
