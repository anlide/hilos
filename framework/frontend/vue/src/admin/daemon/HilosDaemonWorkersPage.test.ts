import { mount } from '@vue/test-utils'
import {
  DAEMON_NODE_DATA,
  DAEMON_WORKERS_PROCESSES_REPORTED_DATA,
  HilosPages,
  ScopeManager,
  createSignal,
  type HilosConnection,
  type HilosDaemonNodeHeading,
  type HilosRouter,
  type PageRouteMatch,
} from '@hilos/core'
import { describe, expect, it } from 'vitest'
import { nextTick } from 'vue'

import HilosDaemonWorkersPage from './HilosDaemonWorkersPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

function router(diagramBuilt = false): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.DAEMON_WORKERS,
      params: { nodeId: 'n1' },
      admin: true,
    }),
    currentPath: createSignal('/hilos/daemon/n1/workers'),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal(undefined),
    dashboardSections: createSignal(undefined),
    resolvePath: (page) =>
      diagramBuilt && page === HilosPages.DAEMON ? '/hilos/daemon' : undefined,
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    onLeave: () => () => {},
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
}

function harness(diagramBuilt = false) {
  const scopes = new ScopeManager()
  const scope = scopes.openPage(HilosPages.DAEMON_WORKERS)
  const listeners: Array<(signal: { data: unknown }) => void> = []
  const connection = {
    on(event: string, listener: (signal: never) => void): () => void {
      if (event === 'tableWindow') {
        listeners.push(listener as (signal: { data: unknown }) => void)
      }
      return () => {}
    },
    registerTableWindow(): void {},
    unregisterTableWindow(): void {},
    tableWindowDescriptors: () => ({}),
    sendTableViewport(): void {},
    sendTableRendered(): void {},
  } as unknown as HilosConnection
  const view = mount(HilosDaemonWorkersPage, {
    props: { context: { connection, scopes } },
    global: {
      provide: { [hilosRouterKey as symbol]: router(diagramBuilt) },
      stubs: { HilosAdminPage: { template: '<main><slot /></main>' } },
    },
  })

  return {
    view,
    scope,
    heading(value: HilosDaemonNodeHeading): void {
      scope.data.set(DAEMON_NODE_DATA, value)
    },
    reported(value: boolean): void {
      scope.data.set(DAEMON_WORKERS_PROCESSES_REPORTED_DATA, value)
    },
    window(rows: Record<string, unknown>[]): void {
      for (const listener of listeners) {
        listener({
          data: {
            page: HilosPages.DAEMON_WORKERS,
            tableKey: 'hilosDaemonWorkers',
            rows: rows.map((worker) => ({
              rowKey: String(worker.rowKey),
              slots: { worker },
            })),
            totalCount: rows.length,
          },
        })
      }
    },
  }
}

function worker(
  overrides: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    rowKey: 'regular:1',
    index: 1,
    kind: 'regular',
    pid: 1234,
    memoryBytes: 2048,
    agentCount: 2,
    agentIds: ['chat_room:12', 'chat_user:7'],
    logStream: 'worker-regular-1.log',
    ...overrides,
  }
}

