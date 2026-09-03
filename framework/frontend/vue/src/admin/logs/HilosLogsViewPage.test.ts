import { flushPromises, mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { describe, expect, it } from 'vitest'
import {
  ActionLifecycle,
  createSignal,
  HilosPages,
  LOGS_FOLLOW_START_ACTION,
  LOG_LINES_APPENDED_SIGNAL,
  LOG_VIEWER_CATALOG_SIGNAL,
} from '@hilos/core'
import type {
  HilosConnection,
  HilosLogViewerCatalog,
  HilosRouter,
  PageRouteMatch,
} from '@hilos/core'

import HilosLogsViewPage from './HilosLogsViewPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

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
    global: { provide: { [hilosRouterKey as symbol]: router(params) } },
  })
}

describe('HilosLogsViewPage', () => {
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

  it('drops the stream when the new source does not hold it', async () => {
    const { connection, pushCatalog } = makeConnection()
    const wrapper = mountPage(connection, {
      nodeId: '-',
      source: String(BATCH),
      stream: 'agent-chat.log',
    })
    pushCatalog(catalog())
    await nextTick()

    await wrapper.find('[data-id="hilos-log-source"]').setValue('live')

    // The file is not in the live journal, so it stops being chosen rather than
    // staying selected and naming nothing.
    expect(wrapper.find('[data-id="hilos-log-empty-unchosen"]').exists()).toBe(
      true,
    )
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
    pushCatalog(catalog())
    await nextTick()

    expect(wrapper.find('[data-id="hilos-log-tail-badge"]').exists()).toBe(
      false,
    )

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

    await scrollUp(wrapper.find('[data-id="hilos-log-pane"]').element)
    pushAppended({ followId, lines: [wireLine('one'), wireLine('two')] })
    await nextTick()

    expect(wrapper.find('[data-id="hilos-log-back-to-tail"]').text()).toBe(
      'Back to the tail · 2 new',
    )
    // The pane under the reader's eyes does not move at all while they are up.
    expect(wrapper.findAll('[data-id="hilos-log-entry"]')).toHaveLength(0)

    await wrapper.find('[data-id="hilos-log-back-to-tail"]').trigger('click')

    expect(wrapper.findAll('[data-id="hilos-log-entry"]')).toHaveLength(2)
    expect(wrapper.find('[data-id="hilos-log-back-to-tail"]').exists()).toBe(
      false,
    )
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
