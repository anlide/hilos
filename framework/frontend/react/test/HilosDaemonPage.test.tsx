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
    measurements: {
      cores: 2,
      daemonCores: 3,
      masterMemory: 1024,
      workersMemory: 3072,
      workersMeasured: 2,
      memoryCeiling: 2048,
    },
    standing: { connections: 3 },
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
                page: HilosPages.DAEMON_NODE,
                label: 'Node',
                lead: 'One node: processor, memory, environment summary, and maintenance.',
                icon: 'bi-hdd-network',
              },
              {
                page: HilosPages.DAEMON_ENV_MISMATCH,
                label: 'Env mismatch',
                lead: 'Environment keys whose values differ across the cluster.',
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
    window: (rows: readonly TableRow[], requestId?: string) =>
      emit('tableWindow', {
        data: {
          ...(requestId === undefined ? {} : { requestId }),
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
  it('consumes the lean answer and exposes each node’s real encoded Overview link', () => {
    const nodeId = 'node /?#%2F🌍'
    const h = harness(
      false,
      picture([node('n1'), node(nodeId)]),
      placementRows(),
    )
    expect(h.view.container.querySelectorAll('h1')).toHaveLength(1)
    expect(h.view.container.querySelector('h1')?.textContent).toBe(
      'Daemon from catalog',
    )
    const box = [...h.view.container.querySelectorAll('a')].find(
      (item) => item.getAttribute('data-id') === `hilos-daemon-node-${nodeId}`,
    )!
    expect(box.tagName).toBe('A')
    expect(box.getAttribute('href')).toBe(
      h.router.resolvePath(HilosPages.DAEMON_NODE, { nodeId }),
    )
    expect(box.getAttribute('aria-label')).toBe(`Node ${nodeId}`)
    expect(box.getAttribute('aria-pressed')).toBeNull()
    expect(h.find('hilos-daemon-inspector')).toBeNull()
    expect(h.register).toHaveBeenCalledTimes(1)
    expect(h.sendAction).not.toHaveBeenCalled()
    fireEvent.keyDown(box, { key: ' ' })
    expect(h.navigate).not.toHaveBeenCalled()
    fireEvent.click(box)
    expect(h.navigate).toHaveBeenCalledWith(
      h.router.resolvePath(HilosPages.DAEMON_NODE, { nodeId }),
    )
    expect(h.router.currentPath.get()).toBe(
      h.router.resolvePath(HilosPages.DAEMON_NODE, { nodeId }),
    )
    expect(h.send).toHaveBeenCalledTimes(1)
    h.view.unmount()
    expect(h.unregister).toHaveBeenCalledWith(
      'hilosDaemonPlacement',
      h.register.mock.calls[0]?.[1],
    )
    expect(
      [...h.listeners.values()].reduce((n, group) => n + group.size, 0),
    ).toBe(h.initialListenerCount)
  })

  it('keeps unknown, empty and zero pictures distinct and standalone addressable', () => {
    const h = harness(false)
    expect(h.find('hilos-daemon-overview-waiting')).not.toBeNull()
    act(() => h.update(picture([])))
    expect(h.find('hilos-daemon-overview-empty')?.textContent).toBe(
      'No nodes in the picture',
    )
    act(() =>
      h.update(
        picture(
          [node('solo', { workers: null, measurements: null, standing: null })],
          false,
        ),
      ),
    )
    expect(h.find('hilos-daemon-workers-solo')?.textContent).toBe('Workers: —')
    expect(h.find('hilos-daemon-browsers-solo')?.textContent).toBe(
      'Browser connections: —',
    )
    expect(h.find('hilos-daemon-node-solo')?.getAttribute('href')).toBe(
      '/hilos/daemon/solo',
    )
    expect(h.find('hilos-daemon-node-solo')?.textContent).toContain(
      'Single installation',
    )
    expect(h.find('hilos-daemon-consensus')).toBeNull()
    const zero = node('solo')
    act(() =>
      h.update(
        picture(
          [
            {
              ...zero,
              workers: [],
              standing: { connections: 0 },
              measurements: {
                ...zero.measurements!,
                cores: 0,
                daemonCores: 0,
                masterMemory: 0,
                workersMemory: 0,
                workersMeasured: 0,
                memoryCeiling: 0,
              },
            },
          ],
          false,
        ),
      ),
    )
    expect(h.find('hilos-daemon-workers-solo')?.textContent).toBe('No workers')
    expect(h.find('hilos-daemon-browsers-solo')?.textContent).toBe(
      'Browser connections: 0',
    )
    expect(h.find('hilos-daemon-node-solo')?.textContent).toContain('0 B / 0 B')
    expect(h.find('hilos-daemon-inspector')).toBeNull()
    expect(h.view.container.textContent).not.toContain('APP_ENV')
  })

  it('draws live CPU, memory, coverage and workers and replaces stale silent metrics with time', () => {
    const h = harness(true, placementPicture())
    const box = () => h.find('hilos-daemon-node-n1')!
    expect(box().textContent).toContain('150%')
    expect(box().textContent).toContain('4.0 KB / 2.0 KB')
    expect(box().textContent).toContain('2 worker processes measured')
    expect(
      h
        .find('hilos-daemon-worker-n1-monopolistic-9')
        ?.getAttribute('data-occupancy'),
    ).toBe('free')
    expect(h.find('hilos-daemon-quorum')?.textContent).toContain(
      '1 of 5 · Unavailable',
    )
    act(() =>
      h.update(
        picture([
          node('n1', {
            online: false,
            state: 'silent',
            silentSince: 1_700_000_000,
          }),
        ]),
      ),
    )
    expect(box().getAttribute('href')).toBe('/hilos/daemon/n1')
    expect(box().textContent).toContain('Silent since')
    expect(h.find('hilos-daemon-silent-n1')?.textContent).not.toContain('—')
    expect(box().textContent).not.toContain('Daemon CPU')
    expect(box().textContent).not.toContain('Measured memory')
    expect(h.find('hilos-daemon-workers-n1')).toBeNull()
    act(() =>
      h.update(
        picture([
          node('n1', {
            online: false,
            state: 'silent',
            silentSince: null,
            measurements: null,
            workers: null,
            standing: null,
          }),
        ]),
      ),
    )
    expect(box().textContent).toContain('Silent since —')
    act(() =>
      h.update(picture([node('n1', { role: 'slave', state: 'data' })]), {
        host: '::1',
        port: 3306,
        name: 'hilos',
        socket: null,
        migrationIndex: 0,
      }),
    )
    expect(box().textContent).toContain('150%')
    expect(h.find('hilos-daemon-browsers-n1')?.textContent).toBe(
      'Does not accept browsers',
    )
    expect(h.find('hilos-daemon-database-address')?.textContent).toBe(
      'mysql://[::1]:3306/hilos',
    )
    expect(h.find('hilos-daemon-database-migration')?.textContent).toBe(
      'Migration: 0',
    )
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
    act(() =>
      h.window(
        placementRows(['chat_room:99']),
        h.sendViewport.mock.calls.at(-1)?.[2].requestId,
      ),
    )
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

  it('keeps root boxes free of Inspector and logs when showLogLinks is false', () => {
    const h = harness(false, picture([node('n1')]), undefined, false)
    expect(h.find('hilos-daemon-inspector')).toBeNull()
    expect(h.find('hilos-daemon-node-n1')?.getAttribute('href')).toBe(
      '/hilos/daemon/n1',
    )
    expect(h.find('hilos-daemon-node-n1')?.textContent).toContain('150%')
    expect(h.find('hilos-daemon-node-log')).toBeNull()
  })
})
