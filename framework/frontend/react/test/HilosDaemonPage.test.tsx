import { act, cleanup, fireEvent, render } from '@testing-library/react'
import {
  HilosPages,
  ScopeManager,
  bindPageScope,
  bindAdminAccess,
  createAppPageRouter,
  createHilosRouter,
  hilosAdminAccess,
  hilosUnbuiltPages,
  type HilosConnection,
  type HilosDaemonOverviewData,
  type HilosDaemonOverviewNode,
  type HilosDaemonDatabaseData,
  type ProjectSignal,
  type TableRow,
} from '@hilos/core'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { HilosDaemonPage } from '../src/admin/daemon/HilosDaemonPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

function node(
  nodeId: string,
  overrides: Partial<HilosDaemonOverviewNode> = {},
): HilosDaemonOverviewNode {
  return {
    nodeId,
    online: true,
    role: 'master',
    state: 'standby',
    silentSince: null,
    workers: [
      { index: 0, kind: 'regular', agentCount: 2 },
      { index: 4, kind: 'monopolistic', agentCount: 1 },
      { index: 9, kind: 'monopolistic', agentCount: 0 },
    ],
    workerRestarts24h: 0,
    uptimeMinutes: 125,
    measurements: {
      startedAt: 1_700_000_000,
      cores: 2,
      coresBound: 'quota',
      daemonCores: 3,
      masterCores: 0.1,
      machineLoad: 25,
      masterMemory: 1024,
      workersMemory: 3072,
      workersMeasured: 2,
      memoryCeiling: 2048,
      memoryCeilingBound: 'cgroup',
      phpMemoryLimit: 8192,
    },
    standing: { sessions: 2, connections: 3 },
    environment: { missingRequired: 1, fromExample: 2, drifted: 3, orphans: 4 },
    ...overrides,
  }
}

function picture(
  nodes: readonly HilosDaemonOverviewNode[],
  clustered = true,
): HilosDaemonOverviewData {
  return {
    clustered,
    nodes,
    placement: {
      instances: null,
      types: 3,
      clusterTypes: 2,
      unplacedTypes: null,
    },
    consensus: clustered
      ? {
          nodeId: 'n1',
          term: 7,
          leaderId: 'n1',
          onlineMasters: 1,
          masters: 5,
          quorumSize: 3,
        }
      : null,
    environmentComparison: {
      answeringNodes: clustered ? 2 : 1,
      divergedKeys: clustered ? 2 : null,
      sourcesDifferKeys: clustered ? 1 : null,
    },
  }
}

