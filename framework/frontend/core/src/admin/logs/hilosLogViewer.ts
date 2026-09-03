// The framework Hilos log-viewer admin headless: the catalog of readable sources,
// the address that names one file, the reading of its lines, and the entry
// view-model the per-framework HilosLogsViewPage view renders from. It is
// framework-agnostic (imports no UI framework) and reads only @hilos/core
// primitives, so the Vue/React/Angular viewer views stay thin
// (multiframework-core.md).
//
// Two things travel to this screen and they travel apart. The catalog — which
// nodes, batches and streams exist — is the page's own signal, sent on subscribe
// and again whenever it changes. The lines are the `logs_read_lines` action,
// asked for only once a file is fully named. One frame carrying both would make
// every page of lines re-deliver the catalog.
//
// Nothing is filtered here: the level and the substring are fields of the read
// request, and the server answers with the lines that matched. What IS done here
// is presentation-only cutting — the `[timestamp] ` and `LEVEL: ` prefixes come
// off so the pane can lay a line out in columns — and the grouping of an entry
// with its continuation lines, so a twenty-frame stack does not push its
// neighbours out of sight.
//
// Following the live tail is here too, and there is no Refresh button on
// purpose: freshness arrives as a push, not as a re-request. Scrolling up
// releases the STICKINESS and not the follow — the tail keeps running, what
// arrives while the reader is above piles up in a buffer beside the feed, and
// the return control says how much of it is waiting. The rejected alternative
// (the follow switching itself off, which is what the mockup drew) loses
// whatever grew while the reader was away, silently and without a size, and
// this subsystem loses nothing silently anywhere else.
//
// So the feed is a list of ITEMS and not of lines: a rotation, a jump, a
// server-side stop and a buffer overflow are notes standing at their own place
// in the sequence, and the grouping into entries runs over that list and breaks
// on a note. A line arriving after a rotation does not belong to the error
// above it.

import { z } from 'zod'
import {
  type ActionLifecycle,
  ActionError,
} from '../../connection/actionLifecycle.js'
import { type HilosConnection } from '../../connection/HilosConnection.js'
import { resolveHilosPath } from '../../routing/hilosAdmin.js'
import { HilosPages } from '../../routing/hilosPages.js'
import { type PageRouteMatch } from '../../routing/PageRouter.js'
import {
  computedSignal,
  createSignal,
  subscribeSignal,
  type ReadonlySignal,
  type Unsubscribe,
} from '../../state/signal.js'
import { hilosToasts } from '../../state/toasts.js'

/** Server→client signal `type` carrying the catalog (PHP `SUBSCRIPTION_PAGE_HILOS_LOGS_VIEW`). */
export const LOG_VIEWER_CATALOG_SIGNAL = 'subscription_page_hilos_logs_view'

/** The action one page of lines is asked for by (PHP `LOGS_READ_LINES`). */
export const LOGS_READ_LINES_ACTION = 'logs_read_lines'

/**
 * The action the live tail is begun by (PHP `LOGS_FOLLOW_START`).
 *
 * Its reply is the first page, which is why no read is sent before it: a read
 * followed by a start would cost a round trip and still leave a gap between the
 * page it answered and the moment the owner began watching.
 */
export const LOGS_FOLLOW_START_ACTION = 'logs_follow_start'

/** The action the live tail is ended by (PHP `LOGS_FOLLOW_STOP`). */
export const LOGS_FOLLOW_STOP_ACTION = 'logs_follow_stop'

/** Server→client signal `type` carrying what happened to the followed file (PHP `LOGS_LINES_APPENDED`). */
export const LOG_LINES_APPENDED_SIGNAL = 'logs_lines_appended'

/** Source value: the live journal, the file still being written to. */
export const LOG_SOURCE_LIVE = 'live'

/** Source value: a file inside one archived batch (PHP `LogsReadLinesActionDTO::SOURCE_BATCH`). */
const LOG_SOURCE_BATCH = 'batch'

/**
 * Address segment standing for the node of an installation that has no cluster.
 *
 * Such a node is the empty string on the wire, and an empty path segment is not a
 * segment at all: the three slots are positional, so a skipped node would slide
 * the source into its place and open a file named `live`.
 */
export const LOG_NODE_SELF_SEGMENT = '-'

/** Route param naming the node, matching the `{nodeId?}` slot of the viewer route. */
const LOG_VIEWER_NODE_PARAM = 'nodeId'

/** Route param naming the source, matching the `{source?}` slot of the viewer route. */
const LOG_VIEWER_SOURCE_PARAM = 'source'

/** Route param naming the stream, matching the `{stream?}` slot of the viewer route. */
const LOG_VIEWER_STREAM_PARAM = 'stream'

/**
 * How near the bottom the reader counts as being AT it, in pixels.
 *
 * Not zero: a pane rounded to fractional pixels never reports an exact bottom,
 * and a tail that unsticks itself on a rounding error would be unusable.
 */
const LOG_VIEWER_TAIL_THRESHOLD_PX = 24

/**
 * The most lines the feed holds; the oldest are cut off the top past it.
 *
 * Ten pages of reading (PHP `LogStoreAgent::READ_PAGE_LINES` = 200) and ten
 * rounds of the tail (PHP `LogStoreAgent::FOLLOW_PUSH_MAX_LINES` = 200, once a
 * second), which is far more than a screen and is what stands in for the
 * virtualized list this pane deliberately does not have.
 */
const LOG_VIEWER_FEED_MAX_LINES = 2000

/** The most lines the buffer beside the feed holds while the reader is above it. */
const LOG_VIEWER_PENDING_MAX_LINES = 2000

