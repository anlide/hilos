import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import {
  createHilosLogViewer,
  hasLogViewerNodes,
  isLogViewerPinned,
  logViewerNodeOf,
  logViewerPaneState,
  logViewerPath,
  logViewerStreamsOf,
  readLogViewerAddress,
  splitLogLine,
  toLogViewerRows,
  LOGS_FOLLOW_START_ACTION,
  LOGS_FOLLOW_STOP_ACTION,
  LOGS_READ_LINES_ACTION,
  LOGS_VIEWER_SIGNAL_SCHEMAS,
  LOG_LINES_APPENDED_SIGNAL,
  LOG_VIEWER_CATALOG_SIGNAL,
  type HilosLogViewerAddress,
  type HilosLogViewerCatalog,
  type HilosLogViewerContext,
  type HilosLogViewerEntry,
  type HilosLogViewerFeedItem,
  type HilosLogViewerRow,
  type HilosLogViewerSelection,
} from '../../../src/admin/logs/hilosLogViewer.js'
import {
  ActionLifecycle,
  type ActionLifecycleSource,
} from '../../../src/connection/actionLifecycle.js'
import { type ActionSuccessSignal } from '../../../src/protocol/parseSignal.js'
import { type HilosConnection } from '../../../src/connection/HilosConnection.js'
import { type ConnectionState } from '../../../src/connection/HilosConnection.js'
import { type PageRouteMatch } from '../../../src/routing/PageRouter.js'
import { createSignal } from '../../../src/state/signal.js'

/** Any fixed batch, so a timestamp in a fixture means something to read. */
const BATCH = 1800000000

/** The feed cap, restated here because the module keeps it private. */
const FEED_MAX_LINES = 2000

/** One line of a read reply or a follow frame, as it comes off the wire. */
function wireLine(text: string, level = 'INFO', isContinuation = false) {
  return { text, level, isContinuation }
}

/** One item of an already-keyed feed, for the grouping tests. */
function feedLine(
  id: string,
  text: string,
  level = 'INFO',
  isContinuation = false,
): HilosLogViewerFeedItem {
  return { kind: 'line', line: { id, text, level, isContinuation } }
}

/** The row as an entry, so a test can read the frames folded under it. */
function asEntry(row: HilosLogViewerRow): HilosLogViewerEntry {
  if (row.kind !== 'entry') {
    throw new Error(`Expected an entry row, got the ${row.notice} note.`)
  }

  return row
}

function catalog(
  overrides: Partial<HilosLogViewerCatalog> = {},
): HilosLogViewerCatalog {
  return {
    available: true,
    nodes: [
      {
        nodeId: 'node-1',
        available: true,
        batches: [BATCH],
        streams: [
          {
            key: 'worker-0.log',
            class: 'worker',
            live: true,
            batchTimestamps: [BATCH],
          },
          {
            key: 'agent-chat.log',
            class: 'agent',
            live: false,
            batchTimestamps: [BATCH],
          },
        ],
      },
      {
        nodeId: 'node-2',
        available: true,
        batches: [],
        streams: [
          {
            key: 'daemon.log',
            class: 'daemon',
            live: true,
            batchTimestamps: [],
          },
        ],
      },
    ],
    ...overrides,
  }
}

function selection(
  overrides: Partial<HilosLogViewerSelection> = {},
): HilosLogViewerSelection {
  return {
    nodeId: 'node-1',
    source: 'live',
    stream: 'worker-0.log',
    ...overrides,
  }
}

/** A minimal action source: the sent frames are what a read is judged by. */
class FakeActions implements ActionLifecycleSource {
  readonly sent: { action: string; data: unknown; requestId?: string }[] = []
  private readonly listeners: ((signal: ActionSuccessSignal) => void)[] = []

  sendAction(action: string, data: unknown, requestId?: string): boolean {
    this.sent.push({ action, data, requestId })

    return true
  }

  on(event: string, listener: (payload: never) => void): () => void {
    if (event === 'actionSuccess') {
      this.listeners.push(
        listener as unknown as (signal: ActionSuccessSignal) => void,
      )
    }

    return () => {}
  }

