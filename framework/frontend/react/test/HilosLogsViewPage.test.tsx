import { afterEach, describe, expect, it, vi } from 'vitest'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import {
  ActionLifecycle,
  HILOS_ROUTE_DECLARATIONS,
  HIDDEN_VALUE,
  ScopeManager,
  bindAdminAccess,
  bindSessionScope,
  HilosPages,
  LOG_LINES_APPENDED_SIGNAL,
  LOG_VIEWER_CATALOG_SIGNAL,
  LOGS_FOLLOW_START_ACTION,
  LOGS_READ_LINES_ACTION,
  createPageRouter,
  createSignal,
} from '@hilos/core'
import type {
  Hideable,
  HilosConnection,
  HilosLogViewerCatalog,
  HilosRouter,
  ProjectSignal,
} from '@hilos/core'

import { HilosLogsViewPage } from '../src/admin/logs/HilosLogsViewPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

/** Any fixed batch, so a timestamp in a fixture means something to read. */
const BATCH = 1800000000

const LATEST_BATCH = BATCH + 200

const releases: (() => void)[] = []

afterEach(() => {
  for (const release of releases.splice(0)) release()
})

/** Bind the session and admin-access signal the application boot binds. */
function bindViewerSession(): void {
  const listeners: ((signal: ProjectSignal) => void)[] = []
  const connection = {
    on(event: string, listener: (signal: never) => void): () => void {
      if (event === 'projectSignal')
        listeners.push(listener as (signal: ProjectSignal) => void)
      return () => {}
    },
  } as unknown as HilosConnection
  const scopes = new ScopeManager()
  bindSessionScope(connection, scopes)
  releases.push(bindAdminAccess(scopes))
  const handshake = {
    kind: 'project',
    type: 'handshake_response',
    data: { entities: { currentUser: null }, data: { adminViewMode: true } },
    envelope: {},
  } as unknown as ProjectSignal
  for (const listener of listeners) listener(handshake)
}

