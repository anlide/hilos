import { mount } from '@vue/test-utils'
import {
  createSignal,
  HilosPages,
  ingest,
  ScopeManager,
  type HilosConnection,
  type HilosRouter,
  type PageRouteMatch,
} from '@hilos/core/base'
import {
  type HilosDaemonHttpSummary,
  type HilosDaemonNodeHeading,
} from '@hilos/core/admin/daemon'
import { describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'

import HilosDaemonHttpServerPage from './HilosDaemonHttpServerPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

const LIVE_HEADING: HilosDaemonNodeHeading = {
  clustered: true,
  state: 'leader',
  silentSince: null,
}
const SILENT_HEADING: HilosDaemonNodeHeading = {
  clustered: true,
  state: 'silent',
  silentSince: 1_700_000_000,
}

function summary(
  overrides: Partial<HilosDaemonHttpSummary> = {},
): HilosDaemonHttpSummary {
  return {
    host: '0.0.0.0',
    port: 8080,
    countingHours: 23,
    requests: 1284,
    serverErrors: 2,
    slow: 3,
    ...overrides,
  }
}

function route(
  overrides: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    rowKey: 'GET /status',
    method: 'GET',
    path: '/status',
    agentType: null,
    requests: 1240,
    serverErrors: 0,
    slowestMs: 12,
    ...overrides,
  }
}

function harness(diagramBuilt = false, nodeId = 'n1') {
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.DAEMON_HTTP_SERVER)
  const currentRoute = createSignal<PageRouteMatch>({
    page: HilosPages.DAEMON_HTTP_SERVER,
    params: { nodeId },
    admin: true,
  })
  const router: HilosRouter = {
    currentRoute,
    currentPath: createSignal(`/hilos/daemon/${nodeId}/http`),
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
  type Listener = (signal: { type?: string; data: unknown }) => void
  const listeners = new Map<string, Set<Listener>>()
  const activeWindows = new Map<string, unknown>()
  const lifecycle: string[] = []
  const sendViewport = vi.fn()
  const connection = {
    on(event: string, listener: Listener): () => void {
      const group = listeners.get(event) ?? new Set<Listener>()
      group.add(listener)
      listeners.set(event, group)
      return () => {
        group.delete(listener)
      }
    },
    registerTableWindow(tableKey: string, sink: unknown): void {
      expect(activeWindows.has(tableKey)).toBe(false)
      activeWindows.set(tableKey, sink)
      lifecycle.push('register')
    },
    unregisterTableWindow(tableKey: string): void {
      activeWindows.delete(tableKey)
      lifecycle.push('unregister')
    },
    tableWindowDescriptors: () => ({}),
    sendTableViewport: sendViewport,
    sendTableRendered: vi.fn(),
  } as unknown as HilosConnection
  const view = mount(HilosDaemonHttpServerPage, {
    props: { context: { connection, scopes } },
    global: {
      provide: { [hilosRouterKey as symbol]: router },
      stubs: { HilosAdminPage: { template: '<main><slot /></main>' } },
    },
  })

  function emit(event: string, signal: { type?: string; data: unknown }): void {
    for (const listener of listeners.get(event) ?? []) {
      listener(signal)
    }
  }

  function windowData(rows: Record<string, unknown>[]) {
    return {
      rows: rows.map((row) => ({
        rowKey: String(row.rowKey),
        slots: { route: row },
      })),
      totalCount: rows.length,
      totalExact: true,
      limit: 25,
      sort: null,
      firstAnchor: null,
      lastAnchor: null,
      rowsBefore: 0,
    }
  }

  return {
    view,
    lifecycle,
    activeWindows,
    listeners,
    sendViewport,
    response(
      http: HilosDaemonHttpSummary | null,
      node = LIVE_HEADING,
      rows?: Record<string, unknown>[],
    ): void {
      const data = { node, http }
      ingest(scopes.page()!, { data })
      emit('projectSignal', {
        type: 'page_response',
        data: {
          page: HilosPages.DAEMON_HTTP_SERVER,
          payload: {
            data,
            ...(rows === undefined
              ? {}
              : { windows: { hilosDaemonHttpRoutes: windowData(rows) } }),
          },
        },
      })
      // The cold window installs the node filter and requests its own viewport;
      // answer that request as the existing HTTP table does on the wire.
      if (rows !== undefined) {
        emit('tableWindow', {
          data: {
            page: HilosPages.DAEMON_HTTP_SERVER,
            tableKey: 'hilosDaemonHttpRoutes',
            ...windowData(rows),
          },
        })
      }
    },
    window(rows: Record<string, unknown>[]): void {
      emit('tableWindow', {
        data: {
          page: HilosPages.DAEMON_HTTP_SERVER,
          tableKey: 'hilosDaemonHttpRoutes',
          ...windowData(rows),
        },
      })
    },
    selectNode(nextNodeId: string): void {
      currentRoute.set({
        page: HilosPages.DAEMON_HTTP_SERVER,
        params: { nodeId: nextNodeId },
        admin: true,
      })
      scopes.openPage(HilosPages.DAEMON_HTTP_SERVER)
    },
  }
}