  /** Answer one dispatched read the way the owner of the file does. */
  answer(requestId: string | undefined, reply: unknown): void {
    for (const listener of this.listeners) {
      listener({
        kind: 'actionSuccess',
        action: LOGS_READ_LINES_ACTION,
        message: undefined,
        reply,
        requestId,
        envelope: { type: 'action_success', data: {} },
      })
    }
  }

  /** The payload of the last read that went out. */
  lastRead(): Record<string, unknown> {
    return (this.sent.at(-1)?.data ?? {}) as Record<string, unknown>
  }
}

/** One follow frame as the owner of the file pushes it. */
interface AppendedFrame {
  followId: string
  lines: ReturnType<typeof wireLine>[]
  rotated: boolean
  skippedBytes: number | null
  stopped: boolean
}

/** A minimal connection: the three events the viewer listens on, emitted by hand. */
class FakeConnection {
  readonly listeners: {
    projectSignal: ((signal: unknown) => void)[]
    actionError: ((signal: unknown) => void)[]
    state: ((state: ConnectionState) => void)[]
  } = { projectSignal: [], actionError: [], state: [] }

  /** The untracked action frames sent straight down the connection. */
  readonly sent: { action: string; data: unknown }[] = []

  on(event: string, listener: (payload: never) => void): () => void {
    const bucket = this.listeners[event as keyof typeof this.listeners]
    bucket.push(listener as never)

    return () => {}
  }

  sendAction(action: string, data: unknown): boolean {
    this.sent.push({ action, data })

    return true
  }

  sendCatalog(data: HilosLogViewerCatalog): void {
    for (const listener of this.listeners.projectSignal) {
      listener({ type: LOG_VIEWER_CATALOG_SIGNAL, data })
    }
  }

  /** Push one frame of a follow, the way the owner of the file does. */
  sendAppended(frame: Partial<AppendedFrame> & { followId: string }): void {
    const data: AppendedFrame = {
      lines: [],
      rotated: false,
      skippedBytes: null,
      stopped: false,
      ...frame,
    }
    for (const listener of this.listeners.projectSignal) {
      listener({ type: LOG_LINES_APPENDED_SIGNAL, data })
    }
  }

  reconnect(): void {
    for (const listener of this.listeners.state) {
      listener('connected')
    }
  }
}

/** A navigator whose address the viewer reads and rewrites, plus a way to drive it. */
interface FakeAddress extends HilosLogViewerAddress {
  /** A navigation somebody else made — back, forward, or a link. */
  navigate(pathname: string): void
  /** The addresses the viewer itself wrote, oldest first. */
  readonly written: string[]
}

function fakeAddress(pathname = '/hilos/logs/view'): FakeAddress {
  const route = createSignal<PageRouteMatch>({
    page: 'hilos_logs_view',
    params: paramsOf(pathname),
    admin: true,
  })
  const written: string[] = []

  const publish = (next: string): void => {
    route.set({
      page: 'hilos_logs_view',
      params: paramsOf(next),
      admin: true,
    })
  }

  return {
    currentRoute: route,
    replacePath(next: string) {
      written.push(next)
      publish(next)
    },
    navigate: publish,
    written,
  }
}

/** The route params the viewer route captures out of one address. */
function paramsOf(pathname: string): Record<string, string> {
  const tail = pathname
    .replace('/hilos/logs/view', '')
    .split('/')
    .filter(Boolean)
  const names = ['nodeId', 'source', 'stream']

  return Object.fromEntries(tail.map((value, index) => [names[index], value]))
}

function viewer(pathname = '/hilos/logs/view'): {
  context: HilosLogViewerContext
  connection: FakeConnection
  actions: FakeActions
  address: ReturnType<typeof fakeAddress>
} {
  const connection = new FakeConnection()
  const actions = new FakeActions()
  const address = fakeAddress(pathname)

  return {
    connection,
    actions,
    address,
    context: {
      connection: connection as unknown as HilosConnection,
      actions: new ActionLifecycle(actions),
    },
  }
}

