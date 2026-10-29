import { flushPromises, mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  ActionLifecycle,
  HIDDEN_VALUE,
  ScopeManager,
  bindTimeZoneState,
  bindAdminAccess,
  bindSessionScope,
  createPageRouter,
  createSignal,
  HILOS_ROUTE_DECLARATIONS,
  HilosPages,
  hilosTimeZone,
  LOG_LINES_APPENDED_SIGNAL,
  LOG_VIEWER_CATALOG_SIGNAL,
} from '@hilos/core/base'
import {
  LOGS_FOLLOW_START_ACTION,
  LOGS_READ_LINES_ACTION,
} from '@hilos/core/admin/logs'
import type {
  HilosConnection,
  HilosLogViewerCatalog,
  HilosRouter,
  ProjectSignal,
} from '@hilos/core/base'

import HilosLogsViewPage from './HilosLogsViewPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

/** Any fixed batch, so a timestamp in a fixture means something to read. */
const BATCH = 1800000000000
const LATEST_BATCH = 1800000100000
const DST_MOMENT = Date.parse('2026-03-29T01:30:00.123Z')
const DST_MOMENT_ISO = '2026-03-29T01:30:00.123Z'

const releases: (() => void)[] = []

function dateTimeLabel(moment: number, timeZone: string): string {
  return new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'medium',
    timeZone,
  }).format(new Date(moment))
}

function logTimeLabel(timeZone: string): string {
  return new Intl.DateTimeFormat(undefined, {
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    fractionalSecondDigits: 3,
    hourCycle: 'h23',
    timeZone,
  }).format(new Date(DST_MOMENT))
}

function bindReaderZone(): {
  setZone: (timeZone: string) => void
  restore: () => void
} {
  type ZoneSignal = { type: string; data: unknown }
  const previousZone = hilosTimeZone.get()
  const listeners = new Set<(signal: ZoneSignal) => void>()
  const connection = {
    projectTimeZone: 'UTC',
    on(event: string, listener: (signal: never) => void): () => void {
      if (event !== 'projectSignal') return () => {}
      const receive = listener as unknown as (signal: ZoneSignal) => void
      listeners.add(receive)
      return () => listeners.delete(receive)
    },
  } as unknown as HilosConnection
  const release = bindTimeZoneState(connection)
  const setZone = (timeZone: string): void => {
    for (const listener of listeners) {
      listener({
        type: 'handshake_response',
        data: { entities: { currentUser: { id: 1, timeZone } } },
      })
    }
  }
  setZone('UTC')

  return {
    setZone,
    restore(): void {
      setZone(previousZone)
      release()
    },
  }
}

afterEach(() => {
  for (const release of releases.splice(0)) release()
})

/** Bind the same session and admin-access signal the application boot binds. */
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
  node.streams[1].batchTimestamps = [BATCH, LATEST_BATCH + 100000, LATEST_BATCH]
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
    text: string
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
    for (const listener of listeners[event] ?? []) {
      ;(listener as unknown as (payload: unknown) => void)(signal)
    }
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

/** A full live address whose stream has only archived files at first. */
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
) {
  return mount(HilosLogsViewPage, {
    props: {
      context: {
        connection,
        actions: new ActionLifecycle(
          connection as unknown as ConstructorParameters<
            typeof ActionLifecycle
          >[0],
        ),
      },
    },
    global: { provide: { [hilosRouterKey as symbol]: navigator } },
  })
}

