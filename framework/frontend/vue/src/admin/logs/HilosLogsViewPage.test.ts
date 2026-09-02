import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { describe, expect, it } from 'vitest'
import {
  ActionLifecycle,
  createSignal,
  HilosPages,
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
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
}

/**
 * A connection stub handing back the one frame this screen lives on — the page's
 * own catalog — and swallowing the reads, whose answers the pane states below do
 * not depend on.
 */
function makeConnection(): {
  connection: HilosConnection
  pushCatalog: (frame: HilosLogViewerCatalog) => void
} {
  const projectListeners: ((signal: {
    type: string
    data: unknown
  }) => void)[] = []
  const connection = {
    on(event: string, listener: (signal: never) => void): () => void {
      if (event === 'projectSignal') {
        projectListeners.push(
          listener as unknown as (signal: {
            type: string
            data: unknown
          }) => void,
        )
      }

      return () => {}
    },
    sendAction(): boolean {
      return true
    },
  } as unknown as HilosConnection

  return {
    connection,
    pushCatalog(frame: HilosLogViewerCatalog): void {
      for (const listener of projectListeners) {
        listener({ type: LOG_VIEWER_CATALOG_SIGNAL, data: frame })
      }
    },
  }
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
})