describe('readLogViewerAddress', () => {
  it('reads a full address into the file it names', () => {
    expect(
      readLogViewerAddress({
        nodeId: 'node-2',
        source: String(BATCH),
        stream: 'worker-0.log',
      }),
    ).toEqual({ nodeId: 'node-2', source: BATCH, stream: 'worker-0.log' })
  })

  it('reads the single-node segment as the name that node reports under', () => {
    // The empty string, not "no node": it is what the read request is addressed by.
    expect(
      readLogViewerAddress({
        nodeId: '-',
        source: 'live',
        stream: 'daemon.log',
      }),
    ).toEqual({ nodeId: '', source: 'live', stream: 'daemon.log' })
  })

  it('leaves every unfilled slot unchosen', () => {
    expect(readLogViewerAddress({})).toEqual({
      nodeId: null,
      source: null,
      stream: null,
    })
  })
})

describe('logViewerPath', () => {
  it('builds the address of the file being read', () => {
    expect(logViewerPath(selection({ nodeId: 'node-2', source: BATCH }))).toBe(
      `/hilos/logs/view/node-2/${BATCH}/worker-0.log`,
    )
  })

  it('writes the single-node segment rather than an empty one', () => {
    // An empty segment is not a segment: the slots are positional, and a skipped
    // node would slide the source into its place.
    expect(logViewerPath(selection({ nodeId: '' }))).toBe(
      '/hilos/logs/view/-/live/worker-0.log',
    )
  })

  it('stays bare while any slot is unchosen', () => {
    expect(logViewerPath(selection({ stream: null }))).toBe('/hilos/logs/view')
    expect(logViewerPath(readLogViewerAddress({}))).toBe('/hilos/logs/view')
  })
})

describe('splitLogLine', () => {
  it('cuts the timestamp and the level prefix off a line', () => {
    expect(
      splitLogLine(
        '[2027-01-15 03:12:19.907] ERROR: Deadlock detected',
        'ERROR',
      ),
    ).toEqual({
      time: '03:12:19.907',
      level: 'ERROR',
      text: 'Deadlock detected',
    })
  })

  it('cuts the bracketed level form too', () => {
    expect(
      splitLogLine('[2027-01-15 03:00:01.482] [INFO] Rotation done', 'INFO'),
    ).toEqual({ time: '03:00:01.482', level: 'INFO', text: 'Rotation done' })
  })

  it('draws a line it does not recognize whole', () => {
    // The text belongs to whoever wrote it; a viewer that guessed at its shape
    // would hide the part it guessed wrong about.
    expect(
      splitLogLine('#0 /app/framework/backend/Db.php(214)', 'ERROR'),
    ).toEqual({
      time: '',
      level: 'ERROR',
      text: '#0 /app/framework/backend/Db.php(214)',
    })
  })
})