describe('HilosDaemonWorkersPage', () => {
  it('shows a cluster state and the diagram only when the root is built', async () => {
    const built = harness(true)
    built.heading({ clustered: true, state: 'leader', silentSince: null })
    await nextTick()
    expect(built.view.find('[data-id="hilos-daemon-node-id"]').text()).toBe(
      'n1',
    )
    expect(built.view.find('[data-id="hilos-daemon-node-state"]').text()).toBe(
      'Leader',
    )
    expect(
      built.view
        .find('[data-id="hilos-daemon-node-state"]')
        .attributes('data-state'),
    ).toBe('leader')
    expect(
      built.view
        .find('[data-id="hilos-daemon-node-diagram"]')
        .attributes('href'),
    ).toBe('/hilos/daemon')
    built.view.unmount()

    const staged = harness()
    staged.heading({ clustered: true, state: 'leader', silentSince: null })
    await nextTick()
    expect(
      staged.view.find('[data-id="hilos-daemon-node-diagram"]').exists(),
    ).toBe(false)
    staged.view.unmount()
  })

  it('shows a single installation without a cluster state or diagram', async () => {
    const h = harness(true)
    h.heading({ clustered: false, state: null, silentSince: null })
    await nextTick()
    expect(h.view.find('[data-id="hilos-daemon-node-solo"]').text()).toBe(
      'Single installation',
    )
    expect(h.view.find('[data-id="hilos-daemon-node-state"]').exists()).toBe(
      false,
    )
    expect(h.view.find('[data-id="hilos-daemon-node-diagram"]').exists()).toBe(
      false,
    )
    h.view.unmount()
  })

  it('reacts to whole-page heading changes and distinguishes all empty states', async () => {
    const h = harness()
    h.window([])
    await nextTick()
    expect(
      h.view.find('[data-id="hilos-daemon-workers-empty-waiting"]').text(),
    ).toContain('not zero workers')

    h.heading({ clustered: true, state: 'silent', silentSince: 1_700_000_000 })
    await nextTick()
    expect(h.view.find('[data-id="hilos-daemon-node-state"]').text()).toBe(
      'Silent',
    )
    expect(
      h.view.find('[data-id="hilos-daemon-workers-empty-silent"]').text(),
    ).toContain('never reported')

    h.reported(true)
    await nextTick()
    expect(
      h.view.find('[data-id="hilos-daemon-workers-empty-none"]').text(),
    ).toBe('The node reports no workers')

    h.heading({ clustered: true, state: 'standby', silentSince: null })
    await nextTick()
    expect(h.view.find('[data-id="hilos-daemon-node-state"]').text()).toBe(
      'Standby',
    )
    expect(h.view.find('[data-id="hilos-viewport-table"]').exists()).toBe(true)
    h.view.unmount()
  })

  it('draws worker cells and agent details and keeps last rows under silence', async () => {
    const h = harness()
    h.heading({ clustered: true, state: 'leader', silentSince: null })
    h.reported(true)
    h.window([
      worker(),
      worker({
        rowKey: 'monopolistic:3',
        index: 3,
        kind: 'monopolistic',
        agentCount: 0,
        agentIds: [],
      }),
    ])
    await nextTick()
    const regular = h.view.find('[data-id="hilos-table-row-regular:1"]')
    const monopolistic = h.view.find(
      '[data-id="hilos-table-row-monopolistic:3"]',
    )
    expect(regular.findAll('td')[0]?.text()).toBe('w1')
    expect(monopolistic.findAll('td')[0]?.text()).toBe('m3')
    expect(regular.findAll('td')[1]?.text()).toBe('Ordinary')
    expect(regular.findAll('td')[2]?.text()).toBe('1234')
    expect(regular.findAll('td')[4]?.text()).toBe('2.0 KB')
    expect(monopolistic.findAll('td')[3]?.text()).toBe('·')
    expect(monopolistic.findAll('td')[3]?.find('span').classes()).toContain(
      'text-body-secondary',
    )
    expect(
      h.view
        .find('[data-id="hilos-daemon-worker-log-regular:1"]')
        .attributes('href'),
    ).toBe('/hilos/logs/view/n1/live/worker-regular-1.log')
    expect(
      h.view
        .find('[data-id="hilos-daemon-worker-log-monopolistic:3"]')
        .exists(),
    ).toBe(true)
    await h.view
      .find('[data-id="hilos-table-expand-regular:1"]')
      .trigger('click')
    await nextTick()
    expect(
      h.view.find('[data-id="hilos-daemon-worker-agents-regular:1"]').text(),
    ).toContain('chat_room:12')
    await h.view
      .find('[data-id="hilos-table-expand-monopolistic:3"]')
      .trigger('click')
    await nextTick()
    expect(
      h.view
        .find('[data-id="hilos-daemon-worker-agents-monopolistic:3"]')
        .text(),
    ).toBe('—')

    h.heading({ clustered: true, state: 'silent', silentSince: 1_700_000_000 })
    await nextTick()
    expect(
      h.view.find('[data-id="hilos-daemon-workers-silent"]').text(),
    ).toContain('from its last report')
    expect(h.view.find('[data-id="hilos-daemon-node-state"]').text()).toBe(
      'Silent',
    )
    expect(h.view.find('[data-id="hilos-table-row-regular:1"]').element).toBe(
      regular.element,
    )
    expect(
      h.view.find('[data-id="hilos-daemon-worker-agents-regular:1"]').exists(),
    ).toBe(true)
    expect(
      h.view.find('[data-id="hilos-daemon-worker-log-regular:1"]').exists(),
    ).toBe(true)
    h.view.unmount()
  })
})