/** One stream of one node: the file, what writes it, and where it can be found. */
const logViewerStreamSchema = z.looseObject({
  key: z.string(),
  class: z.string(),
  live: z.boolean(),
  batchTimestamps: z.array(z.number()),
})

/** One node of the catalog: its name, whether it can be read, and what it holds. */
const logViewerNodeSchema = z.looseObject({
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
const logViewerCatalogSchema = z.looseObject({
  available: z.boolean().nullable(),
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
const logViewerLineSchema = z.looseObject({
  text: z.string(),
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
const logsLinesAppendedSchema = z.looseObject({
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

/** One page of lines as the owner of the file answers a read — and a start — with. */
const logsReadLinesReplySchema = z.looseObject({
  readable: z.boolean(),
  lines: z.array(logViewerLineSchema),
  nextCursor: z.number().nullable(),
  hasMore: z.boolean(),
})

/** One line as it comes off the wire, before it is keyed and laid out. */
type LogViewerWireLine = z.infer<typeof logViewerLineSchema>

/** One follow frame, as it comes off the wire. */
type LogsLinesAppended = z.infer<typeof logsLinesAppendedSchema>

/** A log level the level picker offers, and the value it sends. */
export interface HilosLogLevelOption {
  /** The wire value sent as the level field (empty asks for every level). */
  readonly value: string
  /** The label shown in the level picker. */
  readonly label: string
}

/**
 * The choices the level picker offers, matching the backend `Logger::LEVEL_*`
 * values. The empty value is "any level" and clears the field.
 */
export const HILOS_LOG_LEVEL_OPTIONS: readonly HilosLogLevelOption[] = [
  { value: '', label: 'Any level' },
  { value: 'ERROR', label: 'ERROR' },
  { value: 'WARNING', label: 'WARNING' },
  { value: 'INFO', label: 'INFO' },
  { value: 'DEBUG', label: 'DEBUG' },
]

/**
 * The file being read: a node, a source, and a stream. Each is null until it is
 * chosen — entering from the menu chooses none of them, and a stream is never
 * guessed, because opening the wrong file looks exactly like an answer.
 *
 * The node is the empty string in an installation with no cluster: that is the
 * name it reports under, and the one the read request is addressed by.
 */
export interface HilosLogViewerSelection {
  /** The node holding the file, empty in a single-node installation, null when unchosen. */
  readonly nodeId: string | null
  /** {@link LOG_SOURCE_LIVE}, an archived batch timestamp, or null when unchosen. */
  readonly source: typeof LOG_SOURCE_LIVE | number | null
  /** The file name inside that source, or null when unchosen. */
  readonly stream: string | null
}

/** One line as the pane lays it out: the cut-off time, the level, and what is left. */
export interface HilosLogViewerLine {
  /** The `HH:MM:SS.mmm` the line began with, or empty when it began with no timestamp. */
  readonly time: string
  /** The level the reader recognized; a continuation inherits its entry's. */
  readonly level: string
  /** The line without the prefixes the two columns beside it now carry. */
  readonly text: string
}

/** One entry of the pane: a line that started it, and the frames that continue it. */
export interface HilosLogViewerEntry extends HilosLogViewerLine {
  /** Stable across a page of older lines arriving above, so an opened stack stays open. */
  readonly key: string
  /** The continuation lines, hidden behind the stack marker until it is opened. */
  readonly frames: readonly HilosLogViewerLine[]
  /**
   * Whether this is a continuation whose own start line was never read.
   *
   * Such a line is drawn on its own and dimmed rather than folded into the entry
   * above it: folding it would claim it belongs to an error it may have nothing
   * to do with.
   */
  readonly orphan: boolean
}

/** One line as it was read, with the identity its entry is keyed by. */
export interface HilosLogViewerReadLine {
  /** Stable for the life of the pane, so an opened stack survives a page above it. */
  readonly id: string
  /** The line as the file holds it, prefixes included. */
  readonly text: string
  /** The level the reader recognized for it. */
  readonly level: string
  /** Whether the line continues the entry above it instead of starting one. */
  readonly isContinuation: boolean
}

/** What a note in the feed has to say, each of them a break in the reading. */
export type HilosLogViewerNotice =
  /** The file was carried off and reading restarted at the start of the new one. */
  | 'rotated'
  /** The owner jumped ahead over bytes it was too far behind to read. */
  | 'skipped'
  /** The buffer overflowed while the reader was above it. */
  | 'dropped'
  /** The follow ended on the server's side. */
  | 'stopped'

/**
 * One item of the feed: a line that was read, or a note about what happened
 * between the lines around it.
 *
 * The notes travel IN the sequence rather than beside it, because a rotation
 * that arrived while the reader was above must land back where it happened once
 * the buffer is poured in — not at the end of everything.
 */
export type HilosLogViewerFeedItem =
  | { readonly kind: 'line'; readonly line: HilosLogViewerReadLine }
  | {
      readonly kind: 'notice'
      readonly key: string
      readonly notice: HilosLogViewerNotice
      readonly text: string
    }

/** One row of the pane: an entry with its continuation frames, or a note. */
export type HilosLogViewerRow =
  | ({ readonly kind: 'entry' } & HilosLogViewerEntry)
  | {
      readonly kind: 'notice'
      readonly key: string
      readonly notice: HilosLogViewerNotice
      readonly text: string
    }

/**
 * The slice of the navigator the viewer drives: it reads the address it was
 * entered by, and rewrites it whenever another file is chosen.
 *
 * {@link HilosRouter} is a structural fit; a test passes a fake. Rewriting rather
 * than navigating is the whole point — another file is the same page, and a
 * navigation would re-subscribe it and drop the catalog it just delivered.
 */
export interface HilosLogViewerAddress {
  /** The matched route, whose params name the file when the address carries one. */
  readonly currentRoute: ReadonlySignal<PageRouteMatch>
  /**
   * Rewrite the current address without re-subscribing the page.
   *
   * @param pathname The address the current selection resolves to.
   */
  replacePath(pathname: string): void
}

/**
 * The project-supplied context the log viewer reads from: the connection the
 * catalog arrives on, and the action lifecycle the reads dispatch over.
 * Everything else — the view-model, the address format, the wording — is the
 * framework's.
 *
 * The navigator is not here on purpose: it belongs to the view layer, which
 * injects it, and passes it as the second argument of
 * {@link createHilosLogViewer}. A project supplies where its data lives, not how
 * its pages are routed.
 */
export interface HilosLogViewerContext {
  /** The connection the catalog arrives on. */
  readonly connection: HilosConnection
  /** The action lifecycle one page of lines is read over. */
  readonly actions: ActionLifecycle
}

/**
 * Reads the file an address names.
 *
 * A slot the address stopped short of stays null rather than becoming an empty
 * choice; a source segment that is all digits is an archived batch, and anything
 * else is the live journal, which is the only other thing that segment can be.
 *
 * @param params The route params of the viewer route.
 */
export function readLogViewerAddress(
  params: Record<string, string>,
): HilosLogViewerSelection {
  const node = params[LOG_VIEWER_NODE_PARAM]
  const source = params[LOG_VIEWER_SOURCE_PARAM]
  const stream = params[LOG_VIEWER_STREAM_PARAM]

  return {
    nodeId:
      node === undefined ? null : node === LOG_NODE_SELF_SEGMENT ? '' : node,
    source: source === undefined ? null : readSourceSegment(source),
    stream: stream === undefined ? null : stream,
  }
}

/**
 * The address a selection resolves to.
 *
 * All three slots or none: the slots are positional, so a half-filled address
 * would name a different file rather than a partly known one. The level, the
 * substring and the byte cursor are deliberately absent — the first two change
 * every half minute, and the third stops meaning anything at the next rotation.
 *
 * @param selection The file currently being read.
 */
export function logViewerPath(selection: HilosLogViewerSelection): string {
  if (
    selection.nodeId === null ||
    selection.source === null ||
    selection.stream === null
  ) {
    return resolveHilosPath(HilosPages.LOGS_VIEW)
  }

  return resolveHilosPath(HilosPages.LOGS_VIEW, {
    [LOG_VIEWER_NODE_PARAM]:
      selection.nodeId === '' ? LOG_NODE_SELF_SEGMENT : selection.nodeId,
    [LOG_VIEWER_SOURCE_PARAM]: String(selection.source),
    [LOG_VIEWER_STREAM_PARAM]: selection.stream,
  })
}

/**
 * Cuts the prefixes the pane's own columns now carry off one line.
 *
 * A line matching neither shape is drawn whole with an empty time column: the
 * text belongs to whoever wrote it, and a viewer that guessed at its shape would
 * hide the part it guessed wrong about.
 *
 * @param text The line as the file holds it.
 * @param level The level the reader recognized for it.
 */
export function splitLogLine(text: string, level: string): HilosLogViewerLine {
  const stamped = TIMESTAMP_PREFIX_PATTERN.exec(text)
  if (stamped === null) {
    return { time: '', level, text }
  }

  return {
    time: stamped[1],
    level,
    text: cutLevelPrefix(text.slice(stamped[0].length)),
  }
}

/** What the catalog itself has to say, before any file is named. */
export type HilosLogViewerCatalogState =
  /** No merged picture has arrived; unknown rather than empty. */
  | 'unknown'
  /** The picture arrived and no node could read its log store. */
  | 'unreadable'
  /** The picture arrived and holds no stream to open. */
  | 'empty'
  /** There is something to choose between. */
  | 'ready'

/**
 * The three answers to what the catalog has to say, or that it has something to
 * offer.
 *
 * Told apart because they are cured differently and only one of them is a fault:
 * waiting on the aggregator, a picture in which nothing can be read, and a
 * catalog holding nodes that have nothing to offer.
 *
 * @param catalog The latest catalog, or null before the first one arrives.
 */
export function logViewerCatalogState(
  catalog: HilosLogViewerCatalog | null,
): HilosLogViewerCatalogState {
  if (catalog === null || catalog.available === null) {
    return 'unknown'
  }
  if (!catalog.available) {
    return 'unreadable'
  }

  return catalog.nodes.some((node) => node.streams.length > 0)
    ? 'ready'
    : 'empty'
}

/**
 * Whether this installation names its nodes, which is what decides the node
 * select.
 *
 * A single node reports under the empty string, and a picker with one nameless
 * option is furniture for a choice that does not exist.
 *
 * @param catalog The latest catalog, or null before the first one arrives.
 */
export function hasLogViewerNodes(
  catalog: HilosLogViewerCatalog | null,
): boolean {
  return catalog !== null && catalog.nodes.some((node) => node.nodeId !== '')
}

/**
 * The streams a node offers under one source, in the order the catalog holds them.
 *
 * @param node The node whose streams are being offered, or null when none is chosen.
 * @param source The chosen source, or null when none is chosen.
 */
export function logViewerStreamsOf(
  node: HilosLogViewerNode | null,
  source: typeof LOG_SOURCE_LIVE | number | null,
): readonly HilosLogViewerStream[] {
  if (node === null || source === null) {
    return []
  }

  return node.streams.filter((stream) =>
    source === LOG_SOURCE_LIVE
      ? stream.live
      : stream.batchTimestamps.includes(source),
  )
}

/**
 * The node of the catalog a selection names, or null when the catalog has none.
 *
 * @param catalog The latest catalog, or null before the first one arrives.
 * @param nodeId The node the selection names, or null when none is chosen.
 */
export function logViewerNodeOf(
  catalog: HilosLogViewerCatalog | null,
  nodeId: string | null,
): HilosLogViewerNode | null {
  if (catalog === null || nodeId === null) {
    return null
  }

  return catalog.nodes.find((node) => node.nodeId === nodeId) ?? null
}

/** What the pane shows: lines, or the reason there are none. */
export type HilosLogViewerPaneState =
  /** No merged picture has arrived yet. */
  | 'unknown'
  /** The picture arrived and no node could read its log store. */
  | 'unreadable'
  /** The picture arrived and holds no stream to open. */
  | 'empty'
  /** Nothing has been chosen yet; the stream is never guessed. */
  | 'unchosen'
  /** The named file could not be read - rotation carried it off, or nothing wrote it yet. */
  | 'missing'
  /** The level or the substring matched nothing in a file that reads fine. */
  | 'nomatch'
  /** The file reads fine and holds nothing - a stream nobody has written to yet. */
  | 'silent'
  /** There are lines to draw. */
  | 'lines'

/**
 * What the pane has to say, in the order the answers outrank each other.
 *
 * CONTENT is asked first: lines in the pane of a chosen stream outrank every
 * other answer, and the empty states of the catalog above all — they cancel
 * "the cluster picture has not arrived yet". The tail is
 * followed by the address in the URL and needs no catalog at all, so a pane
 * holding lines while the picture is still on its way is a normal thing on a
 * slow cluster — and drawing an empty-catalog notice over lines the operator can
 * see says something plainly untrue. The stream has to be chosen for that to
 * apply: without a choice there can be no lines, and asking about the choice
 * first would make 'unchosen' unreachable behind a junk count.
 *
 * Otherwise the states of the CATALOG come first: a pane empty because no
 * picture arrived is empty for a reason that has nothing to do with the file or
 * the filter. Then the file - unchosen, or chosen and unreadable - and only then
 * the filter, which is the one state that means the read worked and found
 * nothing.
 *
 * @param catalog The latest catalog, or null before the first one arrives.
 * @param selection The file being read.
 * @param rowCount How many rows the pane holds — notes count, so a note in a file with no lines is not hidden under "this file is empty".
 * @param readable Whether the named file could be read at all.
 * @param filtered Whether a level or a substring is narrowing the read.
 */
export function logViewerPaneState(
  catalog: HilosLogViewerCatalog | null,
  selection: HilosLogViewerSelection,
  rowCount: number,
  readable: boolean,
  filtered: boolean,
): HilosLogViewerPaneState {
  if (rowCount > 0 && selection.stream !== null) {
    return 'lines'
  }
  const state = logViewerCatalogState(catalog)
  if (state !== 'ready') {
    return state
  }
  if (selection.stream === null) {
    return 'unchosen'
  }
  if (!readable) {
    return 'missing'
  }
  if (rowCount > 0) {
    return 'lines'
  }

  return filtered ? 'nomatch' : 'silent'
}

/**
 * Whether the reader is at the tail, which is what decides between a line
 * sticking to the bottom and a line waiting in the buffer.
 *
 * It lives here rather than in a view so the React and Angular viewers measure
 * the same edge as the Vue one: a threshold restated per framework is a
 * threshold that drifts.
 *
 * @param scrollTop How far the pane is scrolled from its top.
 * @param scrollHeight The full height of the pane's content.
 * @param clientHeight The visible height of the pane.
 */
export function isLogViewerPinned(
  scrollTop: number,
  scrollHeight: number,
  clientHeight: number,
): boolean {
  return scrollHeight - scrollTop - clientHeight <= LOG_VIEWER_TAIL_THRESHOLD_PX
}

/**
 * The Bootstrap theme color one level is drawn in — the tag beside a line and
 * the bar down its left edge.
 *
 * A level this build does not know is drawn in the neutral color rather than as
 * an error: the writer may be newer than the viewer, and shouting about a line
 * because its level is unfamiliar is a false alarm the operator cannot silence.
 *
 * @param level The level the reader recognized.
 */
export function logLevelVariant(level: string): string {
  switch (level) {
    case 'ERROR':
      return 'danger'
    case 'WARNING':
      return 'warning'
    case 'INFO':
      return 'primary'
    default:
      return 'secondary'
  }
}

/** The live log viewer a view drives. */
export interface HilosLogViewer {
  /** The latest catalog this connection was sent, or null until the first one arrives. */
  readonly catalog: ReadonlySignal<HilosLogViewerCatalog | null>
  /** The file being read, each slot null until it is chosen. */
  readonly selection: ReadonlySignal<HilosLogViewerSelection>
  /** The rows the pane draws — entries and notes — oldest first. */
  readonly rows: ReadonlySignal<readonly HilosLogViewerRow[]>
  /** Where the Follow switch stands: whether the reader WANTS the tail. */
  readonly followRequested: ReadonlySignal<boolean>
  /** Whether the tail is actually running on the server; this is what the badge burns by. */
  readonly following: ReadonlySignal<boolean>
  /** Whether the chosen source can be followed at all; an archived batch cannot. */
  readonly canFollow: ReadonlySignal<boolean>
  /** Whether the reader is at the tail, so new lines stick instead of waiting. */
  readonly pinned: ReadonlySignal<boolean>
  /** How many LINES wait in the buffer; an entry whose continuations have not arrived is not assembled yet. */
  readonly pendingLines: ReadonlySignal<number>
  /** Level asked of the server, empty for any level. */
  readonly level: ReadonlySignal<string>
  /** Substring asked of the server, empty for no substring. */
  readonly substring: ReadonlySignal<string>
  /** True while a page of lines is being waited for. */
  readonly busy: ReadonlySignal<boolean>
  /** Whether the named file could be read at all; false is a state, not a fault. */
  readonly readable: ReadonlySignal<boolean>
  /** Whether older matching lines remain beyond what has been read. */
  readonly hasMore: ReadonlySignal<boolean>
  /** The refusal of the last read (an unknown or offline node), or null. */
  readonly refusal: ReadonlySignal<string | null>
  /**
   * Choose another file, keeping the stream when the new place also has it.
   *
   * @param change The slots that change; the others are kept.
   */
  select(change: Partial<HilosLogViewerSelection>): void
  /**
   * Ask the server for one level only.
   *
   * @param level A `Logger::LEVEL_*` value, or empty for any level.
   */
  setLevel(level: string): void
  /**
   * Ask the server for lines carrying a substring.
   *
   * @param substring The text to look for, or empty for no substring.
   */
  setSubstring(substring: string): void
  /**
   * Raise or lower the Follow switch.
   *
   * Lowering it pours the buffer into the feed rather than throwing it away: the
   * lines already arrived and are real.
   *
   * @param next Whether the reader wants the tail.
   */
  setFollow(next: boolean): void
  /**
   * Say where the reader is, as {@link isLogViewerPinned} measured it.
   *
   * @param next Whether the reader is at the tail.
   */
  setPinned(next: boolean): void
  /** Pour the buffer into the feed and stick to the tail again. */
  returnToTail(): void
  /** Read the page before the oldest line held, keeping what is already shown. */
  readOlder(): void
  /** Start listening for the catalog and read what the address names — call on mount. */
  start(): void
  /** Stop listening — call on unmount. */
  dispose(): void
}

/**
 * The log viewer: the catalog it offers, the address it keeps, and the lines it
 * reads.
 *
 * Every change of the file is a read from the tail with the cursor thrown away,
 * because a byte offset means nothing in another file. A reconnect is the same
 * thing for the same reason: a rotation may have happened while the socket was
 * down, and the old offset would then point inside a file that has moved.
 *
 * @param context The project context (connection and action lifecycle).
 * @param address The navigator whose address names the file being read.
 */
export function createHilosLogViewer(
  context: HilosLogViewerContext,
  address: HilosLogViewerAddress,
): HilosLogViewer {
  const catalog = createSignal<HilosLogViewerCatalog | null>(null)
  const selection = createSignal<HilosLogViewerSelection>(
    readLogViewerAddress(address.currentRoute.get().params),
  )
  const feed = createSignal<readonly HilosLogViewerFeedItem[]>([])
  const rows = createSignal<readonly HilosLogViewerRow[]>([])
  const level = createSignal('')
  const substring = createSignal('')
  const busy = createSignal(false)
  const readable = createSignal(true)
  const hasMore = createSignal(false)
  const refusal = createSignal<string | null>(null)
  // The switch stands up by default: an operator opening a live file is looking
  // at what is happening now, and asking them to turn that on is asking twice.
  const followRequested = createSignal(true)
  const following = createSignal(false)
  const canFollow = computedSignal(
    () => selection.get().source === LOG_SOURCE_LIVE,
  )
  const pinned = createSignal(true)
  const pendingLines = createSignal(0)
  const teardown: Unsubscribe[] = []
  let cursor: number | null = null
  let page = 0
  // What arrived while the reader was above the tail, and how many lines fell out
  // of the front of it. The dropped count is held apart from the buffer so that
  // the note it becomes is not itself buffered and counted.
  let pending: readonly HilosLogViewerFeedItem[] = []
  let pendingDropped = 0
  let notices = 0
  // The requestId of the start in progress: every frame of that follow is stamped
  // with it, and frames of a follow already left behind may still be in flight.
  let followId: string | null = null
  // The node the follow was begun on, which is the node the removal is addressed
  // to — a switch that has moved on since would name a different one.
  let followNode: string | null = null
  // Only the newest read may write the pane: a reply to a file the operator has
  // already moved off would otherwise land under the selects of another one.
  let generation = 0
  // The address this viewer itself last wrote, so its own echo is not read back
  // as a navigation. A half-made choice resolves to the bare address, which
  // carries LESS than the selection does - read back, it would throw away the
  // node and the source the operator had just chosen.
  let ownPath: string | null = null

  const publish = (items: readonly HilosLogViewerFeedItem[]): void => {
    const kept = trimFeedItems(items, LOG_VIEWER_FEED_MAX_LINES)
    feed.set(kept)
    rows.set(toLogViewerRows(kept))
  }

  const store = (
    stored: readonly LogViewerWireLine[],
  ): readonly HilosLogViewerFeedItem[] => {
    page += 1

    return stored.map((line, index) => ({
      kind: 'line',
      line: {
        id: `${page}:${index}`,
        text: line.text,
        level: line.level,
        isContinuation: line.isContinuation,
      },
    }))
  }

  const note = (
    notice: HilosLogViewerNotice,
    text: string,
  ): HilosLogViewerFeedItem => {
    notices += 1

    return { kind: 'notice', key: `notice:${notices}`, notice, text }
  }

  // Pouring the buffer in rebuilds the entries over the WHOLE feed rather than
  // appending assembled ones: a stack whose first frames arrived before the
  // reader scrolled up and whose last ones waited in the buffer has to end up
  // under its own error line, not as an orphan under the seam.
  const drain = (): void => {
    if (pending.length === 0 && pendingDropped === 0) {
      return
    }

    const poured =
      pendingDropped === 0
        ? pending
        : [
            note(
              'dropped',
              `${pendingDropped} lines were dropped while you were away.`,
            ),
            ...pending,
          ]
    pending = []
    pendingDropped = 0
    pendingLines.set(0)
    publish([...feed.get(), ...poured])
  }

  const buffer = (items: readonly HilosLogViewerFeedItem[]): void => {
    const all = [...pending, ...items]
    const kept = trimFeedItems(all, LOG_VIEWER_PENDING_MAX_LINES)
    pendingDropped += countFeedLines(all) - countFeedLines(kept)
    pending = kept
    pendingLines.set(countFeedLines(kept))
  }

  /**
   * Forget the follow in progress and empty what it had buffered.
   *
   * The owner is told only when nothing is starting in its place: a start on the
   * same connection replaces the previous follow by itself, and a removal sent
   * alongside it would race the replacement it is not needed for.
   */
  const unfollow = (tellOwner: boolean): void => {
    const node = followNode
    followId = null
    followNode = null
    following.set(false)
    pending = []
    pendingDropped = 0
    pendingLines.set(0)
    if (tellOwner && node !== null) {
      // Untracked on purpose: the removal is answered with nothing, and a handle
      // waiting for a reply that is not coming would time out for show.
      context.connection.sendAction(LOGS_FOLLOW_STOP_ACTION, { nodeId: node })
    }
  }

  const read = (older: boolean): void => {
    const current = selection.get()
    if (
      current.nodeId === null ||
      current.source === null ||
      current.stream === null
    ) {
      unfollow(true)
      // The generation moves although nothing is asked for: a reply still on the
      // wire belongs to the file just left, and without this it would pass the
      // guard below and republish its lines under a selection that names no file.
      generation += 1
      busy.set(false)
      publish([])
      hasMore.set(false)
      readable.set(true)
      refusal.set(null)

      return
    }

    // Reading older lines leaves the tail alone: the two travel in opposite
    // directions and the operator asked for both.
    const starts =
      !older && current.source === LOG_SOURCE_LIVE && followRequested.get()
    if (!older) {
      unfollow(!starts)
    }

    generation += 1
    const mine = generation
    busy.set(true)
    refusal.set(null)
    const handle = starts
      ? context.actions.dispatch(
          LOGS_FOLLOW_START_ACTION,
          {
            nodeId: current.nodeId,
            stream: current.stream,
            level: level.get() === '' ? null : level.get(),
            substring: substring.get() === '' ? null : substring.get(),
          },
          { replySchema: logsReadLinesReplySchema },
        )
      : context.actions.dispatch(
          LOGS_READ_LINES_ACTION,
          {
            nodeId: current.nodeId,
            source:
              current.source === LOG_SOURCE_LIVE
                ? LOG_SOURCE_LIVE
                : LOG_SOURCE_BATCH,
            batchTimestamp:
              current.source === LOG_SOURCE_LIVE ? null : current.source,
            stream: current.stream,
            level: level.get() === '' ? null : level.get(),
            substring: substring.get() === '' ? null : substring.get(),
            cursor: older ? cursor : null,
          },
          { replySchema: logsReadLinesReplySchema },
        )
    if (starts) {
      // Taken synchronously, not off the reply: the owner begins pushing the
      // moment it takes the file, and the first frames can outrun the first page.
      followId = handle.requestId
      followNode = current.nodeId
    }
    handle.done.then(
      (result) => {
        if (mine !== generation || result.reply === undefined) {
          return
        }

        const reply = result.reply
        busy.set(false)
        readable.set(reply.readable)
        hasMore.set(reply.hasMore)
        cursor = reply.nextCursor
        if (starts && followId === handle.requestId) {
          // The id and not the generation: lowering the switch stops the follow
          // without asking for anything, so it leaves the generation where it is
          // and a reply still on the wire would otherwise light the badge for a
          // tail that has just been called off.
          following.set(true)
        }
        // Older lines go ABOVE what is shown, and the view holds its scroll
        // position, so the page the operator was reading does not move.
        publish(
          older ? [...store(reply.lines), ...feed.get()] : store(reply.lines),
        )
      },
      (error: unknown) => {
        if (mine !== generation) {
          return
        }

        busy.set(false)
        if (starts && followId === handle.requestId) {
          // The start was refused, so no frame will ever carry this id: keeping
          // it would leave the badge lit for a tail that never began.
          unfollow(false)
        }
        if (error instanceof ActionError && error.outcome === 'disconnected') {
          // Not a refusal of anything: the socket was not open when the mount
          // asked. The reconnect below reads again, and saying so in the pane
          // would put a fault where the shell already shows the transport.
          return
        }

        // A node this cluster does not have, or one the master last saw offline,
        // is shown in the pane and not as a toast: the operator is looking
        // straight at it, and a toast is for somebody looking elsewhere.
        refusal.set(
          error instanceof ActionError ? error.message : 'The read failed.',
        )
      },
    )
  }

  /**
   * Takes one frame of the follow it belongs to.
   *
   * Four shapes and never two at once, so exactly one of the branches speaks.
   * Where the result goes is the other question, and the only one the reading
   * position decides: stuck to the tail it goes into the feed, above it into the
   * buffer, and the pane under the reader's eyes does not move either way.
   */
  const appended = (frame: LogsLinesAppended): void => {
    if (frame.followId !== followId) {
      return
    }

    let items: readonly HilosLogViewerFeedItem[]
    if (frame.lines.length > 0) {
      items = store(frame.lines)
    } else if (frame.rotated) {
      items = [
        note(
          'rotated',
          'The file was rotated. Reading continues from the start of the new one.',
        ),
      ]
    } else if (frame.skippedBytes !== null) {
      items = [
        note(
          'skipped',
          `Jumped over ${formatLogViewerBytes(frame.skippedBytes)} to catch up.`,
        ),
      ]
    } else if (frame.stopped) {
      items = [note('stopped', 'Following stopped on the server.')]
      followId = null
      followNode = null
      following.set(false)
      // The switch goes down by itself here, and only here: the fact changed
      // against the reader's wish, and a raised switch over a dead tail lies.
      followRequested.set(false)
    } else {
      return
    }

    if (pinned.get()) {
      publish([...feed.get(), ...items])

      return
    }

    buffer(items)
  }

  // What can be chosen without guessing, once there is a catalog to choose from.
  // The node only when the installation has exactly one and it has no name of its
  // own - in a cluster, picking one for the operator would open some machine's
  // log because it happened to report first. The stream is never preselected at
  // all: the wrong file open looks exactly like an answer.
  const preselect = (next: HilosLogViewerCatalog): void => {
    const current = selection.get()
    if (current.nodeId !== null && current.source !== null) {
      return
    }

    const single = next.nodes.length === 1 && next.nodes[0].nodeId === ''
    selection.set({
      nodeId: current.nodeId ?? (single ? '' : null),
      source: current.source ?? LOG_SOURCE_LIVE,
      stream: current.stream,
    })
  }

  const choose = (change: Partial<HilosLogViewerSelection>): void => {
    const current = selection.get()
    const next: HilosLogViewerSelection = {
      nodeId: change.nodeId === undefined ? current.nodeId : change.nodeId,
      source: change.source === undefined ? current.source : change.source,
      stream: change.stream === undefined ? current.stream : change.stream,
    }
    selection.set(
      change.stream === undefined ? withKeptStream(catalog.get(), next) : next,
    )
    ownPath = logViewerPath(selection.get())
    address.replacePath(ownPath)
    read(false)
  }

  return {
    catalog,
    selection,
    rows,
    followRequested,
    following,
    canFollow,
    pinned,
    pendingLines,
    level,
    substring,
    busy,
    readable,
    hasMore,
    refusal,
    select: choose,
    setLevel(next) {
      level.set(next)
      read(false)
    },
    setSubstring(next) {
      substring.set(next)
      read(false)
    },
    setFollow(next) {
      followRequested.set(next)
      if (next) {
        if (canFollow.get()) {
          read(false)
        }

        return
      }

      // What waited in the buffer is shown rather than dropped: the lines
      // arrived and are real, and losing them is the very thing the rejected
      // design was rejected for. Nothing is re-read — the switch answers for the
      // tail, not for what has already been shown.
      drain()
      unfollow(true)
    },
    setPinned(next) {
      if (next) {
        drain()
      }
      pinned.set(next)
    },
    returnToTail() {
      drain()
      pinned.set(true)
    },
    readOlder() {
      read(true)
    },
    start() {
      teardown.push(
        context.connection.on('projectSignal', (signal) => {
          if (signal.type === LOG_VIEWER_CATALOG_SIGNAL) {
            // Validated against logViewerCatalogSchema at the parse boundary; this
            // cast is the declared typed selector for that schema's output.
            const next = signal.data as HilosLogViewerCatalog
            catalog.set(next)
            preselect(next)

            return
          }
          if (signal.type === LOG_LINES_APPENDED_SIGNAL) {
            // Validated against logsLinesAppendedSchema at the same boundary.
            appended(signal.data as LogsLinesAppended)
          }
        }),
        // A read refused with no requestId answers no pending request: nothing here
        // is waiting for it, so it surfaces as a toast rather than in the pane.
        context.connection.on('actionError', (signal) => {
          if (
            signal.requestId === undefined &&
            signal.action === LOGS_READ_LINES_ACTION
          ) {
            hilosToasts.push(signal.reason, { severity: 'error' })
          }
        }),
        // A reconnect reads from the tail again with the cursor thrown away: a
        // rotation may have happened while the socket was down, and the offset
        // would then point into a file that is no longer this one. The follow is
        // gone with the old connection — the owner keys its viewers by accept
        // key, and the reconnected browser carries a different one — so it is
        // begun anew and not removed: there is nothing left to remove.
        context.connection.on('state', (state) => {
          if (state === 'connected') {
            unfollow(false)
            read(false)
          }
        }),
        // Back and forward move through addresses of this same page, so the
        // selection follows them. Rewriting our own address lands here too, and
        // is skipped by the path rather than by comparing the selections: the
        // address of a half-made choice is the bare one, which read back would
        // undo the choice that produced it.
        subscribeSignal(address.currentRoute, (route) => {
          const next = readLogViewerAddress(route.params)
          if (logViewerPath(next) === ownPath) {
            return
          }

          ownPath = null
          if (!sameSelection(next, selection.get())) {
            selection.set(next)
            read(false)
          }
        }),
      )
      read(false)
    },
    dispose() {
      for (const off of teardown.splice(0)) {
        off()
      }
    },
  }
}

/**
 * Groups the feed into rows: a line that starts an entry, the lines continuing
 * it, and the notes standing between them.
 *
 * A continuation whose start line was never read stays a row of its own, marked
 * an orphan. Folding it into whatever happens to be above it would claim a
 * relationship the read cannot know about — and a note closes whatever entry was
 * open for the same reason: the first line after a rotation belongs to the new
 * file, not to the error that was being written when the old one was carried off.
 *
 * @param items The accumulated feed, oldest first.
 */
export function toLogViewerRows(
  items: readonly HilosLogViewerFeedItem[],
): readonly HilosLogViewerRow[] {
  const rows: (EntryDraft | HilosLogViewerRow)[] = []
  let open: EntryDraft | null = null
  for (const item of items) {
    if (item.kind === 'notice') {
      rows.push(item)
      open = null
      continue
    }

    const line = item.line
    const split = splitLogLine(line.text, line.level)
    if (line.isContinuation && open !== null && !open.orphan) {
      open.frames.push(split)
      continue
    }

    open = {
      kind: 'entry',
      key: line.id,
      ...split,
      frames: [],
      orphan: line.isContinuation,
    }
    rows.push(open)
  }

  return rows
}

/** One entry while it is still being filled with the frames that continue it. */
interface EntryDraft {
  kind: 'entry'
  key: string
  time: string
  level: string
  text: string
  frames: HilosLogViewerLine[]
  orphan: boolean
}

/** How many of the feed's items are lines; the notes are not lines. */
function countFeedLines(items: readonly HilosLogViewerFeedItem[]): number {
  return items.reduce(
    (count, item) => (item.kind === 'line' ? count + 1 : count),
    0,
  )
}

/**
 * Cuts the oldest items off the front until no more than `maxLines` lines are
 * left, notes and all.
 *
 * The cap is counted in LINES because that is what the memory is spent on; a
 * note riding along at the front costs nothing and would only be cut with the
 * line it stands before.
 *
 * @param items The feed or the buffer, oldest first.
 * @param maxLines The most lines to keep.
 */
function trimFeedItems(
  items: readonly HilosLogViewerFeedItem[],
  maxLines: number,
): readonly HilosLogViewerFeedItem[] {
  let lines = countFeedLines(items)
  if (lines <= maxLines) {
    return items
  }

  let cut = 0
  while (lines > maxLines) {
    if (items[cut].kind === 'line') {
      lines -= 1
    }
    cut += 1
  }

  return items.slice(cut)
}

/**
 * A byte figure in the largest unit that leaves a readable number, for the note
 * saying how much the owner jumped over.
 *
 * A fourth copy of one body, and deliberately so: hoisting the shared helper
 * would edit three modules this leaf has no business in (P-231).
 *
 * @param bytes The figure, in bytes.
 */
function formatLogViewerBytes(bytes: number): string {
  const units = ['B', 'KB', 'MB', 'GB', 'TB']
  let size = bytes
  let unit = 0
  while (size >= 1024 && unit < units.length - 1) {
    size /= 1024
    unit += 1
  }

  return `${unit === 0 ? size : size.toFixed(1)} ${units[unit]}`
}

/** Matches the `[YYYY-MM-DD HH:MM:SS.mmm] ` prefix, capturing the clock time. */
const TIMESTAMP_PREFIX_PATTERN =
  /^\[\d{4}-\d{2}-\d{2} (\d{2}:\d{2}:\d{2}\.\d{3})\] /

/** The level prefixes the writer emits, in both its modes (PHP `LogLineReader`). */
const LEVEL_PREFIX_PATTERN =
  /^(?:\[(?:ERROR|WARNING|INFO|DEBUG)\] |(?:ERROR|WARNING|DEBUG): )/

/**
 * Cuts the level prefix off a timestamped line, when it carries one.
 *
 * @param text The line with its timestamp already cut.
 */
function cutLevelPrefix(text: string): string {
  const level = LEVEL_PREFIX_PATTERN.exec(text)

  return level === null ? text : text.slice(level[0].length)
}

/**
 * Reads a source address segment: all digits is an archived batch, anything else
 * is the live journal.
 *
 * @param segment The source segment of the address.
 */
function readSourceSegment(segment: string): typeof LOG_SOURCE_LIVE | number {
  return /^\d+$/.test(segment) ? Number(segment) : LOG_SOURCE_LIVE
}

/**
 * Keeps the chosen stream across a change of node or source when the new place
 * also holds it, and drops it when it does not.
 *
 * Dropping it is the honest answer: the same file name on another node is another
 * file, and one that is not in the new batch at all cannot be opened there.
 *
 * @param catalog The latest catalog, or null before the first one arrives.
 * @param selection The selection with its new node or source already in it.
 */
function withKeptStream(
  catalog: HilosLogViewerCatalog | null,
  selection: HilosLogViewerSelection,
): HilosLogViewerSelection {
  const streams = logViewerStreamsOf(
    logViewerNodeOf(catalog, selection.nodeId),
    selection.source,
  )
  if (streams.some((stream) => stream.key === selection.stream)) {
    return selection
  }

  return { ...selection, stream: null }
}

/**
 * Whether two selections name the same file.
 *
 * @param one The first selection.
 * @param other The second selection.
 */
function sameSelection(
  one: HilosLogViewerSelection,
  other: HilosLogViewerSelection,
): boolean {
  return (
    one.nodeId === other.nodeId &&
    one.source === other.source &&
    one.stream === other.stream
  )
}