function harness(
  envBuilt = true,
  initial?: HilosDaemonOverviewData,
  initialRows?: readonly TableRow[],
  showLogLinks?: boolean,
) {
  const scopes = new ScopeManager()
  const sendAction = vi.fn()
  const send = vi.fn(() => true)
  const listeners = new Map<string, Set<(signal: never) => void>>()
  let lastResponse: ProjectSignal | undefined
  const emit = (event: string, signal: unknown) => {
    for (const listener of listeners.get(event) ?? []) listener(signal as never)
  }
  const register = vi.fn<HilosConnection['registerTableWindow']>()
  const unregister = vi.fn()
  const sendViewport = vi.fn<HilosConnection['sendTableViewport']>(() => true)
  const sendRendered = vi.fn(() => true)
  const on = vi.fn((event: string, listener: (payload: never) => void) => {
    const group = listeners.get(event) ?? new Set()
    group.add(listener)
    listeners.set(event, group)
    if (event === 'projectSignal' && lastResponse)
      listener(lastResponse as never)
    return () => {
      group.delete(listener)
    }
  })
  const connection = {
    state: 'connected',
    send,
    sendAction,
    on,
    registerTableWindow: register,
    unregisterTableWindow: unregister,
    sendTableViewport: sendViewport,
    sendTableRendered: sendRendered,
    forgetPageFrames: () => {},
    tableWindowDescriptors: () => ({}),
  } as unknown as HilosConnection
  const pages = bindPageScope(connection, scopes)
  const matcher = createAppPageRouter({})
  const unbuilt = new Set(hilosUnbuiltPages('react'))
  // Exercise the future built Env link and the current production gate separately.
  if (envBuilt) {
    unbuilt.delete(HilosPages.DAEMON_ENV)
    unbuilt.delete(HilosPages.DAEMON_ENV_MISMATCH)
  } else {
    unbuilt.add(HilosPages.DAEMON_ENV)
  }
  let pathname = '/hilos/daemon'
  const router = createHilosRouter(
    matcher,
    pages,
    {
      pathname: () => pathname,
      pushState: (next) => {
        pathname = next
      },
      replaceState: (next) => {
        pathname = next
      },
      onPopState: () => () => {},
    },
    undefined,
    unbuilt,
  )
  router.start()
  pages.releaseOnSession()
  expect(send).toHaveBeenCalledTimes(1)
  send.mockClear()
  on.mockClear()
  const navigate = vi.spyOn(router, 'navigate')
  const replacePath = vi.spyOn(router, 'replacePath')
  const page = scopes.page()!
  const update = (
    value: HilosDaemonOverviewData | null,
    database: HilosDaemonDatabaseData | null = null,
    rows?: readonly TableRow[],
  ) => {
    const signal = {
      type: 'page_response',
      data: {
        page: HilosPages.DAEMON,
        payload: {
          ...(rows === undefined
            ? {}
            : {
                windows: {
                  hilosDaemonPlacement: {
                    rows,
                    totalCount: rows.length,
                    totalExact: true,
                    firstAnchor: null,
                    lastAnchor: null,
                    limit: 25,
                    sort: [],
                    rowsBefore: 0,
                  },
                },
              }),
          data: {
            daemon: value,
            database,
            pageLabel: 'Daemon from catalog',
            pageLead: 'Technical overview',
            pageBreadcrumb: [
              { page: HilosPages.DAEMON, label: 'Daemon from catalog' },
            ],
            pageChildren: [
              {
                page: HilosPages.DAEMON_WORKERS,
                label: 'Workers',
                lead: 'Worker processes',
                icon: null,
              },
              ...[
                [HilosPages.DAEMON_AGENTS, 'Agents'],
                [HilosPages.DAEMON_CRON, 'Cron'],
                [HilosPages.DAEMON_HTTP_SERVER, 'HTTP'],
              ].map(([page, label]) => ({ page, label, lead: '', icon: null })),
              {
                page: HilosPages.DAEMON_WEBSOCKETS,
                label: 'WebSockets',
                lead: '',
                icon: null,
              },
            ],
          },
        },
      },
    } as unknown as ProjectSignal
    lastResponse = signal
    emit('projectSignal', signal)
  }
  if (initial) update(initial, null, initialRows)
  const initialListenerCount = [...listeners.values()].reduce(
    (n, group) => n + group.size,
    0,
  )
  const view = render(
    <HilosRouterContext.Provider value={router}>
      <HilosDaemonPage
        context={{ scopes, connection }}
        showLogLinks={showLogLinks}
      />
    </HilosRouterContext.Provider>,
  )
  const find = (
    id: string,
    root: ParentNode = view.container,
  ): HTMLElement | null => root.querySelector(`[data-id="${id}"]`)
  const click = (id: string, root?: ParentNode): void => {
    fireEvent.click(find(id, root)!)
  }
  return {
    view,
    find,
    click,
    page,
    scopes,
    router,
    matcher,
    send,
    sendAction,
    on,
    navigate,
    replacePath,
    update,
    emit,
    register,
    unregister,
    sendViewport,
    sendRendered,
    listeners,
    initialListenerCount,
    controller: () => register.mock.calls[0]![1],
    window: (rows: readonly TableRow[]) =>
      emit('tableWindow', {
        data: {
          page: HilosPages.DAEMON,
          tableKey: 'hilosDaemonPlacement',
          rows,
          totalCount: rows.length,
          totalExact: true,
          firstAnchor: null,
          lastAnchor: null,
          limit: 25,
          rowsBefore: 0,
        },
      }),
  }
}

function placementRows(
  ids: readonly string[] | null = ['chat_room:1', 'chat_room:2'],
  node2Ids: readonly string[] | null = [],
): TableRow[] {
  return [
    {
      rowKey: 'chat_room',
      slots: {
        type: {
          agentType: 'chat_room',
          placement: 'policy',
          idle: true,
          nodes: [
            { nodeId: 'n1', agentIds: ids },
            { nodeId: 'n2', agentIds: node2Ids },
          ],
          unplacedAgentIds: ['chat_room:3'],
        },
      },
    },
  ]
}

function placementPicture(clustered = true): HilosDaemonOverviewData {
  return {
    ...picture([node('n1', { state: 'leader' }), node('n2')], clustered),
    placement: {
      instances: 27,
      types: 12,
      clusterTypes: 4,
      unplacedTypes: ['chat_room', 'unshown_type'],
    },
  }
}