describe('HilosLogsViewPage', () => {
  it('shows a hidden line with its clock and level while keeping search disabled', async () => {
    bindViewerSession()
    const { connection, pushCatalog, sent, answer } = makeConnection()
    const wrapper = mountPage(connection, LIVE_FILE)
    pushCatalog(catalog())
    await nextTick()
    answer(sent.at(-1)?.requestId, {
      readable: true,
      lines: [
        {
          time: '2026-09-06T10:00:02.250Z',
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
    await flushPromises()

    const entry = wrapper.get('[data-id="hilos-log-entry"]')
    expect(entry.text()).toContain('10:00:02.250')
    expect(entry.find('[data-id="hilos-log-entry-time"]').text()).toContain(
      '10:00:02.250',
    )
    expect(entry.text()).toContain('ERROR')
    expect(entry.find('[data-id="hilos-hidden"]').exists()).toBe(true)
    expect(entry.find('[data-id="hilos-log-stack-toggle"]').exists()).toBe(
      false,
    )
    for (const id of ['hilos-log-substring', 'hilos-log-search']) {
      const control = wrapper.get(`[data-id="${id}"]`)
      expect(control.attributes('disabled')).toBeDefined()
      expect(control.attributes('aria-describedby')).toBe(
        'hilos-view-mode-strip-text',
      )
    }
    expect(
      wrapper.get('[data-id="hilos-log-follow"]').attributes('disabled'),
    ).toBeUndefined()
  })

  it('reformats a hidden entry clock on a zone change without another frame', async () => {
    bindViewerSession()
    const zone = bindReaderZone()
    const { connection, pushCatalog, sent, answer } = makeConnection()
    const navigator = router(LIVE_FILE)
    const wrapper = mountPage(connection, {}, navigator)

    try {
      pushCatalog(catalog())
      await nextTick()
      answer(sent.at(-1)?.requestId, {
        readable: true,
        lines: [
          {
            time: DST_MOMENT_ISO,
            text: HIDDEN_VALUE,
            level: 'ERROR',
            isContinuation: false,
          },
        ],
        nextCursor: null,
        hasMore: false,
      })
      await flushPromises()

      const entry = wrapper.get('[data-id="hilos-log-entry"]')
      const time = entry.get('[data-id="hilos-log-entry-time"]')
      expect(time.text()).toBe(logTimeLabel('UTC'))
      expect(entry.find('[data-id="hilos-hidden"]').exists()).toBe(true)
      expect(entry.text()).not.toContain('private log text')
      const source = wrapper.get<HTMLSelectElement>(
        '[data-id="hilos-log-source"]',
      )
      const sourceValue = source.element.value
      const path = navigator.currentPath.get()
      const sentCount = sent.length

      zone.setZone('Europe/Warsaw')
      await nextTick()

      expect(wrapper.get('[data-id="hilos-log-entry"]').element).toBe(
        entry.element,
      )
      expect(time.text()).toBe(logTimeLabel('Europe/Warsaw'))
      expect(entry.find('[data-id="hilos-hidden"]').exists()).toBe(true)
      expect(entry.text()).not.toContain('private log text')
      expect(source.element.value).toBe(sourceValue)
      expect(navigator.currentPath.get()).toBe(path)
      expect(sent).toHaveLength(sentCount)
    } finally {
      wrapper.unmount()
      zone.restore()
    }
  })

  it('keeps an opened stack through a reader-zone repaint', async () => {
    const zone = bindReaderZone()
    const { connection, pushCatalog, sent, answer } = makeConnection()
    const wrapper = mountPage(connection, LIVE_FILE)

    try {
      pushCatalog(catalog())
      await nextTick()
      answer(sent.at(-1)?.requestId, {
        readable: true,
        lines: [
          wireLine(
            `[${DST_MOMENT_ISO}] INFO: Action failed {"trace":"#0 /src/App.php(12): run()\\n#1 {main}"}`,
          ),
        ],
        nextCursor: null,
        hasMore: false,
      })
      await flushPromises()

      const entry = wrapper.get('[data-id="hilos-log-entry"]')
      const toggle = entry.get('[data-id="hilos-log-stack-toggle"]')
      await toggle.trigger('click')
      expect(toggle.attributes('aria-expanded')).toBe('true')
      expect(entry.get('[data-id="hilos-log-stack"]').text()).toContain(
        '#0 /src/App.php(12): run()',
      )
      const sentCount = sent.length

      zone.setZone('Europe/Warsaw')
      await nextTick()

      expect(wrapper.get('[data-id="hilos-log-entry"]').element).toBe(
        entry.element,
      )
      expect(
        wrapper
          .get('[data-id="hilos-log-stack-toggle"]')
          .attributes('aria-expanded'),
      ).toBe('true')
      expect(wrapper.get('[data-id="hilos-log-stack"]').text()).toContain(
        '#0 /src/App.php(12): run()',
      )
      expect(wrapper.get('[data-id="hilos-log-entry-time"]').text()).toBe(
        logTimeLabel('Europe/Warsaw'),
      )
      expect(sent).toHaveLength(sentCount)
    } finally {
      wrapper.unmount()
      zone.restore()
    }
  })

  it('waits rather than reporting a fault before any catalog arrives', () => {
    const { connection } = makeConnection()
    const wrapper = mountPage(connection)

    expect(wrapper.find('[data-id="hilos-log-empty-unknown"]').exists()).toBe(
      true,
    )
  })

  it('reports the fault once the picture says no node could be read', async () => {
    const { connection, pushCatalog } = makeConnection()
    const wrapper = mountPage(connection)

    pushCatalog(catalog({ available: false, nodes: [] }))
    await nextTick()

    expect(
      wrapper.find('[data-id="hilos-log-empty-unreadable"]').exists(),
    ).toBe(true)
  })

  it('asks for a stream rather than guessing one', async () => {
    // A guessed stream would open the wrong file and look exactly like an answer.
    const { connection, pushCatalog } = makeConnection()
    const wrapper = mountPage(connection)

    pushCatalog(catalog())
    await nextTick()

    expect(wrapper.find('[data-id="hilos-log-empty-unchosen"]').exists()).toBe(
      true,
    )
  })

  it('drops the node select in an installation with no cluster', async () => {
    const { connection, pushCatalog } = makeConnection()
    const wrapper = mountPage(connection)

    pushCatalog(catalog())
    await nextTick()

    expect(wrapper.find('[data-id="hilos-log-node"]').exists()).toBe(false)
  })

  it('offers the node select where the nodes have names', async () => {
    const { connection, pushCatalog } = makeConnection()
    const wrapper = mountPage(connection)

    pushCatalog(clusterCatalog())
    await nextTick()

    expect(wrapper.find('[data-id="hilos-log-node"]').exists()).toBe(true)
  })

  it('offers the streams of the chosen source only', async () => {
    // The archived batch holds both files; the live journal holds one of them.
    const { connection, pushCatalog } = makeConnection()
    const wrapper = mountPage(connection, {
      nodeId: '-',
      source: 'live',
      stream: 'worker-0.log',
    })

    pushCatalog(catalog())
    await nextTick()

    const live = wrapper
      .find('[data-id="hilos-log-stream"]')
      .findAll('option')
      .map((option) => option.element.value)
    expect(live).toEqual(['', 'worker-0.log'])

    await wrapper.find('[data-id="hilos-log-source"]').setValue(String(BATCH))

    expect(
      wrapper
        .find('[data-id="hilos-log-stream"]')
        .findAll('option')
        .map((option) => option.element.value),
    ).toEqual(['', 'worker-0.log', 'agent-chat.log'])
  })

  it('keeps the stream when returning to live without its file', async () => {
    const { connection, pushCatalog, sent } = makeConnection()
    const wrapper = mountPage(connection, {
      nodeId: '-',
      source: String(BATCH),
      stream: 'agent-chat.log',
    })
    pushCatalog(catalog())
    await nextTick()

    await wrapper.find('[data-id="hilos-log-source"]').setValue('live')

    // Returning to live keeps the explicit stream and starts its tail even
    // before that live file exists.
    expect(wrapper.find('[data-id="hilos-log-empty-unchosen"]').exists()).toBe(
      false,
    )
    expect(sent.at(-1)?.action).toBe(LOGS_FOLLOW_START_ACTION)
    expect(sent.at(-1)?.data).toEqual({
      nodeId: 'standalone',
      stream: 'agent-chat.log',
      level: null,
      substring: null,
    })
    expect(
      wrapper.get<HTMLSelectElement>('[data-id="hilos-log-stream"]').element
        .value,
    ).toBe('agent-chat.log')
  })

  it.each([false, true])(
    'opens the latest batch only on a link click (view mode: %s)',
    async (viewMode) => {
      if (viewMode) bindViewerSession()
      const { connection, pushCatalog, sent, answer } = makeConnection()
      const navigator = router(ARCHIVED_LIVE_FILE)
      const navigate = vi.spyOn(navigator, 'navigate')
      const replace = vi.spyOn(navigator, 'replacePath')
      const wrapper = mountPage(connection, {}, navigator)
      const livePath = '/hilos/logs/view/node-2/live/agent-chat.log'
      expect(navigator.currentPath.get()).toBe(livePath)

      pushCatalog(archivedStreamCatalog())
      await nextTick()
      answer(sent.at(-1)?.requestId, {
        readable: false,
        lines: [],
        nextCursor: null,
        hasMore: false,
      })
      await flushPromises()

      expect(
        wrapper.get<HTMLSelectElement>('[data-id="hilos-log-stream"]').element
          .value,
      ).toBe('agent-chat.log')
      expect(
        wrapper.get('[data-id="hilos-log-empty-missing"]').isVisible(),
      ).toBe(true)
      expect(
        wrapper.find('[data-id="hilos-log-empty-unchosen"]').exists(),
      ).toBe(false)
      const link = wrapper.get('[data-id="hilos-log-latest-batch"]')
      const batchPath = '/hilos/logs/view/node-2/1800000100000/agent-chat.log'
      expect(link.element.tagName).toBe('A')
      expect(link.text()).toBe('Open latest batch')
      expect(link.attributes('href')).toBe(batchPath)
      expect(link.attributes('aria-disabled')).toBeUndefined()
      expect(link.attributes('tabindex')).not.toBe('-1')
      expect(navigate).not.toHaveBeenCalled()
      expect(replace).not.toHaveBeenCalled()
      expect(navigator.currentPath.get()).toBe(livePath)

      await link.trigger('click', { button: 0 })

      expect(navigate).toHaveBeenCalledExactlyOnceWith(batchPath)
      expect(navigator.currentPath.get()).toBe(batchPath)
      expect(
        wrapper.get<HTMLSelectElement>('[data-id="hilos-log-source"]').element
          .value,
      ).toBe(String(LATEST_BATCH))
      expect(
        wrapper
          .get<HTMLSelectElement>('[data-id="hilos-log-source"]')
          .element.selectedOptions[0].textContent?.trim(),
      ).toBe(`Batch — ${dateTimeLabel(LATEST_BATCH, hilosTimeZone.get())}`)
      expect(sent.at(-1)?.action).toBe(LOGS_READ_LINES_ACTION)
      expect(sent.at(-1)?.data).toMatchObject({
        nodeId: 'node-2',
        source: 'batch',
        batchTimestamp: LATEST_BATCH,
        stream: 'agent-chat.log',
      })
      expect(wrapper.find('[data-id="hilos-log-latest-batch"]').exists()).toBe(
        false,
      )
      expect(
        wrapper.get('[data-id="hilos-log-follow"]').attributes('disabled'),
      ).toBeDefined()
      expect(
        sent.filter((frame) => frame.action === LOGS_FOLLOW_START_ACTION),
      ).toHaveLength(1)

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
      await flushPromises()

      if (viewMode) {
        expect(wrapper.get('[data-id="hilos-hidden"]').isVisible()).toBe(true)
        expect(wrapper.text()).not.toContain('Archived line.')
        for (const id of ['hilos-log-substring', 'hilos-log-search']) {
          expect(
            wrapper.get(`[data-id="${id}"]`).attributes('disabled'),
          ).toBeDefined()
        }
      } else {
        expect(wrapper.get('[data-id="hilos-log-entry"]').text()).toContain(
          'Archived line.',
        )
      }

      await wrapper.get('[data-id="hilos-log-source"]').setValue('live')
      expect(
        wrapper.get<HTMLSelectElement>('[data-id="hilos-log-source"]').element
          .value,
      ).toBe('live')
      expect(
        wrapper.get<HTMLSelectElement>('[data-id="hilos-log-stream"]').element
          .value,
      ).toBe('agent-chat.log')
      expect(navigator.currentPath.get()).toBe(livePath)
      expect(sent.at(-1)?.action).toBe(LOGS_FOLLOW_START_ACTION)
      expect(sent.at(-1)?.data).toMatchObject({
        nodeId: 'node-2',
        stream: 'agent-chat.log',
      })
    },
  )

  it('keeps the missing live name without a link when no archive is known', async () => {
    const { connection, pushCatalog, sent, answer } = makeConnection()
    const wrapper = mountPage(connection, ARCHIVED_LIVE_FILE)
    const picture = archivedStreamCatalog()
    picture.nodes[0].batches = []
    picture.nodes[0].streams[1].batchTimestamps = []
    pushCatalog(picture)
    await nextTick()
    answer(sent.at(-1)?.requestId, {
      readable: false,
      lines: [],
      nextCursor: null,
      hasMore: false,
    })
    await flushPromises()

    expect(
      wrapper.get<HTMLSelectElement>('[data-id="hilos-log-stream"]').element
        .value,
    ).toBe('agent-chat.log')
    expect(wrapper.get('[data-id="hilos-log-empty-missing"]').isVisible()).toBe(
      true,
    )
    expect(wrapper.find('[data-id="hilos-log-empty-unchosen"]').exists()).toBe(
      false,
    )
    expect(wrapper.find('[data-id="hilos-log-latest-batch"]').exists()).toBe(
      false,
    )
  })

  it('keeps a full unknown stream through a late catalog without guessing a file', async () => {
    const { connection, pushCatalog, sent, answer } = makeConnection()
    const params = { ...ARCHIVED_LIVE_FILE, stream: 'unknown.log' }
    const navigator = router(params)
    const navigate = vi.spyOn(navigator, 'navigate')
    const replace = vi.spyOn(navigator, 'replacePath')
    const wrapper = mountPage(connection, {}, navigator)
    const stream = wrapper.get<HTMLSelectElement>(
      '[data-id="hilos-log-stream"]',
    )
    expect(stream.element.value).toBe('unknown.log')
    answer(sent.at(-1)?.requestId, {
      readable: false,
      lines: [],
      nextCursor: null,
      hasMore: false,
    })
    await flushPromises()
    expect(wrapper.get('[data-id="hilos-log-empty-missing"]').isVisible()).toBe(
      true,
    )

    pushCatalog(archivedStreamCatalog())
    await nextTick()

    expect(stream.element.value).toBe('unknown.log')
    expect(
      stream.findAll('option').map((option) => option.element.value),
    ).toEqual(['', 'worker-0.log', 'unknown.log'])
    expect(wrapper.find('[data-id="hilos-log-empty-unchosen"]').exists()).toBe(
      false,
    )
    expect(wrapper.find('[data-id="hilos-log-latest-batch"]').exists()).toBe(
      false,
    )
    expect(navigate).not.toHaveBeenCalled()
    expect(replace).not.toHaveBeenCalled()
  })

  it('adds the archive link on a late catalog and restores live lines without duplicate options', async () => {
    const { connection, pushCatalog, pushAppended, sent, answer } =
      makeConnection()
    const navigator = router(ARCHIVED_LIVE_FILE)
    const navigate = vi.spyOn(navigator, 'navigate')
    const replace = vi.spyOn(navigator, 'replacePath')
    const wrapper = mountPage(connection, {}, navigator)
    const followId = sent.at(-1)?.requestId ?? ''
    answer(followId, {
      readable: false,
      lines: [],
      nextCursor: null,
      hasMore: false,
    })
    await flushPromises()
    expect(wrapper.find('[data-id="hilos-log-latest-batch"]').exists()).toBe(
      false,
    )

    const picture = archivedStreamCatalog()
    pushCatalog(picture)
    await nextTick()
    expect(wrapper.get('[data-id="hilos-log-empty-missing"]').isVisible()).toBe(
      true,
    )
    expect(
      wrapper.get('[data-id="hilos-log-latest-batch"]').attributes('href'),
    ).toBe('/hilos/logs/view/node-2/1800000100000/agent-chat.log')
    expect(navigator.currentPath.get()).toBe(
      '/hilos/logs/view/node-2/live/agent-chat.log',
    )
    expect(navigate).not.toHaveBeenCalled()
    expect(replace).not.toHaveBeenCalled()

    const livePicture = archivedStreamCatalog()
    livePicture.nodes[0].streams[1].live = true
    pushCatalog(livePicture)
    pushAppended({ followId, lines: [wireLine('The live file now exists.')] })
    await nextTick()

    const stream = wrapper.get<HTMLSelectElement>(
      '[data-id="hilos-log-stream"]',
    )
    expect(stream.element.value).toBe('agent-chat.log')
    expect(
      stream.findAll('option').map((option) => option.element.value),
    ).toEqual(['', 'worker-0.log', 'agent-chat.log'])
    expect(wrapper.get('[data-id="hilos-log-entry"]').text()).toContain(
      'The live file now exists.',
    )
    expect(wrapper.find('[data-id="hilos-log-empty-missing"]').exists()).toBe(
      false,
    )
    expect(wrapper.find('[data-id="hilos-log-latest-batch"]').exists()).toBe(
      false,
    )
    expect(navigate).not.toHaveBeenCalled()
    expect(replace).not.toHaveBeenCalled()
    expect(
      sent.filter((frame) => frame.action === LOGS_FOLLOW_START_ACTION),
    ).toHaveLength(1)
  })

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
          const wrapper = mountPage(connection, {}, navigator)
          pushCatalog(archivedStreamCatalog())
          const oldId = sent.at(-1)?.requestId ?? ''
          answer(oldId, {
            readable: false,
            lines: [],
            nextCursor: null,
            hasMore: false,
          })
          await flushPromises()
          expect(
            wrapper.get('[data-id="hilos-log-empty-missing"]').isVisible(),
          ).toBe(true)
          expect(
            wrapper
              .get('[data-id="hilos-log-latest-batch"]')
              .attributes('href'),
          ).toBe(`/hilos/logs/view/node-2/${LATEST_BATCH}/agent-chat.log`)

          if (navigation === 'select') {
            await wrapper
              .get('[data-id="hilos-log-stream"]')
              .setValue('worker-0.log')
          } else {
            navigator.navigate('/hilos/logs/view/node-2/live/worker-0.log')
          }
          await nextTick()
          const currentId = sent.at(-1)?.requestId ?? ''
          expect(currentId).not.toBe(oldId)
          expect(sent.at(-1)?.action).toBe(LOGS_FOLLOW_START_ACTION)
          expect(
            wrapper.get<HTMLSelectElement>('[data-id="hilos-log-node"]').element
              .value,
          ).toBe('node-2')
          expect(
            wrapper.get<HTMLSelectElement>('[data-id="hilos-log-source"]')
              .element.value,
          ).toBe('live')
          expect(
            wrapper.get<HTMLSelectElement>('[data-id="hilos-log-stream"]')
              .element.value,
          ).toBe('worker-0.log')
          expect(
            wrapper.get<HTMLInputElement>('[data-id="hilos-log-follow"]')
              .element.checked,
          ).toBe(true)
          expect(
            wrapper.get('[data-id="hilos-log-earlier"]').attributes('disabled'),
          ).toBeDefined()
          for (const id of [
            'hilos-log-empty-missing',
            'hilos-log-latest-batch',
            'hilos-log-refusal',
          ]) {
            expect(wrapper.find(`[data-id="${id}"]`).exists()).toBe(false)
          }

          pushAppended({
            followId: oldId,
            lines: [wireLine('Old file frame.')],
          })
          await nextTick()
          expect(wrapper.find('[data-id="hilos-log-entry"]').exists()).toBe(
            false,
          )
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
          await flushPromises()
          expect(
            wrapper.get<HTMLSelectElement>('[data-id="hilos-log-stream"]')
              .element.value,
          ).toBe('worker-0.log')
          expect(
            wrapper.find('[data-id="hilos-log-empty-missing"]').exists(),
          ).toBe(outcome === 'missing')
          expect(
            wrapper.find('[data-id="hilos-log-latest-batch"]').exists(),
          ).toBe(outcome === 'missing')
          expect(wrapper.find('[data-id="hilos-log-refusal"]').exists()).toBe(
            outcome === 'refusal',
          )
          if (outcome === 'missing') {
            expect(
              wrapper
                .get('[data-id="hilos-log-latest-batch"]')
                .attributes('href'),
            ).toBe(`/hilos/logs/view/node-2/${BATCH}/worker-0.log`)
          } else if (outcome === 'refusal') {
            expect(wrapper.get('[data-id="hilos-log-refusal"]').text()).toBe(
              'The selected node is offline.',
            )
          } else if (outcome === 'lines') {
            expect(wrapper.get('[data-id="hilos-log-entry"]').text()).toContain(
              'Selected file line.',
            )
            expect(
              wrapper
                .get('[data-id="hilos-log-earlier"]')
                .attributes('disabled'),
            ).toBeUndefined()
          } else {
            expect(
              wrapper.get('[data-id="hilos-log-empty-silent"]').isVisible(),
            ).toBe(true)
          }
          expect(wrapper.text()).not.toContain('Old file frame.')
          wrapper.unmount()
        },
      )
    },
  )

  it('keeps node and batch without guessing a stream on a partial URL', async () => {
    const { connection, pushCatalog, sent } = makeConnection()
    const navigator = router({ nodeId: 'node-2', source: String(BATCH) })
    const wrapper = mountPage(connection, {}, navigator)
    pushCatalog(archivedStreamCatalog())
    await nextTick()

    expect(navigator.currentPath.get()).toBe(
      '/hilos/logs/view/node-2/1800000000000',
    )
    expect(
      wrapper.get<HTMLSelectElement>('[data-id="hilos-log-source"]').element
        .value,
    ).toBe(String(BATCH))
    expect(
      wrapper.get<HTMLSelectElement>('[data-id="hilos-log-stream"]').element
        .value,
    ).toBe('')
    expect(
      wrapper.get('[data-id="hilos-log-empty-unchosen"]').isVisible(),
    ).toBe(true)
    expect(wrapper.find('[data-id="hilos-log-latest-batch"]').exists()).toBe(
      false,
    )
    expect(sent).toEqual([])
  })

  it('keeps a selected batch as an unavailable display option after it disappears', async () => {
    const zone = bindReaderZone()
    const { connection, pushCatalog, sent, answer } = makeConnection()
    const navigator = router({
      ...ARCHIVED_LIVE_FILE,
      source: String(DST_MOMENT),
    })
    const wrapper = mountPage(connection, {}, navigator)

    try {
      const picture = archivedStreamCatalog()
      picture.nodes[0].batches = [DST_MOMENT]
      picture.nodes[0].streams[1].batchTimestamps = [DST_MOMENT]
      pushCatalog(picture)
      await nextTick()
      const source = wrapper.get<HTMLSelectElement>(
        '[data-id="hilos-log-source"]',
      )
      expect(source.element.value).toBe(String(DST_MOMENT))
      expect(source.get(`option[value="${DST_MOMENT}"]`).text()).not.toContain(
        'unavailable',
      )

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
      await flushPromises()

      const unavailable = `Batch — ${dateTimeLabel(DST_MOMENT, 'UTC')} (unavailable)`
      expect(source.element.value).toBe(String(DST_MOMENT))
      expect(source.findAll(`option[value="${DST_MOMENT}"]`)).toHaveLength(1)
      expect(source.get(`option[value="${DST_MOMENT}"]`).text()).toBe(
        unavailable,
      )
      expect(sent.at(-1)?.data).toMatchObject({
        nodeId: 'node-2',
        source: 'batch',
        batchTimestamp: DST_MOMENT,
        stream: 'agent-chat.log',
      })
      expect(
        wrapper.get<HTMLSelectElement>('[data-id="hilos-log-stream"]').element
          .value,
      ).toBe('agent-chat.log')
      expect(
        wrapper.get('[data-id="hilos-log-empty-missing"]').isVisible(),
      ).toBe(true)
      expect(wrapper.find('[data-id="hilos-log-latest-batch"]').exists()).toBe(
        false,
      )
      const path = navigator.currentPath.get()
      const sentCount = sent.length

      zone.setZone('Europe/Warsaw')
      await nextTick()

      const updatedSource = wrapper.get<HTMLSelectElement>(
        '[data-id="hilos-log-source"]',
      )
      expect(updatedSource.element.value).toBe(String(DST_MOMENT))
      expect(updatedSource.get(`option[value="${DST_MOMENT}"]`).text()).toBe(
        `Batch — ${dateTimeLabel(DST_MOMENT, 'Europe/Warsaw')} (unavailable)`,
      )
      expect(navigator.currentPath.get()).toBe(path)
      expect(sent).toHaveLength(sentCount)
    } finally {
      wrapper.unmount()
      zone.restore()
    }
  })

  it('keeps the Earlier button dark until there is a page before this one', async () => {
    const { connection, pushCatalog } = makeConnection()
    const wrapper = mountPage(connection)

    pushCatalog(catalog())
    await nextTick()

    expect(
      wrapper.find('[data-id="hilos-log-earlier"]').attributes('disabled'),
    ).toBeDefined()
  })

  it('asks the server nothing while the substring is only typed, and says so', async () => {
    const { connection, pushCatalog, sent } = makeConnection()
    const wrapper = mountPage(connection, LIVE_FILE)
    pushCatalog(catalog())
    await nextTick()
    const asked = sent.length
    const room = expectCountRoom(wrapper)
    const region = room.find('[role="status"]')
    expect(region.text()).toBe('')
    expect(room.find('[data-id="hilos-log-count"]').exists()).toBe(true)

    await wrapper.find('[data-id="hilos-log-substring"]').setValue('deadlock')

    expect(sent).toHaveLength(asked)
    const pending = region.find('[data-id="hilos-log-search-pending"]')
    expect(pending.exists()).toBe(true)
    expect(pending.text()).toBe('Not applied yet — press Search')
    expect(pending.attributes('role')).toBeUndefined()
    expect(room.find('[data-id="hilos-log-count"]').exists()).toBe(false)
    expectCountRoom(wrapper)
  })

  it('applies the typed substring on the Search button, and drops the note', async () => {
    const { connection, pushCatalog, sent } = makeConnection()
    const wrapper = mountPage(connection, LIVE_FILE)
    pushCatalog(catalog())
    await nextTick()
    await wrapper.find('[data-id="hilos-log-substring"]').setValue('deadlock')
    const asked = sent.length

    await wrapper.find('[data-id="hilos-log-search"]').trigger('click')

    expect(sent).toHaveLength(asked + 1)
    expect(sent.at(-1)?.data).toMatchObject({ substring: 'deadlock' })
    expect(wrapper.find('[data-id="hilos-log-search-pending"]').exists()).toBe(
      false,
    )
    const room = expectCountRoom(wrapper)
    expect(room.find('[data-id="hilos-log-count"]').exists()).toBe(true)
    expect(room.find('[role="status"]').text()).toBe('')
  })

  it('applies the same substring on Enter as on the button', async () => {
    const { connection, pushCatalog, sent } = makeConnection()
    const wrapper = mountPage(connection, LIVE_FILE)
    pushCatalog(catalog())
    await nextTick()
    const field = wrapper.find('[data-id="hilos-log-substring"]')
    await field.setValue('deadlock')
    const asked = sent.length

    await field.trigger('keydown', { key: 'Enter' })

    expect(sent).toHaveLength(asked + 1)
    expect(sent.at(-1)?.data).toMatchObject({ substring: 'deadlock' })
    expect(wrapper.find('[data-id="hilos-log-search-pending"]').exists()).toBe(
      false,
    )
  })

  it('greys the Follow switch out on an archived batch and says why', async () => {
    // Greyed out says "not applicable here", not "you turned it off": the switch
    // keeps its own position, so coming back to the live journal resumes the tail
    // without a click nobody made.
    const { connection, pushCatalog } = makeConnection()
    const wrapper = mountPage(connection, {
      ...LIVE_FILE,
      source: String(BATCH),
    })

    pushCatalog(catalog())
    await nextTick()

    const control = wrapper.find('[data-id="hilos-log-follow"]')
    expect(control.attributes('disabled')).toBeDefined()
    expect((control.element as HTMLInputElement).checked).toBe(true)
    expect(wrapper.find('[data-id="hilos-log-follow-off"]').text()).toBe(
      'An archived batch has no tail.',
    )
  })

  it('lights the tail badge once the start is answered', async () => {
    const { connection, pushCatalog, sent, answer } = makeConnection()
    const wrapper = mountPage(connection, LIVE_FILE)
    const statusRoom = expectStatusRoom(wrapper)
    expectCountRoom(wrapper)
    pushCatalog(catalog())
    await nextTick()

    expect(wrapper.find('[data-id="hilos-log-tail-badge"]').exists()).toBe(
      false,
    )
    expect(expectStatusRoom(wrapper).element).toBe(statusRoom.element)

    answer(sent.at(-1)?.requestId, {
      readable: true,
      lines: [],
      nextCursor: null,
      hasMore: false,
    })
    await flushPromises()

    expect(wrapper.find('[data-id="hilos-log-tail-badge"]').text()).toBe(
      'Tail is running',
    )
    expect(wrapper.find('[data-id="hilos-log-anchor-badge"]').exists()).toBe(
      false,
    )
    expect(wrapper.get('[data-id="hilos-log-count"]').text()).toBe(
      '0 entries shown',
    )
    expectStatusRoom(wrapper)
  })

  it('keeps the tail badge tied to following, not to a released scroll', async () => {
    const { connection, pushCatalog, sent, answer } = makeConnection()
    const wrapper = mountPage(connection, LIVE_FILE)
    pushCatalog(catalog())
    await nextTick()
    answer(sent.at(-1)?.requestId, {
      readable: true,
      lines: [],
      nextCursor: null,
      hasMore: false,
    })
    await flushPromises()

    expect(wrapper.find('[data-id="hilos-log-tail-badge"]').exists()).toBe(true)
    await wrapper
      .get<HTMLInputElement>('[data-id="hilos-log-follow"]')
      .setValue(false)
    expect(wrapper.find('[data-id="hilos-log-tail-badge"]').exists()).toBe(
      false,
    )
    expect(wrapper.find('[data-id="hilos-log-anchor-badge"]').exists()).toBe(
      false,
    )

    await wrapper
      .get<HTMLInputElement>('[data-id="hilos-log-follow"]')
      .setValue(true)
    answer(sent.at(-1)?.requestId, {
      readable: true,
      lines: [],
      nextCursor: null,
      hasMore: false,
    })
    await flushPromises()
    const pane = wrapper.get('[data-id="hilos-log-pane"]').element
    await scrollUp(pane)

    expect(wrapper.find('[data-id="hilos-log-tail-badge"]').text()).toBe(
      'Tail is running',
    )
    expect(wrapper.find('[data-id="hilos-log-anchor-badge"]').exists()).toBe(
      false,
    )
  })

  it('offers the way back with a count while the reader is above the tail', async () => {
    const { connection, pushCatalog, pushAppended, sent } = makeConnection()
    const wrapper = mountPage(connection, LIVE_FILE)
    pushCatalog(catalog())
    await nextTick()
    const followId = sent.at(-1)?.requestId ?? ''

    expect(wrapper.find('[data-id="hilos-log-back-to-tail"]').exists()).toBe(
      false,
    )
    // The room is taken before there is anything to say, by a twin that holds
    // the place and never takes the focus.
    expectBackToTailRoom(wrapper)

    await scrollUp(wrapper.find('[data-id="hilos-log-pane"]').element)
    pushAppended({ followId, lines: [wireLine('one'), wireLine('two')] })
    await nextTick()

    expect(
      wrapper.find('[data-id="hilos-log-back-to-tail-count"]').text(),
    ).toBe('2')
    // The control stands in its room, in the flow, and not over the pane.
    const room = expectBackToTailRoom(wrapper)
    const back = room.find('[data-id="hilos-log-back-to-tail"]')
    expect(back.exists()).toBe(true)
    expect(back.classes()).not.toContain('position-absolute')
    // The pane under the reader's eyes does not move at all while they are up.
    expect(wrapper.findAll('[data-id="hilos-log-entry"]')).toHaveLength(0)

    await wrapper.find('[data-id="hilos-log-back-to-tail"]').trigger('click')

    expect(wrapper.findAll('[data-id="hilos-log-entry"]')).toHaveLength(2)
    expect(wrapper.find('[data-id="hilos-log-back-to-tail"]').exists()).toBe(
      false,
    )
  })

  it('writes a count past the ceiling as the widest label', async () => {
    const { connection, pushCatalog, pushAppended, sent } = makeConnection()
    const wrapper = mountPage(connection, LIVE_FILE)
    pushCatalog(catalog())
    await nextTick()
    const followId = sent.at(-1)?.requestId ?? ''

    await scrollUp(wrapper.find('[data-id="hilos-log-pane"]').element)
    const lines = Array.from({ length: 600 }, (_, i) => wireLine(`line ${i}`))
    pushAppended({ followId, lines })
    pushAppended({ followId, lines })
    await nextTick()

    expect(
      wrapper.find('[data-id="hilos-log-back-to-tail-count"]').text(),
    ).toBe('999+')
  })

  it('takes the pane back to the tail when the reader goes back with nothing waiting', async () => {
    const { connection, pushCatalog } = makeConnection()
    const wrapper = mountPage(connection, LIVE_FILE)
    pushCatalog(catalog())
    await nextTick()

    const pane = wrapper.find('[data-id="hilos-log-pane"]').element
    await scrollUp(pane)

    expect(wrapper.find('[data-id="hilos-log-back-to-tail"]').text()).toBe(
      'Back to the tail',
    )

    await wrapper.find('[data-id="hilos-log-back-to-tail"]').trigger('click')
    await nextTick()

    expect(pane.scrollTop).toBe(1000)
  })

  it('draws a note as a row of the feed, in the reading where it happened', async () => {
    const { connection, pushCatalog, pushAppended, sent } = makeConnection()
    const wrapper = mountPage(connection, LIVE_FILE)
    pushCatalog(catalog())
    await nextTick()

    pushAppended({ followId: sent.at(-1)?.requestId ?? '', rotated: true })
    await nextTick()

    expect(wrapper.find('[data-id="hilos-log-notice"]').text()).toBe(
      'The file was rotated. Reading continues from the start of the new one.',
    )
  })

  it('frames the entry a link opened the viewer on, and no other', async () => {
    const { connection, pushCatalog, sent, answer } = makeConnection()
    const wrapper = mountPage(connection, {
      ...LIVE_FILE,
      anchor: '1788000000250',
    })
    expect(wrapper.find('[data-id="hilos-log-anchor-badge"]').exists()).toBe(
      false,
    )
    pushCatalog(catalog())
    await nextTick()

    answer(sent.at(-1)?.requestId, {
      readable: true,
      lines: [
        wireLine(
          '[2026-09-06T10:00:02.250Z] ERROR: the entry the row named',
          'ERROR',
        ),
        wireLine('[2026-09-06T10:00:03.000Z] INFO: what happened next'),
      ],
      nextCursor: 4096,
      hasMore: true,
      anchorFound: true,
    })
    await flushPromises()

    const anchored = wrapper.findAll('[data-id="hilos-log-entry-anchor"]')
    expect(anchored).toHaveLength(1)
    expect(anchored[0].text()).toContain('the entry the row named')
    expect(wrapper.findAll('[aria-current="location"]')).toHaveLength(1)
    expect(wrapper.find('[data-id="hilos-log-anchor-badge"]').text()).toBe(
      'Sticking released',
    )
    expect(wrapper.findAll('[data-id="hilos-log-anchor-badge"]')).toHaveLength(
      1,
    )
    const icon = wrapper.get('[data-id="hilos-log-anchor-badge"] i')
    expect(icon.classes()).toContain('bi-pin-angle-fill')
    expect(icon.attributes('aria-hidden')).toBe('true')
    expect(wrapper.find('[data-id="hilos-log-tail-badge"]').exists()).toBe(
      false,
    )
    const time = anchored[0].get('[data-id="hilos-log-entry-time"]').text()
    expect(wrapper.get('[data-id="hilos-log-count"]').text()).toBe(
      `Lines around the entry of ${time}`,
    )
    expect(
      wrapper.find('[data-id="hilos-log-back-to-tail-count"]').exists(),
    ).toBe(false)
    expectStatusRoom(wrapper)

    await wrapper.get('[data-id="hilos-log-back-to-tail"]').trigger('click')
    await nextTick()
    expect(wrapper.find('[data-id="hilos-log-anchor-badge"]').exists()).toBe(
      false,
    )
    expect(wrapper.get('[data-id="hilos-log-count"]').text()).not.toContain(
      'Lines around the entry',
    )
  })

  it('reformats the anchored label with the reader zone without another answer', async () => {
    const zone = bindReaderZone()
    const { connection, pushCatalog, sent, answer } = makeConnection()
    const wrapper = mountPage(connection, {
      ...LIVE_FILE,
      anchor: String(DST_MOMENT),
    })

    try {
      pushCatalog(catalog())
      await nextTick()
      answer(sent.at(-1)?.requestId, {
        readable: true,
        lines: [wireLine(`[${DST_MOMENT_ISO}] ERROR: anchored entry`, 'ERROR')],
        nextCursor: null,
        hasMore: false,
        anchorFound: true,
      })
      await flushPromises()

      const time = wrapper.get('[data-id="hilos-log-entry-time"]')
      expect(wrapper.get('[data-id="hilos-log-count"]').text()).toBe(
        `Lines around the entry of ${time.text()}`,
      )
      const sentCount = sent.length

      zone.setZone('Europe/Warsaw')
      await nextTick()

      expect(wrapper.get('[data-id="hilos-log-entry-time"]').text()).toBe(
        logTimeLabel('Europe/Warsaw'),
      )
      expect(wrapper.get('[data-id="hilos-log-count"]').text()).toBe(
        `Lines around the entry of ${time.text()}`,
      )
      expect(sent).toHaveLength(sentCount)
    } finally {
      wrapper.unmount()
      zone.restore()
    }
  })

  it('keeps the confirmed badge through a draft and drops it when Search applies', async () => {
    const { connection, pushCatalog, sent, answer } = makeConnection()
    const wrapper = mountPage(connection, {
      ...LIVE_FILE,
      anchor: '1788000000250',
    })
    pushCatalog(catalog())
    await nextTick()
    answer(sent.at(-1)?.requestId, {
      readable: true,
      lines: [wireLine('[2026-09-06T10:00:02.250Z] ERROR: anchored entry')],
      nextCursor: null,
      hasMore: false,
      anchorFound: true,
    })
    await flushPromises()

    await wrapper.get('[data-id="hilos-log-substring"]').setValue('draft')
    expect(wrapper.find('[data-id="hilos-log-count"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="hilos-log-anchor-badge"]').exists()).toBe(
      true,
    )

    await wrapper.get('[data-id="hilos-log-search"]').trigger('click')

    expect(wrapper.find('[data-id="hilos-log-anchor-badge"]').exists()).toBe(
      false,
    )
    expect(wrapper.find('[data-id="hilos-log-count"]').text()).toBe(
      '1 entries shown',
    )
    expect(wrapper.find('[data-id="hilos-log-count"]').text()).not.toContain(
      'Lines around the entry',
    )
    expectCountRoom(wrapper)
    expectStatusRoom(wrapper)
  })

  it('does not report an anchored state when the anchored read is unreadable', async () => {
    const { connection, pushCatalog, sent, answer } = makeConnection()
    const wrapper = mountPage(connection, {
      ...LIVE_FILE,
      anchor: '1788000000250',
    })
    pushCatalog(catalog())
    await nextTick()
    answer(sent.at(-1)?.requestId, {
      readable: false,
      lines: [],
      nextCursor: null,
      hasMore: false,
      anchorFound: true,
    })
    await flushPromises()

    expect(wrapper.find('[data-id="hilos-log-anchor-badge"]').exists()).toBe(
      false,
    )
    expect(wrapper.find('[data-id="hilos-log-count"]').text()).toBe(
      '0 entries shown',
    )
    expectStatusRoom(wrapper)
  })

  it('says in the feed that the entry a link named is not in the file any more', async () => {
    const { connection, pushCatalog, sent, answer } = makeConnection()
    const wrapper = mountPage(connection, {
      ...LIVE_FILE,
      anchor: '1788000000250',
    })
    pushCatalog(catalog())
    await nextTick()

    answer(sent.at(-1)?.requestId, {
      readable: true,
      lines: [wireLine('[2026-09-06T11:00:00.000Z] INFO: the end of the file')],
      nextCursor: null,
      hasMore: false,
      anchorFound: false,
    })
    await flushPromises()

    expect(wrapper.find('[data-id="hilos-log-notice"]').text()).toBe(
      'The entry this link points at is no longer in this file. Showing the end of the file instead.',
    )
    expect(wrapper.find('[data-id="hilos-log-entry-anchor"]').exists()).toBe(
      false,
    )
    expect(wrapper.find('[data-id="hilos-log-anchor-badge"]').exists()).toBe(
      false,
    )
    expect(wrapper.get('[data-id="hilos-log-count"]').text()).toBe(
      '1 entries shown',
    )
    expectStatusRoom(wrapper)
  })
})