/** A catalog as the page answers a subscription with it. */
function catalog(
  overrides: Partial<HilosLogViewerCatalog> = {},
): HilosLogViewerCatalog {
  return {
    available: true,
    clusterEnabled: false,
    nodes: [
      {
        nodeId: 'standalone',
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
    clusterEnabled: true,
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

/** A named node with an archived stream and an unsorted batch catalog. */
function archivedStreamCatalog(): HilosLogViewerCatalog {
  const picture = catalog({ clusterEnabled: true })
  const node = picture.nodes[0]
  node.nodeId = 'node-2'
  node.batches = [LATEST_BATCH, BATCH]
  node.streams[1].batchTimestamps = [BATCH, LATEST_BATCH + 100, LATEST_BATCH]
  picture.nodes.push(clusterCatalog().nodes[0])

  return picture
}

function router(params: Record<string, string> = {}): HilosRouter {
  const routes = createPageRouter(HILOS_ROUTE_DECLARATIONS)
  const path = routes.path(HilosPages.LOGS_VIEW, params) ?? ''
  const currentRoute = createSignal(routes.match(path))
  const currentPath = createSignal(path)
  const publish = (nextPath: string): void => {
    currentRoute.set(routes.match(nextPath))
    currentPath.set(nextPath)
  }

  return {
    currentRoute,
    currentPath,
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal(undefined),
    dashboardSections: createSignal(undefined),
    resolvePath: () => undefined,
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    onLeave: () => () => {},
    navigate: publish,
    replacePath: publish,
    start: () => {},
    stop: () => {},
  }
}

/** One follow frame as the owner of the file pushes it. */
interface AppendedFrame {
  followId: string
  lines: {
    time: string | null
    text: Hideable<string>
    level: string
    isContinuation: boolean
  }[]
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
  refuse: (requestId: string | undefined, reason: string) => void
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
    refuse(requestId: string | undefined, reason: string): void {
      emit('actionError', {
        kind: 'actionError',
        action: sent.find((frame) => frame.requestId === requestId)?.action,
        reason,
        requestId,
        envelope: { type: 'action_error', data: {} },
      })
    },
    answer(requestId: string | undefined, reply: unknown): void {
      emit('actionSuccess', {
        kind: 'actionSuccess',
        action: sent.find((frame) => frame.requestId === requestId)?.action,
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

/** A full live address whose stream initially exists only in the archive. */
const ARCHIVED_LIVE_FILE = {
  nodeId: 'node-2',
  source: 'live',
  stream: 'agent-chat.log',
}

/** One line as it comes off the wire. */
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

function mountPage(
  connection: HilosConnection,
  params: Record<string, string> = {},
  navigator: HilosRouter = router(params),
): HTMLElement {
  return render(
    <HilosRouterContext.Provider value={navigator}>
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

/**
 * Asserts the room of the way back to the tail stands in the strip, held by
 * exactly one invisible twin hidden from assistive technology, and returns it.
 *
 * @param container The mounted page.
 */
function expectBackToTailRoom(container: HTMLElement): HTMLElement {
  const room = byId(container, 'hilos-log-back-to-tail-room')
  expect(room).not.toBeNull()
  const twins = (room as HTMLElement).querySelectorAll('.invisible')
  expect(twins).toHaveLength(1)
  expect(twins[0].getAttribute('aria-hidden')).toBe('true')

  return room as HTMLElement
}

/**
 * Asserts the room of the line count stands in the strip, held by exactly
 * one invisible twin hidden from assistive technology, with one live status
 * region, and returns it.
 *
 * @param container The mounted page.
 */
function expectCountRoom(container: HTMLElement): HTMLElement {
  const room = byId(container, 'hilos-log-count-room')
  expect(room).not.toBeNull()
  const twins = (room as HTMLElement).querySelectorAll('.invisible')
  expect(twins).toHaveLength(1)
  expect(twins[0].getAttribute('aria-hidden')).toBe('true')
  expect(twins[0].querySelectorAll('[data-id]')).toHaveLength(0)
  const regions = (room as HTMLElement).querySelectorAll('[role="status"]')
  expect(regions).toHaveLength(1)
  expect(regions[0].getAttribute('aria-live')).toBe('polite')

  return room as HTMLElement
}

describe('HilosLogsViewPage', () => {
  it('shows a hidden line with clock and level and disables search for a viewer', async () => {
    bindViewerSession()
    const { connection, pushCatalog, sent, answer } = makeConnection()
    const container = mountPage(connection, LIVE_FILE)
    pushCatalog(catalog())
    answer(sent.at(-1)?.requestId, {
      readable: true,
      lines: [
        {
          time: '2026-09-06 10:00:02.250',
          text: HIDDEN_VALUE,
          level: 'ERROR',
          isContinuation: false,
        },
        {
          time: null,
          text: HIDDEN_VALUE,
          level: 'ERROR',
          isContinuation: true,
        },
      ],
      nextCursor: null,
      hasMore: false,
    })
    await settled()

    const entry = byId(container, 'hilos-log-entry')
    expect(entry?.textContent).toContain('10:00:02.250')
    expect(entry?.textContent).toContain('ERROR')
    expect(entry?.querySelector('[data-id="hilos-hidden"]')).not.toBeNull()
    expect(
      entry?.querySelector('[data-id="hilos-log-stack-toggle"]'),
    ).toBeNull()
    for (const id of ['hilos-log-substring', 'hilos-log-search']) {
      expect(byId(container, id)?.hasAttribute('disabled')).toBe(true)
      expect(byId(container, id)?.getAttribute('aria-describedby')).toBe(
        'hilos-view-mode-strip-text',
      )
    }
    expect(byId(container, 'hilos-log-follow')?.hasAttribute('disabled')).toBe(
      false,
    )
  })

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

  it('keeps the stream when returning to live without its file', () => {
    const { connection, pushCatalog, sent } = makeConnection()
    const container = mountPage(connection, {
      nodeId: '-',
      source: String(BATCH),
      stream: 'agent-chat.log',
    })
    pushCatalog(catalog())

    fireEvent.change(byId(container, 'hilos-log-source') as HTMLSelectElement, {
      target: { value: 'live' },
    })

    // Returning to live keeps the explicit stream and starts its tail even
    // before that live file exists.
    expect(byId(container, 'hilos-log-empty-unchosen')).toBeNull()
    expect(sent.at(-1)?.action).toBe(LOGS_FOLLOW_START_ACTION)
    expect(sent.at(-1)?.data).toEqual({
      nodeId: 'standalone',
      stream: 'agent-chat.log',
      level: null,
      substring: null,
    })
    expect(
      (byId(container, 'hilos-log-stream') as HTMLSelectElement).value,
    ).toBe('agent-chat.log')
  })

  it.each([true, false])(
    'keeps the missing live name selected with known archive=%s',
    async (hasArchive) => {
      const { connection, pushCatalog, sent, answer } = makeConnection()
      const navigator = router(ARCHIVED_LIVE_FILE)
      const navigate = vi.spyOn(navigator, 'navigate')
      const replace = vi.spyOn(navigator, 'replacePath')
      const container = mountPage(connection, {}, navigator)
      const picture = archivedStreamCatalog()
      if (!hasArchive) {
        picture.nodes[0].batches = []
        picture.nodes[0].streams[1].batchTimestamps = []
      }
      pushCatalog(picture)
      answer(sent.at(-1)?.requestId, {
        readable: false,
        lines: [],
        nextCursor: null,
        hasMore: false,
      })
      await settled()

      const stream = byId(container, 'hilos-log-stream') as HTMLSelectElement
      expect(stream.value).toBe('agent-chat.log')
      expect(optionValues(stream)).toEqual([
        '',
        'worker-0.log',
        'agent-chat.log',
      ])
      expect(
        byId(container, 'hilos-log-empty-missing')?.textContent?.trim(),
      ).toBe(
        'This file cannot be read. The rotation may have carried it off, or nothing has written to it yet.' +
          (hasArchive ? ' Open latest batch' : ''),
      )
      expect(byId(container, 'hilos-log-latest-batch') !== null).toBe(
        hasArchive,
      )
      expect(byId(container, 'hilos-log-empty-unchosen')).toBeNull()
      expect(
        (byId(container, 'hilos-log-source') as HTMLSelectElement).value,
      ).toBe('live')
      expect(navigator.currentPath.get()).toBe(
        '/hilos/logs/view/node-2/live/agent-chat.log',
      )
      expect(navigate).not.toHaveBeenCalled()
      expect(replace).not.toHaveBeenCalled()
    },
  )

  it.each([false, true])(
    'opens the latest batch only on a link click (view mode: %s)',
    async (viewMode) => {
      if (viewMode) bindViewerSession()
      const { connection, pushCatalog, sent, answer } = makeConnection()
      const navigator = router(ARCHIVED_LIVE_FILE)
      const navigate = vi.spyOn(navigator, 'navigate')
      const replace = vi.spyOn(navigator, 'replacePath')
      const container = mountPage(connection, {}, navigator)
      const livePath = '/hilos/logs/view/node-2/live/agent-chat.log'
      pushCatalog(archivedStreamCatalog())
      answer(sent.at(-1)?.requestId, {
        readable: false,
        lines: [],
        nextCursor: null,
        hasMore: false,
      })
      await settled()

      expect(
        (byId(container, 'hilos-log-stream') as HTMLSelectElement).value,
      ).toBe('agent-chat.log')
      expect(byId(container, 'hilos-log-empty-missing')).not.toBeNull()
      expect(byId(container, 'hilos-log-empty-unchosen')).toBeNull()
      const link = byId(
        container,
        'hilos-log-latest-batch',
      ) as HTMLAnchorElement
      const batchPath = `/hilos/logs/view/node-2/${LATEST_BATCH}/agent-chat.log`
      expect(link.tagName).toBe('A')
      expect(link.textContent).toBe('Open latest batch')
      expect(link.getAttribute('href')).toBe(batchPath)
      expect(link.getAttribute('aria-disabled')).toBeNull()
      expect(link.tabIndex).toBe(0)
      expect(navigate).not.toHaveBeenCalled()
      expect(replace).not.toHaveBeenCalled()
      expect(navigator.currentPath.get()).toBe(livePath)

      fireEvent.click(link, { button: 0 })
      await settled()

      expect(navigate).toHaveBeenCalledExactlyOnceWith(batchPath)
      expect(navigator.currentPath.get()).toBe(batchPath)
      const source = byId(container, 'hilos-log-source') as HTMLSelectElement
      expect(source.value).toBe(String(LATEST_BATCH))
      expect(source.selectedOptions[0].textContent).toBe(
        `Batch — ${new Date(LATEST_BATCH * 1000).toLocaleString()}`,
      )
      expect(sent.at(-1)?.action).toBe(LOGS_READ_LINES_ACTION)
      expect(sent.at(-1)?.data).toMatchObject({
        nodeId: 'node-2',
        source: 'batch',
        batchTimestamp: LATEST_BATCH,
        stream: 'agent-chat.log',
      })
      expect(
        (byId(container, 'hilos-log-follow') as HTMLInputElement).disabled,
      ).toBe(true)
      expect(
        sent.filter((frame) => frame.action === LOGS_FOLLOW_START_ACTION),
      ).toHaveLength(1)
      expect(byId(container, 'hilos-log-latest-batch')).toBeNull()

      answer(sent.at(-1)?.requestId, {
        readable: true,
        lines: [
          {
            time: null,
            text: viewMode ? HIDDEN_VALUE : 'Archived line.',
            level: 'INFO',
            isContinuation: false,
          },
        ],
        nextCursor: null,
        hasMore: false,
      })
      await settled()
      if (viewMode) {
        expect(byId(container, 'hilos-hidden')).not.toBeNull()
        expect(container.textContent).not.toContain('Archived line.')
        for (const id of ['hilos-log-substring', 'hilos-log-search']) {
          expect(byId(container, id)?.hasAttribute('disabled')).toBe(true)
        }
      } else {
        expect(byId(container, 'hilos-log-entry')?.textContent).toContain(
          'Archived line.',
        )
      }

      fireEvent.change(source, { target: { value: 'live' } })
      expect(
        (byId(container, 'hilos-log-stream') as HTMLSelectElement).value,
      ).toBe('agent-chat.log')
      expect(source.value).toBe('live')
      expect(navigator.currentPath.get()).toBe(livePath)
      expect(sent.at(-1)?.action).toBe(LOGS_FOLLOW_START_ACTION)
      expect(sent.at(-1)?.data).toMatchObject({
        nodeId: 'node-2',
        stream: 'agent-chat.log',
      })
    },
  )

  it('keeps a full unknown stream through a late catalog without guessing a file', async () => {
    const { connection, pushCatalog, sent, answer } = makeConnection()
    const navigator = router({ ...ARCHIVED_LIVE_FILE, stream: 'unknown.log' })
    const navigate = vi.spyOn(navigator, 'navigate')
    const replace = vi.spyOn(navigator, 'replacePath')
    const container = mountPage(connection, {}, navigator)
    const stream = byId(container, 'hilos-log-stream') as HTMLSelectElement
    expect(stream.value).toBe('unknown.log')
    answer(sent.at(-1)?.requestId, {
      readable: false,
      lines: [],
      nextCursor: null,
      hasMore: false,
    })
    await settled()
    expect(byId(container, 'hilos-log-empty-missing')).not.toBeNull()

    pushCatalog(archivedStreamCatalog())

    expect(stream.value).toBe('unknown.log')
    expect(optionValues(stream)).toEqual(['', 'worker-0.log', 'unknown.log'])
    expect(byId(container, 'hilos-log-empty-missing')).not.toBeNull()
    expect(byId(container, 'hilos-log-empty-unchosen')).toBeNull()
    expect(byId(container, 'hilos-log-latest-batch')).toBeNull()
    expect(navigate).not.toHaveBeenCalled()
    expect(replace).not.toHaveBeenCalled()
  })

  it.each([false, true])(
    'restores live lines after a late catalog without duplicate options in view mode=%s',
    async (viewMode) => {
      if (viewMode) bindViewerSession()
      const { connection, pushCatalog, pushAppended, sent, answer } =
        makeConnection()
      const navigator = router(ARCHIVED_LIVE_FILE)
      const navigate = vi.spyOn(navigator, 'navigate')
      const replace = vi.spyOn(navigator, 'replacePath')
      const container = mountPage(connection, {}, navigator)
      const followId = sent.at(-1)?.requestId ?? ''
      const stream = byId(container, 'hilos-log-stream') as HTMLSelectElement
      expect(stream.value).toBe('agent-chat.log')
      answer(followId, {
        readable: false,
        lines: [],
        nextCursor: null,
        hasMore: false,
      })
      await settled()

      expect(byId(container, 'hilos-log-latest-batch')).toBeNull()
      pushCatalog(archivedStreamCatalog())
      expect(byId(container, 'hilos-log-empty-missing')).not.toBeNull()
      expect(byId(container, 'hilos-log-empty-unchosen')).toBeNull()
      expect(stream.value).toBe('agent-chat.log')
      expect(
        byId(container, 'hilos-log-latest-batch')?.getAttribute('href'),
      ).toBe(`/hilos/logs/view/node-2/${LATEST_BATCH}/agent-chat.log`)
      expect(navigator.currentPath.get()).toBe(
        '/hilos/logs/view/node-2/live/agent-chat.log',
      )
      expect(navigate).not.toHaveBeenCalled()
      expect(replace).not.toHaveBeenCalled()

      const livePicture = archivedStreamCatalog()
      livePicture.nodes[0].streams[1].live = true
      pushCatalog(livePicture)
      const line = wireLine('The live file now exists.')
      pushAppended({
        followId,
        lines: [{ ...line, text: viewMode ? HIDDEN_VALUE : line.text }],
      })

      expect(stream.value).toBe('agent-chat.log')
      expect(optionValues(stream)).toEqual([
        '',
        'worker-0.log',
        'agent-chat.log',
      ])
      const entry = byId(container, 'hilos-log-entry')
      expect(entry).not.toBeNull()
      if (viewMode) {
        expect(entry?.querySelector('[data-id="hilos-hidden"]')).not.toBeNull()
        expect(
          byId(container, 'hilos-log-substring')?.hasAttribute('disabled'),
        ).toBe(true)
        expect(
          byId(container, 'hilos-log-search')?.hasAttribute('disabled'),
        ).toBe(true)
      } else {
        expect(entry?.textContent).toContain('The live file now exists.')
      }
      expect(byId(container, 'hilos-log-empty-missing')).toBeNull()
      expect(byId(container, 'hilos-log-latest-batch')).toBeNull()
      expect(navigate).not.toHaveBeenCalled()
      expect(replace).not.toHaveBeenCalled()
      expect(
        sent.filter((frame) => frame.action === LOGS_FOLLOW_START_ACTION),
      ).toHaveLength(1)
    },
  )

  describe.each(['select', 'route'] as const)(
    'after a missing file, %s',
    (navigation) => {
      it.each(['lines', 'silent', 'missing', 'refusal'] as const)(
        'waits for the selected file before showing its %s outcome',
        async (outcome) => {
          const {
            connection,
            pushCatalog,
            pushAppended,
            sent,
            answer,
            refuse,
          } = makeConnection()
          const navigator = router(ARCHIVED_LIVE_FILE)
          const container = mountPage(connection, {}, navigator)
          pushCatalog(archivedStreamCatalog())
          const oldId = sent.at(-1)?.requestId ?? ''
          answer(oldId, {
            readable: false,
            lines: [],
            nextCursor: null,
            hasMore: false,
          })
          await settled()
          expect(byId(container, 'hilos-log-empty-missing')).not.toBeNull()
          expect(
            byId(container, 'hilos-log-latest-batch')?.getAttribute('href'),
          ).toBe(`/hilos/logs/view/node-2/${LATEST_BATCH}/agent-chat.log`)

          if (navigation === 'select') {
            fireEvent.change(byId(container, 'hilos-log-stream')!, {
              target: { value: 'worker-0.log' },
            })
          } else {
            act(() =>
              navigator.navigate('/hilos/logs/view/node-2/live/worker-0.log'),
            )
          }
          const currentId = sent.at(-1)?.requestId ?? ''
          expect(currentId).not.toBe(oldId)
          expect(sent.at(-1)?.action).toBe(LOGS_FOLLOW_START_ACTION)
          expect(
            (byId(container, 'hilos-log-node') as HTMLSelectElement).value,
          ).toBe('node-2')
          expect(
            (byId(container, 'hilos-log-source') as HTMLSelectElement).value,
          ).toBe('live')
          expect(
            (byId(container, 'hilos-log-stream') as HTMLSelectElement).value,
          ).toBe('worker-0.log')
          expect(
            (byId(container, 'hilos-log-follow') as HTMLInputElement).checked,
          ).toBe(true)
          expect(
            byId(container, 'hilos-log-earlier')?.hasAttribute('disabled'),
          ).toBe(true)
          for (const id of [
            'hilos-log-empty-missing',
            'hilos-log-latest-batch',
            'hilos-log-refusal',
          ]) {
            expect(byId(container, id)).toBeNull()
          }

          pushAppended({
            followId: oldId,
            lines: [wireLine('Old file frame.')],
          })
          expect(byId(container, 'hilos-log-entry')).toBeNull()
          if (outcome === 'refusal') {
            refuse(currentId, 'The selected node is offline.')
          } else {
            answer(currentId, {
              readable: outcome !== 'missing',
              lines:
                outcome === 'lines' ? [wireLine('Selected file line.')] : [],
              nextCursor: outcome === 'lines' ? 100 : null,
              hasMore: outcome === 'lines',
            })
          }
          await settled()
          expect(
            (byId(container, 'hilos-log-stream') as HTMLSelectElement).value,
          ).toBe('worker-0.log')
          expect(byId(container, 'hilos-log-empty-missing') !== null).toBe(
            outcome === 'missing',
          )
          expect(byId(container, 'hilos-log-latest-batch') !== null).toBe(
            outcome === 'missing',
          )
          expect(byId(container, 'hilos-log-refusal') !== null).toBe(
            outcome === 'refusal',
          )
          if (outcome === 'missing') {
            expect(
              byId(container, 'hilos-log-latest-batch')?.getAttribute('href'),
            ).toBe(`/hilos/logs/view/node-2/${BATCH}/worker-0.log`)
          } else if (outcome === 'refusal') {
            expect(
              byId(container, 'hilos-log-refusal')?.textContent?.trim(),
            ).toBe('The selected node is offline.')
          } else if (outcome === 'lines') {
            expect(byId(container, 'hilos-log-entry')?.textContent).toContain(
              'Selected file line.',
            )
            expect(
              byId(container, 'hilos-log-earlier')?.hasAttribute('disabled'),
            ).toBe(false)
          } else {
            expect(byId(container, 'hilos-log-empty-silent')).not.toBeNull()
          }
          expect(container.textContent).not.toContain('Old file frame.')
        },
      )
    },
  )

  it('keeps node and batch without guessing a stream on a partial URL', () => {
    const { connection, pushCatalog, sent } = makeConnection()
    const navigator = router({ nodeId: 'node-2', source: String(BATCH) })
    const container = mountPage(connection, {}, navigator)
    pushCatalog(archivedStreamCatalog())

    expect(navigator.currentPath.get()).toBe(
      '/hilos/logs/view/node-2/1800000000',
    )
    expect(
      (byId(container, 'hilos-log-source') as HTMLSelectElement).value,
    ).toBe(String(BATCH))
    expect(
      (byId(container, 'hilos-log-stream') as HTMLSelectElement).value,
    ).toBe('')
    expect(byId(container, 'hilos-log-empty-unchosen')).not.toBeNull()
    expect(byId(container, 'hilos-log-latest-batch')).toBeNull()
    expect(sent).toEqual([])
  })

  it('marks a vanished selected batch unavailable until it returns without duplicates', async () => {
    const { connection, pushCatalog, sent, answer } = makeConnection()
    const container = mountPage(connection, {
      ...ARCHIVED_LIVE_FILE,
      source: String(BATCH),
    })
    pushCatalog(archivedStreamCatalog())
    const source = byId(container, 'hilos-log-source') as HTMLSelectElement
    expect(source.value).toBe(String(BATCH))
    expect(source.selectedOptions[0].textContent).not.toContain('unavailable')

    const nextPicture = archivedStreamCatalog()
    nextPicture.nodes[0].batches = [LATEST_BATCH]
    nextPicture.nodes[0].streams[1].batchTimestamps = [LATEST_BATCH]
    pushCatalog(nextPicture)
    answer(sent.at(-1)?.requestId, {
      readable: false,
      lines: [],
      nextCursor: null,
      hasMore: false,
    })
    await settled()

    expect(source.value).toBe(String(BATCH))
    expect(source.querySelectorAll(`option[value="${BATCH}"]`)).toHaveLength(1)
    expect(source.selectedOptions[0].textContent).toBe(
      `Batch — ${new Date(BATCH * 1000).toLocaleString()} (unavailable)`,
    )
    expect(
      (byId(container, 'hilos-log-stream') as HTMLSelectElement).value,
    ).toBe('agent-chat.log')
    expect(byId(container, 'hilos-log-empty-missing')).not.toBeNull()
    expect(byId(container, 'hilos-log-latest-batch')).toBeNull()

    pushCatalog(archivedStreamCatalog())

    expect(source.value).toBe(String(BATCH))
    expect(source.querySelectorAll(`option[value="${BATCH}"]`)).toHaveLength(1)
    expect(source.selectedOptions[0].textContent).toBe(
      `Batch — ${new Date(BATCH * 1000).toLocaleString()}`,
    )
  })

  it('keeps the Earlier button dark until there is a page before this one', () => {
    const { connection, pushCatalog } = makeConnection()
    const container = mountPage(connection)

    pushCatalog(catalog())

    expect(
      (byId(container, 'hilos-log-earlier') as HTMLButtonElement).disabled,
    ).toBe(true)
  })

  it('asks the server nothing while the substring is only typed, and says so', () => {
    const { connection, pushCatalog, sent } = makeConnection()
    const container = mountPage(connection, LIVE_FILE)
    pushCatalog(catalog())
    const asked = sent.length
    const room = expectCountRoom(container)
    const region = room.querySelector('[role="status"]') as HTMLElement
    expect(region.textContent).toBe('')
    expect(byId(room, 'hilos-log-count')).not.toBeNull()

    fireEvent.change(byId(container, 'hilos-log-substring') as HTMLElement, {
      target: { value: 'deadlock' },
    })

    expect(sent).toHaveLength(asked)
    const pending = byId(region, 'hilos-log-search-pending')
    expect(pending).not.toBeNull()
    expect(pending?.textContent).toBe('Not applied yet — press Search')
    expect(pending?.getAttribute('role')).toBeNull()
    expect(byId(room, 'hilos-log-count')).toBeNull()
    expectCountRoom(container)
  })

  it('applies the typed substring on the Search button, and drops the note', () => {
    const { connection, pushCatalog, sent } = makeConnection()
    const container = mountPage(connection, LIVE_FILE)
    pushCatalog(catalog())
    fireEvent.change(byId(container, 'hilos-log-substring') as HTMLElement, {
      target: { value: 'deadlock' },
    })
    const asked = sent.length

    fireEvent.click(byId(container, 'hilos-log-search') as HTMLElement)

    expect(sent).toHaveLength(asked + 1)
    expect(sent.at(-1)?.data).toMatchObject({ substring: 'deadlock' })
    expect(byId(container, 'hilos-log-search-pending')).toBeNull()
    const room = expectCountRoom(container)
    expect(byId(room, 'hilos-log-count')).not.toBeNull()
    const region = room.querySelector('[role="status"]') as HTMLElement
    expect(region.textContent).toBe('')
  })

  it('applies the same substring on Enter as on the button', () => {
    const { connection, pushCatalog, sent } = makeConnection()
    const container = mountPage(connection, LIVE_FILE)
    pushCatalog(catalog())
    const field = byId(container, 'hilos-log-substring') as HTMLElement
    fireEvent.change(field, { target: { value: 'deadlock' } })
    const asked = sent.length

    fireEvent.keyDown(field, { key: 'Enter' })

    expect(sent).toHaveLength(asked + 1)
    expect(sent.at(-1)?.data).toMatchObject({ substring: 'deadlock' })
    expect(byId(container, 'hilos-log-search-pending')).toBeNull()
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
    // The room is taken before there is anything to say, by a twin that holds
    // the place and never takes the focus.
    expectBackToTailRoom(container)

    scrollUp(byId(container, 'hilos-log-pane') as Element)
    pushAppended({ followId, lines: [wireLine('one'), wireLine('two')] })

    expect(byId(container, 'hilos-log-back-to-tail-count')?.textContent).toBe(
      '2',
    )
    // The control stands in its room, in the flow, and not over the pane.
    const back = expectBackToTailRoom(container).querySelector(
      '[data-id="hilos-log-back-to-tail"]',
    )
    expect(back).not.toBeNull()
    expect(back?.classList.contains('position-absolute')).toBe(false)
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

  it('writes a count past the ceiling as the widest label', () => {
    const { connection, pushCatalog, pushAppended, sent } = makeConnection()
    const container = mountPage(connection, LIVE_FILE)
    pushCatalog(catalog())
    const followId = sent.at(-1)?.requestId ?? ''

    scrollUp(byId(container, 'hilos-log-pane') as Element)
    const lines = Array.from({ length: 600 }, (_, i) => wireLine(`line ${i}`))
    pushAppended({ followId, lines })
    pushAppended({ followId, lines })

    expect(byId(container, 'hilos-log-back-to-tail-count')?.textContent).toBe(
      '999+',
    )
  })

  it('takes the pane back to the tail when the reader goes back with nothing waiting', () => {
    const { connection, pushCatalog } = makeConnection()
    const container = mountPage(connection, LIVE_FILE)
    pushCatalog(catalog())
    const pane = byId(container, 'hilos-log-pane') as HTMLElement
    scrollUp(pane)

    expect(byId(container, 'hilos-log-back-to-tail')?.textContent).toBe(
      'Back to the tail',
    )

    act(() => {
      fireEvent.click(byId(container, 'hilos-log-back-to-tail') as HTMLElement)
    })

    expect(pane.scrollTop).toBe(1000)
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

  it('frames the entry a link opened the viewer on, and no other', async () => {
    const { connection, pushCatalog, sent, answer } = makeConnection()
    const container = mountPage(connection, {
      ...LIVE_FILE,
      anchor: '1788000000250',
    })
    pushCatalog(catalog())

    answer(sent.at(-1)?.requestId, {
      readable: true,
      lines: [
        wireLine(
          '[2026-09-06 10:00:02.250] ERROR: the entry the row named',
          'ERROR',
        ),
        wireLine('[2026-09-06 10:00:03.000] INFO: what happened next'),
      ],
      nextCursor: 4096,
      hasMore: true,
      anchorFound: true,
    })
    await settled()

    const anchored = container.querySelectorAll(
      '[data-id="hilos-log-entry-anchor"]',
    )
    expect(anchored).toHaveLength(1)
    expect(anchored[0].textContent).toContain('the entry the row named')
    expect(
      container.querySelectorAll('[aria-current="location"]'),
    ).toHaveLength(1)
  })

  it('says in the feed that the entry a link named is not in the file any more', async () => {
    const { connection, pushCatalog, sent, answer } = makeConnection()
    const container = mountPage(connection, {
      ...LIVE_FILE,
      anchor: '1788000000250',
    })
    pushCatalog(catalog())

    answer(sent.at(-1)?.requestId, {
      readable: true,
      lines: [wireLine('[2026-09-06 11:00:00.000] INFO: the end of the file')],
      nextCursor: null,
      hasMore: false,
      anchorFound: false,
    })
    await settled()

    expect(byId(container, 'hilos-log-notice')?.textContent).toBe(
      'The entry this link points at is no longer in this file. Showing the end of the file instead.',
    )
    expect(byId(container, 'hilos-log-entry-anchor')).toBeNull()
  })
})
