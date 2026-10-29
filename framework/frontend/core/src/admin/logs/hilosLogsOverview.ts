// The framework Hilos logs-overview admin headless: the header view-model, the two
// empty states, the takeout verdict, and the wording of every figure the four tiles
// draw. It is framework-agnostic (imports no UI framework)
// and reads only @hilos/core primitives, so the Vue/React/Angular overview views
// stay thin (multiframework-core.md).
//
// The page's header signal carries the full picture for tiles, banners and the
// forecast, including nodes outside the visible table window. The node table uses
// its own ordinary viewport binding (hilosLogNodes.ts).
//
// The screen answers "is anything wrong with the logs, and where do I go from here".
// It commands nothing: every way off it is ordinary navigation.

import {
  OVERVIEW_SIGNAL,
  OVERVIEW_NODE_ID_FIELD,
  OVERVIEW_NODE_AVAILABLE_FIELD,
  OVERVIEW_NODE_GROWTH_FIELD,
  OVERVIEW_NODE_FREE_BYTES_FIELD,
  OVERVIEW_NODE_TOTAL_BYTES_FIELD,
  OVERVIEW_NODE_THRESHOLD_FIELD,
  type HilosLogsOverviewRecentEntry,
  type HilosLogsOverview,
} from '../../protocol/logsSignals.js'
import { type HilosConnection } from '../../connection/HilosConnection.js'
import { formatBytes } from '../../format/bytes.js'
import { formatMoment } from '../../format/moment.js'
import {
  HIDDEN_VALUE,
  isHiddenValue,
  type Hideable,
} from '../../state/hiddenValue.js'
import { createSignal, type ReadonlySignal } from '../../state/signal.js'
import { LOG_SOURCE_LIVE, logViewerPath } from './hilosLogViewer.js'

export {
  OVERVIEW_SIGNAL,
  OVERVIEW_NODE_ID_FIELD,
  OVERVIEW_NODE_AVAILABLE_FIELD,
  OVERVIEW_NODE_LAST_ROTATION_FIELD,
  OVERVIEW_NODE_LIVE_BYTES_FIELD,
  OVERVIEW_NODE_ARCHIVE_BYTES_FIELD,
  OVERVIEW_NODE_GROWTH_FIELD,
  OVERVIEW_NODE_DUE_FIELD,
  OVERVIEW_NODE_FREE_BYTES_FIELD,
  OVERVIEW_NODE_TOTAL_BYTES_FIELD,
  OVERVIEW_NODE_THRESHOLD_FIELD,
  OVERVIEW_RECENT_NODE_ID_FIELD,
  OVERVIEW_RECENT_STREAM_FIELD,
  OVERVIEW_RECENT_AT_FIELD,
  OVERVIEW_RECENT_MESSAGE_FIELD,
  OVERVIEW_RECENT_TRACE_FRAMES_FIELD,
  type HilosLogsOverviewNode,
  type HilosLogsOverviewRecentEntry,
  type HilosLogsOverview,
  LOGS_OVERVIEW_SIGNAL_SCHEMAS,
} from '../../protocol/logsSignals.js'

/** The tab of the recent-failures panel that lists errors; the one a screen opens on. */
export const RECENT_TAB_ERRORS = 'errors'

/** The tab of the recent-failures panel that lists warnings. */
export const RECENT_TAB_WARNINGS = 'warnings'

/**
 * Which tab of the recent-failures panel is open.
 *
 * A state of the screen and not of the data: both lists arrive in every frame, so a
 * view keeps the open tab to itself and switching it asks the server for nothing.
 */
export type HilosLogsRecentTab =
  typeof RECENT_TAB_ERRORS | typeof RECENT_TAB_WARNINGS

/**
 * The project-supplied context the logs-overview admin reads from.
 *
 * The header only needs a connection. The node table's HilosLogNodesContext adds
 * the scope manager, leaving header-only consumers with this smaller contract.
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

  return formatBytes(bytes)
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

  return (
    formatMoment(at, {
      kind: 'dateTime',
      format: {
        year: 'numeric',
        month: 'numeric',
        day: 'numeric',
        hour: 'numeric',
        minute: 'numeric',
        second: 'numeric',
      },
    }) ?? NOTHING_KNOWN
  )
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
 * `daysAway` null means the threshold has already been reached, independently of
 * whether a growth rate is known. A future forecast needs a positive rate.
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
  /** Unrounded free share of this same filesystem, in percent. */
  readonly freePercent: number
  /** Whether the free bytes are exactly at the computed threshold boundary. */
  readonly atThreshold: boolean
}

