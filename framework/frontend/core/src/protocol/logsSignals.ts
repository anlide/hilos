// Framework wire definitions shared by eager connections and lazy admin models.
import { z } from 'zod'
import { hideable } from '../state/hideableSchema.js'
import { settingPresetsSignalSchemas } from './settingPresetsSignals.js'

/** Server→client signal `type` carrying the screen header (PHP `SUBSCRIPTION_PAGE_HILOS_LOGS_ROTATIONS`). */
export const ROTATIONS_HEADER_SIGNAL = 'subscription_page_hilos_logs_rotations'

/**
 * Payload of the screen header: whether there is a picture, which nodes it holds,
 * and the rotation and retention rules actually in force (PHP
 * `HilosLogsRotationsSignalData`).
 */
export const rotationsHeaderSchema = z.looseObject({
  available: z.boolean().nullable(),
  nodes: z.array(z.string()),
  rotationCron: hideable(z.string().nullable()),
  rotationMaxAgeSeconds: hideable(z.number()),
  rotationMaxLiveSizeBytes: hideable(z.number()),
  retentionKeepBatches: hideable(z.number()),
  retentionMaxAgeSeconds: hideable(z.number()),
})

/** The screen header as the page answers a subscription with it. */
export type HilosLogRotationsHeader = z.infer<typeof rotationsHeaderSchema>

/**
 * The logs signal schemas keyed for a connection's `projectSchemas`, so the parse
 * boundary validates the header frames {@link createHilosLogRotationsHeader}
 * ingests. {@link createHilosConnection} merges them in, so a project never
 * restates them.
 */
export const LOGS_SIGNAL_SCHEMAS = {
  [ROTATIONS_HEADER_SIGNAL]: rotationsHeaderSchema,
}

/** Server→client signal `type` carrying the screen header (PHP `SUBSCRIPTION_PAGE_HILOS_LOGS_KEYS`). */
export const KEYS_HEADER_SIGNAL = 'subscription_page_hilos_logs_keys'

/**
 * Payload of the screen header: whether there is a picture and which nodes it holds
 * (PHP `HilosLogsKeysSignalData`).
 */
export const keysHeaderSchema = z.looseObject({
  available: z.boolean().nullable(),
  nodes: z.array(z.string()),
})

/** The screen header as the page answers a subscription with it. */
export type HilosLogKeysHeader = z.infer<typeof keysHeaderSchema>

/**
 * The by-key signal schemas keyed for a connection's `projectSchemas`, so the parse
 * boundary validates the header frames {@link createHilosLogKeysHeader} ingests.
 * {@link createHilosConnection} merges them in, so a project never restates them.
 *
 * A set of its own rather than an entry in the rotations one: two independent screens
 * must not depend on which of them landed first.
 */
export const LOGS_KEYS_SIGNAL_SCHEMAS = {
  [KEYS_HEADER_SIGNAL]: keysHeaderSchema,
}

/** Server→client signal `type` carrying the whole screen (PHP `SUBSCRIPTION_PAGE_HILOS_LOGS`). */
export const OVERVIEW_SIGNAL = 'subscription_page_hilos_logs'

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
 * Row payload key of the node an entry was written on.
 *
 * Carries the effective node ID even on standalone, so a recent entry links to
 * the exact node and file.
 */
export const OVERVIEW_RECENT_NODE_ID_FIELD = 'nodeId'

/** Row payload key of the live stream an entry was written to. */
export const OVERVIEW_RECENT_STREAM_FIELD = 'stream'

/** Row payload key of when an entry was written, ISO 8601 with milliseconds. */
export const OVERVIEW_RECENT_AT_FIELD = 'at'

/** Row payload key of the entry's text, already cut by the node that read it. */
export const OVERVIEW_RECENT_MESSAGE_FIELD = 'message'

/** Row payload key of the frames in an entry's stack trace, null when it has none. */
export const OVERVIEW_RECENT_TRACE_FRAMES_FIELD = 'traceFrames'

/**
 * One row of the per-node table.
 *
 * A node that could not be read keeps its row with null in every figure: a zero
 * there would be a measurement nobody took, and dropping the row would read as a
 * node that never reported at all.
 */
