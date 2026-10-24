import { openNodePage } from './helpers/hilosDaemonNode.js'
import { act, cleanup, render } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  ScopeManager,
  pageIdentity,
  createSignal,
  HilosPages,
  type HilosConnection,
  type HilosRouter,
  type HilosDaemonNodeHeading,
  type PageRouteMatch,
} from '@hilos/core'
import { HilosDaemonCronPage } from '../src/admin/daemon/HilosDaemonCronPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

function harness() {
  const scopes = new ScopeManager()
  const scope = openNodePage(scopes, HilosPages.DAEMON_CRON)
  const currentRoute = createSignal<PageRouteMatch>({
    page: HilosPages.DAEMON_CRON,
    params: { nodeId: 'n1' },
    admin: true,
  })
  const router = {
    currentRoute,
    currentPath: createSignal('/hilos/daemon/n1/cron'),
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
      <HilosDaemonCronPage context={{ connection, scopes }} />
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
      act(() => scope.data.set('cronReported', value))
    },
    window(rows: Record<string, unknown>[]) {
      act(() => {
        for (const listener of listeners.get('tableWindow') ?? [])
          listener({
            data: {
              page: HilosPages.DAEMON_CRON,
              tableKey: 'hilosDaemonCron',
              rows: rows.map((rule) => ({
                rowKey: String(rule.rowKey),
                slots: { rule },
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

function rule(
  overrides: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    rowKey: '/cleanup',
    agentId: null,
    name: 'cleanup',
    expression: '*/30 * * * *',
    lastRunAt: 1_700_000_000_000,
    nextRunAt: 1_700_001_800_000,
    idleReason: null,
    ...overrides,
  }
}

describe('HilosDaemonCronPage', () => {
  it('distinguishes all empty states and retains the last rows during silence', () => {
    const h = harness()
    h.window([])
    expect(h.find('hilos-daemon-cron-empty-waiting')?.textContent).toContain(
      'not zero rules',
    )
    h.heading({
      clustered: true,
      role: null,
      state: 'silent',
      silentSince: 1_700_000_000_000,
    })
    expect(h.find('hilos-daemon-cron-empty-silent')?.textContent).toContain(
      'never reported',
    )
    h.reported(true)
    expect(h.find('hilos-daemon-cron-empty-none')?.textContent).toBe(
      'The node reports no cron rules',
    )
    expect(h.find('hilos-daemon-cron-silent')).toBeNull()
    h.window([rule()])
    const row = h.find('hilos-table-row-/cleanup')
    expect(h.find('hilos-daemon-cron-silent')?.textContent).toContain(
      'from its last report',
    )
    h.heading({
      clustered: true,
      role: null,
      state: 'leader',
      silentSince: null,
    })
    expect(h.find('hilos-daemon-node-state')?.textContent).toBe('Leader')
    expect(h.find('hilos-table-row-/cleanup')).toBe(row)
    expect(h.find('hilos-daemon-cron-silent')).toBeNull()
    expect(h.find('hilos-page-head-action')?.getAttribute('href')).toBe(
      '/hilos/daemon',
    )
  })

  it.each(['standby', 'data', 'silent'] as const)(
    'explains idle daemon rules on %s without idling agent schedules',
    (state) => {
      const h = harness()
      h.heading({
        clustered: true,
        role: null,
        state,
        silentSince: state === 'silent' ? 1_700_000_000_000 : null,
      })
      h.window([
        rule({ idleReason: 'not_leader' }),
        rule({ rowKey: 'agent/sweep', agentId: 'agent', name: 'sweep' }),
        rule({
          rowKey: 'agent/yearly',
          agentId: 'agent',
          name: 'yearly',
          lastRunAt: null,
          nextRunAt: null,
        }),
      ])
      const notice = h.find('hilos-daemon-cron-not-leader')!
      expect(notice.getAttribute('data-notice')).toBe(
        state === 'standby' ? 'idle_until_leader' : 'idle',
      )
      expect(notice.textContent).toContain('only the leader runs them')
      expect(notice.textContent?.includes('They will start here')).toBe(
        state === 'standby',
      )
      const cells = (key: string) =>
        [...h.find(`hilos-table-row-${key}`)!.querySelectorAll('td')].map(
          (cell) => cell.textContent,
        )
      expect(cells('/cleanup').slice(3, 5)).toEqual(['Not on this node', '—'])
      expect(cells('agent/yearly').slice(3, 5)).toEqual(['Not yet', 'Never'])
      expect(cells('agent/sweep')[3]).not.toBe('Not on this node')
      expect(cells('agent/sweep')[4]).not.toBe('—')
      expect(h.find('hilos-daemon-cron-executor-/cleanup')?.textContent).toBe(
        'Daemon (leader)',
      )
      expect(
        h.find('hilos-daemon-cron-executor-agent/sweep')?.textContent,
      ).toBe('agent')
      expect(h.find('hilos-table-search')).toBeNull()
      expect(
        h.view.container.querySelectorAll('[data-id^="hilos-table-sort-"]'),
      ).toHaveLength(0)
    },
  )

  it('keeps the notice room on leadership change and takes none without daemon rules', () => {
    const h = harness()
    h.heading({
      clustered: true,
      role: null,
      state: 'standby',
      silentSince: null,
    })
    h.window([rule({ idleReason: 'not_leader' })])
    const room = h.view.container.querySelector('.alert-warning.invisible')
    expect(room).not.toBeNull()
    h.heading({
      clustered: true,
      role: null,
      state: 'leader',
      silentSince: null,
    })
    h.window([rule()])
    expect(h.find('hilos-daemon-cron-not-leader')).toBeNull()
    expect(h.view.container.querySelector('.alert-warning.invisible')).toBe(
      room,
    )
    h.window([rule({ agentId: 'agent' })])
    expect(
      h.view.container.querySelector('.alert-warning.invisible'),
    ).toBeNull()
    h.heading({ clustered: false, role: null, state: null, silentSince: null })
    h.window([rule()])
    expect(
      h.view.container.querySelector('.alert-warning.invisible'),
    ).toBeNull()
    expect(h.find('hilos-daemon-cron-executor-/cleanup')?.textContent).toBe(
      'Daemon',
    )
  })

  it('rebinds on node change and releases every listener on unmount', () => {
    const h = harness()
    h.window([rule()])
    const first = h.register.mock.calls[0]![1]
    const count = [...h.listeners.values()].reduce(
      (n, group) => n + group.size,
      0,
    )
    act(() =>
      h.currentRoute.set({
        page: HilosPages.DAEMON_CRON,
        params: { nodeId: 'n2' },
        admin: true,
      }),
    )
    expect(h.unregister).toHaveBeenCalledWith(
      'hilosDaemonCron',
      h.register.mock.calls[0]?.[1],
    )
    expect(h.register).toHaveBeenCalledTimes(2)
    expect(h.register.mock.calls[1]![1]).not.toBe(first)
    expect(
      [...h.listeners.values()].reduce((n, group) => n + group.size, 0),
    ).toBe(count)
    h.window([rule({ name: 'updated-rule' })])
    expect(h.find('hilos-table-row-/cleanup')?.textContent).toContain(
      'updated-rule',
    )
    expect(h.find('hilos-page-head-title')?.textContent).toBe('n2')
    h.view.unmount()
    expect(h.unregister).toHaveBeenCalledTimes(2)
    expect([...h.listeners.values()].every((group) => group.size === 0)).toBe(
      true,
    )
  })
})