/**
 * The forecast for one set of figures, or null when there is nothing to forecast.
 *
 * Known free/total/threshold figures can already establish a reached threshold.
 * Before it is reached, an unknown or nonpositive rate gives no future forecast.
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
    totalBytes <= 0
  ) {
    return null
  }

  const room = freeBytes - Math.floor((totalBytes * thresholdPercent) / 100)
  let daysAway: number | null = null
  if (room > 0) {
    if (growthBytesPerDay === null || growthBytesPerDay <= 0) {
      return null
    }
    daysAway = Math.floor(room / growthBytesPerDay)
  }

  return {
    nodeId,
    daysAway,
    thresholdPercent,
    freePercent: (freeBytes / totalBytes) * 100,
    atThreshold: room === 0,
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
 * The complete header supplies every candidate, regardless of the visible node
 * table window. A hidden threshold hides the result for the whole picture.
 *
 * @param overview The latest screen, or null before the first frame arrives.
 */
function logsOverviewWorstForecast(
  overview: HilosLogsOverview | null,
): Hideable<LogsOverviewForecast> | null {
  if (
    overview !== null &&
    (isHiddenValue(overview[OVERVIEW_NODE_THRESHOLD_FIELD]) ||
      overview.nodes.some((node) =>
        isHiddenValue(node[OVERVIEW_NODE_THRESHOLD_FIELD]),
      ))
  ) {
    return HIDDEN_VALUE
  }
  if (overview === null || logsOverviewState(overview) !== 'figures') {
    return null
  }

  if (!hasLogsOverviewNodes(overview)) {
    if (isHiddenValue(overview[OVERVIEW_NODE_THRESHOLD_FIELD])) {
      return null
    }
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
    if (isHiddenValue(node[OVERVIEW_NODE_THRESHOLD_FIELD])) {
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

/** Format a measured free share without rounding a positive remainder to zero. */
function logsOverviewFreePercent(percent: number): string {
  if (percent > 0 && percent < 0.1) {
    return '<0.1%'
  }

  return `${percent.toFixed(1).replace(/\.0$/, '')}%`
}

/**
 * Whether the complete overview has a reached threshold. Hidden or unknown
 * figures never expose a warning state to a viewer.
 *
 * @param overview The latest screen, or null before the first frame arrives.
 */
export function logsOverviewThresholdPassed(
  overview: HilosLogsOverview | null,
): boolean {
  const forecast = logsOverviewWorstForecast(overview)

  return (
    forecast !== null && !isHiddenValue(forecast) && forecast.daysAway === null
  )
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
): Hideable<string> | null {
  const forecast = logsOverviewWorstForecast(overview)
  if (isHiddenValue(forecast)) {
    return HIDDEN_VALUE
  }
  if (forecast === null) {
    return null
  }

  const on = forecast.nodeId === null ? '' : ` on ${forecast.nodeId}`
  if (forecast.daysAway === null) {
    const message =
      forecast.thresholdPercent === 0
        ? `The log disk is full${on}`
        : forecast.atThreshold
          ? `The ${forecast.thresholdPercent}% threshold has been reached${on}`
          : `Free space is already below the ${forecast.thresholdPercent}% threshold${on}`

    return `${message} — ${logsOverviewFreePercent(forecast.freePercent)} free`
  }

  const when = logsOverviewForecastWhen(forecast.daysAway)

  return forecast.thresholdPercent === 0
    ? `At this rate the disk is full in ${when}${on}`
    : `At this rate the ${forecast.thresholdPercent}% threshold is ${when} away${on}`
}

/**
 * The entries one tab of the panel draws, newest first.
 *
 * Empty before the first frame, which is not the same as "nothing went wrong": the
 * panel is not drawn at all until there is a picture, because saying so about what we
 * have not been told would be good news made up. Both lists arrive in every frame, so
 * switching the tab asks the server for nothing.
 *
 * @param overview The latest screen, or null before the first frame arrives.
 * @param tab The tab being drawn.
 */
export function logsOverviewRecent(
  overview: HilosLogsOverview | null,
  tab: HilosLogsRecentTab,
): HilosLogsOverviewRecentEntry[] {
  if (overview === null) {
    return []
  }

  return tab === RECENT_TAB_ERRORS
    ? overview.recentErrors
    : overview.recentWarnings
}

/**
 * Whether a tab has a list to draw, as opposed to its good-news plaque.
 *
 * @param overview The latest screen, or null before the first frame arrives.
 * @param tab The tab being drawn.
 */
export function hasLogsOverviewRecent(
  overview: HilosLogsOverview | null,
  tab: HilosLogsRecentTab,
): boolean {
  return logsOverviewRecent(overview, tab).length > 0
}

/**
 * The counter inside a tab, which says `10+` once that tab's list was cut.
 *
 * The plus is not a hedge: the nodes send their newest entries and the page cuts the
 * window, so a list that reached the limit says there were at least that many. Each tab
 * reads its own flag — warnings past the limit say nothing about how many errors there
 * were.
 *
 * @param overview The latest screen, or null before the first frame arrives.
 * @param tab The tab the counter sits in.
 */
export function logsOverviewRecentBadge(
  overview: HilosLogsOverview | null,
  tab: HilosLogsRecentTab,
): string {
  const entries = logsOverviewRecent(overview, tab)
  const capped =
    tab === RECENT_TAB_ERRORS
      ? overview?.recentErrorsCapped
      : overview?.recentWarningsCapped

  return capped === true ? `${entries.length}+` : String(entries.length)
}

/**
 * The level every row of a tab carries, which is also what colors its tag and its
 * counter through the viewer's `logLevelVariant()`.
 *
 * @param tab The tab being drawn.
 */
export function logsOverviewRecentLevel(
  tab: HilosLogsRecentTab,
): 'ERROR' | 'WARNING' {
  return tab === RECENT_TAB_ERRORS ? 'ERROR' : 'WARNING'
}

/**
 * The caption of a tab.
 *
 * @param tab The tab being captioned.
 */
export function logsOverviewRecentTabLabel(tab: HilosLogsRecentTab): string {
  return tab === RECENT_TAB_ERRORS ? 'Errors' : 'Warnings'
}

/**
 * The sentence under the heading, saying what the open tab is for.
 *
 * An error and a warning ask different things of the reader, and that difference is
 * why they are two tabs and never one feed: warnings always outnumber errors and would
 * bury them.
 *
 * @param tab The open tab.
 */
export function logsOverviewRecentLead(tab: HilosLogsRecentTab): string {
  return tab === RECENT_TAB_ERRORS
    ? 'An error asks somebody to go and look at it. Each line opens its journal on the entry itself — the same node, the same file, the same place.'
    : 'A warning asks nobody to go anywhere yet: it says things are holding up. Each line opens its journal on the entry itself — the same node, the same file, the same place.'
}

/**
 * The headline of a tab's good-news plaque.
 *
 * @param tab The tab with nothing to list.
 */
export function logsOverviewRecentEmptyTitle(tab: HilosLogsRecentTab): string {
  return tab === RECENT_TAB_ERRORS
    ? 'No errors in the last hour'
    : 'No warnings in the last hour'
}

/**
 * The line under that headline.
 *
 * Its own for each tab, because the good news is not the same: no error means nothing
 * needed attention, no warning means nothing was even straining.
 *
 * @param tab The tab with nothing to list.
 */
export function logsOverviewRecentEmptyLead(tab: HilosLogsRecentTab): string {
  return tab === RECENT_TAB_ERRORS
    ? 'Nothing has asked for attention in that time.'
    : 'Nothing has been straining in that time either.'
}

/**
 * When an entry was written, to the second and in the reader's own locale.
 *
 * The date is left out where the rotation tile keeps it: everything in this panel
 * happened within the last hour, and a date on every row would be the same date
 * repeated. An instant that cannot be read is a dash rather than `Invalid Date`.
 *
 * @param at The instant as ISO UTC with milliseconds and Z.
 */
export function formatLogsOverviewRecentAt(at: string): string {
  return (
    formatMoment(at, {
      kind: 'time',
      format: { hour: 'numeric', minute: 'numeric', second: 'numeric' },
    }) ?? NOTHING_KNOWN
  )
}

/**
 * Where an entry was written: the stream, and the node too when there is one.
 *
 * The node is named only in an installation that has node names — the same rule the
 * rest of the screen keeps, and the reason it is asked of the picture rather than of
 * the row: a standalone entry still carries a technical node ID, so its value
 * does not say whether a node column belongs on the screen.
 *
 * @param overview The latest screen, or null before the first frame arrives.
 * @param entry The entry the row draws.
 */
export function logsOverviewRecentOrigin(
  overview: HilosLogsOverview | null,
  entry: HilosLogsOverviewRecentEntry,
): string {
  return hasLogsOverviewNodes(overview)
    ? `${entry.nodeId} · ${entry.stream}`
    : entry.stream
}

/**
 * The address a row leads to: the viewer, on the live file this line is in, opened on
 * the line itself.
 *
 * The place is the moment the line was written, taken from the instant the row prints:
 * the viewer finds the line by time, because a byte offset would stop naming it at the
 * next rotation, and no second field travels for it. An instant that cannot be read
 * leads to the file without a place rather than to a made-up one.
 *
 * @param entry The entry the row draws.
 */
export function logsOverviewRecentPath(
  entry: HilosLogsOverviewRecentEntry,
): string {
  const atMs = Date.parse(entry.at)

  return logViewerPath({
    nodeId: entry.nodeId,
    source: LOG_SOURCE_LIVE,
    stream: entry.stream,
    anchorAtMs: Number.isNaN(atMs) ? null : atMs,
  })
}