describe('toLogViewerRows', () => {
  it('folds continuations under the line that started them', () => {
    const rows = toLogViewerRows([
      feedLine('1:0', '[2027-01-15 03:12:19.907] ERROR: Deadlock', 'ERROR'),
      feedLine('1:1', '#0 Db.php(214)', 'ERROR', true),
      feedLine('1:2', '#1 Users.php(88)', 'ERROR', true),
    ])

    expect(rows).toHaveLength(1)
    const entry = asEntry(rows[0])
    expect(entry.key).toBe('1:0')
    expect(entry.text).toBe('Deadlock')
    expect(entry.frames.map((frame) => frame.text)).toEqual([
      '#0 Db.php(214)',
      '#1 Users.php(88)',
    ])
    expect(entry.orphan).toBe(false)
  })

  it('leaves a continuation whose start was never read on its own', () => {
    // Folding it into whatever happens to be above would claim a relationship the
    // read cannot know about.
    const rows = toLogViewerRows([
      feedLine('1:0', '#7 Page.php(160)', 'ERROR', true),
      feedLine('1:1', '[2027-01-15 03:12:20.004] INFO: Retrying'),
    ])

    expect(rows).toHaveLength(2)
    expect(asEntry(rows[0]).orphan).toBe(true)
    expect(asEntry(rows[0]).frames).toEqual([])
    expect(asEntry(rows[1]).orphan).toBe(false)
  })

  it('closes the open entry on a note', () => {
    // The first line after a rotation belongs to the new file, not to the error
    // that was being written when the old one was carried off.
    const rows = toLogViewerRows([
      feedLine('1:0', '[2027-01-15 03:12:19.907] ERROR: Deadlock', 'ERROR'),
      {
        kind: 'notice',
        key: 'notice:1',
        notice: 'rotated',
        text: 'The file was rotated.',
      },
      feedLine('2:0', '#0 Db.php(214)', 'ERROR', true),
    ])

    expect(rows.map((row) => row.kind)).toEqual(['entry', 'notice', 'entry'])
    expect(asEntry(rows[0]).frames).toEqual([])
    expect(asEntry(rows[2]).orphan).toBe(true)
  })

  it('keeps the key of an entry when older lines arrive above it', () => {
    const newer = feedLine('1:0', '[2027-01-15 03:12:20.004] INFO: Retrying')
    const older = feedLine(
      '2:0',
      '[2027-01-15 03:00:01.482] INFO: Rotation done',
    )

    expect(asEntry(toLogViewerRows([older, newer])[1]).key).toBe(
      asEntry(toLogViewerRows([newer])[0]).key,
    )
  })
})

describe('isLogViewerPinned', () => {
  it('counts the reader as at the tail up to the threshold, and not past it', () => {
    // Not zero: a pane rounded to fractional pixels never reports an exact
    // bottom, and a tail unsticking itself on a rounding error is unusable.
    expect(isLogViewerPinned(0, 1000, 1000)).toBe(true)
    expect(isLogViewerPinned(0, 1024, 1000)).toBe(true)
    expect(isLogViewerPinned(0, 1025, 1000)).toBe(false)
  })
})

describe('the catalog', () => {
  it('rejects a frame that is not a catalog', () => {
    const schema = LOGS_VIEWER_SIGNAL_SCHEMAS[LOG_VIEWER_CATALOG_SIGNAL]

    expect(schema.safeParse(catalog()).success).toBe(true)
    expect(schema.safeParse({ available: true }).success).toBe(false)
    expect(
      schema.safeParse({ available: true, nodes: [{ nodeId: 'node-1' }] })
        .success,
    ).toBe(false)
  })

  it('validates the follow frame at the same boundary as the catalog', () => {
    // Both keys travel in one map, so a project that merges the viewer's schemas
    // in gets the follow frames parsed without restating anything.
    expect(Object.keys(LOGS_VIEWER_SIGNAL_SCHEMAS)).toEqual([
      LOG_VIEWER_CATALOG_SIGNAL,
      LOG_LINES_APPENDED_SIGNAL,
    ])

    const schema = LOGS_VIEWER_SIGNAL_SCHEMAS[LOG_LINES_APPENDED_SIGNAL]

    expect(
      schema.safeParse({
        followId: '7',
        lines: [wireLine('[2027-01-15 03:12:19.907] INFO: Ready')],
        rotated: false,
        skippedBytes: null,
        stopped: false,
      }).success,
    ).toBe(true)
    expect(schema.safeParse({ followId: '7', lines: [] }).success).toBe(false)
  })

  it('offers a node select only where the nodes have names', () => {
    expect(hasLogViewerNodes(catalog())).toBe(true)
    expect(
      hasLogViewerNodes(
        catalog({
          nodes: [{ nodeId: '', available: true, batches: [], streams: [] }],
        }),
      ),
    ).toBe(false)
    expect(hasLogViewerNodes(null)).toBe(false)
  })

  it('offers the streams that exist under the chosen source', () => {
    const node = logViewerNodeOf(catalog(), 'node-1')

    expect(logViewerStreamsOf(node, 'live').map((s) => s.key)).toEqual([
      'worker-0.log',
    ])
    expect(logViewerStreamsOf(node, BATCH).map((s) => s.key)).toEqual([
      'worker-0.log',
      'agent-chat.log',
    ])
    expect(logViewerStreamsOf(node, null)).toEqual([])
  })
})