export const overviewNodeSchema = z.looseObject({
  [OVERVIEW_NODE_ID_FIELD]: z.string(),
  [OVERVIEW_NODE_AVAILABLE_FIELD]: z.boolean(),
  [OVERVIEW_NODE_LAST_ROTATION_FIELD]: z.string().nullable(),
  [OVERVIEW_NODE_LIVE_BYTES_FIELD]: z.number().nullable(),
  [OVERVIEW_NODE_ARCHIVE_BYTES_FIELD]: z.number().nullable(),
  [OVERVIEW_NODE_GROWTH_FIELD]: z.number().nullable(),
  [OVERVIEW_NODE_DUE_FIELD]: z.number().nullable(),
  [OVERVIEW_NODE_FREE_BYTES_FIELD]: z.number().nullable(),
  [OVERVIEW_NODE_TOTAL_BYTES_FIELD]: z.number().nullable(),
  [OVERVIEW_NODE_THRESHOLD_FIELD]: hideable(z.number().nullable()),
})

/**
 * One row of the recent-failures panel, in either of its tabs.
 *
 * Every field but the frame count is a string the node measured, so none of them is
 * nullable: a row exists because a line was really written. The frame count keeps its
 * null, which is the difference between "there is a stack to look at" and "there is
 * not" — read as a zero it would put a badge on every row.
 */
export const recentEntrySchema = z.looseObject({
  [OVERVIEW_RECENT_NODE_ID_FIELD]: z.string(),
  [OVERVIEW_RECENT_STREAM_FIELD]: z.string(),
  [OVERVIEW_RECENT_AT_FIELD]: z.string(),
  [OVERVIEW_RECENT_MESSAGE_FIELD]: hideable(z.string()),
  [OVERVIEW_RECENT_TRACE_FRAMES_FIELD]: z.number().nullable(),
})

/**
 * Payload of the whole screen: the tiles, the takeout verdict and the per-node table
 * in one frame (PHP `HilosLogsOverviewSignalData`).
 */
export const overviewSchema = z.looseObject({
  available: z.boolean().nullable(),
  totalRotationsAllTime: z.number().nullable(),
  lastRotationAt: z.string().nullable(),
  logKeysPerDaemon: z.number().nullable(),
  totalWeightDaemonKeysBytes: z.number().nullable(),
  logKeysPerAgent: z.number().nullable(),
  totalWeightAgentKeysBytes: z.number().nullable(),
  logKeysPerWorker: z.number().nullable(),
  totalWeightWorkerKeysBytes: z.number().nullable(),
  growthBytesPerDay: z.number().nullable(),
  keysWithoutGrowthWindow: z.number().nullable(),
  batchesDueForTakeout: z.number().nullable(),
  nodes: z.array(overviewNodeSchema),
  recentErrors: z.array(recentEntrySchema),
  recentErrorsCapped: z.boolean(),
  recentWarnings: z.array(recentEntrySchema),
  recentWarningsCapped: z.boolean(),
  // The header half of the same three fields the rows carry: a single-node
  // installation has no row to put its disk in, a cluster leaves these null and
  // answers per node. Exactly one half is ever filled.
  [OVERVIEW_NODE_FREE_BYTES_FIELD]: z.number().nullable(),
  [OVERVIEW_NODE_TOTAL_BYTES_FIELD]: z.number().nullable(),
  [OVERVIEW_NODE_THRESHOLD_FIELD]: hideable(z.number().nullable()),
})

/** One node's row of the per-node table. */
export type HilosLogsOverviewNode = z.infer<typeof overviewNodeSchema>

/** One entry of the recent-failures panel, an error or a warning. */
export type HilosLogsOverviewRecentEntry = z.infer<typeof recentEntrySchema>

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

/** Server→client signal `type` carrying the group (PHP `SUBSCRIPTION_PAGE_HILOS_LOGS_SETTINGS`). */
export const LOG_SETTINGS_SIGNAL = 'subscription_page_hilos_logs_settings'

/**
 * The logging-mode signal schema keyed for a connection's `projectSchemas`, so the
 * parse boundary validates the frames the screen ingests. {@link createHilosConnection}
 * merges it in, so a project never restates it.
 *
 * A set of its own rather than an entry in a neighbouring log screen's: two
 * independent screens must not depend on which of them landed first.
 */
export const LOGS_SETTINGS_SIGNAL_SCHEMAS =
  settingPresetsSignalSchemas(LOG_SETTINGS_SIGNAL)

/** Server→client signal `type` carrying the catalog (PHP `SUBSCRIPTION_PAGE_HILOS_LOGS_VIEW`). */
export const LOG_VIEWER_CATALOG_SIGNAL = 'subscription_page_hilos_logs_view'