describe('HilosDaemonHttpServerPage', () => {
  it('shows all summary values and the period from the report', async () => {
    const h = harness()
    h.response(summary())
    await nextTick()
    expect(h.view.find('[data-id="hilos-daemon-http-address"]').text()).toBe(
      '0.0.0.0:8080',
    )
    expect(h.view.find('[data-id="hilos-daemon-http-requests"]').text()).toBe(
      (1284).toLocaleString(),
    )
    expect(h.view.find('[data-id="hilos-daemon-http-errors"]').text()).toBe('2')
    expect(h.view.find('[data-id="hilos-daemon-http-slow"]').text()).toBe('3')
    expect(h.view.find('[data-id="hilos-daemon-http-period"]').text()).toBe(
      'Since start, 23 h',
    )
    expect(h.view.text()).toContain('Listening')
    expect(h.view.text()).toContain('5xx · Since start, 23 h')
    expect(h.view.text()).toContain('Over 1 s · Since start, 23 h')
    expect(h.view.find('[data-id="hilos-daemon-http-status"]').exists()).toBe(
      false,
    )
    expect(
      h.view.find('[data-id="hilos-daemon-http-address"]').find('a').exists(),
    ).toBe(false)
    for (const icon of h.view.findAll('.row > .col i')) {
      expect(icon.attributes('aria-hidden')).toBe('true')
    }
    h.view.unmount()
  })

  it('distinguishes no report from known zeros and keeps a cold table loading', async () => {
    const h = harness()
    h.response(null, LIVE_HEADING, [])
    await nextTick()
    for (const name of ['address', 'requests', 'errors', 'slow']) {
      expect(h.view.find(`[data-id="hilos-daemon-http-${name}"]`).text()).toBe(
        '—',
      )
    }
    const status = h.view.find('[data-id="hilos-daemon-http-status"]')
    expect(status.attributes('data-status')).toBe('waiting')
    expect(status.text()).toBe('The node has not reported HTTP metrics yet')
    expect(h.view.find('[data-id="hilos-table-empty"]').text()).toContain(
      status.text(),
    )

    h.view.unmount()
    const cold = harness()
    cold.response(
      summary({ requests: 0, serverErrors: 0, slow: 0, countingHours: 0 }),
    )
    await nextTick()
    for (const name of ['requests', 'errors', 'slow']) {
      expect(
        cold.view.find(`[data-id="hilos-daemon-http-${name}"]`).text(),
      ).toBe('0')
    }
    expect(cold.view.find('[data-id="hilos-daemon-http-period"]').text()).toBe(
      'Since start, <1 h',
    )
    expect(cold.view.find('[data-id="hilos-table-loading"]').exists()).toBe(
      true,
    )
    expect(cold.view.find('[data-id="hilos-table-empty"]').exists()).toBe(false)
    expect(
      cold.view.find('[data-id="hilos-daemon-http-status"]').exists(),
    ).toBe(false)
    cold.view.unmount()
  })

  it('updates the complete page data, including address, reset counters and null', async () => {
    const h = harness()
    h.response(summary())
    await nextTick()
    h.response(
      summary({
        host: '::1',
        port: 9090,
        requests: 0,
        serverErrors: 0,
        slow: 0,
        countingHours: 24,
      }),
    )
    await nextTick()
    expect(h.view.find('[data-id="hilos-daemon-http-address"]').text()).toBe(
      '[::1]:9090',
    )
    expect(h.view.find('[data-id="hilos-daemon-http-requests"]').text()).toBe(
      '0',
    )
    expect(h.view.find('[data-id="hilos-daemon-http-errors"]').text()).toBe('0')
    expect(h.view.find('[data-id="hilos-daemon-http-slow"]').text()).toBe('0')
    expect(h.view.find('[data-id="hilos-daemon-http-period"]').text()).toBe(
      'Last 24 h',
    )
    h.response(null)
    await nextTick()
    expect(h.view.find('[data-id="hilos-daemon-http-address"]').text()).toBe(
      '—',
    )
    expect(h.view.find('[data-id="hilos-daemon-http-requests"]').text()).toBe(
      '—',
    )
    h.view.unmount()
  })

  it('keeps the last values and routes on silence and recovers on a live report', async () => {
    const h = harness()
    h.response(summary(), LIVE_HEADING, [route()])
    await nextTick()
    const row = h.view.find('[data-id="hilos-table-row-GET /status"]')
    h.response(summary(), SILENT_HEADING, [route()])
    await nextTick()
    const status = h.view.find('[data-id="hilos-daemon-http-status"]')
    expect(status.attributes('data-status')).toBe('last-report')
    expect(status.text()).toMatch(
      /^This node has been silent since .+\. The values below are from its last report\.$/,
    )
    expect(h.view.text()).toContain('Last report')
    expect(h.view.find('[data-id="hilos-daemon-http-period"]').text()).toBe(
      'Since start, 23 h',
    )
    expect(h.view.find('[data-id="hilos-daemon-http-requests"]').text()).toBe(
      (1284).toLocaleString(),
    )
    const replacement = h.view.find('[data-id="hilos-table-row-GET /status"]')
    expect(replacement.element).not.toBe(row.element)
    expect(replacement.text()).toBe(row.text())
    expect(h.view.find('[data-id="hilos-daemon-node-state"]').text()).toBe(
      'Silent',
    )
    h.response(summary(), LIVE_HEADING, [route()])
    await nextTick()
    expect(h.view.find('[data-id="hilos-daemon-http-status"]').exists()).toBe(
      false,
    )
    expect(h.view.text()).toContain('Listening')
    h.view.unmount()
  })

  it('words silence without an HTTP report and reserves every message state', async () => {
    const h = harness()
    await nextTick()
    const twins = h.view.findAll('main > .hilos-stack > p.invisible')
    expect(twins).toHaveLength(3)
    for (const twin of twins) {
      expect(twin.attributes('aria-hidden')).toBe('true')
      expect(twin.attributes('data-id')).toBeUndefined()
      expect(twin.attributes('role')).toBeUndefined()
      expect(twin.attributes('aria-live')).toBeUndefined()
    }
    h.response(null, SILENT_HEADING, [])
    await nextTick()
    const status = h.view.find('[data-id="hilos-daemon-http-status"]')
    expect(status.attributes('data-status')).toBe('silent')
    expect(status.text()).toBe(
      'The node is silent and has not reported HTTP metrics',
    )
    expect(h.view.find('[data-id="hilos-table-empty"]').text()).toContain(
      status.text(),
    )
    expect(status.attributes('role')).toBeUndefined()
    expect(h.view.findAll('[data-id="hilos-daemon-http-status"]')).toHaveLength(
      1,
    )
    h.response(summary(), LIVE_HEADING)
    await nextTick()
    expect(
      h.view
        .findAll('main > .hilos-stack > p.invisible')
        .map((node) => node.element),
    ).toEqual(twins.map((node) => node.element))
    h.view.unmount()
  })

  it('draws routes, non-GET methods, the agent badge, zero counts and No route', async () => {
    const h = harness()
    h.response(summary(), LIVE_HEADING, [
      route(),
      route({
        rowKey: 'POST /_hilos/file',
        method: 'POST',
        path: '/_hilos/file',
        agentType: 'hilos_files_library',
        requests: 37,
        serverErrors: 1,
        slowestMs: 1240,
      }),
      route({
        rowKey: 'unrouted',
        method: null,
        path: null,
        requests: 0,
        slowestMs: null,
      }),
    ])
    await nextTick()
    const get = h.view.find('[data-id="hilos-table-row-GET /status"]')
    expect(get.find('code').text()).toBe('/status')
    expect(get.findAll('td')[1]?.text()).toBe((1240).toLocaleString())
    expect(get.findAll('td')[2]?.find('span').classes()).toContain(
      'text-body-secondary',
    )
    const agent = h.view.find('[data-id="hilos-table-row-POST /_hilos/file"]')
    expect(agent.find('code').text()).toBe('POST /_hilos/file')
    expect(agent.text()).toContain('agent')
    expect(agent.find('.badge.font-monospace').text()).toBe(
      'hilos_files_library',
    )
    expect(agent.findAll('td')[3]?.text()).toBe(`${(1240).toLocaleString()} ms`)
    const unrouted = h.view.find('[data-id="hilos-table-row-unrouted"]')
    expect(unrouted.find('code').text()).toBe('No route')
    expect(unrouted.findAll('td')[1]?.find('span').classes()).toContain(
      'text-body-secondary',
    )
    expect(unrouted.findAll('td')[3]?.text()).toBe('—')
    h.view.unmount()
  })

  it('hides the unbuilt diagram and draws the node line on standalone', async () => {
    const h = harness()
    h.response(summary())
    await nextTick()
    expect(h.view.find('[data-id="hilos-daemon-node-id"]').text()).toBe('n1')
    expect(h.view.find('[data-id="hilos-daemon-node-diagram"]').exists()).toBe(
      false,
    )
    h.view.unmount()
    const solo = harness(true, 'standalone')
    solo.response(summary(), {
      clustered: false,
      state: null,
      silentSince: null,
    })
    await nextTick()
    expect(solo.view.find('[data-id="hilos-daemon-node-id"]').text()).toBe(
      'standalone',
    )
    expect(solo.view.find('[data-id="hilos-daemon-node-solo"]').text()).toBe(
      'Single installation',
    )
    expect(
      solo.view.find('[data-id="hilos-daemon-node-diagram"]').exists(),
    ).toBe(false)
    solo.view.unmount()
  })

  it('uses the built root address for the shared diagram link', async () => {
    const h = harness(true)
    h.response(summary())
    await nextTick()
    expect(
      h.view.find('[data-id="hilos-daemon-node-diagram"]').attributes('href'),
    ).toBe('/hilos/daemon')
    h.view.unmount()
  })

  it('disposes the old node binding before starting the next one and releases all listeners on unmount', async () => {
    const h = harness()
    h.response(summary(), LIVE_HEADING, [route()])
    await nextTick()
    expect(h.sendViewport.mock.calls.at(-1)?.[2].filter).toEqual({ node: 'n1' })
    const oldWindow = h.activeWindows.get('hilosDaemonHttpRoutes')
    h.sendViewport.mockClear()
    h.selectNode('n2')
    h.response(summary({ host: 'n2', requests: 44 }), LIVE_HEADING, [
      route({ rowKey: 'GET /n2', path: '/n2', requests: 44 }),
    ])
    await nextTick()
    expect(h.lifecycle).toEqual(['register', 'unregister', 'register'])
    expect(h.activeWindows.size).toBe(1)
    expect(h.activeWindows.get('hilosDaemonHttpRoutes')).not.toBe(oldWindow)
    expect(h.listeners.get('tableWindow')?.size).toBe(1)
    expect(h.sendViewport).toHaveBeenCalledTimes(1)
    expect(h.sendViewport.mock.calls[0]?.[2].filter).toEqual({ node: 'n2' })
    expect(h.view.find('[data-id="hilos-daemon-node-id"]').text()).toBe('n2')
    expect(h.view.find('[data-id="hilos-daemon-http-address"]').text()).toBe(
      'n2:8080',
    )
    expect(
      h.view.find('[data-id="hilos-table-row-GET /status"]').exists(),
    ).toBe(false)
    h.window([route({ rowKey: 'GET /new', path: '/new' })])
    await nextTick()
    expect(h.view.find('[data-id="hilos-table-row-GET /new"]').exists()).toBe(
      true,
    )
    h.view.unmount()
    expect(h.lifecycle).toEqual([
      'register',
      'unregister',
      'register',
      'unregister',
    ])
    expect(h.activeWindows.size).toBe(0)
    for (const group of h.listeners.values()) {
      expect(group.size).toBe(0)
    }
  })
})
