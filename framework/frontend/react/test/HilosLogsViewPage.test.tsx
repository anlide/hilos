import { afterEach, describe, expect, it } from 'vitest'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import {
  ActionLifecycle,
  HilosPages,
  LOG_LINES_APPENDED_SIGNAL,
  LOG_VIEWER_CATALOG_SIGNAL,
  LOGS_FOLLOW_START_ACTION,
  createSignal,
} from '@hilos/core'
import type {
  HilosConnection,
  HilosLogViewerCatalog,
  HilosRouter,
  PageRouteMatch,
} from '@hilos/core'

import { HilosLogsViewPage } from '../src/admin/logs/HilosLogsViewPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

/** Any fixed batch, so a timestamp in a fixture means something to read. */
const BATCH = 1800000000

/** A catalog as the page answers a subscription with it. */
function catalog(
  overrides: Partial<HilosLogViewerCatalog> = {},
): HilosLogViewerCatalog {
  return {
    available: true,
    nodes: [
      {
        nodeId: '',
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
    ],
    ...overrides,
  }
}

/** A cluster catalog: two named nodes, which is what puts the node select on. */
function clusterCatalog(): HilosLogViewerCatalog {
  return {
    available: true,
    nodes: [
      {
        nodeId: 'node-1',
        available: true,
        batches: [],
        streams: [
          {
            key: 'worker-0.log',
            class: 'worker',
            live: true,
            batchTimestamps: [],
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
  }
}

function router(params: Record<string, string> = {}): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.LOGS_VIEW,
      params,
      admin: true,
    }),
    currentPath: createSignal(''),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal(undefined),
    dashboardSections: createSignal(undefined),
    resolvePath: () => undefined,
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
}

/** One follow frame as the owner of the file pushes it. */
interface AppendedFrame {
  followId: string
  lines: { text: string; level: string; isContinuation: boolean }[]
  rotated: boolean
  skippedBytes: number | null
  stopped: boolean
}

/**
 * A connection stub handing back the frames this screen lives on — the page's
 * own catalog and the frames of a follow — and recording the actions it is sent,
 * so a test can answer one the way the owner of the file does.
 */
function makeConnection(): {
  connection: HilosConnection
  sent: { action: string; data: unknown; requestId?: string }[]
  pushCatalog: (frame: HilosLogViewerCatalog) => void
  pushAppended: (frame: Partial<AppendedFrame> & { followId: string }) => void
  answer: (requestId: string | undefined, reply: unknown) => void
} {
  const listeners: Record<string, ((signal: never) => void)[]> = {}
  const sent: { action: string; data: unknown; requestId?: string }[] = []
  const emit = (event: string, signal: unknown): void => {
    act(() => {
      for (const listener of listeners[event] ?? []) {
        ;(listener as unknown as (payload: unknown) => void)(signal)
      }
    })
  }
  const connection = {
    on(event: string, listener: (signal: never) => void): () => void {
      listeners[event] = [...(listeners[event] ?? []), listener]

      return () => {}
    },
    sendAction(action: string, data: unknown, requestId?: string): boolean {
      sent.push({ action, data, requestId })

      return true
    },
  } as unknown as HilosConnection

  return {
    connection,
    sent,
    pushCatalog(frame: HilosLogViewerCatalog): void {
      emit('projectSignal', { type: LOG_VIEWER_CATALOG_SIGNAL, data: frame })
    },
    pushAppended(frame: Partial<AppendedFrame> & { followId: string }): void {
      emit('projectSignal', {
        type: LOG_LINES_APPENDED_SIGNAL,
        data: {
          lines: [],
          rotated: false,
          skippedBytes: null,
          stopped: false,
          ...frame,
        },
      })
    },
    answer(requestId: string | undefined, reply: unknown): void {
      emit('actionSuccess', {
        kind: 'actionSuccess',
        action: LOGS_FOLLOW_START_ACTION,
        message: undefined,
        reply,
        requestId,
        envelope: { type: 'action_success', data: {} },
      })
    },
  }
}

/** The address of the live file every follow test opens. */
const LIVE_FILE = { nodeId: '-', source: 'live', stream: 'worker-0.log' }

/** One line as it comes off the wire. */
function wireLine(text: string, level = 'INFO', isContinuation = false) {
  return { text, level, isContinuation }
}

function mountPage(
  connection: HilosConnection,
  params: Record<string, string> = {},
): HTMLElement {
  return render(
    <HilosRouterContext.Provider value={router(params)}>
      <HilosLogsViewPage
        context={{
          connection,
          actions: new ActionLifecycle(
            connection as unknown as ConstructorParameters<
              typeof ActionLifecycle
            >[0],
          ),
        }}
      />
    </HilosRouterContext.Provider>,
  ).container
}

function byId(container: HTMLElement, id: string): HTMLElement | null {
  return container.querySelector(`[data-id="${id}"]`)
}

function optionValues(select: HTMLElement | null): string[] {
  return Array.from(select?.querySelectorAll('option') ?? []).map(
    (option) => option.value,
  )
}

/** Wait out the microtasks a settled action resolves through. */
async function settled(): Promise<void> {
  await act(async () => {
    await Promise.resolve()
  })
}

/**
 * Puts the reader above the tail: jsdom lays nothing out, so the pane is told how
 * tall it is and then scrolled the way a hand would.
 *
 * @param pane The scrolling pane element.
 */
function scrollUp(pane: Element): void {
  Object.defineProperty(pane, 'scrollHeight', {
    value: 1000,
    configurable: true,
  })
  Object.defineProperty(pane, 'clientHeight', {
    value: 200,
    configurable: true,
  })
  pane.scrollTop = 0
  fireEvent.scroll(pane)
}

describe('HilosLogsViewPage', () => {
  afterEach(cleanup)

  it('waits rather than reporting a fault before any catalog arrives', () => {
    const { connection } = makeConnection()
    const container = mountPage(connection)

    expect(byId(container, 'hilos-log-empty-unknown')).not.toBeNull()
  })

  it('reports the fault once the picture says no node could be read', () => {
    const { connection, pushCatalog } = makeConnection()
    const container = mountPage(connection)

    pushCatalog(catalog({ available: false, nodes: [] }))

    expect(byId(container, 'hilos-log-empty-unreadable')).not.toBeNull()
  })

  it('asks for a stream rather than guessing one', () => {
    // A guessed stream would open the wrong file and look exactly like an answer.
    const { connection, pushCatalog } = makeConnection()
    const container = mountPage(connection)

    pushCatalog(catalog())

    expect(byId(container, 'hilos-log-empty-unchosen')).not.toBeNull()
  })

  it('drops the node select in an installation with no cluster', () => {
    const { connection, pushCatalog } = makeConnection()
    const container = mountPage(connection)

    pushCatalog(catalog())

    expect(byId(container, 'hilos-log-node')).toBeNull()
  })

  it('offers the node select where the nodes have names', () => {
    const { connection, pushCatalog } = makeConnection()
    const container = mountPage(connection)

    pushCatalog(clusterCatalog())

    expect(byId(container, 'hilos-log-node')).not.toBeNull()
  })

  it('offers the streams of the chosen source only', () => {
    // The archived batch holds both files; the live journal holds one of them.
    const { connection, pushCatalog } = makeConnection()
    const container = mountPage(connection, {
      nodeId: '-',
      source: 'live',
      stream: 'worker-0.log',
    })

    pushCatalog(catalog())

    expect(optionValues(byId(container, 'hilos-log-stream'))).toEqual([
      '',
      'worker-0.log',
    ])

    fireEvent.change(byId(container, 'hilos-log-source') as HTMLSelectElement, {
      target: { value: String(BATCH) },
    })

    expect(optionValues(byId(container, 'hilos-log-stream'))).toEqual([
      '',
      'worker-0.log',
      'agent-chat.log',
    ])
  })

  it('drops the stream when the new source does not hold it', () => {
    const { connection, pushCatalog } = makeConnection()
    const container = mountPage(connection, {
      nodeId: '-',
      source: String(BATCH),
      stream: 'agent-chat.log',
    })
    pushCatalog(catalog())

    fireEvent.change(byId(container, 'hilos-log-source') as HTMLSelectElement, {
      target: { value: 'live' },
    })

    // The file is not in the live journal, so it stops being chosen rather than
    // staying selected and naming nothing.
    expect(byId(container, 'hilos-log-empty-unchosen')).not.toBeNull()
  })

  it('keeps the Earlier button dark until there is a page before this one', () => {
    const { connection, pushCatalog } = makeConnection()
    const container = mountPage(connection)

    pushCatalog(catalog())

    expect(
      (byId(container, 'hilos-log-earlier') as HTMLButtonElement).disabled,
    ).toBe(true)
  })

  it('greys the Follow switch out on an archived batch and says why', () => {
    // Greyed out says "not applicable here", not "you turned it off": the switch
    // keeps its own position, so coming back to the live journal resumes the tail
    // without a click nobody made.
    const { connection, pushCatalog } = makeConnection()
    const container = mountPage(connection, {
      ...LIVE_FILE,
      source: String(BATCH),
    })

    pushCatalog(catalog())

    const control = byId(container, 'hilos-log-follow') as HTMLInputElement
    expect(control.disabled).toBe(true)
    expect(control.checked).toBe(true)
    expect(byId(container, 'hilos-log-follow-off')?.textContent).toBe(
      'An archived batch has no tail.',
    )
  })

  it('lights the tail badge once the start is answered', async () => {
    const { connection, pushCatalog, sent, answer } = makeConnection()
    const container = mountPage(connection, LIVE_FILE)
    pushCatalog(catalog())

    expect(byId(container, 'hilos-log-tail-badge')).toBeNull()

    answer(sent.at(-1)?.requestId, {
      readable: true,
      lines: [],
      nextCursor: null,
      hasMore: false,
    })
    await settled()

    expect(byId(container, 'hilos-log-tail-badge')?.textContent).toBe(
      'Tail is running',
    )
  })

  it('offers the way back with a count while the reader is above the tail', () => {
    const { connection, pushCatalog, pushAppended, sent } = makeConnection()
    const container = mountPage(connection, LIVE_FILE)
    pushCatalog(catalog())
    const followId = sent.at(-1)?.requestId ?? ''

    expect(byId(container, 'hilos-log-back-to-tail')).toBeNull()

    scrollUp(byId(container, 'hilos-log-pane') as Element)
    pushAppended({ followId, lines: [wireLine('one'), wireLine('two')] })

    expect(byId(container, 'hilos-log-back-to-tail')?.textContent).toBe(
      'Back to the tail · 2 new',
    )
    // The pane under the reader's eyes does not move at all while they are up.
    expect(
      container.querySelectorAll('[data-id="hilos-log-entry"]'),
    ).toHaveLength(0)

    fireEvent.click(byId(container, 'hilos-log-back-to-tail') as HTMLElement)

    expect(
      container.querySelectorAll('[data-id="hilos-log-entry"]'),
    ).toHaveLength(2)
    expect(byId(container, 'hilos-log-back-to-tail')).toBeNull()
  })

  it('draws a note as a row of the feed, in the reading where it happened', () => {
    const { connection, pushCatalog, pushAppended, sent } = makeConnection()
    const container = mountPage(connection, LIVE_FILE)
    pushCatalog(catalog())

    pushAppended({ followId: sent.at(-1)?.requestId ?? '', rotated: true })

    expect(byId(container, 'hilos-log-notice')?.textContent).toBe(
      'The file was rotated. Reading continues from the start of the new one.',
    )
  })
})