/**
 * Puts the reader above the tail: jsdom lays nothing out, so the pane is told how
 * tall it is and then scrolled the way a hand would.
 *
 * @param pane The scrolling pane element.
 */
async function scrollUp(pane: Element): Promise<void> {
  Object.defineProperty(pane, 'scrollHeight', {
    value: 1000,
    configurable: true,
  })
  Object.defineProperty(pane, 'clientHeight', {
    value: 200,
    configurable: true,
  })
  pane.scrollTop = 0
  pane.dispatchEvent(new Event('scroll'))
  await nextTick()
}

/**
 * Asserts the room of the way back to the tail stands in the strip, held by
 * exactly one invisible twin hidden from assistive technology, and returns it.
 *
 * @param wrapper The mounted page.
 */
function expectBackToTailRoom(
  wrapper: ReturnType<typeof mountPage>,
): ReturnType<ReturnType<typeof mountPage>['find']> {
  const room = wrapper.find('[data-id="hilos-log-back-to-tail-room"]')
  expect(room.exists()).toBe(true)
  const twins = room.findAll('.invisible')
  expect(twins).toHaveLength(1)
  expect(twins[0].attributes('aria-hidden')).toBe('true')

  return room
}

/**
 * Asserts the room of the line count stands in the strip, held by exactly
 * one invisible twin hidden from assistive technology, with one live status
 * region, and returns it.
 *
 * @param wrapper The mounted page.
 */
