import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { hilosTimeZone } from '../../../src/timeZone/timeZoneState.js'
import { applyServerTime } from '../../../src/session/serverClock.js'

import {
  createHilosLogViewer,
  hasLogViewerNodes,
  isLogViewerPinned,
  logLineClock,
  logViewerLatestBatchPath,
  logViewerNodeOf,
  logViewerPendingLabel,
  logViewerPaneState,
  logViewerPath,
  logViewerStreamKeys,
  logViewerStreamsOf,
  readLogViewerAddress,
  splitLogTrace,
  toLogViewerRows,
  LOGS_FOLLOW_START_ACTION,
  LOGS_FOLLOW_STOP_ACTION,
  LOGS_READ_LINES_ACTION,
  LOGS_VIEWER_SIGNAL_SCHEMAS,
  LOG_LINES_APPENDED_SIGNAL,
  LOG_VIEWER_CATALOG_SIGNAL,
  LOG_VIEWER_PENDING_WIDEST_LABEL,
  type HilosLogViewer,
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
import {
  type ActionErrorSignal,
  type ActionSuccessSignal,
} from '../../../src/protocol/parseSignal.js'
import { type HilosConnection } from '../../../src/connection/HilosConnection.js'
import { type ConnectionState } from '../../../src/connection/HilosConnection.js'
import { type PageRouteMatch } from '../../../src/routing/PageRouter.js'
import { createAppPageRouter } from '../../../src/routing/appPageRouter.js'
import { HilosPages } from '../../../src/routing/hilosPages.js'
import { createSignal, subscribeSignal } from '../../../src/state/signal.js'
import { HIDDEN_VALUE } from '../../../src/state/hiddenValue.js'

const pageRouter = createAppPageRouter({})

/** Any fixed batch, so a timestamp in a fixture means something to read. */
const BATCH = 1800000000000

/** The feed cap, restated here because the module keeps it private. */
const FEED_MAX_LINES = 2000

/** One line of a read reply or a follow frame, as it comes off the wire. */
function wireLine(text: string, level = 'INFO', isContinuation = false) {
  const stamped = /^\[([^\]]+)\] /.exec(text)
  return {
    time: stamped?.[1] ?? null,
    text:
      stamped === null
        ? text
        : text
            .slice(stamped[0].length)
            .replace(
              /^(?:\[(?:ERROR|WARNING|INFO|DEBUG)\] |(?:ERROR|WARNING|DEBUG): )/,
              '',
            ),
    level,
    isContinuation,
  }
}

/** One item of an already-keyed feed, for the grouping tests. */
function feedLine(
  id: string,
  text: string,
  level = 'INFO',
  isContinuation = false,
): HilosLogViewerFeedItem {
  return {
    kind: 'line',
    line: { id, ...wireLine(text, level, isContinuation) },
  }
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
    clusterEnabled: true,
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
    anchorAtMs: null,
    ...overrides,
  }
}

/** A minimal action source: the sent frames are what a read is judged by. */
class FakeActions implements ActionLifecycleSource {
  readonly sent: { action: string; data: unknown; requestId?: string }[] = []
  private readonly listeners: ((signal: ActionSuccessSignal) => void)[] = []
  private readonly errors: ((signal: ActionErrorSignal) => void)[] = []

  sendAction(action: string, data: unknown, requestId?: string): boolean {
    this.sent.push({ action, data, requestId })

    return true
  }

