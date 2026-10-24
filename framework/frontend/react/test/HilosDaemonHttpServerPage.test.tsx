import { openNodePage } from './helpers/hilosDaemonNode.js'
import { act, cleanup, render } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  ingest,
  type HilosDaemonHttpSummary,
  ScopeManager,
  pageIdentity,
  createSignal,
  HilosPages,
  type HilosConnection,
  type HilosRouter,
  type HilosDaemonNodeHeading,
  type PageRouteMatch,
} from '@hilos/core'
import { HilosDaemonHttpServerPage } from '../src/admin/daemon/HilosDaemonHttpServerPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

function harness() {
  const scopes = new ScopeManager()
  openNodePage(scopes, HilosPages.DAEMON_HTTP_SERVER)
  const currentRoute = createSignal<PageRouteMatch>({
    page: HilosPages.DAEMON_HTTP_SERVER,
    params: { nodeId: 'n1' },
    admin: true,
  })
  const router = {
    currentRoute,
    currentPath: createSignal('/hilos/daemon/n1/http'),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: pageIdentity(scopes),
    dashboardSections: createSignal(undefined),
    resolvePath: (page: string) =>
      page === HilosPages.DAEMON ? '/hilos/daemon' : undefined,
    navigate: vi.fn(),
  } as unknown as HilosRouter
  const listeners = new Map<string, Set<(payload: never) => void>>()
  const unregister = vi.fn()
  const register = vi.fn<HilosConnection['registerTableWindow']>()
  const connection = {
    on(event: string, callback: (payload: never) => void) {
      const group = listeners.get(event) ?? new Set()
      group.add(callback)
      listeners.set(event, group)
      return () => {
        group.delete(callback)
      }
    },
    registerTableWindow: register,
    unregisterTableWindow: unregister,
    tableWindowDescriptors: () => ({}),
    sendTableViewport: vi.fn(),
    sendTableRendered: vi.fn(),
  } as unknown as HilosConnection
  const view = render(
    <HilosRouterContext.Provider value={router}>
      <HilosDaemonHttpServerPage context={{ connection, scopes }} />
    </HilosRouterContext.Provider>,
  )
  return {
    view,
    currentRoute,
    register,
    unregister,
    listeners,
    sendViewport: connection.sendTableViewport,
    find: (id: string): HTMLElement | null =>
      view.container.querySelector(`[data-id="${id}"]`),
    response(
      http: HilosDaemonHttpSummary | null,
      node: HilosDaemonNodeHeading | null = LIVE,
      rows?: Record<string, unknown>[],
    ) {
      act(() => {
        const data = { http, node }
        ingest(scopes.page()!, { data })
        for (const listener of listeners.get('projectSignal') ?? [])
          listener({
            type: 'page_response',
            data: {
              page: HilosPages.DAEMON_HTTP_SERVER,
              payload: {
                data,
                ...(rows === undefined
                  ? {}
                  : {
                      windows: {
                        hilosDaemonHttpRoutes: {
                          rows: rows.map((route) => ({
                            rowKey: String(route.rowKey),
                            slots: { route },
                          })),
                          totalCount: rows.length,
                          totalExact: true,
                          limit: 25,
                          sort: null,
                          firstAnchor: null,
                          lastAnchor: null,
                          rowsBefore: 0,
                        },
                      },
                    }),
              },
            },
          } as never)
      })
    },
    selectNode(nodeId: string) {
      act(() => {
        openNodePage(scopes, HilosPages.DAEMON_HTTP_SERVER)
        currentRoute.set({
          page: HilosPages.DAEMON_HTTP_SERVER,
          params: { nodeId },
          admin: true,
        })
      })
    },
    window(rows: Record<string, unknown>[]) {
      act(() => {
        for (const listener of listeners.get('tableWindow') ?? [])
          listener({
            data: {
              page: HilosPages.DAEMON_HTTP_SERVER,
              tableKey: 'hilosDaemonHttpRoutes',
              rows: rows.map((route) => ({
                rowKey: String(route.rowKey),
                slots: { route },
              })),
              totalCount: rows.length,
              totalExact: true,
              limit: 25,
              firstAnchor: null,
              lastAnchor: null,
              rowsBefore: 0,
            },
          } as never)
      })
    },
  }
}

