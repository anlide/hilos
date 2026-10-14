import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  ScopeManager,
  createSignal,
  HilosPages,
  type HilosConnection,
  type HilosRouter,
  type HilosDaemonNodeHeading,
  type PageRouteMatch,
} from '@hilos/core'
import { HilosDaemonWorkersPage } from '../src/admin/daemon/HilosDaemonWorkersPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

function harness() {
  const scopes = new ScopeManager()
  const scope = scopes.openPage(HilosPages.DAEMON_WORKERS)
  const currentRoute = createSignal<PageRouteMatch>({
    page: HilosPages.DAEMON_WORKERS,
    params: { nodeId: 'n1' },
    admin: true,
  })
  const router = {
    currentRoute,
    currentPath: createSignal('/hilos/daemon/n1/workers'),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal({
      label: 'Workers',
      lead: '',
      breadcrumb: [],
      children: [],
    }),
    dashboardSections: createSignal(undefined),
    resolvePath: (page: string) =>
      page === HilosPages.DAEMON ? '/hilos/daemon' : undefined,
    navigate: vi.fn(),
  } as unknown as HilosRouter
  const listeners = new Map<string, Set<(payload: never) => void>>()
  const unregister = vi.fn()
  const register = vi.fn<HilosConnection['registerTableWindow']>()
  const focus = vi.fn()
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
    sendTableRowFocus: focus,
  } as unknown as HilosConnection
  const view = render(
    <HilosRouterContext.Provider value={router}>
      <HilosDaemonWorkersPage context={{ connection, scopes }} />
    </HilosRouterContext.Provider>,
  )
  return {
    view,
    currentRoute,
    register,
    unregister,
    listeners,
    focus,
    find: (id: string): HTMLElement | null =>
      view.container.querySelector(`[data-id="${id}"]`),
    heading(value: HilosDaemonNodeHeading) {
      act(() => scope.data.set('node', value))
    },
    reported(value: boolean) {
      act(() => scope.data.set('processesReported', value))
    },
    window(rows: Record<string, unknown>[]) {
      act(() => {
        for (const listener of listeners.get('tableWindow') ?? [])
          listener({
            data: {
              page: HilosPages.DAEMON_WORKERS,
              tableKey: 'hilosDaemonWorkers',
              rows: rows.map((worker) => ({
                rowKey: String(worker.rowKey),
                slots: { worker },
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
    agentIds: ['room:12', 'user:7'],
    logStream: 'worker-regular-1.log',
    ...overrides,
  }
}

describe('HilosDaemonWorkersPage', () => {
  it('distinguishes waiting, silent and reported empty rosters with a live node heading', () => {
    const h = harness()
    h.window([])
    expect(h.find('hilos-daemon-workers-empty-waiting')?.textContent).toContain(
      'not zero workers',
    )
    h.heading({ clustered: true, state: 'silent', silentSince: 1_700_000_000 })
    expect(h.find('hilos-daemon-workers-empty-silent')?.textContent).toContain(
      'never reported',
    )
    expect(h.find('hilos-daemon-node-state')?.textContent).toBe('Silent')
    expect(h.find('hilos-daemon-node-diagram')?.getAttribute('href')).toBe(
      '/hilos/daemon',
    )
    h.reported(true)
    expect(h.find('hilos-daemon-workers-empty-none')?.textContent).toBe(
      'The node reports no workers',
    )
    expect(h.find('hilos-daemon-workers-silent')).toBeNull()
    h.heading({ clustered: false, state: null, silentSince: null })
    expect(h.find('hilos-daemon-node-solo')?.textContent).toBe(
      'Single installation',
    )
    expect(h.find('hilos-daemon-node-state')).toBeNull()
    expect(h.find('hilos-daemon-node-diagram')).toBeNull()
  })

  it('renders workers, unknown and zero values, details and live ids while retaining silent rows', () => {
    const h = harness()
    h.heading({ clustered: true, state: 'leader', silentSince: null })
    h.reported(true)
    h.window([
      worker(),
      worker({
        rowKey: 'monopolistic:3',
        index: 3,
        kind: 'monopolistic',
        pid: null,
        memoryBytes: null,
        agentCount: 0,
        agentIds: [],
      }),
    ])
    const regular = h.find('hilos-table-row-regular:1')!
    expect(
      [...regular.querySelectorAll('td')]
        .slice(0, 5)
        .map((cell) => cell.textContent),
    ).toEqual(['w1', 'Ordinary', '1234', '2', '2.0 KB'])
    expect(
      [...h.find('hilos-table-row-monopolistic:3')!.querySelectorAll('td')]
        .slice(0, 5)
        .map((cell) => cell.textContent),
    ).toEqual(['m3', 'Monopolistic', '—', '·', '—'])
    expect(
      h.find('hilos-daemon-worker-log-regular:1')?.getAttribute('href'),
    ).toBe('/hilos/logs/view/n1/live/worker-regular-1.log')
    fireEvent.click(h.find('hilos-table-expand-regular:1')!)
    expect(
      h.find('hilos-daemon-worker-agents-regular:1')?.textContent,
    ).toContain('room:12')
    h.window([worker({ agentCount: 1, agentIds: ['room:99'], memoryBytes: 0 })])
    expect(h.find('hilos-daemon-worker-agents-regular:1')?.textContent).toBe(
      'room:99',
    )
    expect(regular.textContent).toContain('0 B')
    h.heading({ clustered: true, state: 'silent', silentSince: 1_700_000_000 })
    expect(h.find('hilos-daemon-workers-silent')?.textContent).toContain(
      'from its last report',
    )
    expect(h.find('hilos-table-row-regular:1')).toBe(regular)
    expect(h.find('hilos-daemon-worker-agents-regular:1')?.textContent).toBe(
      'room:99',
    )
  })

  it('releases old bindings on node change and uses the new node in log links', () => {
    const h = harness()
    h.window([worker()])
    const first = h.register.mock.calls[0]![1]
    const listenerCount = [...h.listeners.values()].reduce(
      (n, group) => n + group.size,
      0,
    )
    act(() =>
      h.currentRoute.set({
        page: HilosPages.DAEMON_WORKERS,
        params: { nodeId: 'n2' },
        admin: true,
      }),
    )
    expect(h.unregister).toHaveBeenCalledWith('hilosDaemonWorkers')
    expect(h.register).toHaveBeenCalledTimes(2)
    expect(h.register.mock.calls[1]![1]).not.toBe(first)
    expect(
      [...h.listeners.values()].reduce((n, group) => n + group.size, 0),
    ).toBe(listenerCount)
    h.window([worker()])
    expect(h.find('hilos-daemon-node-id')?.textContent).toBe('n2')
    expect(
      h.find('hilos-daemon-worker-log-regular:1')?.getAttribute('href'),
    ).toBe('/hilos/logs/view/n2/live/worker-regular-1.log')
    h.view.unmount()
    expect(h.unregister).toHaveBeenCalledTimes(2)
    expect([...h.listeners.values()].every((group) => group.size === 0)).toBe(
      true,
    )
  })
})