  on(event: string, listener: (payload: never) => void): () => void {
    if (event === 'actionSuccess') {
      this.listeners.push(
        listener as unknown as (signal: ActionSuccessSignal) => void,
      )
    } else if (event === 'actionError') {
      this.errors.push(
        listener as unknown as (signal: ActionErrorSignal) => void,
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

  /** Refuse one dispatched read with the owner's own reason. */
  refuse(requestId: string | undefined, reason: string): void {
    for (const listener of this.errors) {
      listener({
        kind: 'actionError',
        action: LOGS_FOLLOW_START_ACTION,
        reason,
        errorCode: undefined,
        errorType: undefined,
        errorDetail: undefined,
        requestId,
        envelope: { type: 'action_error', data: {} },
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
  return pageRouter.match(pathname).params
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
    ).toEqual({
      nodeId: 'node-2',
      source: BATCH,
      stream: 'worker-0.log',
      anchorAtMs: null,
    })
  })

  it('keeps the legacy dash until the catalog resolves its node', () => {
    expect(
      readLogViewerAddress({
        nodeId: '-',
        source: 'live',
        stream: 'daemon.log',
      }),
    ).toEqual({
      nodeId: '-',
      source: 'live',
      stream: 'daemon.log',
      anchorAtMs: null,
    })
  })

  it('reads the fourth segment as the moment of the entry to open on', () => {
    expect(
      readLogViewerAddress({
        nodeId: 'node-2',
        source: 'live',
        stream: 'worker-0.log',
        anchor: '1788000000250',
      }).anchorAtMs,
    ).toBe(1788000000250)
  })

  it('reads an anchor it cannot make out as none, keeping the file the address names', () => {
    expect(
      readLogViewerAddress({
        nodeId: 'node-2',
        source: 'live',
        stream: 'worker-0.log',
        anchor: 'yesterday',
      }),
    ).toEqual({
      nodeId: 'node-2',
      source: 'live',
      stream: 'worker-0.log',
      anchorAtMs: null,
    })
  })

  it('leaves every unfilled slot unchosen', () => {
    expect(readLogViewerAddress({})).toEqual({
      nodeId: null,
      source: null,
      stream: null,
      anchorAtMs: null,
    })
  })
})

describe('logViewerPath', () => {
  it.each(['node-1', 'node /?#%', '%2F', 'узел🌍'])(
    'roundtrips %s and a raw stream through the real matcher',
    (nodeId) => {
      const chosen = selection({
        nodeId,
        stream: 'worker /?#%2F🌍.log',
        anchorAtMs: 1788000000250,
      })
      const path = logViewerPath(chosen)
      expect(path).toBe(
        `/hilos/logs/view/${encodeURIComponent(nodeId)}/live/worker%20%2F%3F%23%252F%F0%9F%8C%8D.log/1788000000250`,
      )
      expect(readLogViewerAddress(pageRouter.match(path).params)).toEqual(
        chosen,
      )
      const address = fakeAddress()
      address.replacePath(path)
      expect(readLogViewerAddress(address.currentRoute.get().params)).toEqual(
        chosen,
      )
      const partial = pageRouter.path(HilosPages.LOGS_VIEW, {
        nodeId,
        source: 'live',
      })!
      expect(readLogViewerAddress(pageRouter.match(partial).params)).toEqual({
        nodeId,
        source: 'live',
        stream: null,
        anchorAtMs: null,
      })
    },
  )

  it('builds the address of the file being read', () => {
    expect(logViewerPath(selection({ nodeId: 'node-2', source: BATCH }))).toBe(
      `/hilos/logs/view/node-2/${BATCH}/worker-0.log`,
    )
  })

  it('writes the effective standalone node id', () => {
    expect(logViewerPath(selection({ nodeId: 'standalone' }))).toBe(
      '/hilos/logs/view/standalone/live/worker-0.log',
    )
  })

  it('writes the moment of the entry as the fourth segment', () => {
    expect(logViewerPath(selection({ anchorAtMs: 1788000000250 }))).toBe(
      '/hilos/logs/view/node-1/live/worker-0.log/1788000000250',
    )
  })

  it('names no place in a file nobody chose, anchor or not', () => {
    expect(
      logViewerPath(selection({ stream: null, anchorAtMs: 1788000000250 })),
    ).toBe('/hilos/logs/view/node-1/live')
  })

  it.each(['live', 0, 1000, 1800000000, BATCH] as const)(
    'roundtrips a node and source %s without guessing a stream',
    (source) => {
      const chosen = selection({ source, stream: null })
      const path = logViewerPath(chosen)
      expect(path).toBe(`/hilos/logs/view/node-1/${source}`)
      expect(readLogViewerAddress(paramsOf(path))).toEqual(chosen)
    },
  )

  it('stays bare while the node or source is unchosen', () => {
    expect(logViewerPath(selection({ nodeId: null }))).toBe('/hilos/logs/view')
    expect(logViewerPath(selection({ source: null }))).toBe('/hilos/logs/view')
    expect(logViewerPath(readLogViewerAddress({}))).toBe('/hilos/logs/view')
  })
})

describe('logLineClock', () => {
  it('formats a UTC timestamp and leaves an unstamped line empty', () => {
    expect(logLineClock('2027-01-15T03:12:19.907Z')).toBe('03:12:19.907')
    expect(logLineClock(null)).toBe('')
  })

  it.each([
    ['2026-01-01T00:30:05.123Z', '16:30:05.123'],
    ['2026-03-08T09:59:59.123Z', '01:59:59.123'],
    ['2026-03-08T10:00:00.123Z', '03:00:00.123'],
    ['2026-11-01T08:59:59.123Z', '01:59:59.123'],
    ['2026-11-01T09:00:00.123Z', '01:00:00.123'],
  ])('keeps milliseconds across day and DST boundaries at %s', (at, clock) => {
    const zone = vi
      .spyOn(hilosTimeZone, 'get')
      .mockReturnValue('America/Los_Angeles')
    try {
      expect(logLineClock(at)).toBe(clock)
    } finally {
      zone.mockRestore()
    }
  })

  it.each([-300_000, 300_000])(
    'preserves the server moment with device drift %i',
    (drift) => {
      const zone = vi.spyOn(hilosTimeZone, 'get').mockReturnValue('UTC')
      applyServerTime(Date.now() + drift)
      try {
        expect(logLineClock('2026-01-01T00:30:05.123Z')).toBe('00:30:05.123')
      } finally {
        zone.mockRestore()
        applyServerTime(Date.now())
      }
    },
  )

  it.each([
    '',
    'invalid',
    '2026-01-01 00:30:05.123',
    '2026-01-01T00:30:05.123',
    '2026-01-01T01:30:05.123+01:00',
    '2026-02-31T00:30:05.123Z',
  ])('leaves an invalid moment empty: %s', (at) => {
    expect(logLineClock(at)).toBe('')
  })

  it('keeps the complete ISO moment in the feed while localizing presentation rows', () => {
    const zone = vi
      .spyOn(hilosTimeZone, 'get')
      .mockReturnValue('America/Los_Angeles')
    const feed = [
      feedLine('1:0', '[2026-01-01T00:30:05.123Z] ERROR: failure', 'ERROR'),
    ]
    try {
      expect(asEntry(toLogViewerRows(feed)[0]).time).toBe('16:30:05.123')
      expect(feed[0]).toMatchObject({
        kind: 'line',
        line: { id: '1:0', time: '2026-01-01T00:30:05.123Z' },
      })
    } finally {
      zone.mockRestore()
    }
  })
})

/**
 * One real error line as the writer leaves it, prefixes already cut: the context
 * of the event in the tail of the SAME physical line, its slashes escaped by the
 * `json_encode` in PHP `Logger::writeLine()`.
 */
const TRACED_LINE =
  'Action failed {"file":"\\/hilos\\/backend\\/PasskeyCommands.php","line":291,' +
  '"trace":"#0 \\/hilos\\/backend\\/Db.php(214): run()\\n#1 {main}"}'

describe('splitLogTrace', () => {
  it('takes the trace out of the context and hands back its frames', () => {
    expect(splitLogTrace(TRACED_LINE).frames).toEqual([
      '#0 /hilos/backend/Db.php(214): run()',
      '#1 {main}',
    ])
  })

  it('keeps the rest of the context in the text, re-printed', () => {
    // The escaping goes away by itself: the tail was read and printed again, not
    // rewritten by us.
    expect(splitLogTrace(TRACED_LINE).text).toBe(
      'Action failed {"file":"/hilos/backend/PasskeyCommands.php","line":291}',
    )
  })

  it('drops the tail whole when the trace was all it carried', () => {
    expect(splitLogTrace('Action failed {"trace":"#0 A.php(1)"}')).toEqual({
      text: 'Action failed',
      frames: ['#0 A.php(1)'],
    })
  })

  it('starts from the candidate the context really begins at', () => {
    // The message may carry an object of its own, and the tail taken from the
    // first candidate would hold two of them in a row and parse as neither.
    expect(
      splitLogTrace(
        'Action failed payload={"a":1} {"line":291,"trace":"#0 A.php(1)"}',
      ),
    ).toEqual({
      text: 'Action failed payload={"a":1} {"line":291}',
      frames: ['#0 A.php(1)'],
    })
  })

  it('leaves a line whose context carries no trace alone', () => {
    const line = 'Rotation done {"file":"a.php","line":1}'

    expect(splitLogTrace(line)).toEqual({ text: line, frames: [] })
  })

  it('leaves a line whose trace holds no frame alone', () => {
    const line = 'Action failed {"line":291,"trace":""}'

    expect(splitLogTrace(line)).toEqual({ text: line, frames: [] })
  })

  it('leaves a tail that looks like an object but does not parse alone', () => {
    const line = 'Action failed {"line":291,"trace":"#0 A.php(1)",}'

    expect(splitLogTrace(line)).toEqual({ text: line, frames: [] })
  })
})

describe('toLogViewerRows', () => {
  it('keeps a hidden entry visible by time and level without forming a stack', () => {
    const rows = toLogViewerRows([
      {
        kind: 'line',
        line: {
          id: '1:0',
          time: '2027-01-15T03:12:19.907Z',
          text: HIDDEN_VALUE,
          level: 'ERROR',
          isContinuation: false,
        },
      },
      {
        kind: 'line',
        line: {
          id: '1:1',
          time: null,
          text: HIDDEN_VALUE,
          level: 'ERROR',
          isContinuation: true,
        },
      },
    ])

    expect(rows).toHaveLength(1)
    expect(asEntry(rows[0])).toMatchObject({
      time: '03:12:19.907',
      level: 'ERROR',
      text: HIDDEN_VALUE,
      frames: [],
    })
  })

  it('draws a hidden continuation without a preceding entry as its own dimmed row', () => {
    const rows = toLogViewerRows([
      {
        kind: 'line',
        line: {
          id: '1:0',
          time: null,
          text: HIDDEN_VALUE,
          level: 'ERROR',
          isContinuation: true,
        },
      },
    ])

    expect(asEntry(rows[0])).toMatchObject({
      orphan: true,
      time: '',
      text: HIDDEN_VALUE,
      frames: [],
    })
  })

  it('folds continuations under the line that started them', () => {
    const rows = toLogViewerRows([
      feedLine('1:0', '[2027-01-15T03:12:19.907Z] ERROR: Deadlock', 'ERROR'),
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

  it('gives an entry whose stack sits in its context the frames of it', () => {
    const rows = toLogViewerRows([
      feedLine(
        '1:0',
        `[2027-01-15T03:12:19.907Z] ERROR: ${TRACED_LINE}`,
        'ERROR',
      ),
    ])

    expect(rows).toHaveLength(1)
    const entry = asEntry(rows[0])
    expect(entry.text).toBe(
      'Action failed {"file":"/hilos/backend/PasskeyCommands.php","line":291}',
    )
    expect(entry.frames).toEqual([
      {
        time: '',
        level: 'ERROR',
        text: '#0 /hilos/backend/Db.php(214): run()',
      },
      { time: '', level: 'ERROR', text: '#1 {main}' },
    ])
  })

  it('puts the frames of the trace before the physical continuations', () => {
    // For the reader it is one stack of one error, so it is counted and opened
    // as one; the file order says the trace was written first.
    const rows = toLogViewerRows([
      feedLine(
        '1:0',
        `[2027-01-15T03:12:19.907Z] ERROR: ${TRACED_LINE}`,
        'ERROR',
      ),
      feedLine('1:1', '#2 Users.php(88)', 'ERROR', true),
    ])

    expect(rows).toHaveLength(1)
    expect(asEntry(rows[0]).frames.map((frame) => frame.text)).toEqual([
      '#0 /hilos/backend/Db.php(214): run()',
      '#1 {main}',
      '#2 Users.php(88)',
    ])
  })

  it('leaves a continuation carrying a JSON tail alone', () => {
    // A continuation carries no context of its own, and an orphan is not folded
    // into anything either — so nothing is taken out of it.
    const tail = '#7 Page.php(160) {"trace":"#0 A.php(1)"}'
    const rows = toLogViewerRows([feedLine('1:0', tail, 'ERROR', true)])

    expect(rows).toHaveLength(1)
    const entry = asEntry(rows[0])
    expect(entry.orphan).toBe(true)
    expect(entry.text).toBe(tail)
    expect(entry.frames).toEqual([])
  })

  it('leaves a continuation whose start was never read on its own', () => {
    // Folding it into whatever happens to be above would claim a relationship the
    // read cannot know about.
    const rows = toLogViewerRows([
      feedLine('1:0', '#7 Page.php(160)', 'ERROR', true),
      feedLine('1:1', '[2027-01-15T03:12:20.004Z] INFO: Retrying'),
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
      feedLine('1:0', '[2027-01-15T03:12:19.907Z] ERROR: Deadlock', 'ERROR'),
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
    const newer = feedLine('1:0', '[2027-01-15T03:12:20.004Z] INFO: Retrying')
    const older = feedLine(
      '2:0',
      '[2027-01-15T03:00:01.482Z] INFO: Rotation done',
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

describe('logViewerPendingLabel', () => {
  it('writes nothing for no lines, the number up to the ceiling, and the widest label past it', () => {
    // The ceiling keeps the live control inside the room its invisible twin
    // holds: a count without one would widen it and wrap the strip on a phone.
    expect(logViewerPendingLabel(0)).toBe('')
    expect(logViewerPendingLabel(14)).toBe('14')
    expect(logViewerPendingLabel(999)).toBe('999')
    expect(logViewerPendingLabel(1000)).toBe('999+')
    expect(logViewerPendingLabel(1270)).toBe('999+')
    expect(LOG_VIEWER_PENDING_WIDEST_LABEL).toBe('999+')
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
        lines: [wireLine('[2027-01-15T03:12:19.907Z] INFO: Ready')],
        rotated: false,
        skippedBytes: null,
        stopped: false,
      }).success,
    ).toBe(true)
    expect(schema.safeParse({ followId: '7', lines: [] }).success).toBe(false)
  })

  it('offers a node select only in cluster mode', () => {
    expect(hasLogViewerNodes(catalog())).toBe(true)
    expect(
      hasLogViewerNodes(
        catalog({
          clusterEnabled: false,
          nodes: [
            { nodeId: 'standalone', available: true, batches: [], streams: [] },
          ],
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

  it('offers an explicit stream name without adding a file to the catalog', () => {
    const picture = catalog()
    const before = catalog()
    const node = logViewerNodeOf(picture, 'node-1')

    expect(logViewerStreamKeys(node, selection())).toEqual(['worker-0.log'])
    expect(
      logViewerStreamKeys(node, selection({ stream: 'agent-chat.log' })),
    ).toEqual(['worker-0.log', 'agent-chat.log'])
    expect(
      logViewerStreamKeys(node, selection({ stream: 'unknown.log' })),
    ).toEqual(['worker-0.log', 'unknown.log'])
    expect(logViewerStreamKeys(null, selection())).toEqual(['worker-0.log'])
    expect(logViewerStreamKeys(null, selection({ stream: null }))).toEqual([])
    expect(picture).toEqual(before)
  })
})

describe('logViewerLatestBatchPath', () => {
  it('finds the maximum shared stamp of this stream and node without carrying the anchor', () => {
    const picture = catalog()
    const node = picture.nodes[0]
    node.batches = [BATCH + 20000, BATCH, BATCH + 10000, BATCH + 30000]
    node.streams[1].batchTimestamps = [BATCH + 10000, BATCH + 40000, BATCH]
    node.streams[0].batchTimestamps = [BATCH + 30000]
    picture.nodes[1].batches = [BATCH + 50000]
    picture.nodes[1].streams.push({
      key: 'agent-chat.log',
      class: 'agent',
      live: false,
      batchTimestamps: [BATCH + 50000],
    })
    const chosen = selection({ stream: 'agent-chat.log', anchorAtMs: 1234 })
    const before = { ...chosen }

    expect(logViewerLatestBatchPath(picture, chosen)).toBe(
      `/hilos/logs/view/node-1/${BATCH + 10000}/agent-chat.log`,
    )
    expect(chosen).toEqual(before)
  })

  it('promises no archive without an available catalog and selected node', () => {
    const picture = catalog()
    expect(logViewerLatestBatchPath(null, selection())).toBeNull()
    expect(
      logViewerLatestBatchPath(catalog({ available: null }), selection()),
    ).toBeNull()
    expect(
      logViewerLatestBatchPath(catalog({ available: false }), selection()),
    ).toBeNull()
    expect(
      logViewerLatestBatchPath(picture, selection({ nodeId: 'unknown' })),
    ).toBeNull()
    expect(
      logViewerLatestBatchPath(picture, selection({ nodeId: null })),
    ).toBeNull()
    picture.nodes[0].available = false
    expect(logViewerLatestBatchPath(picture, selection())).toBeNull()
  })

  it('requires live and an archive belonging to the selected stream', () => {
    const picture = catalog()
    expect(
      logViewerLatestBatchPath(picture, selection({ source: BATCH })),
    ).toBeNull()
    expect(
      logViewerLatestBatchPath(picture, selection({ source: null })),
    ).toBeNull()
    expect(
      logViewerLatestBatchPath(picture, selection({ stream: null })),
    ).toBeNull()
    expect(
      logViewerLatestBatchPath(picture, selection({ stream: 'unknown.log' })),
    ).toBeNull()
    expect(
      logViewerLatestBatchPath(
        picture,
        selection({ nodeId: 'node-2', stream: 'daemon.log' }),
      ),
    ).toBeNull()
    picture.nodes[0].batches = []
    expect(logViewerLatestBatchPath(picture, selection())).toBeNull()
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

  it.each([
    null,
    catalog({ available: null }),
    catalog({ available: false }),
    catalog({ nodes: [] }),
    catalog(),
  ])(
    'keeps a concrete unreadable file answer ahead of catalog state',
    (picture) => {
      expect(logViewerPaneState(picture, selection(), 0, false, false)).toBe(
        'missing',
      )
      expect(logViewerPaneState(picture, selection(), 1, false, false)).toBe(
        'lines',
      )
    },
  )

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
    expect(
      logViewerPaneState(null, selection({ stream: null }), 0, false, false),
    ).toBe('unknown')
    expect(
      logViewerPaneState(
        catalog({ available: false }),
        selection({ stream: null }),
        0,
        false,
        false,
      ),
    ).toBe('unreadable')
    expect(
      logViewerPaneState(
        catalog({ nodes: [] }),
        selection({ stream: null }),
        0,
        false,
        false,
      ),
    ).toBe('empty')
    expect(
      logViewerPaneState(
        catalog(),
        selection({ stream: null }),
        0,
        false,
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

  it.each(['agent-chat.log', 'unknown.log'])(
    'retains the explicit live stream %s through empty and late catalogs',
    async (stream) => {
      const path = `/hilos/logs/view/node-1/live/${stream}`
      const { context, connection, actions, address } = viewer(path)
      const view = createHilosLogViewer(context, address)
      view.start()
      const chosen = selection({ stream })
      const requestId = actions.sent[0].requestId

      expect(view.selection.get()).toEqual(chosen)
      expect(logViewerStreamKeys(null, chosen)).toEqual([stream])
      expect(view.busy.get()).toBe(true)
      expect(view.readable.get()).toBe(true)
      connection.sendCatalog(catalog({ nodes: [] }))
      actions.answer(requestId, {
        readable: false,
        lines: [],
        nextCursor: null,
        hasMore: false,
      })
      await Promise.resolve()

      expect(view.busy.get()).toBe(false)
      expect(view.refusal.get()).toBeNull()
      expect(
        logViewerPaneState(
          view.catalog.get(),
          view.selection.get(),
          view.rows.get().length,
          view.readable.get(),
          false,
        ),
      ).toBe('missing')
      const picture = catalog()
      connection.sendCatalog(picture)
      expect(view.selection.get()).toEqual(chosen)
      expect(logViewerStreamKeys(picture.nodes[0], chosen)).toEqual([
        'worker-0.log',
        stream,
      ])
      expect(picture).toEqual(catalog())
      expect(logViewerStreamsOf(picture.nodes[0], 'live')).toEqual([
        picture.nodes[0].streams[0],
      ])
      expect(view.followRequested.get()).toBe(true)
      expect(view.following.get()).toBe(true)
      expect(actions.sent).toHaveLength(1)
      expect(address.written).toEqual([])
      expect(logViewerPath(view.selection.get())).toBe(path)
    },
  )

  it.each(['select', 'route'] as const)(
    'does not carry a completed missing answer into a pending %s read',
    async (navigation) => {
      const { context, connection, actions, address } = viewer(
        '/hilos/logs/view/node-1/live/agent-chat.log',
      )
      const view = createHilosLogViewer(context, address)
      view.start()
      connection.sendCatalog(catalog())
      actions.answer(actions.sent.at(-1)?.requestId, {
        readable: false,
        lines: [],
        nextCursor: null,
        hasMore: false,
      })
      await Promise.resolve()
      expect(view.readable.get()).toBe(false)
      expect(view.busy.get()).toBe(false)

      // Keep another A reply on the wire while the reader moves to B.
      connection.reconnect()
      const oldId = actions.sent.at(-1)?.requestId ?? ''
      if (navigation === 'select') {
        view.select({ stream: 'worker-0.log' })
      } else {
        address.navigate('/hilos/logs/view/node-1/live/worker-0.log')
      }
      const currentId = actions.sent.at(-1)?.requestId ?? ''
      expect(view.selection.get()).toEqual(selection())
      expect(view.busy.get()).toBe(true)
      expect(view.readable.get()).toBe(true)
      expect(view.refusal.get()).toBeNull()
      expect(view.followRequested.get()).toBe(true)

      actions.answer(oldId, {
        readable: false,
        lines: [],
        nextCursor: 50,
        hasMore: true,
      })
      connection.sendAppended({
        followId: oldId,
        lines: [wireLine('A must not land under B.')],
      })
      await Promise.resolve()
      expect(view.busy.get()).toBe(true)
      expect(view.readable.get()).toBe(true)
      expect(view.rows.get()).toEqual([])
      expect(view.hasMore.get()).toBe(false)
      expect(view.following.get()).toBe(false)

      actions.answer(currentId, {
        readable: false,
        lines: [],
        nextCursor: null,
        hasMore: false,
      })
      await Promise.resolve()
      expect(view.busy.get()).toBe(false)
      expect(view.readable.get()).toBe(false)
      expect(view.following.get()).toBe(true)
      expect(
        logViewerPaneState(
          view.catalog.get(),
          view.selection.get(),
          view.rows.get().length,
          view.readable.get(),
          false,
        ),
      ).toBe('missing')
      expect(
        logViewerLatestBatchPath(view.catalog.get(), view.selection.get()),
      ).toBe(`/hilos/logs/view/node-1/${BATCH}/worker-0.log`)
    },
  )

  it('opens a partial batch address without reading an arbitrary stream', () => {
    const { context, connection, actions, address } = viewer(
      `/hilos/logs/view/node-1/${BATCH}`,
    )
    const view = createHilosLogViewer(context, address)
    view.start()
    connection.sendCatalog(catalog())

    expect(view.selection.get()).toEqual(
      selection({ source: BATCH, stream: null }),
    )
    expect(actions.sent).toEqual([])
    expect(address.written).toEqual([])
  })

  it('keeps a refusal of the selected file above an empty catalog', async () => {
    const { context, connection, actions, address } = viewer(
      '/hilos/logs/view/node-1/live/agent-chat.log',
    )
    const view = createHilosLogViewer(context, address)
    view.start()
    connection.sendCatalog(catalog({ nodes: [] }))
    expect(view.busy.get()).toBe(true)

    actions.refuse(actions.sent[0].requestId, 'The selected node is offline.')
    await Promise.resolve()

    expect(view.busy.get()).toBe(false)
    expect(view.refusal.get()).toBe('The selected node is offline.')
    expect(view.readable.get()).toBe(true)
    expect(view.following.get()).toBe(false)
    expect(view.followRequested.get()).toBe(true)
    expect(view.selection.get()).toEqual(
      selection({ stream: 'agent-chat.log' }),
    )
  })

  it('resolves an old standalone bookmark before reading the file', () => {
    const { context, connection, actions, address } = viewer(
      '/hilos/logs/view/-/live/daemon.log',
    )

    const view = createHilosLogViewer(context, address)
    // With the tail down this is a plain read, which is the request whose source
    // and batch stamp are under test; the start carries neither.
    view.setFollow(false)
    view.start()

    expect(actions.sent).toHaveLength(0)
    connection.sendCatalog(catalog({ clusterEnabled: false, nodes: [] }))
    connection.reconnect()
    expect(actions.sent).toHaveLength(0)
    connection.sendCatalog(
      catalog({
        clusterEnabled: false,
        nodes: [
          { nodeId: 'standalone', available: true, batches: [], streams: [] },
        ],
      }),
    )

    expect(actions.lastRead().nodeId).toBe('standalone')
    expect(actions.lastRead().source).toBe('live')
    expect(actions.lastRead().batchTimestamp).toBeNull()
    expect(address.written).toEqual([
      '/hilos/logs/view/standalone/live/daemon.log',
    ])
  })

  it('resolves a partial legacy standalone address without choosing a stream', () => {
    const { context, connection, actions, address } = viewer(
      `/hilos/logs/view/-/${BATCH}`,
    )
    const view = createHilosLogViewer(context, address)
    view.start()
    connection.sendCatalog(
      catalog({ clusterEnabled: false, nodes: [catalog().nodes[0]] }),
    )

    expect(view.selection.get()).toEqual(
      selection({ source: BATCH, stream: null }),
    )
    expect(address.written).toEqual([`/hilos/logs/view/node-1/${BATCH}`])
    expect(actions.sent).toEqual([])
  })

  it('reads a configured standalone node whose real id is the legacy dash', () => {
    const { context, connection, actions, address } = viewer(
      '/hilos/logs/view/-/live/daemon.log',
    )
    const view = createHilosLogViewer(context, address)
    view.setFollow(false)
    view.start()

    connection.sendCatalog(catalog({ clusterEnabled: false, nodes: [] }))
    expect(actions.sent).toHaveLength(0)
    const dashCatalog = catalog({
      clusterEnabled: false,
      nodes: [{ nodeId: '-', available: true, batches: [], streams: [] }],
    })
    connection.sendCatalog(dashCatalog)
    expect(actions.lastRead().nodeId).toBe('-')
    expect(actions.sent).toHaveLength(1)
    connection.sendCatalog(dashCatalog)
    expect(actions.sent).toHaveLength(1)
  })

  it('does not re-read a cluster node named dash on every catalog update', () => {
    const { context, connection, actions, address } = viewer(
      '/hilos/logs/view/-/live/daemon.log',
    )
    const view = createHilosLogViewer(context, address)
    view.setFollow(false)
    view.start()

    const dashCatalog = catalog({
      nodes: [{ nodeId: '-', available: true, batches: [], streams: [] }],
    })
    connection.sendCatalog(dashCatalog)
    expect(actions.lastRead().nodeId).toBe('-')
    expect(actions.sent).toHaveLength(1)
    connection.sendCatalog(dashCatalog)
    expect(actions.sent).toHaveLength(1)
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

  it('keeps an archive-only stream when returning to live on the same node', () => {
    const { context, connection, actions, address } = viewer(
      `/hilos/logs/view/node-1/${BATCH}/agent-chat.log`,
    )
    const view = createHilosLogViewer(context, address)
    view.start()
    connection.sendCatalog(catalog())

    view.select({ source: 'live' })

    expect(view.selection.get()).toEqual(
      selection({ stream: 'agent-chat.log' }),
    )
    expect(view.followRequested.get()).toBe(true)
    expect(actions.sent.at(-1)?.action).toBe(LOGS_FOLLOW_START_ACTION)
    expect(actions.lastRead().stream).toBe('agent-chat.log')
    expect(address.written).toEqual([
      '/hilos/logs/view/node-1/live/agent-chat.log',
    ])
  })

  it('requires the new destination to offer the stream on another node or batch', () => {
    const { context, connection, actions, address } = viewer(
      `/hilos/logs/view/node-1/${BATCH}/agent-chat.log`,
    )
    const view = createHilosLogViewer(context, address)
    view.start()
    connection.sendCatalog(catalog())

    view.select({ source: BATCH + 1000 })
    expect(view.selection.get()).toEqual(
      selection({ source: BATCH + 1000, stream: null }),
    )
    address.navigate(`/hilos/logs/view/node-1/${BATCH}/agent-chat.log`)
    view.select({ nodeId: 'node-2', source: 'live' })
    expect(view.selection.get()).toEqual(
      selection({ nodeId: 'node-2', stream: null }),
    )
    expect(actions.sent).toHaveLength(2)
    expect(address.written).toEqual([
      `/hilos/logs/view/node-1/${BATCH + 1000}`,
      '/hilos/logs/view/node-2/live',
    ])
  })

  it('drops the stream when the new source does not have it, and keeps the rest', () => {
    // The same name in another place is another file, and one that is not there
    // at all cannot be opened: leaving it chosen would name nothing. What must
    // NOT go with it is the choice that caused the drop — the partial address
    // and its own echo must keep the node the operator had just picked.
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
      anchorAtMs: null,
    })
    expect(address.written).toEqual(['/hilos/logs/view/node-2/live'])
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
      lines: [wireLine('[2027-01-15T03:00:01.482Z] INFO: Rotation done')],
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
      anchorAtMs: null,
    })
  })

  it('follows Back and Forward between a complete and a partial address', () => {
    const path = '/hilos/logs/view/node-1/live/agent-chat.log'
    const { context, connection, actions, address } = viewer(path)
    const view = createHilosLogViewer(context, address)
    view.start()
    connection.sendCatalog(catalog())

    view.select({ nodeId: 'node-2' })
    expect(actions.sent).toHaveLength(1)
    expect(view.selection.get()).toEqual(
      selection({ nodeId: 'node-2', stream: null }),
    )
    address.navigate(path)
    expect(view.selection.get()).toEqual(
      selection({ stream: 'agent-chat.log' }),
    )
    expect(actions.sent).toHaveLength(2)
    address.navigate('/hilos/logs/view/node-2/live')
    expect(view.selection.get()).toEqual(
      selection({ nodeId: 'node-2', stream: null }),
    )
    expect(actions.sent).toHaveLength(2)
  })

  it('asks the server for the level and the substring rather than filtering', () => {
    const { context, actions, address } = viewer(
      '/hilos/logs/view/node-1/live/worker-0.log',
    )
    const view = createHilosLogViewer(context, address)
    view.setFollow(false)
    view.start()

    view.setLevel('ERROR')
    view.editSubstring('deadlock')
    view.applySubstring()

    expect(actions.lastRead().level).toBe('ERROR')
    expect(actions.lastRead().substring).toBe('deadlock')
    // Every change reads from the tail: a byte offset into the previous answer
    // means nothing in a differently filtered one.
    expect(actions.lastRead().cursor).toBeNull()
  })

  it('asks the server nothing while the substring is only typed', () => {
    const { context, actions, address } = viewer(
      '/hilos/logs/view/node-1/live/worker-0.log',
    )
    const view = createHilosLogViewer(context, address)
    view.setFollow(false)
    view.start()
    const asked = actions.sent.length

    view.editSubstring('deadlock')

    expect(actions.sent).toHaveLength(asked)
    expect(view.substring.get()).toBe('')
    expect(view.substringDraft.get()).toBe('deadlock')
  })

  it('reads the unfiltered lines again when a cleared field is applied', () => {
    const { context, actions, address } = viewer(
      '/hilos/logs/view/node-1/live/worker-0.log',
    )
    const view = createHilosLogViewer(context, address)
    view.setFollow(false)
    view.start()
    view.editSubstring('deadlock')
    view.applySubstring()

    view.editSubstring('')
    view.applySubstring()

    expect(actions.lastRead().substring).toBeNull()
    expect(actions.lastRead().cursor).toBeNull()
  })

  it('says the field is unapplied from the typing until the texts agree again', () => {
    const { context, address } = viewer(
      '/hilos/logs/view/node-1/live/worker-0.log',
    )
    const view = createHilosLogViewer(context, address)
    view.setFollow(false)
    view.start()
    expect(view.substringDirty.get()).toBe(false)

    view.editSubstring('deadlock')
    expect(view.substringDirty.get()).toBe(true)

    view.applySubstring()
    expect(view.substringDirty.get()).toBe(false)

    view.editSubstring('dead')
    expect(view.substringDirty.get()).toBe(true)

    // Typed back to what was applied: nothing was pressed, and nothing is stale.
    view.editSubstring('deadlock')
    expect(view.substringDirty.get()).toBe(false)
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

  it('receives the first line of a missing live file and drops frames after leaving it', async () => {
    const { view, actions, address, connection, followId } = following(
      '/hilos/logs/view/node-1/live/agent-chat.log',
    )
    connection.sendCatalog(catalog({ nodes: [] }))
    expect(view.busy.get()).toBe(true)
    actions.answer(followId, {
      readable: false,
      lines: [],
      nextCursor: null,
      hasMore: false,
    })
    await Promise.resolve()
    expect(view.busy.get()).toBe(false)
    expect(view.readable.get()).toBe(false)
    expect(view.following.get()).toBe(true)
    expect(view.followRequested.get()).toBe(true)

    connection.sendAppended({ followId, lines: [wireLine('First entry')] })

    expect(asEntry(view.rows.get()[0]).text).toBe('First entry')
    expect(
      logViewerPaneState(
        view.catalog.get(),
        view.selection.get(),
        view.rows.get().length,
        view.readable.get(),
        false,
      ),
    ).toBe('lines')
    expect(actions.sent).toHaveLength(1)
    expect(address.written).toEqual([])
    expect(view.selection.get()).toEqual(
      selection({ stream: 'agent-chat.log' }),
    )

    view.select({ nodeId: 'node-2' })
    connection.sendAppended({ followId, lines: [wireLine('Too late')] })
    expect(view.rows.get()).toEqual([])
    expect(actions.sent).toHaveLength(1)
    expect(connection.sent).toEqual([
      { action: LOGS_FOLLOW_STOP_ACTION, data: { nodeId: 'node-1' } },
    ])
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
      lines: [wireLine('[2027-01-15T03:12:19.907Z] [INFO] Ready')],
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
      lines: [wireLine('[2027-01-15T03:12:19.907Z] ERROR: Deadlock', 'ERROR')],
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
      lines: [wireLine('[2027-01-15T03:12:19.907Z] INFO: Ready')],
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

describe('reactive log viewer clocks', () => {
  beforeEach(() => {
    vi.useFakeTimers()
  })
  afterEach(() => {
    vi.useRealTimers()
  })

  it('repaints the visible stack without disturbing its follow or buffered lines', async () => {
    const zone = createSignal('UTC')
    const zoneGet = vi
      .spyOn(hilosTimeZone, 'get')
      .mockImplementation(() => zone.get())
    const { context, connection, actions, address } = viewer(
      '/hilos/logs/view/node-1/live/worker-0.log',
    )
    const view = createHilosLogViewer(context, address)
    let rowUpdates = 0
    const stopRows = subscribeSignal(view.rows, () => {
      rowUpdates += 1
    })

    try {
      view.start()
      const followId = actions.sent[0]?.requestId ?? ''
      const entry = wireLine(
        '[2026-01-01T00:30:05.123Z] ERROR: Deadlock',
        'ERROR',
      )
      actions.answer(followId, {
        readable: true,
        lines: [entry],
        nextCursor: null,
        hasMore: false,
      })
      await Promise.resolve()
      connection.sendAppended({
        followId,
        lines: [
          wireLine(
            '[2026-01-01T00:30:06.234Z] [INFO] #0 initial',
            'INFO',
            true,
          ),
        ],
      })
      view.setPinned(false)
      connection.sendAppended({
        followId,
        lines: [
          wireLine(
            '[2026-01-01T00:30:07.345Z] [INFO] #1 buffered',
            'INFO',
            true,
          ),
        ],
      })

      const before = asEntry(view.rows.get()[0])
      expect(before.time).toBe('00:30:05.123')
      expect(before.key).toBe('1:0')
      expect(before.frames).toEqual([
        { time: '00:30:06.234', level: 'INFO', text: '#0 initial' },
      ])
      expect(view.pendingLines.get()).toBe(1)
      expect(view.followRequested.get()).toBe(true)
      expect(view.following.get()).toBe(true)
      expect(view.pinned.get()).toBe(false)

      const selectionBefore = view.selection.get()
      const actionsBefore = actions.sent.length
      const addressBefore = [...address.written]
      const connectionActionsBefore = [...connection.sent]
      rowUpdates = 0
      zone.set('America/Los_Angeles')
      await Promise.resolve()

      const repainted = asEntry(view.rows.get()[0])
      expect(rowUpdates).toBeGreaterThan(0)
      expect(repainted).toMatchObject({
        kind: 'entry',
        key: '1:0',
        time: '16:30:05.123',
        text: 'Deadlock',
      })
      expect(repainted.frames).toEqual([
        { time: '16:30:06.234', level: 'INFO', text: '#0 initial' },
      ])
      expect(view.selection.get()).toEqual(selectionBefore)
      expect(view.pendingLines.get()).toBe(1)
      expect(view.followRequested.get()).toBe(true)
      expect(view.following.get()).toBe(true)
      expect(view.pinned.get()).toBe(false)
      expect(actions.sent).toHaveLength(actionsBefore)
      expect(address.written).toEqual(addressBefore)
      expect(connection.sent).toEqual(connectionActionsBefore)
      expect(entry.time).toBe('2026-01-01T00:30:05.123Z')

      view.returnToTail()
      expect(view.pendingLines.get()).toBe(0)
      expect(view.pinned.get()).toBe(true)
      expect(asEntry(view.rows.get()[0])).toMatchObject({
        key: '1:0',
        time: '16:30:05.123',
        frames: [
          { time: '16:30:06.234', text: '#0 initial' },
          { time: '16:30:07.345', text: '#1 buffered' },
        ],
      })
      expect(actions.sent).toHaveLength(actionsBefore)

      const updatesBeforeDispose = rowUpdates
      view.dispose()
      zone.set('UTC')
      await Promise.resolve()

      expect(rowUpdates).toBe(updatesBeforeDispose)
      expect(asEntry(view.rows.get()[0]).time).toBe('16:30:05.123')
    } finally {
      view.dispose()
      stopRows()
      zoneGet.mockRestore()
    }
  })

  it('keeps an anchored entry and its older-page cursor through a zone change', async () => {
    const zone = createSignal('UTC')
    const zoneGet = vi
      .spyOn(hilosTimeZone, 'get')
      .mockImplementation(() => zone.get())
    const { context, actions, address } = viewer(
      '/hilos/logs/view/node-1/live/worker-0.log/1788000000250',
    )
    const view = createHilosLogViewer(context, address)

    try {
      view.start()
      actions.answer(actions.sent[0]?.requestId, {
        readable: true,
        lines: [
          wireLine('[2026-09-06T10:00:02.250Z] ERROR: anchored entry', 'ERROR'),
        ],
        nextCursor: 4096,
        hasMore: true,
        anchorFound: true,
      })
      await Promise.resolve()

      const original = asEntry(view.rows.get()[0])
      expect(original).toMatchObject({
        key: '1:0',
        time: '10:00:02.250',
        anchored: true,
      })
      expect(view.anchorClock.get()).toBe('10:00:02.250')
      expect(view.entryCountLabel.get()).toBe(
        'Lines around the entry of 10:00:02.250',
      )
      const selectionBefore = view.selection.get()
      const addressBefore = [...address.written]
      const actionsBefore = actions.sent.length
      zone.set('America/Los_Angeles')
      await Promise.resolve()

      expect(asEntry(view.rows.get()[0])).toMatchObject({
        key: '1:0',
        time: '03:00:02.250',
        anchored: true,
      })
      expect(view.anchorClock.get()).toBe('03:00:02.250')
      expect(view.entryCountLabel.get()).toBe(
        'Lines around the entry of 03:00:02.250',
      )
      expect(view.selection.get()).toEqual(selectionBefore)
      expect(view.selection.get().anchorAtMs).toBe(1788000000250)
      expect(view.followRequested.get()).toBe(false)
      expect(view.following.get()).toBe(false)
      expect(view.pinned.get()).toBe(false)
      expect(address.written).toEqual(addressBefore)
      expect(actions.sent).toHaveLength(actionsBefore)

      view.readOlder()

      expect(actions.sent).toHaveLength(actionsBefore + 1)
      expect(actions.lastRead()).toMatchObject({ cursor: 4096 })
      expect(actions.lastRead()).not.toHaveProperty('anchorAtMs')
      expect(view.anchorClock.get()).toBe('03:00:02.250')
      actions.answer(actions.sent.at(-1)?.requestId, {
        readable: true,
        lines: [wireLine('[2026-09-06T09:00:00.000Z] INFO: older entry')],
        nextCursor: 2048,
        hasMore: true,
      })
      await Promise.resolve()

      expect(view.rows.get()).toHaveLength(2)
      expect(view.anchorClock.get()).toBe('03:00:02.250')
      expect(view.entryCountLabel.get()).toBe(
        'Lines around the entry of 03:00:02.250',
      )
      view.editSubstring('draft only')
      expect(actions.sent).toHaveLength(actionsBefore + 1)
      expect(view.anchorClock.get()).toBe('03:00:02.250')
      expect(view.entryCountLabel.get()).toBe(
        'Lines around the entry of 03:00:02.250',
      )
    } finally {
      view.dispose()
      zoneGet.mockRestore()
    }
  })
})

describe('createHilosLogViewer opened on an entry', () => {
  /** A row of the recent-failures panel leads here: a live file and the moment of one entry in it. */
  const ANCHORED = '/hilos/logs/view/node-1/live/worker-0.log/1788000000250'

  beforeEach(() => {
    vi.useFakeTimers()
    vi.spyOn(hilosTimeZone, 'get').mockReturnValue('UTC')
  })
  afterEach(() => {
    vi.restoreAllMocks()
    vi.useRealTimers()
  })

  it('asks for the page starting on the entry, and does not start the tail', () => {
    const { context, actions, address } = viewer(ANCHORED)
    const view = createHilosLogViewer(context, address)

    view.start()

    expect(actions.sent).toHaveLength(1)
    expect(actions.sent[0].action).toBe(LOGS_READ_LINES_ACTION)
    expect(actions.lastRead()).toMatchObject({
      stream: 'worker-0.log',
      cursor: null,
      anchorAtMs: 1788000000250,
    })
    // A raised switch over a tail that is not running would lie in the most
    // visible place on the screen, and the pane must not be pulled to the end.
    expect(view.followRequested.get()).toBe(false)
    expect(view.following.get()).toBe(false)
    expect(view.pinned.get()).toBe(false)
    expect(view.anchorClock.get()).toBeNull()
    expect(view.entryCountLabel.get()).toBe('0 entries shown')
  })

  it('marks the entry the page starts on, and no other', async () => {
    const { context, actions, address } = viewer(ANCHORED)
    const view = createHilosLogViewer(context, address)
    view.start()

    actions.answer(actions.sent.at(-1)?.requestId, {
      readable: true,
      lines: [
        wireLine('[2026-09-06T10:00:02.250Z] ERROR: the entry the row named'),
        wireLine('[2026-09-06T10:00:03.000Z] INFO: what happened next'),
      ],
      nextCursor: 4096,
      hasMore: true,
      anchorFound: true,
    })
    await Promise.resolve()

    expect(
      view.rows.get().map((row) => row.kind === 'entry' && row.anchored),
    ).toEqual([true, false])
    expect(view.anchorClock.get()).toBe('10:00:02.250')
    expect(view.entryCountLabel.get()).toBe(
      'Lines around the entry of 10:00:02.250',
    )
  })

  it('says so above the tail when the entry is not in the file any more', async () => {
    const { context, actions, address } = viewer(ANCHORED)
    const view = createHilosLogViewer(context, address)
    view.start()

    actions.answer(actions.sent.at(-1)?.requestId, {
      readable: true,
      lines: [wireLine('[2026-09-06T11:00:00.000Z] INFO: the end of the file')],
      nextCursor: null,
      hasMore: false,
      anchorFound: false,
    })
    await Promise.resolve()

    const rows = view.rows.get()
    expect(rows[0]).toMatchObject({ kind: 'notice', notice: 'anchorMissing' })
    expect(
      rows.slice(1).map((row) => row.kind === 'entry' && row.anchored),
    ).toEqual([false])
    expect(view.anchorClock.get()).toBeNull()
    expect(view.entryCountLabel.get()).toBe('1 entries shown')
    expect(view.selection.get().anchorAtMs).toBe(1788000000250)
    expect(view.followRequested.get()).toBe(false)
    expect(actions.sent).toHaveLength(1)
    expect(address.written).toEqual([])
  })

  it.each([
    { readable: false, anchorFound: true, time: '2026-09-06T10:00:02.250Z' },
    { readable: true, anchorFound: false, time: '2026-09-06T10:00:02.250Z' },
    { readable: true, anchorFound: null, time: '2026-09-06T10:00:02.250Z' },
    {
      readable: true,
      anchorFound: undefined,
      time: '2026-09-06T10:00:02.250Z',
    },
    { readable: true, anchorFound: true, time: null },
    { readable: true, anchorFound: true, time: 'invalid' },
    { readable: true, anchorFound: true, time: '2026-02-30T10:00:02.250Z' },
  ])(
    'does not confirm an entry from $readable / $anchorFound / $time',
    async (answer) => {
      const { context, actions, address } = viewer(ANCHORED)
      const view = createHilosLogViewer(context, address)
      view.start()
      actions.answer(actions.sent.at(-1)?.requestId, {
        readable: answer.readable,
        lines: [{ ...wireLine('entry'), time: answer.time }],
        nextCursor: null,
        hasMore: false,
        ...(answer.anchorFound === undefined
          ? {}
          : { anchorFound: answer.anchorFound }),
      })
      await Promise.resolve()

      expect(view.anchorClock.get()).toBeNull()
      expect(view.entryCountLabel.get()).toBe('1 entries shown')
    },
  )

  it('does not confirm an empty anchored answer', async () => {
    const { context, actions, address } = viewer(ANCHORED)
    const view = createHilosLogViewer(context, address)
    view.start()
    actions.answer(actions.sent.at(-1)?.requestId, {
      readable: true,
      lines: [],
      nextCursor: null,
      hasMore: false,
      anchorFound: true,
    })
    await Promise.resolve()

    expect(view.anchorClock.get()).toBeNull()
    expect(view.entryCountLabel.get()).toBe('0 entries shown')
  })

  it.each([
    '/hilos/logs/view/node-2/live/daemon.log/1788000000500',
    '/hilos/logs/view/node-2/live',
    '/hilos/logs/view/-/live/worker-0.log/1788000000500',
  ])(
    'clears confirmation on navigation to %s and rejects the old completion',
    async (destination) => {
      const { context, connection, actions, address } = viewer(ANCHORED)
      const view = createHilosLogViewer(context, address)
      view.start()
      const reply = {
        readable: true,
        lines: [wireLine('[2026-09-06T10:00:02.250Z] ERROR: found entry')],
        nextCursor: 4096,
        hasMore: true,
        anchorFound: true,
      }
      actions.answer(actions.sent.at(-1)?.requestId, reply)
      await Promise.resolve()
      expect(view.anchorClock.get()).toBe('10:00:02.250')

      connection.reconnect()
      const oldRead = actions.sent.at(-1)?.requestId
      address.navigate(destination)
      actions.answer(oldRead, reply)
      await Promise.resolve()
      expect(view.anchorClock.get()).toBeNull()

      address.navigate(ANCHORED)
      expect(view.anchorClock.get()).toBeNull()
      actions.answer(actions.sent.at(-1)?.requestId, reply)
      await Promise.resolve()
      expect(view.anchorClock.get()).toBe('10:00:02.250')
    },
  )

  it('retains confirmation when pagination trims the anchored line out of the feed', async () => {
    const { context, actions, address } = viewer(ANCHORED)
    const view = createHilosLogViewer(context, address)
    view.start()
    actions.answer(actions.sent.at(-1)?.requestId, {
      readable: true,
      lines: [
        wireLine('[2026-09-06T10:00:02.250Z] ERROR: found entry'),
        ...Array.from({ length: FEED_MAX_LINES }, () =>
          wireLine('later entry'),
        ),
      ],
      nextCursor: 4096,
      hasMore: true,
      anchorFound: true,
    })
    await Promise.resolve()

    expect(view.rows.get()).toHaveLength(FEED_MAX_LINES)
    expect(
      view.rows.get().some((row) => row.kind === 'entry' && row.anchored),
    ).toBe(false)
    expect(view.anchorClock.get()).toBe('10:00:02.250')
    view.readOlder()
    actions.answer(actions.sent.at(-1)?.requestId, {
      readable: true,
      lines: [wireLine('older entry')],
      nextCursor: 2048,
      hasMore: true,
    })
    await Promise.resolve()
    expect(view.anchorClock.get()).toBe('10:00:02.250')
    expect(view.entryCountLabel.get()).toBe(
      'Lines around the entry of 10:00:02.250',
    )
  })

  it('waits for a new confirmation after each reconnect and ignores a superseded read', async () => {
    const { context, connection, actions, address } = viewer(ANCHORED)
    const view = createHilosLogViewer(context, address)
    view.start()
    const reply = {
      readable: true,
      lines: [wireLine('[2026-09-06T10:00:02.250Z] ERROR: found entry')],
      nextCursor: 4096,
      hasMore: true,
      anchorFound: true,
    }
    actions.answer(actions.sent.at(-1)?.requestId, reply)
    await Promise.resolve()
    expect(view.anchorClock.get()).toBe('10:00:02.250')

    connection.reconnect()
    const superseded = actions.sent.at(-1)?.requestId
    expect(view.anchorClock.get()).toBeNull()
    expect(view.entryCountLabel.get()).toBe('1 entries shown')

    connection.reconnect()
    actions.answer(superseded, reply)
    await Promise.resolve()
    expect(view.anchorClock.get()).toBeNull()
    expect(view.entryCountLabel.get()).toBe('1 entries shown')

    actions.answer(actions.sent.at(-1)?.requestId, reply)
    await Promise.resolve()
    expect(view.anchorClock.get()).toBe('10:00:02.250')
    expect(view.entryCountLabel.get()).toBe(
      'Lines around the entry of 10:00:02.250',
    )

    expect(actions.lastRead()).toMatchObject({ anchorAtMs: 1788000000250 })
    expect(address.written).toEqual([])
  })

  it('drops the anchor from the address and from the read on whatever the operator does next', async () => {
    const moves: ((view: HilosLogViewer) => void)[] = [
      (view) => view.setLevel('ERROR'),
      (view) => {
        view.editSubstring('deadlock')
        view.applySubstring()
      },
      (view) => view.select({ stream: 'daemon.log' }),
      (view) => view.setFollow(true),
      (view) => view.returnToTail(),
    ]
    for (const move of moves) {
      const { context, actions, address } = viewer(ANCHORED)
      const view = createHilosLogViewer(context, address)
      view.start()
      actions.answer(actions.sent.at(-1)?.requestId, {
        readable: true,
        lines: [wireLine('[2026-09-06T10:00:02.250Z] ERROR: found entry')],
        nextCursor: 4096,
        hasMore: true,
        anchorFound: true,
      })
      await Promise.resolve()
      expect(view.anchorClock.get()).toBe('10:00:02.250')

      move(view)

      expect(view.anchorClock.get()).toBeNull()
      expect(view.entryCountLabel.get()).toBe('1 entries shown')
      expect(address.written.at(-1)).not.toContain('1788000000250')
      expect(view.selection.get().anchorAtMs).toBeNull()
      expect(actions.lastRead()).not.toHaveProperty('anchorAtMs')
    }
  })

  it('raises the switch and reads the tail when the operator goes back to it', () => {
    const { context, actions, address } = viewer(ANCHORED)
    const view = createHilosLogViewer(context, address)
    view.start()

    view.returnToTail()

    expect(view.followRequested.get()).toBe(true)
    expect(view.pinned.get()).toBe(true)
    expect(actions.sent.at(-1)?.action).toBe(LOGS_FOLLOW_START_ACTION)
  })
})
