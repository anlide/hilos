import { mount, flushPromises, type VueWrapper } from '@vue/test-utils'
import {
  ActionError,
  DAEMON_NODE_DATA,
  DAEMON_PROCESSES_REPORTED_DATA,
  HilosPages,
  ScopeManager,
  createSignal,
  type ActionHandle,
  type ActionLifecycle,
  type ActionResult,
  type HilosConnection,
  type HilosRouter,
  type HilosDaemonNodeHeading,
  type PageRouteMatch,
} from '@hilos/core'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { nextTick, ref } from 'vue'

import HilosDaemonAgentsPage from './HilosDaemonAgentsPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'
import { hilosAdminViewModeKey } from '../../hilosAdminViewMode.js'

const views: VueWrapper[] = []
afterEach(() => {
  for (const view of views.splice(0)) view.unmount()
  document.body.innerHTML = ''
})

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
    sendTableRowFocus(): void {},
  } as unknown as HilosConnection
  const view = mount(HilosDaemonAgentsPage, {
    props: { context: { connection, scopes, actions: {} as ActionLifecycle } },
    global: {
      provide: { [hilosRouterKey as symbol]: router() },
      stubs: { HilosAdminPage: { template: '<main><slot /></main>' } },
    },
  })

  views.push(view)

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

function restartHarness(clustered = false, placement = 'node', viewer = false) {
  const scopes = new ScopeManager()
  const scope = scopes.openPage(HilosPages.DAEMON_AGENTS)
  scope.data.set(DAEMON_NODE_DATA, {
    clustered,
    state: clustered ? 'leader' : null,
    silentSince: null,
  })
  scope.data.set(DAEMON_PROCESSES_REPORTED_DATA, true)
  const listeners = new Map<string, (signal: { data: unknown }) => void>()
  const connection = {
    on(event: string, listener: (signal: { data: unknown }) => void) {
      listeners.set(event, listener)
      return () => {}
    },
    registerTableWindow(): void {},
    unregisterTableWindow(): void {},
    tableWindowDescriptors: () => ({}),
    sendTableViewport(): void {},
    sendTableRendered(): void {},
    sendTableRowFocus(): void {},
  } as unknown as HilosConnection
  let resolve: (value: ActionResult) => void = () => {}
  let reject: (reason: unknown) => void = () => {}
  const dispatch = vi.fn(
    (): ActionHandle => ({
      requestId: 'restart-1',
      loading: createSignal(false),
      done: new Promise((yes, no) => {
        resolve = yes
        reject = no
      }),
    }),
  )
  const router = {
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
  } as HilosRouter
  const view = mount(HilosDaemonAgentsPage, {
    attachTo: document.body,
    props: {
      context: {
        connection,
        scopes,
        actions: { dispatch } as unknown as ActionLifecycle,
      },
    },
    global: {
      provide: {
        [hilosRouterKey as symbol]: router,
        [hilosAdminViewModeKey as symbol]: ref(viewer),
      },
      stubs: { HilosAdminPage: { template: '<main><slot /></main>' } },
    },
  })
  views.push(view)
  const window = (restarting = false, absent = false) =>
    listeners.get('tableWindow')?.({
      data: {
        page: HilosPages.DAEMON_AGENTS,
        tableKey: 'hilosDaemonAgents',
        rows: absent
          ? []
          : [
              {
                rowKey: 'agent:1',
                slots: {
                  agent: {
                    agentId: 'agent:1',
                    restarting,
                    workerIndex: restarting ? null : 1,
                    workerKind: restarting ? null : 'regular',
                    placement,
                    idle: false,
                    ownsRt: [],
                    ownsDb: [],
                    logStream: 'agent-agent_1.log',
                  },
                },
              },
            ],
        totalCount: absent ? 0 : 1,
      },
    })
  window()
  return {
    view,
    dispatch,
    scope,
    window,
    success: () => resolve({ message: 'Agent agent:1 restarted on node n1.' }),
    fail: () =>
      reject(
        new ActionError(
          'hilos_daemon_agent_restart',
          'fail',
          'Restart refused',
        ),
      ),
    gone: () =>
      listeners.get('tableViewportDelta')?.({
        data: {
          page: HilosPages.DAEMON_AGENTS,
          tableKey: 'hilosDaemonAgents',
          rowKey: 'agent:1',
          kind: 'row_removed',
          reason: 'left_set',
        },
      }),
  }
}

