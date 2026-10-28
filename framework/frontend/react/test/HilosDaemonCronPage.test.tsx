import { openNodePage } from './helpers/hilosDaemonNode.js'
import { act, cleanup, render } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import * as daemon from '@hilos/core/admin/daemon'
import * as core from '@hilos/core/base'
import {
  ScopeManager,
  pageIdentity,
  createSignal,
  HilosPages,
  ingest,
  type HilosConnection,
  type HilosRouter,
  type HilosDaemonNodeHeading,
  type PageRouteMatch,
} from '@hilos/core'
import {
  bindAdminAccess,
  hilosAdminAccess,
  hilosTimeZone,
} from '@hilos/core/base'
import { HilosDaemonCronPage } from '../src/admin/daemon/HilosDaemonCronPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'
import { readerTimeZone } from './readerTimeZone.js'

function transport(initialProjectTimeZone?: string) {
  let projectTimeZone = initialProjectTimeZone
  const listeners = new Map<string, Set<(payload: never) => void>>()
  const unregister = vi.fn()
  const register = vi.fn<HilosConnection['registerTableWindow']>()
  const focus = vi.fn()
  const sendViewport = vi.fn<HilosConnection['sendTableViewport']>(() => true)
  const connection = {
    get projectTimeZone() {
      return projectTimeZone
    },
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
    sendTableViewport: sendViewport,
    sendTableRendered: vi.fn(),
    sendTableRowFocus: focus,
  } as unknown as HilosConnection
  return {
    connection,
    register,
    unregister,
    listeners,
    focus,
    sendViewport,
    welcome(zone?: string) {
      projectTimeZone = zone
      act(() => {
        for (const listener of listeners.get('handshake') ?? [])
          listener({} as never)
      })
    },
  }
}

