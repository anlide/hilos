import { mount } from '@vue/test-utils'
import {
  DAEMON_NODE_DATA,
  DAEMON_PROCESSES_REPORTED_DATA,
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

import HilosDaemonAgentsPage from './HilosDaemonAgentsPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

function router(): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.DAEMON_AGENTS,
      params: { nodeId: 'n1' },
      admin: true,
    }),
    currentPath: createSignal('/hilos/daemon/n1/agents'),
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
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
}

function harness() {
  const scopes = new ScopeManager()
  const scope = scopes.openPage(HilosPages.DAEMON_AGENTS)
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
  const view = mount(HilosDaemonAgentsPage, {
    props: { context: { connection, scopes } },
    global: {
      provide: { [hilosRouterKey as symbol]: router() },
      stubs: { HilosAdminPage: { template: '<main><slot /></main>' } },
    },
  })

  return {
    view,
    heading(value: HilosDaemonNodeHeading): void {
      scope.data.set(DAEMON_NODE_DATA, value)
    },
    reported(value: boolean): void {
      scope.data.set(DAEMON_PROCESSES_REPORTED_DATA, value)
    },
    window(rows: Record<string, unknown>[]): void {
      for (const listener of listeners) {
        listener({
          data: {
            page: HilosPages.DAEMON_AGENTS,
            tableKey: 'hilosDaemonAgents',
            rows: rows.map((agent) => ({
              rowKey: String(agent.rowKey),
              slots: { agent },
            })),
            totalCount: rows.length,
          },
        })
      }
    },
  }
}

function agent(
  overrides: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    rowKey: 'chat_room:12',
    agentId: 'chat_room:12',
    workerIndex: 0,
    workerKind: 'regular',
    placement: 'node',
    idle: false,
    ownsRt: [{ collection: 'rooms', width: 'set' }],
    ownsDb: [],
    logStream: 'chat-room-12.log',
    ...overrides,
  }
}

describe('HilosDaemonAgentsPage', () => {
  it('draws the node line and all three empty states', async () => {
    const h = harness()
    h.window([])
    await nextTick()
    expect(h.view.find('[data-id="hilos-daemon-node-id"]').text()).toBe('n1')
    expect(
      h.view.find('[data-id="hilos-daemon-agents-empty-waiting"]').text(),
    ).toContain('not zero agents')

    h.heading({ clustered: true, state: 'silent', silentSince: 1_700_000_000 })
    await nextTick()
    expect(h.view.find('[data-id="hilos-daemon-node-state"]').text()).toBe(
      'Silent',
    )
    expect(
      h.view.find('[data-id="hilos-daemon-agents-empty-silent"]').text(),
    ).toContain('never reported')
    expect(h.view.find('[data-id="hilos-daemon-agents-silent"]').exists()).toBe(
      false,
    )

    h.reported(true)
    await nextTick()
    expect(
      h.view.find('[data-id="hilos-daemon-agents-empty-none"]').text(),
    ).toBe('The node reports no agents')
    h.view.unmount()
  })

  it('draws workers, clustered kind, ownership width and a log link', async () => {
    const h = harness()
    h.heading({ clustered: true, state: 'leader', silentSince: null })
    h.reported(true)
    h.window([
      agent(),
      agent({
        rowKey: 'hilos_settings',
        agentId: 'hilos_settings',
        workerIndex: 2,
        workerKind: 'monopolistic',
        placement: 'leader',
        idle: true,
        ownsRt: [],
        ownsDb: [{ collection: 'settings', width: 'whole' }],
      }),
    ])
    await nextTick()
    const room = h.view.find('[data-id="hilos-table-row-chat_room:12"]')
    const settings = h.view.find('[data-id="hilos-table-row-hilos_settings"]')
    expect(room.findAll('td')[0]?.text()).toBe('chat_room:12')
    expect(room.findAll('td')[1]?.text()).toBe('w0')
    expect(settings.findAll('td')[1]?.text()).toBe('m2')
    expect(room.findAll('td')[2]?.text()).toBe('Node replica')
    expect(settings.findAll('td')[2]?.text()).toBe('Leader-hosted · lazy')
    expect(room.findAll('td')[3]?.text()).toBe('rooms · set')
    expect(room.findAll('td')[4]?.text()).toBe('—')
    expect(room.findAll('td')[4]?.find('code').classes()).toContain(
      'text-body-secondary',
    )
    expect(settings.findAll('td')[4]?.text()).toBe('settings')
    expect(
      h.view
        .find('[data-id="hilos-daemon-agent-log-chat_room:12"]')
        .attributes('href'),
    ).toBe('/hilos/logs/view/n1/live/chat-room-12.log')
    expect(
      h.view.find('[data-id="hilos-daemon-agent-log-chat_room:12"]').element
        .tagName,
    ).toBe('A')

    h.heading({ clustered: true, state: 'silent', silentSince: 1_700_000_000 })
    await nextTick()
    expect(
      h.view.find('[data-id="hilos-daemon-agents-silent"]').text(),
    ).toContain('from its last report')
    expect(
      h.view.find('[data-id="hilos-table-row-chat_room:12"]').element,
    ).toBe(room.element)
    h.view.unmount()
  })

  it('labels a single installation without cluster placement', async () => {
    const h = harness()
    h.heading({ clustered: false, state: null, silentSince: null })
    h.reported(true)
    h.window([agent({ idle: true })])
    await nextTick()
    expect(h.view.find('[data-id="hilos-daemon-node-solo"]').text()).toBe(
      'Single installation',
    )
    expect(
      h.view
        .find('[data-id="hilos-table-row-chat_room:12"]')
        .findAll('td')[2]
        ?.text(),
    ).toBe('Lazy')
    h.view.unmount()
  })
})