function modal(id: string): HTMLElement {
  const element = document.querySelector<HTMLElement>(`[data-id="${id}"]`)
  if (!element) throw new Error(`Missing modal element ${id}`)
  return element
}

async function open(h: ReturnType<typeof restartHarness>): Promise<void> {
  await nextTick()
  await h.view
    .get('[data-id="hilos-daemon-agent-restart-agent:1"]')
    .trigger('click')
  await nextTick()
}

describe('HilosDaemonAgentsPage restart', () => {
  it.each([
    [false, 'policy', false],
    [true, 'node', false],
    [true, 'leader', false],
    [true, 'policy', true],
  ])(
    'shows its target, CLI and POLICY warning only in a cluster (%s, %s)',
    async (clustered, placement, warning) => {
      const h = restartHarness(clustered, placement)
      await open(h)
      expect(modal('hilos-daemon-restart-agent').textContent).toBe('agent:1')
      expect(modal('hilos-daemon-restart-node').textContent).toBe('n1')
      expect(modal('hilos-daemon-restart-cli').textContent).toBe(
        `php cli.php agent:restart agent:1${clustered ? ' --node=n1' : ''}`,
      )
      expect(
        document.querySelector('[data-id="hilos-daemon-restart-policy"]') !==
          null,
      ).toBe(warning)
      modal('hilos-daemon-restart-cancel').click()
      await nextTick()
      expect(document.querySelector('[data-id="modal"]')).toBeNull()
      expect(h.dispatch).not.toHaveBeenCalled()
    },
  )

  it('blocks repeated confirm and all close paths while busy, then closes only on success', async () => {
    const h = restartHarness()
    await open(h)
    modal('hilos-daemon-restart-confirm').click()
    await nextTick()
    expect(h.dispatch).toHaveBeenCalledWith('hilos_daemon_agent_restart', {
      agentId: 'agent:1',
      nodeId: 'n1',
    })
    expect(
      (modal('hilos-daemon-restart-confirm') as HTMLButtonElement).disabled,
    ).toBe(true)
    expect(
      (modal('hilos-daemon-restart-cancel') as HTMLButtonElement).disabled,
    ).toBe(true)
    modal('modal-close').click()
    modal('modal').dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
    )
    modal('modal').click()
    await nextTick()
    expect(document.querySelector('[data-id="modal"]')).not.toBeNull()
    expect(h.dispatch).toHaveBeenCalledTimes(1)
    h.success()
    await flushPromises()
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
  })

  it('keeps a refused confirmation open with the standard action error', async () => {
    const h = restartHarness()
    await open(h)
    modal('hilos-daemon-restart-confirm').click()
    h.fail()
    await flushPromises()
    expect(document.querySelector('[data-id="modal"]')).not.toBeNull()
    expect(document.body.textContent).toContain('Restart refused')
    expect(
      (modal('hilos-daemon-restart-confirm') as HTMLButtonElement).disabled,
    ).toBe(false)
  })

  it('shows a live restart badge and disables viewer, silent and restarting rows', async () => {
    const viewer = restartHarness(false, 'node', true)
    await nextTick()
    expect(
      viewer.view
        .get('[data-id="hilos-daemon-agent-restart-agent:1"]')
        .attributes('disabled'),
    ).toBeDefined()
    const h = restartHarness()
    h.window(true)
    await nextTick()
    expect(
      h.view.get('[data-id="hilos-daemon-agent-restarting-agent:1"]').text(),
    ).toBe('Restarting')
    expect(
      h.view
        .get('[data-id="hilos-daemon-agent-restart-agent:1"]')
        .attributes('disabled'),
    ).toBeDefined()
    expect(h.view.text()).toContain('—')
    h.window()
    h.scope.data.set(DAEMON_NODE_DATA, {
      clustered: true,
      state: 'silent',
      silentSince: 10,
    })
    await nextTick()
    expect(
      h.view
        .get('[data-id="hilos-daemon-agent-restart-agent:1"]')
        .attributes('disabled'),
    ).toBeDefined()
  })

  it('keeps a disappeared target visible by identity and refuses confirm', async () => {
    const h = restartHarness()
    await open(h)
    h.gone()
    await nextTick()
    expect(modal('hilos-daemon-restart-agent').textContent).toBe('agent:1')
    expect(
      (modal('hilos-daemon-restart-confirm') as HTMLButtonElement).disabled,
    ).toBe(true)
    expect(
      modal('hilos-daemon-restart-unavailable').classList.contains('invisible'),
    ).toBe(false)
    expect(h.dispatch).not.toHaveBeenCalled()
  })
})