function harness(projectTimeZone?: string) {
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
  const source = transport(projectTimeZone)
  const context = { connection: source.connection, scopes }
  const view = render(
    <HilosRouterContext.Provider value={router}>
      <HilosDaemonCronPage context={context} />
    </HilosRouterContext.Provider>,
  )
  return {
    ...source,
    view,
    currentRoute,
    scopes,
    router,
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
        for (const listener of source.listeners.get('tableWindow') ?? [])
          listener({
            data: {
              page: HilosPages.DAEMON_CRON,
              tableKey: 'hilosDaemonCron',
              requestId: source.sendViewport.mock.calls.at(-1)?.[2].requestId,
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

afterEach(() => {
  cleanup()
  vi.restoreAllMocks()
})

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
  it('reformats mounted runs and both silence captions without replacing the searched and sorted window', () => {
    const resolved = new Intl.DateTimeFormat().resolvedOptions()
    vi.spyOn(Intl.DateTimeFormat.prototype, 'resolvedOptions').mockReturnValue({
      ...resolved,
      timeZone: 'America/New_York',
    })
    vi.spyOn(Date, 'now').mockReturnValue(Date.parse('2026-10-24T00:15:00Z'))
    const reader = readerTimeZone()
    const factory = vi.spyOn(daemon, 'createHilosDaemonCronTable')
    const subscribe = core.subscribeSignal
    const deliveries = vi.fn()
    const releases: ReturnType<typeof vi.fn>[] = []
    vi.spyOn(core, 'subscribeSignal').mockImplementation(
      <T,>(source: core.ReadonlySignal<T>, listener: (value: T) => void) => {
        if (source !== hilosTimeZone) return subscribe(source, listener)
        const stop = vi.fn(
          subscribe(source, (value) => {
            deliveries(value)
            listener(value)
          }),
        )
        releases.push(stop)
        return stop
      },
    )
    try {
      act(() => reader.choose('UTC'))
      const h = harness('Europe/Warsaw')
      const controller = factory.mock.results[0]!.value.controller
      act(() => {
        controller.setSearch('cleanup')
        controller.setSort('nextRunAt')
      })
      const at = Date.parse('2026-10-24T03:30:00Z')
      h.heading({
        clustered: true,
        role: 'master',
        state: 'silent',
        silentSince: at,
      })
      h.reported(true)
      h.window([rule({ lastRunAt: at, nextRunAt: at + 3_600_000 })])
      const row = h.find('hilos-table-row-/cleanup')!
      const table = h.view.container.querySelector('table')
      const window = controller.rows.get()
      const descriptor = controller.descriptor()
      const requests = h.sendViewport.mock.calls.length
      const expected = (zone: string, moment: number, day: string) =>
        `${day}, ${new Intl.DateTimeFormat(undefined, {
          timeZone: zone,
          hour: '2-digit',
          minute: '2-digit',
        }).format(moment)}`
      for (const [own, zone, day, nextDay] of [
        ['UTC', 'UTC', 'today', 'today'],
        ['Pacific/Honolulu', 'Pacific/Honolulu', 'today', 'today'],
        [null, 'America/New_York', 'today', 'tomorrow'],
      ] as const) {
        act(() => reader.choose(own))
        expect(hilosTimeZone.get()).toBe(zone)
        expect(
          [...row.querySelectorAll('td')]
            .slice(3, 5)
            .map((cell) => cell.textContent),
        ).toEqual([
          expected(zone, at, day),
          expected(zone, at + 3_600_000, nextDay),
        ])
        expect(h.find('hilos-daemon-cron-silent')!.textContent).toBe(
          `This node has been silent since ${expected(zone, at, day)}. The rules below are from its last report.`,
        )
        expect(h.find('hilos-daemon-node-silent')!.textContent).toBe(
          `Silent since ${expected(zone, at, day)}`,
        )
        expect(h.find('hilos-daemon-cron-project-time-zone')!.textContent).toBe(
          'Europe/Warsaw',
        )
        expect(h.view.container.querySelector('table')).toBe(table)
        expect(h.find('hilos-table-row-/cleanup')).toBe(row)
        expect(controller.rows.get()).toBe(window)
        expect(controller.descriptor()).toEqual(descriptor)
        expect((h.find('hilos-table-search') as HTMLInputElement).value).toBe(
          'cleanup',
        )
        expect(
          h
            .find('hilos-table-sort-nextRunAt')!
            .closest('th')!
            .getAttribute('aria-sort'),
        ).toBe('ascending')
        expect(factory).toHaveBeenCalledTimes(1)
        expect(h.register).toHaveBeenCalledTimes(1)
        expect(h.sendViewport).toHaveBeenCalledTimes(requests)
      }
      // Cron, the shared shell and the table's live-status formatter.
      expect(releases).toHaveLength(3)
      const delivered = deliveries.mock.calls.length
      h.view.unmount()
      for (const stop of releases) expect(stop).toHaveBeenCalledTimes(1)
      act(() => reader.choose('Asia/Tokyo'))
      expect(deliveries).toHaveBeenCalledTimes(delivered)
      expect(h.unregister).toHaveBeenCalledTimes(1)
      expect([...h.listeners.values()].every((group) => group.size === 0)).toBe(
        true,
      )
    } finally {
      cleanup()
      reader.release()
    }
  })

  it('keeps epoch zero valid and preserves null, invalid and idle run labels', () => {
    const reader = readerTimeZone()
    try {
      act(() => reader.choose('Pacific/Honolulu'))
      const h = harness()
      h.window([
        rule({ rowKey: 'epoch', lastRunAt: 0, nextRunAt: 0 }),
        rule({ rowKey: 'null', lastRunAt: null, nextRunAt: null }),
        rule({
          rowKey: 'invalid',
          lastRunAt: 8_640_000_000_000_001,
          nextRunAt: 8_640_000_000_000_001,
        }),
        rule({
          rowKey: 'idle',
          lastRunAt: 0,
          nextRunAt: 0,
          idleReason: 'not_leader',
        }),
      ])
      const cells = (key: string) =>
        [...h.find(`hilos-table-row-${key}`)!.querySelectorAll('td')]
          .slice(3, 5)
          .map((cell) => cell.textContent)
      const date = new Intl.DateTimeFormat(undefined, {
        timeZone: 'Pacific/Honolulu',
        dateStyle: 'medium',
      }).format(0)
      const time = new Intl.DateTimeFormat(undefined, {
        timeZone: 'Pacific/Honolulu',
        hour: '2-digit',
        minute: '2-digit',
      }).format(0)
      expect(cells('epoch')).toEqual([`${date}, ${time}`, `${date}, ${time}`])
      expect(cells('null')).toEqual(['Not yet', 'Never'])
      expect(cells('invalid')).toEqual(['—', '—'])
      expect(cells('idle')).toEqual(['Not on this node', '—'])
    } finally {
      cleanup()
      reader.release()
    }
  })

  it.each([true, false])(
    'seeds and refreshes the separate project zone for admin=%s',
    (admin) => {
      const h = harness('America/Argentina/ComodRivadavia')
      act(() =>
        ingest(h.scopes.session, {
          entities: { currentUser: { id: 3, admin } },
          data: { adminViewMode: true },
        }),
      )
      let releaseAccess: () => void
      act(() => {
        releaseAccess = bindAdminAccess(h.scopes)
      })
      try {
        expect(hilosAdminAccess.get()).toBe(admin ? 'full' : 'view')
        const line = h.find('hilos-daemon-cron-time-zone')!
        const zone = h.find('hilos-daemon-cron-project-time-zone')!
        expect(line.textContent).toBe(
          'Expressions use the project time zone — America/Argentina/ComodRivadavia. Run times use your time zone.',
        )
        expect(line.tagName).toBe('P')
        expect(line.className).toBe('small text-body-secondary mb-2')
        expect(line.hasAttribute('role')).toBe(false)
        expect(line.hasAttribute('aria-live')).toBe(false)
        expect(line.querySelector('i')!.getAttribute('aria-hidden')).toBe(
          'true',
        )
        expect(zone.className).toBe('font-monospace text-break')
        expect(h.view.container.querySelectorAll('h1')).toHaveLength(1)
        h.welcome('Asia/Tokyo')
        expect(zone.textContent).toBe('Asia/Tokyo')
        h.welcome()
        expect(zone.textContent).toBe('—')
        expect(h.find('hilos-daemon-cron-time-zone')).toBe(line)
        expect(h.register).toHaveBeenCalledTimes(1)
        h.view.unmount()
        expect(h.listeners.get('handshake')?.size).toBe(0)
      } finally {
        h.view.unmount()
        act(() => releaseAccess!())
      }
    },
  )

  it('clears the old project zone and handshake listener when the connection changes', () => {
    const h = harness('Europe/Warsaw')
    const replacement = transport()
    h.view.rerender(
      <HilosRouterContext.Provider value={h.router}>
        <HilosDaemonCronPage
          context={{ connection: replacement.connection, scopes: h.scopes }}
        />
      </HilosRouterContext.Provider>,
    )
    const zone = h.find('hilos-daemon-cron-project-time-zone')!
    expect(zone.textContent).toBe('—')
    expect([...h.listeners.values()].every((group) => group.size === 0)).toBe(
      true,
    )
    h.welcome('Pacific/Honolulu')
    expect(zone.textContent).toBe('—')
    replacement.welcome('Asia/Tokyo')
    expect(zone.textContent).toBe('Asia/Tokyo')
    expect(replacement.listeners.get('handshake')?.size).toBe(1)
    h.view.unmount()
    expect(
      [...replacement.listeners.values()].every((group) => group.size === 0),
    ).toBe(true)
  })

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
      expect(h.find('hilos-table-search')?.getAttribute('placeholder')).toBe(
        'Find by rule or agent',
      )
      expect(
        h.view.container.querySelectorAll('[data-id^="hilos-table-sort-"]'),
      ).toHaveLength(2)
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