function expectCountRoom(
  wrapper: ReturnType<typeof mountPage>,
): ReturnType<ReturnType<typeof mountPage>['find']> {
  const room = wrapper.find('[data-id="hilos-log-count-room"]')
  expect(room.exists()).toBe(true)
  const twins = room.findAll('.invisible')
  expect(twins).toHaveLength(2)
  expect(twins.map((twin) => twin.attributes('aria-hidden'))).toEqual([
    'true',
    'true',
  ])
  expect(twins[0].findAll('[data-id]')).toHaveLength(0)
  expect(twins[0].text()).toBe('Lines around the entry of 23:59:59.999')
  expect(twins[1].text()).toBe('Not applied yet — press Search')
  const regions = room.findAll('[role="status"]')
  expect(regions).toHaveLength(1)
  expect(regions[0].attributes('aria-live')).toBe('polite')

  return room
}

/**
 * Asserts the permanent status cell keeps both badge shapes in the strip.
 *
 * @param wrapper The mounted page.
 */
function expectStatusRoom(
  wrapper: ReturnType<typeof mountPage>,
): ReturnType<ReturnType<typeof mountPage>['find']> {
  const room = wrapper.find('[data-id="hilos-log-status-room"]')
  expect(room.exists()).toBe(true)
  const twins = room.findAll('.invisible')
  expect(twins).toHaveLength(2)
  expect(twins.map((twin) => twin.attributes('aria-hidden'))).toEqual([
    'true',
    'true',
  ])
  for (const twin of twins) {
    expect(twin.findAll('[data-id]')).toHaveLength(0)
    expect(
      twin.findAll('button, a, input, select, textarea, [tabindex]'),
    ).toHaveLength(0)
  }

  return room
}