describe('logViewerPaneState', () => {
  it('answers for the catalog before it answers for the file', () => {
    // A pane empty because no picture arrived is empty for a reason that has
    // nothing to do with the file or the filter.
    expect(logViewerPaneState(null, selection(), 0, true, false)).toBe(
      'unknown',
    )
    expect(
      logViewerPaneState(
        catalog({ available: false }),
        selection(),
        0,
        true,
        false,
      ),
    ).toBe('unreadable')
    expect(
      logViewerPaneState(catalog({ nodes: [] }), selection(), 0, true, false),
    ).toBe('empty')
  })

  it('answers for the file, and only then for the filter', () => {
    expect(
      logViewerPaneState(
        catalog(),
        selection({ stream: null }),
        0,
        true,
        false,
      ),
    ).toBe('unchosen')
    expect(logViewerPaneState(catalog(), selection(), 0, false, false)).toBe(
      'missing',
    )
    expect(logViewerPaneState(catalog(), selection(), 0, true, true)).toBe(
      'nomatch',
    )
    expect(logViewerPaneState(catalog(), selection(), 0, true, false)).toBe(
      'silent',
    )
    expect(logViewerPaneState(catalog(), selection(), 3, true, true)).toBe(
      'lines',
    )
  })

  it('lets the lines in the pane outrank an empty catalog', () => {
    // The tail follows the address in the URL and needs no catalog, so lines
    // can be on screen while the picture is still on its way; a notice saying
    // it has not arrived would be drawn over rows the operator can see.
    expect(logViewerPaneState(null, selection(), 86, true, false)).toBe('lines')
    expect(
      logViewerPaneState(catalog({ nodes: [] }), selection(), 86, true, false),
    ).toBe('lines')
  })

  it('keeps the catalog state when no stream is chosen', () => {
    // Without a choice there can be no lines, so a junk count must not reach
    // past the catalog — and must not make 'unchosen' unreachable either.
    expect(
      logViewerPaneState(null, selection({ stream: null }), 86, true, false),
    ).toBe('unknown')
    expect(
      logViewerPaneState(
        catalog(),
        selection({ stream: null }),
        86,
        true,
        false,
      ),
    ).toBe('unchosen')
  })
})

