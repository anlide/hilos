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
// Following the live tail (the Follow switch, the sticky bottom, the rotation
// divider) is HIL-758, and there is no Refresh button on purpose: freshness
// arrives as a push, not as a re-request.

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
 * The log-viewer signal schemas keyed for a connection's `projectSchemas`, so the
 * parse boundary validates the catalog frames {@link createHilosLogViewer}
 * ingests. {@link createHilosConnection} merges them in, so a project never
 * restates them.
 *
 * Named apart from the rotation screen's `LOGS_SIGNAL_SCHEMAS` deliberately: two
 * independent names cost one line in the merge and do not depend on which of the
 * two screens landed first.
 */
export const LOGS_VIEWER_SIGNAL_SCHEMAS = {
  [LOG_VIEWER_CATALOG_SIGNAL]: logViewerCatalogSchema,
}

/** One page of lines as the owner of the file answers a read with. */
const logsReadLinesReplySchema = z.looseObject({
  readable: z.boolean(),
  lines: z.array(
    z.looseObject({
      text: z.string(),
      level: z.string(),
      isContinuation: z.boolean(),
    }),
  ),
  nextCursor: z.number().nullable(),
  hasMore: z.boolean(),
})

/** One page of lines, as it comes off the wire. */
type LogsReadLinesReply = z.infer<typeof logsReadLinesReplySchema>

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
 * The states of the CATALOG come first: a pane empty because no picture arrived
 * is empty for a reason that has nothing to do with the file or the filter. Then
 * the file - unchosen, or chosen and unreadable - and only then the filter, which
 * is the one state that means the read worked and found nothing.
 *
 * @param catalog The latest catalog, or null before the first one arrives.
 * @param selection The file being read.
 * @param entryCount How many entries the pane holds.
 * @param readable Whether the named file could be read at all.
 * @param filtered Whether a level or a substring is narrowing the read.
 */
export function logViewerPaneState(
  catalog: HilosLogViewerCatalog | null,
  selection: HilosLogViewerSelection,
  entryCount: number,
  readable: boolean,
  filtered: boolean,
): HilosLogViewerPaneState {
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
  if (entryCount > 0) {
    return 'lines'
  }

  return filtered ? 'nomatch' : 'silent'
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
  /** The entries read so far, oldest first. */
  readonly entries: ReadonlySignal<readonly HilosLogViewerEntry[]>
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
  const lines = createSignal<readonly HilosLogViewerReadLine[]>([])
  const entries = createSignal<readonly HilosLogViewerEntry[]>([])
  const level = createSignal('')
  const substring = createSignal('')
  const busy = createSignal(false)
  const readable = createSignal(true)
  const hasMore = createSignal(false)
  const refusal = createSignal<string | null>(null)
  const teardown: Unsubscribe[] = []
  let cursor: number | null = null
  let page = 0
  // Only the newest read may write the pane: a reply to a file the operator has
  // already moved off would otherwise land under the selects of another one.
  let generation = 0
  // The address this viewer itself last wrote, so its own echo is not read back
  // as a navigation. A half-made choice resolves to the bare address, which
  // carries LESS than the selection does - read back, it would throw away the
  // node and the source the operator had just chosen.
  let ownPath: string | null = null

  const publish = (stored: readonly HilosLogViewerReadLine[]): void => {
    lines.set(stored)
    entries.set(toLogViewerEntries(stored))
  }

  const store = (
    reply: LogsReadLinesReply,
  ): readonly HilosLogViewerReadLine[] => {
    page += 1

    return reply.lines.map((line, index) => ({
      id: `${page}:${index}`,
      text: line.text,
      level: line.level,
      isContinuation: line.isContinuation,
    }))
  }

  const read = (older: boolean): void => {
    const current = selection.get()
    if (
      current.nodeId === null ||
      current.source === null ||
      current.stream === null
    ) {
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

    generation += 1
    const mine = generation
    busy.set(true)
    refusal.set(null)
    context.actions
      .dispatch(
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
      .done.then(
        (result) => {
          if (mine !== generation || result.reply === undefined) {
            return
          }

          const reply = result.reply
          busy.set(false)
          readable.set(reply.readable)
          hasMore.set(reply.hasMore)
          cursor = reply.nextCursor
          // Older lines go ABOVE what is shown, and the view holds its scroll
          // position, so the page the operator was reading does not move.
          publish(older ? [...store(reply), ...lines.get()] : store(reply))
        },
        (error: unknown) => {
          if (mine !== generation) {
            return
          }

          busy.set(false)
          if (
            error instanceof ActionError &&
            error.outcome === 'disconnected'
          ) {
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
    entries,
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
        // would then point into a file that is no longer this one.
        context.connection.on('state', (state) => {
          if (state === 'connected') {
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
 * Groups lines into entries: a line that starts one, and the lines continuing it.
 *
 * A continuation whose start line was never read stays an entry of its own,
 * marked an orphan. Folding it into whatever happens to be above it would claim a
 * relationship the read cannot know about.
 *
 * @param lines The accumulated lines, oldest first.
 */
export function toLogViewerEntries(
  lines: readonly HilosLogViewerReadLine[],
): readonly HilosLogViewerEntry[] {
  const entries: EntryDraft[] = []
  for (const line of lines) {
    const split = splitLogLine(line.text, line.level)
    const open = entries.at(-1)
    if (line.isContinuation && open !== undefined && !open.orphan) {
      open.frames.push(split)
      continue
    }

    entries.push({
      key: line.id,
      ...split,
      frames: [],
      orphan: line.isContinuation,
    })
  }

  return entries
}

/** One entry while it is still being filled with the frames that continue it. */
interface EntryDraft {
  key: string
  time: string
  level: string
  text: string
  frames: HilosLogViewerLine[]
  orphan: boolean
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