afterEach(cleanup)
const LIVE: HilosDaemonNodeHeading = {
  clustered: true,
  role: null,
  state: 'leader',
  silentSince: null,
}
const SILENT: HilosDaemonNodeHeading = {
  clustered: true,
  role: null,
  state: 'silent',
  silentSince: 1700000000,
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
function cells(h: ReturnType<typeof harness>, key: string) {
  return [...h.find(`hilos-table-row-${key}`)!.querySelectorAll('td')].slice(
    0,
    4,
  )
}
describe('HilosDaemonHttpServerPage', () => {
  it('reads the summary and its period from one page answer, independently of the route window', () => {
    const h = harness()
    h.response(summary())
    expect(h.find('hilos-daemon-http-address')?.textContent).toBe(
      '0.0.0.0:8080',
    )
    expect(h.find('hilos-daemon-http-requests')?.textContent).toBe(
      (1284).toLocaleString(),
    )
    expect(h.find('hilos-daemon-http-errors')?.textContent).toBe('2')
    expect(h.find('hilos-daemon-http-slow')?.textContent).toBe('3')
    expect(h.find('hilos-daemon-http-period')?.textContent).toBe(
      'Since start, 23 h',
    )
    expect(h.view.container.textContent).toContain('Listening')
    expect(h.view.container.textContent).toContain('5xx · Since start, 23 h')
    expect(h.view.container.textContent).toContain(
      'Over 1 s · Since start, 23 h',
    )
    expect(h.find('hilos-daemon-http-status')).toBeNull()
    expect(h.find('hilos-daemon-http-address')?.querySelector('a')).toBeNull()
    h.window([route({ requests: 1 })])
    expect(h.find('hilos-daemon-http-requests')?.textContent).toBe(
      (1284).toLocaleString(),
    )
    for (const icon of h.view.container.querySelectorAll('.row > .col i'))
      expect(icon.getAttribute('aria-hidden')).toBe('true')
  })
  it('keeps unknown metrics as dashes and distinguishes waiting, silence and known zero', () => {
    const h = harness()
    h.response(null, null)
    h.window([])
    for (const name of ['address', 'requests', 'errors', 'slow'])
      expect(h.find(`hilos-daemon-http-${name}`)?.textContent).toBe('—')
    expect(
      h.find('hilos-daemon-http-status')?.getAttribute('data-status'),
    ).toBe('waiting')
    expect(h.find('hilos-table-empty')?.textContent).toContain(
      'The node has not reported HTTP metrics yet',
    )
    h.response(null, SILENT)
    expect(
      h.find('hilos-daemon-http-status')?.getAttribute('data-status'),
    ).toBe('silent')
    expect(h.find('hilos-table-empty')?.textContent).toContain(
      'The node is silent and has not reported HTTP metrics',
    )
    h.view.unmount()
    const cold = harness()
    cold.response(
      summary({ requests: 0, serverErrors: 0, slow: 0, countingHours: 0 }),
    )
    for (const name of ['requests', 'errors', 'slow'])
      expect(cold.find(`hilos-daemon-http-${name}`)?.textContent).toBe('0')
    expect(cold.find('hilos-daemon-http-period')?.textContent).toBe(
      'Since start, <1 h',
    )
    expect(cold.find('hilos-table-loading')).not.toBeNull()
    expect(cold.find('hilos-table-empty')).toBeNull()
    expect(cold.find('hilos-daemon-http-status')).toBeNull()
  })
  it('updates whole-page address, reset counters, period and null; reserves all status messages', () => {
    const h = harness()
    const twins = [
      ...h.view.container.querySelectorAll('.hilos-stack > p.invisible'),
    ]
    expect(twins).toHaveLength(3)
    for (const twin of twins) {
      expect(twin.getAttribute('aria-hidden')).toBe('true')
      expect(twin.getAttribute('data-id')).toBeNull()
      expect(twin.getAttribute('role')).toBeNull()
      expect(twin.getAttribute('aria-live')).toBeNull()
    }
    h.response(summary())
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
    expect(h.find('hilos-daemon-http-address')?.textContent).toBe('[::1]:9090')
    for (const name of ['requests', 'errors', 'slow'])
      expect(h.find(`hilos-daemon-http-${name}`)?.textContent).toBe('0')
    expect(h.find('hilos-daemon-http-period')?.textContent).toBe('Last 24 h')
    h.response(null)
    expect(h.find('hilos-daemon-http-address')?.textContent).toBe('—')
    expect(h.find('hilos-daemon-http-requests')?.textContent).toBe('—')
    expect([
      ...h.view.container.querySelectorAll('.hilos-stack > p.invisible'),
    ]).toEqual(twins)
  })
  it('retains the report and routes through silence and recovers with a live report', () => {
    const h = harness()
    h.response(summary(), LIVE, [route()])
    h.window([route()])
    const row = h.find('hilos-table-row-GET /status')
    h.response(summary(), SILENT)
    expect(
      h.find('hilos-daemon-http-status')?.getAttribute('data-status'),
    ).toBe('last-report')
    expect(h.find('hilos-daemon-http-status')?.textContent).toMatch(
      /^This node has been silent since .+\. The values below are from its last report\.$/,
    )
    expect(h.find('hilos-daemon-http-period')?.textContent).toBe(
      'Since start, 23 h',
    )
    expect(h.find('hilos-daemon-http-requests')?.textContent).toBe(
      (1284).toLocaleString(),
    )
    expect(h.find('hilos-table-row-GET /status')).toBe(row)
    expect(h.view.container.textContent).toContain('Last report')
    expect(h.find('hilos-daemon-node-state')?.textContent).toBe('Silent')
    h.response(summary(), LIVE)
    h.window([route({ requests: 1241 })])
    expect(cells(h, 'GET /status')[1]?.textContent).toBe(
      (1241).toLocaleString(),
    )
    expect(h.find('hilos-daemon-http-status')).toBeNull()
  })
  it('draws methods, agent routes, known zero, No route and null slowest', () => {
    const h = harness()
    h.response(summary())
    h.window([
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
    expect(cells(h, 'GET /status').map((cell) => cell.textContent)).toEqual([
      '/status',
      (1240).toLocaleString(),
      '0',
      '12 ms',
    ])
    expect(
      cells(h, 'GET /status')[2]
        ?.querySelector('span')
        ?.classList.contains('text-body-secondary'),
    ).toBe(true)
    expect(
      cells(h, 'POST /_hilos/file')[0]?.querySelector('code')?.textContent,
    ).toBe('POST /_hilos/file')
    expect(
      cells(h, 'POST /_hilos/file')[0]?.querySelector('.badge')?.textContent,
    ).toBe('hilos_files_library')
    expect(cells(h, 'POST /_hilos/file')[3]?.textContent).toBe(
      `${(1240).toLocaleString()} ms`,
    )
    expect(cells(h, 'unrouted').map((cell) => cell.textContent)).toEqual([
      'No route',
      '0',
      '0',
      '—',
    ])
    expect(
      cells(h, 'unrouted')[1]
        ?.querySelector('span')
        ?.classList.contains('text-body-secondary'),
    ).toBe(true)
    expect(h.find('hilos-table-search')).toBeNull()
  })
  it('cleans old bindings on node change and all listeners on unmount', () => {
    const h = harness()
    h.response(summary(), LIVE, [route()])
    h.window([route()])
    expect(h.sendViewport).toHaveBeenLastCalledWith(
      HilosPages.DAEMON_HTTP_SERVER,
      'hilosDaemonHttpRoutes',
      expect.objectContaining({ filter: { node: 'n1' } }),
    )
    const sink = h.register.mock.calls[0]![1]
    h.selectNode('n2')
    h.response(summary({ host: 'n2', requests: 44 }), LIVE, [
      route({ rowKey: 'GET /n2', path: '/n2' }),
    ])
    h.window([route({ rowKey: 'GET /n2', path: '/n2' })])
    expect(h.unregister).toHaveBeenCalledWith(
      'hilosDaemonHttpRoutes',
      h.register.mock.calls[0]?.[1],
    )
    expect(h.register).toHaveBeenCalledTimes(2)
    expect(h.register.mock.calls[1]![1]).not.toBe(sink)
    expect(h.listeners.get('tableWindow')?.size).toBe(1)
    expect(h.sendViewport).toHaveBeenLastCalledWith(
      HilosPages.DAEMON_HTTP_SERVER,
      'hilosDaemonHttpRoutes',
      expect.objectContaining({ filter: { node: 'n2' } }),
    )
    expect(h.find('hilos-page-head-title')?.textContent).toBe('n2')
    expect(h.find('hilos-daemon-http-address')?.textContent).toBe('n2:8080')
    expect(h.find('hilos-table-row-GET /status')).toBeNull()
    expect(h.find('hilos-table-row-GET /n2')).not.toBeNull()
    expect(h.find('hilos-page-head-action')?.getAttribute('href')).toBe(
      '/hilos/daemon',
    )
    h.response(summary(), {
      clustered: false,
      role: null,
      state: null,
      silentSince: null,
    })
    expect(h.find('hilos-daemon-node-solo')?.textContent).toBe(
      'Single installation',
    )
    expect(h.find('hilos-page-head-action')).toBeNull()
    h.view.unmount()
    expect(h.unregister).toHaveBeenCalledTimes(2)
    expect([...h.listeners.values()].every((g) => g.size === 0)).toBe(true)
  })
})