describe('createHilosLogViewer', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })
  afterEach(() => {
    vi.useRealTimers()
  })

  it('asks for nothing while the address names no file', () => {
    const { context, actions, address } = viewer()

    createHilosLogViewer(context, address).start()

    expect(actions.sent).toHaveLength(0)
  })

  it('reads the file the address it was entered by names', () => {
    const { context, actions, address } = viewer(
      `/hilos/logs/view/node-2/${BATCH}/worker-0.log`,
    )

    createHilosLogViewer(context, address).start()

    expect(actions.sent).toHaveLength(1)
    expect(actions.sent[0].action).toBe(LOGS_READ_LINES_ACTION)
    expect(actions.lastRead()).toEqual({
      nodeId: 'node-2',
      source: 'batch',
      batchTimestamp: BATCH,
      stream: 'worker-0.log',
      level: null,
      substring: null,
      cursor: null,
    })
  })

  it('sends the single-node name as the empty string it reports under', () => {
    const { context, actions, address } = viewer(
      '/hilos/logs/view/-/live/daemon.log',
    )

    const view = createHilosLogViewer(context, address)
    // With the tail down this is a plain read, which is the request whose source
    // and batch stamp are under test; the start carries neither.
    view.setFollow(false)
    view.start()

    expect(actions.lastRead().nodeId).toBe('')
    expect(actions.lastRead().source).toBe('live')
    expect(actions.lastRead().batchTimestamp).toBeNull()
  })

  it('rewrites the address and starts the tail when a live file is chosen', () => {
    const { context, actions, address } = viewer()
    const view = createHilosLogViewer(context, address)
    view.start()

    view.select({ nodeId: 'node-1', source: 'live', stream: 'worker-0.log' })

    expect(address.written).toEqual([
      '/hilos/logs/view/node-1/live/worker-0.log',
    ])
    expect(actions.sent.at(-1)?.action).toBe(LOGS_FOLLOW_START_ACTION)
  })

  it('reads a chosen batch instead of following it', () => {
    const { context, actions, address } = viewer()
    const view = createHilosLogViewer(context, address)
    view.start()

    view.select({ nodeId: 'node-1', source: BATCH, stream: 'worker-0.log' })

    expect(actions.sent.at(-1)?.action).toBe(LOGS_READ_LINES_ACTION)
    expect(actions.lastRead().cursor).toBeNull()
    expect(view.canFollow.get()).toBe(false)
  })

  it('keeps the stream across a source change when the new source has it', () => {
    const { context, connection, address } = viewer(
      '/hilos/logs/view/node-1/live/worker-0.log',
    )
    const view = createHilosLogViewer(context, address)
    view.start()
    connection.sendCatalog(catalog())

    view.select({ source: BATCH })

    expect(view.selection.get().stream).toBe('worker-0.log')
  })

  it('drops the stream when the new source does not have it, and keeps the rest', () => {
    // The same name in another place is another file, and one that is not there
    // at all cannot be opened: leaving it chosen would name nothing. What must
    // NOT go with it is the choice that caused the drop — a half-made choice
    // writes the bare address, and reading that echo back would undo the node
    // the operator had just picked.
    const { context, connection, address } = viewer(
      '/hilos/logs/view/node-1/live/worker-0.log',
    )
    const view = createHilosLogViewer(context, address)
    view.start()
    connection.sendCatalog(catalog())

    view.select({ nodeId: 'node-2' })

    expect(view.selection.get()).toEqual({
      nodeId: 'node-2',
      source: 'live',
      stream: null,
    })
    expect(address.written).toEqual(['/hilos/logs/view'])
  })

  it('drops a reply for the file it has moved off', async () => {
    // The read of the previous file is still on the wire when the choice that
    // left it lands; its lines belong to a pane that no longer exists.
    const { context, connection, actions, address } = viewer(
      '/hilos/logs/view/node-1/live/worker-0.log',
    )
    const view = createHilosLogViewer(context, address)
    view.start()
    connection.sendCatalog(catalog())
    const inFlight = actions.sent.at(-1)?.requestId

    view.select({ nodeId: 'node-2' })
    actions.answer(inFlight, {
      readable: true,
      lines: [wireLine('[2027-01-15 03:00:01.482] INFO: Rotation done')],
      nextCursor: 512,
      hasMore: true,
    })
    await Promise.resolve()

    expect(view.rows.get()).toEqual([])
    expect(view.hasMore.get()).toBe(false)
    expect(view.busy.get()).toBe(false)
  })

  it('follows an address the operator navigated back to', () => {
    const { context, address } = viewer(
      '/hilos/logs/view/node-1/live/worker-0.log',
    )
    const view = createHilosLogViewer(context, address)
    view.start()

    address.navigate('/hilos/logs/view/node-2/live/daemon.log')

    expect(view.selection.get()).toEqual({
      nodeId: 'node-2',
      source: 'live',
      stream: 'daemon.log',
    })
  })

  it('asks the server for the level and the substring rather than filtering', () => {
    const { context, actions, address } = viewer(
      '/hilos/logs/view/node-1/live/worker-0.log',
    )
    const view = createHilosLogViewer(context, address)
    view.setFollow(false)
    view.start()

    view.setLevel('ERROR')
    view.setSubstring('deadlock')

    expect(actions.lastRead().level).toBe('ERROR')
    expect(actions.lastRead().substring).toBe('deadlock')
    // Every change reads from the tail: a byte offset into the previous answer
    // means nothing in a differently filtered one.
    expect(actions.lastRead().cursor).toBeNull()
  })

  it('reads from the tail again on a reconnect, with the cursor thrown away', () => {
    const { context, actions, connection, address } = viewer(
      '/hilos/logs/view/node-1/live/worker-0.log',
    )
    const view = createHilosLogViewer(context, address)
    view.setFollow(false)
    view.start()

    connection.reconnect()

    expect(actions.sent).toHaveLength(2)
    expect(actions.lastRead().cursor).toBeNull()
    expect(view.rows.get()).toEqual([])
  })

  it('takes the catalog off the connection', () => {
    const { context, connection, address } = viewer()
    const view = createHilosLogViewer(context, address)
    view.start()

    expect(view.catalog.get()).toBeNull()

    connection.sendCatalog(catalog())

    expect(view.catalog.get()?.nodes).toHaveLength(2)
  })
})

