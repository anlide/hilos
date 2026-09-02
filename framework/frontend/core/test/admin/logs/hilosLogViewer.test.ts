import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import {
  createHilosLogViewer,
  hasLogViewerNodes,
  logViewerNodeOf,
  logViewerPaneState,
  logViewerPath,
  logViewerStreamsOf,
  readLogViewerAddress,
  splitLogLine,
  toLogViewerEntries,
  LOGS_READ_LINES_ACTION,
  LOGS_VIEWER_SIGNAL_SCHEMAS,
  LOG_VIEWER_CATALOG_SIGNAL,
  type HilosLogViewerAddress,
  type HilosLogViewerCatalog,
  type HilosLogViewerContext,
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

/** A minimal connection: the three events the viewer listens on, emitted by hand. */
class FakeConnection {
  readonly listeners: {
    projectSignal: ((signal: unknown) => void)[]
    actionError: ((signal: unknown) => void)[]
    state: ((state: ConnectionState) => void)[]
  } = { projectSignal: [], actionError: [], state: [] }

  on(event: string, listener: (payload: never) => void): () => void {
    const bucket = this.listeners[event as keyof typeof this.listeners]
    bucket.push(listener as never)

    return () => {}
  }

  sendCatalog(data: HilosLogViewerCatalog): void {
    for (const listener of this.listeners.projectSignal) {
      listener({ type: LOG_VIEWER_CATALOG_SIGNAL, data })
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

describe('toLogViewerEntries', () => {
  it('folds continuations under the line that started them', () => {
    const entries = toLogViewerEntries([
      {
        id: '1:0',
        text: '[2027-01-15 03:12:19.907] ERROR: Deadlock',
        level: 'ERROR',
        isContinuation: false,
      },
      {
        id: '1:1',
        text: '#0 Db.php(214)',
        level: 'ERROR',
        isContinuation: true,
      },
      {
        id: '1:2',
        text: '#1 Users.php(88)',
        level: 'ERROR',
        isContinuation: true,
      },
    ])

    expect(entries).toHaveLength(1)
    expect(entries[0].key).toBe('1:0')
    expect(entries[0].text).toBe('Deadlock')
    expect(entries[0].frames.map((frame) => frame.text)).toEqual([
      '#0 Db.php(214)',
      '#1 Users.php(88)',
    ])
    expect(entries[0].orphan).toBe(false)
  })

  it('leaves a continuation whose start was never read on its own', () => {
    // Folding it into whatever happens to be above would claim a relationship the
    // read cannot know about.
    const entries = toLogViewerEntries([
      {
        id: '1:0',
        text: '#7 Page.php(160)',
        level: 'ERROR',
        isContinuation: true,
      },
      {
        id: '1:1',
        text: '[2027-01-15 03:12:20.004] INFO: Retrying',
        level: 'INFO',
        isContinuation: false,
      },
    ])

    expect(entries).toHaveLength(2)
    expect(entries[0].orphan).toBe(true)
    expect(entries[0].frames).toEqual([])
    expect(entries[1].orphan).toBe(false)
  })

  it('keeps the key of an entry when older lines arrive above it', () => {
    const newer = {
      id: '1:0',
      text: '[2027-01-15 03:12:20.004] INFO: Retrying',
      level: 'INFO',
      isContinuation: false,
    }
    const older = {
      id: '2:0',
      text: '[2027-01-15 03:00:01.482] INFO: Rotation done',
      level: 'INFO',
      isContinuation: false,
    }

    expect(toLogViewerEntries([older, newer]).at(-1)?.key).toBe(
      toLogViewerEntries([newer])[0].key,
    )
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

    createHilosLogViewer(context, address).start()

    expect(actions.lastRead().nodeId).toBe('')
    expect(actions.lastRead().source).toBe('live')
    expect(actions.lastRead().batchTimestamp).toBeNull()
  })

  it('rewrites the address and reads from the tail when a file is chosen', () => {
    const { context, actions, address } = viewer()
    const view = createHilosLogViewer(context, address)
    view.start()

    view.select({ nodeId: 'node-1', source: 'live', stream: 'worker-0.log' })

    expect(address.written).toEqual([
      '/hilos/logs/view/node-1/live/worker-0.log',
    ])
    expect(actions.lastRead().cursor).toBeNull()
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
      lines: [
        {
          text: '[2027-01-15 03:00:01.482] INFO: Rotation done',
          level: 'INFO',
          isContinuation: false,
        },
      ],
      nextCursor: 512,
      hasMore: true,
    })
    await Promise.resolve()

    expect(view.entries.get()).toEqual([])
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
    createHilosLogViewer(context, address).start()

    connection.reconnect()

    expect(actions.sent).toHaveLength(2)
    expect(actions.lastRead().cursor).toBeNull()
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