const CELL = 'hilos-daemon-placement-cell-chat_room-n1'
const PANEL = 'hilos-daemon-placement-selection'
const PLACEMENT = 'hilos-daemon-placement-tab'

afterEach(() => {
  cleanup()
  vi.useRealTimers()
})

describe('HilosDaemonPage', () => {
  it('consumes the single page answer, selects nodes locally and disposes both handles', () => {
    const h = harness(false, placementPicture(), placementRows())
    expect(h.view.container.querySelectorAll('h1')).toHaveLength(1)
    expect(h.view.container.querySelector('h1')?.textContent).toBe(
      'Daemon from catalog',
    )
    expect(h.find('hilos-daemon-placement-view')).toBeNull()
    expect(h.register).toHaveBeenCalledTimes(1)
    expect(h.sendRendered).toHaveBeenCalledTimes(1)
    const breadcrumb = h.find('hilos-breadcrumb')!.innerHTML
    h.click('hilos-daemon-node-n2')
    expect(h.find('hilos-daemon-node-n2')?.getAttribute('aria-pressed')).toBe(
      'true',
    )
    expect(h.find('hilos-daemon-inspector')?.getAttribute('data-node-id')).toBe(
      'n2',
    )
    expect(h.find('hilos-daemon-node-log')?.getAttribute('href')).toBe(
      '/hilos/logs/view/n2/live',
    )
    expect(
      h.find('hilos-admin-child-hilos_daemon_workers')?.getAttribute('href'),
    ).toBe('/hilos/daemon/n2/workers')
    expect(h.find('hilos-breadcrumb')!.innerHTML).toBe(breadcrumb)
    expect(h.router.currentRoute.get().params).toEqual({})
    expect(h.router.currentPath.get()).toBe('/hilos/daemon')
    expect(h.send).not.toHaveBeenCalled()
    expect(h.sendAction).not.toHaveBeenCalled()
    expect(h.navigate).not.toHaveBeenCalled()
    expect(h.replacePath).not.toHaveBeenCalled()
    h.view.unmount()
    expect(h.unregister).toHaveBeenCalledWith('hilosDaemonPlacement')
    expect(
      [...h.listeners.values()].reduce((n, group) => n + group.size, 0),
    ).toBe(h.initialListenerCount)
  })

  it('keeps unknown, empty and zero pictures distinct and never fabricates APP_ENV', () => {
    const h = harness(false)
    expect(h.find('hilos-daemon-overview-waiting')).not.toBeNull()
    expect(h.find('hilos-daemon-inspector')).toBeNull()
    act(() => h.update(picture([])))
    expect(h.find('hilos-daemon-overview-empty')?.textContent).toBe(
      'No nodes in the picture',
    )
    act(() =>
      h.update(
        picture(
          [
            node('solo', {
              state: null,
              workers: [],
              measurements: null,
              standing: { sessions: 0, connections: 0 },
              uptimeMinutes: 0,
            }),
          ],
          false,
        ),
      ),
    )
    expect(h.find('hilos-daemon-workers-solo')?.textContent).toBe('No workers')
    expect(h.find('hilos-daemon-cores')?.textContent).toBe('—')
    expect(h.find('hilos-daemon-monopolistic-count')?.textContent).toBe('0')
    expect(h.find('hilos-daemon-inspector-connections')?.textContent).toBe(
      '0 connections',
    )
    expect(h.find('hilos-daemon-consensus')).toBeNull()
    expect(h.find('hilos-daemon-inspector-state')).toBeNull()
    expect(h.find('hilos-daemon-environment-link')).toBeNull()
    expect(h.find('hilos-daemon-environment-unbuilt')?.textContent).toBe(
      'Environment page is not available yet',
    )
    expect(h.view.container.textContent).not.toContain('prod')
    act(() =>
      h.update(
        picture(
          [node('solo', { workers: null, role: null, measurements: null })],
          false,
        ),
      ),
    )
    expect(h.find('hilos-daemon-workers-solo')?.textContent).toBe('Workers: —')
    expect(h.find('hilos-daemon-monopolistic-count')?.textContent).toBe('—')
    expect(h.find('hilos-daemon-report-notice')?.textContent).toContain(
      'This node has not reported yet.',
    )
  })

  it('draws measured coverage, unlimited monopolistic workers, safe DB and retained silent reports', () => {
    const h = harness(true)
    vi.spyOn(Date, 'now').mockReturnValue(1_700_000_060_000)
    act(() =>
      h.update(placementPicture(), {
        host: '::1',
        port: 3306,
        name: 'hilos',
        socket: null,
        migrationIndex: 0,
      }),
    )
    expect(h.find('hilos-daemon-workers-measured')?.textContent).toBe(
      '2 of 3 worker processes measured',
    )
    expect(h.find('hilos-daemon-total-memory')?.textContent).toBe('4.0 KB')
    expect(h.find('hilos-daemon-monopolistic-count')?.textContent).toBe('2')
    expect(
      h
        .find('hilos-daemon-worker-n1-monopolistic-9')
        ?.getAttribute('data-occupancy'),
    ).toBe('free')
    expect(h.find('hilos-daemon-quorum')?.textContent).toContain(
      '1 of 5 · Unavailable',
    )
    expect(h.find('hilos-daemon-database-address')?.textContent).toBe(
      'mysql://[::1]:3306/hilos',
    )
    expect(h.find('hilos-daemon-database-migration')?.textContent).toBe(
      'Migration: 0',
    )
    h.click('hilos-daemon-node-n2')
    expect(h.find('hilos-daemon-environment-link')?.getAttribute('href')).toBe(
      '/hilos/daemon/n2/env',
    )
    expect(h.find('hilos-daemon-database-address')?.textContent).toBe(
      'mysql://[::1]:3306/hilos',
    )
    act(() =>
      h.update(
        picture([
          node('n2', {
            state: 'silent',
            online: false,
            silentSince: 1_700_000_000,
          }),
        ]),
      ),
    )
    expect(h.find('hilos-daemon-uptime')?.textContent).toBe('2 h 5 min')
    expect(h.find('hilos-daemon-report-notice')?.textContent).toContain(
      'values from the last report',
    )
    h.click('hilos-daemon-report-details')
    expect(document.querySelector('[role="dialog"]')?.textContent).toContain(
      'Last arrival:',
    )
    vi.restoreAllMocks()
  })

  it('retains one lazy matrix, search and open cell across local tabs, without another subscription', () => {
    const h = harness(false, placementPicture(), placementRows())
    h.click(PLACEMENT)
    const matrix = h.find('hilos-daemon-placement-view')
    const table = matrix!.querySelector('table')!
    expect(table.textContent).toContain('n1 ★')
    expect(table.textContent).toContain('Policy-placed · lazy')
    fireEvent.change(h.find('hilos-table-search')!, {
      target: { value: 'room' },
    })
    act(() => h.window(placementRows()))
    h.click(CELL, table)
    h.click('hilos-daemon-nodes-tab')
    expect(matrix?.parentElement?.hidden).toBe(true)
    h.click(PLACEMENT)
    expect(h.find('hilos-daemon-placement-view')).toBe(matrix)
    expect(matrix?.parentElement?.hidden).toBe(false)
    expect((h.find('hilos-table-search') as HTMLInputElement).value).toBe(
      'room',
    )
    expect(h.find(PANEL, table)?.textContent).toContain('chat_room:2')
    expect(h.find(CELL, table)?.getAttribute('aria-expanded')).toBe('true')
    expect(h.register).toHaveBeenCalledTimes(1)
    expect(h.send).not.toHaveBeenCalled()
    expect(h.sendAction).not.toHaveBeenCalled()
  })

  it('uses the row detail and card detail, toggles with the chevron and reads live ids', () => {
    const h = harness(false, placementPicture(), placementRows())
    h.click(PLACEMENT)
    const table = h.view.container.querySelector('table')!
    h.click(CELL, table)
    const detail = h.find('hilos-table-row-detail-chat_room', table)!
    expect(h.find('hilos-table-row-chat_room', table)?.nextElementSibling).toBe(
      detail,
    )
    expect(h.find(PANEL, detail)).not.toBeNull()
    expect(
      h
        .find('hilos-table-card-chat_room')
        ?.querySelector(`[data-id="${PANEL}"]`),
    ).not.toBeNull()
    expect(table.querySelectorAll(`[data-id="${PANEL}"]`)).toHaveLength(1)
    h.click('hilos-table-expand-chat_room', table)
    expect(h.find(PANEL, table)).toBeNull()
    expect(h.find(CELL, table)?.getAttribute('aria-expanded')).toBe('false')
    h.click('hilos-table-expand-chat_room', table)
    expect(h.find(PANEL, table)?.textContent).toContain('chat_room:2')
    const longId = `chat_room:${'x'.repeat(200)}`
    act(() => h.window(placementRows([longId], ['chat_room:55'])))
    expect(h.find(PANEL, table)?.textContent).toContain(longId)
    expect(h.find(PANEL, table)?.textContent).not.toContain('chat_room:2')
    expect(
      h.find(PANEL, table)?.querySelector('code.text-break'),
    ).not.toBeNull()
    h.click('hilos-daemon-placement-cell-chat_room-n2', table)
    expect(h.find(PANEL, table)?.textContent).toContain('chat_room:55')
    expect(h.find(PANEL, table)?.textContent).not.toContain(longId)
    h.click('hilos-daemon-placement-cell-chat_room-n2', table)
    expect(h.find(PANEL, table)).toBeNull()
  })

  it('closes on search, keeps totals and alarms unfiltered and needs a new explicit open', () => {
    vi.useFakeTimers()
    const h = harness(false, placementPicture(), placementRows())
    h.click(PLACEMENT)
    const table = h.view.container.querySelector('table')!
    h.click(CELL, table)
    fireEvent.change(h.find('hilos-table-search')!, { target: { value: '99' } })
    act(() => vi.advanceTimersByTime(1000))
    expect(h.find(PANEL)).toBeNull()
    act(() => h.window(placementRows(['chat_room:99'])))
    expect(h.find(PANEL)).toBeNull()
    expect(h.find(CELL, table)?.getAttribute('aria-expanded')).toBe('false')
    expect(h.find('hilos-daemon-placement-instances')?.textContent).toBe('27')
    expect(h.find('hilos-daemon-placement-types')?.textContent).toBe('12')
    expect(h.find('hilos-daemon-placement-cluster-types')?.textContent).toBe(
      '4',
    )
    expect(h.find('hilos-daemon-placement-unplaced')?.textContent).toBe('2')
    expect(
      h.find('hilos-daemon-placement-alerts')?.querySelectorAll('p'),
    ).toHaveLength(2)
    h.click(CELL, table)
    expect(h.find(PANEL, table)?.textContent).toContain('chat_room:99')
    act(() => h.window(placementRows([])))
    expect(h.find(PANEL)).toBeNull()
    expect(h.find(CELL)?.tagName).toBe('SPAN')
    expect(h.find(CELL)?.textContent).toBe('·')
    expect(table.querySelector(`button[data-id="${CELL}"]`)).toBeNull()
  })

  it.each(['silent', 'frozen', 'refused', 'removed'] as const)(
    'forgets an inadmissible cell on %s',
    (state) => {
      const h = harness(false, placementPicture(), placementRows())
      h.click(PLACEMENT)
      h.click(CELL)
      act(() => {
        if (state === 'silent')
          h.update({
            ...placementPicture(),
            nodes: [node('n1', { online: false, state: 'silent' })],
          })
        else if (state === 'removed') h.window([])
        else
          h.emit(
            state === 'frozen' ? 'tableViewportFrozen' : 'tableWindowRefused',
            {
              data: {
                page: HilosPages.DAEMON,
                tableKey: 'hilosDaemonPlacement',
                since: 123,
                errorCode: 'unavailable',
              },
            },
          )
      })
      expect(h.find(PANEL)).toBeNull()
      act(() => {
        h.update(placementPicture())
        h.window(placementRows())
      })
      expect(h.find(PANEL)).toBeNull()
    },
  )

  it('keeps local placement controls available to a view-mode viewer', () => {
    const h = harness(false, placementPicture(), placementRows())
    h.scopes.session.data.set('adminViewMode', true)
    let release = () => {}
    act(() => {
      release = bindAdminAccess(h.scopes)
    })
    expect(hilosAdminAccess.get()).toBe('view')
    h.click(PLACEMENT)
    expect((h.find(CELL) as HTMLButtonElement).disabled).toBe(false)
    h.click(CELL)
    expect(h.find(PANEL)).not.toBeNull()
    h.click('hilos-daemon-placement-collapse')
    expect(h.find(PANEL)).toBeNull()
    act(() => release())
  })

  it('omits node log link when showLogLinks is false while retaining real inspector values', () => {
    const h = harness(false, picture([node('n1')]), undefined, false)
    expect(h.find('hilos-daemon-inspector')?.getAttribute('data-node-id')).toBe(
      'n1',
    )
    expect(h.find('hilos-daemon-cores')?.textContent).toContain('2')
    expect(h.find('hilos-daemon-node-log')).toBeNull()
  })
})