describe('the live tail', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })
  afterEach(() => {
    vi.useRealTimers()
  })

  /** A viewer already following a live file, and the id its frames are stamped with. */
  function following(pathname = '/hilos/logs/view/node-1/live/worker-0.log') {
    const parts = viewer(pathname)
    const view = createHilosLogViewer(parts.context, parts.address)
    view.start()

    return {
      ...parts,
      view,
      followId: parts.actions.sent.at(-1)?.requestId ?? '',
    }
  }

  it('starts the tail instead of reading a page when the switch is up on a live file', () => {
    // The reply to the start IS the first page: a read before it would cost a
    // round trip and still leave a gap between the page and the watching.
    const { actions, followId } = following()

    expect(actions.sent).toHaveLength(1)
    expect(actions.sent[0].action).toBe(LOGS_FOLLOW_START_ACTION)
    expect(actions.lastRead()).toEqual({
      nodeId: 'node-1',
      stream: 'worker-0.log',
      level: null,
      substring: null,
    })
    expect(followId).not.toBe('')
  })

  it('replaces the running tail with a new start when the level changes', () => {
    const { view, actions, connection } = following()

    view.setLevel('ERROR')

    expect(actions.sent.map((one) => one.action)).toEqual([
      LOGS_FOLLOW_START_ACTION,
      LOGS_FOLLOW_START_ACTION,
    ])
    expect(actions.lastRead().level).toBe('ERROR')
    // No removal alongside it: the owner replaces the follow of this viewer by
    // itself, and a removal racing the replacement could undo it.
    expect(connection.sent).toEqual([])
  })

  it('stops the tail and greys the switch out on an archived batch', () => {
    const { view, connection } = following()
    connection.sendCatalog(catalog())

    view.select({ source: BATCH })

    expect(view.canFollow.get()).toBe(false)
    expect(view.following.get()).toBe(false)
    expect(connection.sent).toEqual([
      { action: LOGS_FOLLOW_STOP_ACTION, data: { nodeId: 'node-1' } },
    ])
  })

  it('sticks an arriving line to the feed while the reader is at the tail', () => {
    const { view, connection, followId } = following()

    connection.sendAppended({
      followId,
      lines: [wireLine('[2027-01-15 03:12:19.907] [INFO] Ready')],
    })

    expect(view.rows.get()).toHaveLength(1)
    expect(asEntry(view.rows.get()[0]).text).toBe('Ready')
    expect(view.pendingLines.get()).toBe(0)
  })

  it('holds arriving lines beside the feed while the reader is above the tail', () => {
    // The pane under the reader's eyes does not move at all: the feed does not
    // grow, and the count is what the return control offers instead.
    const { view, connection, followId } = following()
    view.setPinned(false)

    connection.sendAppended({
      followId,
      lines: [wireLine('one'), wireLine('two')],
    })

    expect(view.rows.get()).toEqual([])
    expect(view.pendingLines.get()).toBe(2)
  })

  it('pours the buffer in on the way back, stitching a stack across the seam', () => {
    const { view, connection, followId } = following()
    connection.sendAppended({
      followId,
      lines: [wireLine('[2027-01-15 03:12:19.907] ERROR: Deadlock', 'ERROR')],
    })
    view.setPinned(false)
    connection.sendAppended({
      followId,
      lines: [wireLine('#0 Db.php(214)', 'ERROR', true)],
    })

    expect(asEntry(view.rows.get()[0]).frames).toEqual([])

    view.returnToTail()

    expect(view.rows.get()).toHaveLength(1)
    expect(
      asEntry(view.rows.get()[0]).frames.map((frame) => frame.text),
    ).toEqual(['#0 Db.php(214)'])
    expect(view.pinned.get()).toBe(true)
    expect(view.pendingLines.get()).toBe(0)
  })

  it('shows what the buffer held when the switch goes down, and tells the owner', () => {
    // The lines arrived and are real; dropping them is the very thing the
    // rejected design was rejected for.
    const { view, actions, connection, followId } = following()
    view.setPinned(false)
    connection.sendAppended({ followId, lines: [wireLine('one')] })

    view.setFollow(false)

    expect(view.rows.get()).toHaveLength(1)
    expect(view.pendingLines.get()).toBe(0)
    expect(view.following.get()).toBe(false)
    expect(connection.sent).toEqual([
      { action: LOGS_FOLLOW_STOP_ACTION, data: { nodeId: 'node-1' } },
    ])
    // Nothing is re-read: the switch answers for the tail, not for what is shown.
    expect(actions.sent).toHaveLength(1)
  })

  it('notes a rotation at the place in the reading where it happened', () => {
    const { view, connection, followId } = following()

    connection.sendAppended({ followId, rotated: true })

    expect(view.rows.get()[0]).toMatchObject({
      kind: 'notice',
      notice: 'rotated',
      text: 'The file was rotated. Reading continues from the start of the new one.',
    })
  })

  it('notes a jump with the size of what was jumped over', () => {
    const { view, connection, followId } = following()

    connection.sendAppended({ followId, skippedBytes: 2048 })

    expect(view.rows.get()[0]).toMatchObject({
      kind: 'notice',
      notice: 'skipped',
      text: 'Jumped over 2.0 KB to catch up.',
    })
  })

  it('notes a server-side stop, and drops both the badge and the switch', async () => {
    const { view, actions, connection, followId } = following()
    actions.answer(followId, {
      readable: true,
      lines: [],
      nextCursor: null,
      hasMore: false,
    })
    await Promise.resolve()

    expect(view.following.get()).toBe(true)

    connection.sendAppended({ followId, stopped: true })

    expect(view.rows.get()[0]).toMatchObject({
      kind: 'notice',
      notice: 'stopped',
      text: 'Following stopped on the server.',
    })
    expect(view.following.get()).toBe(false)
    // The switch goes down by itself only here: the fact changed against the
    // reader's wish, and a raised switch over a dead tail lies.
    expect(view.followRequested.get()).toBe(false)
  })

  it('does not light the badge for a tail called off before it answered', async () => {
    // Lowering the switch asks for nothing, so it leaves the generation where it
    // is: the guard on the start reply has to be the follow id, or the badge
    // comes on over a tail that was stopped a moment ago.
    const { view, actions, followId } = following()

    view.setFollow(false)
    actions.answer(followId, {
      readable: true,
      lines: [],
      nextCursor: null,
      hasMore: false,
    })
    await Promise.resolve()

    expect(view.following.get()).toBe(false)
  })

  it('drops a frame of a follow it has left behind', () => {
    // Frames of the previous follow may still be in flight when the new one
    // begins, which is exactly what the owner stamps them for.
    const { view, connection } = following()

    connection.sendAppended({
      followId: 'a-follow-that-is-not-this-one',
      lines: [wireLine('[2027-01-15 03:12:19.907] INFO: Ready')],
    })

    expect(view.rows.get()).toEqual([])
  })

  it('notes what the buffer dropped when it overflowed', () => {
    const { view, connection, followId } = following()
    view.setPinned(false)
    connection.sendAppended({
      followId,
      lines: Array.from({ length: FEED_MAX_LINES }, (unused, index) =>
        wireLine(`line ${index}`),
      ),
    })

    connection.sendAppended({
      followId,
      lines: [wireLine('one'), wireLine('two'), wireLine('three')],
    })

    expect(view.pendingLines.get()).toBe(FEED_MAX_LINES)

    view.returnToTail()

    expect(view.rows.get()[0]).toMatchObject({
      kind: 'notice',
      notice: 'dropped',
      text: '3 lines were dropped while you were away.',
    })
  })
})