/** Server→client signal `type` carrying what happened to the followed file (PHP `LOGS_LINES_APPENDED`). */
export const LOG_LINES_APPENDED_SIGNAL = 'logs_lines_appended'

/** One stream of one node: the file, what writes it, and where it can be found. */
export const logViewerStreamSchema = z.looseObject({
  key: z.string(),
  class: z.string(),
  live: z.boolean(),
  batchTimestamps: z.array(z.number()),
})

/** One node of the catalog: its name, whether it can be read, and what it holds. */
export const logViewerNodeSchema = z.looseObject({
  nodeId: z.string(),
  available: z.boolean(),
  batches: z.array(z.number()),
  streams: z.array(logViewerStreamSchema),
})

/**
 * The catalog of readable sources (PHP `HilosLogsViewCatalogSignalData`).
 *
 * `available` has three answers and they are three different screens: null while
 * no merged picture has arrived, false when it arrived and no node could read its
 * store, true when there is something to open.
 */
export const logViewerCatalogSchema = z.looseObject({
  available: z.boolean().nullable(),
  clusterEnabled: z.boolean(),
  nodes: z.array(logViewerNodeSchema),
})

/** The catalog as the page answers a subscription with it. */
export type HilosLogViewerCatalog = z.infer<typeof logViewerCatalogSchema>

/** One node of the catalog. */
export type HilosLogViewerNode = z.infer<typeof logViewerNodeSchema>

/** One stream of one node. */
export type HilosLogViewerStream = z.infer<typeof logViewerStreamSchema>

/**
 * One line as the owner of the file sends it, in a read reply and in a follow
 * frame alike (PHP `LogsReadLinesReplyDTO::linesFromPage()`).
 *
 * One shape for both because the browser draws the first page and every frame
 * after it with one renderer; a second shape here would be a second renderer.
 */
export const logViewerLineSchema = z.looseObject({
  time: z.string().nullable(),
  text: hideable(z.string()),
  level: z.string(),
  isContinuation: z.boolean(),
})

/**
 * What happened to the followed file since the last frame (PHP
 * `LogsLinesAppendedSignalData`).
 *
 * Four mutually exclusive shapes and never two at once: lines were appended, the
 * file was carried off and reading restarted on the new one, the owner jumped
 * ahead to catch up, or the follow ended on its side. A tick with nothing to say
 * sends no frame at all.
 */
export const logsLinesAppendedSchema = z.looseObject({
  followId: z.string(),
  lines: z.array(logViewerLineSchema),
  rotated: z.boolean(),
  skippedBytes: z.number().nullable(),
  stopped: z.boolean(),
})

/**
 * The log-viewer signal schemas keyed for a connection's `projectSchemas`, so the
 * parse boundary validates the catalog and follow frames
 * {@link createHilosLogViewer} ingests. {@link createHilosConnection} merges them
 * in, so a project never restates them.
 *
 * Named apart from the rotation screen's `LOGS_SIGNAL_SCHEMAS` deliberately: two
 * independent names cost one line in the merge and do not depend on which of the
 * two screens landed first.
 */
export const LOGS_VIEWER_SIGNAL_SCHEMAS = {
  [LOG_VIEWER_CATALOG_SIGNAL]: logViewerCatalogSchema,
  [LOG_LINES_APPENDED_SIGNAL]: logsLinesAppendedSchema,
}

/** Server→client signal `type` carrying the screen header (PHP `SUBSCRIPTION_PAGE_HILOS_LOGS_WORKERS`). */
export const WORKERS_HEADER_SIGNAL = 'subscription_page_hilos_logs_workers'

/**
 * Payload of the screen header: whether there is a picture and which nodes it holds
 * (PHP `HilosLogsWorkersSignalData`).
 */
export const workersHeaderSchema = z.looseObject({
  available: z.boolean().nullable(),
  nodes: z.array(z.string()),
})

/** The screen header as the page answers a subscription with it. */
export type HilosLogWorkersHeader = z.infer<typeof workersHeaderSchema>

/**
 * The by-worker signal schemas keyed for a connection's `projectSchemas`, so the
 * parse boundary validates the header frames {@link createHilosLogWorkersHeader}
 * ingests. {@link createHilosConnection} merges them in, so a project never restates
 * them.
 *
 * A set of its own rather than an entry in the by-key one: two independent screens
 * must not depend on which of them landed first.
 */
export const LOGS_WORKERS_SIGNAL_SCHEMAS = {
  [WORKERS_HEADER_SIGNAL]: workersHeaderSchema,
}
