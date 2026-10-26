import {
  HilosPages,
  ScopeManager,
  bindAdminAccess,
  createAppPageRouter,
  createSignal,
  hilosAdminAccess,
  ingest,
  pageIdentity,
  type HilosConnection,
  type HilosRouter,
  type TableViewportController,
  type HilosDaemonOverviewData,
  type HilosDaemonPlacementRow,
  bindPageScope,
  createHilosRouter,
  hilosUnbuiltPages,
  type HilosDaemonOverviewNode,
  type HilosDaemonDatabaseData,
  type ProjectSignal,
  type TableRow,
} from '@hilos/core'
import {
  act,
  cleanup,
  render,
  type RenderResult,
  fireEvent,
} from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  HilosDaemonPlacementPage,
  HilosDaemonPage,
  HilosDaemonEnvMismatchPage,
} from '../src/admin/daemon/index.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

afterEach(() => {
  cleanup()
  vi.useRealTimers()
  vi.restoreAllMocks()
})

const ownerLead = 'Nodes and their processes, with agent placement across them.'

function picture(clustered = true): HilosDaemonOverviewData {
  return {
    clustered,
    nodes: (clustered ? ['n1', 'n2'] : ['standalone']).map((nodeId) => ({
      nodeId,
      online: true,
      role: 'master',
      state: 'leader',
      silentSince: null,
      workers: [],
      measurements: null,
      standing: null,
    })),
    entryPoints: null,
    placement: {
      instances: 7,
      types: 3,
      clusterTypes: 2,
      unplacedTypes: ['unplaced'],
    },
    consensus: null,
  }
}

function harness(
  clustered = true,
  viewer = false,
  page: string = HilosPages.DAEMON_PLACEMENT,
) {
  const scopes = new ScopeManager()
  const scope = scopes.openPage(page)
  ingest(scope, {
    data: {
      daemon: null,
      envMismatch: { clustered, servingNodeId: 'standalone', comparison: null },
      pageLabel: 'Agent placement',
      pageLead: 'Where agent types and instances run across nodes.',
      pageHeadOwner: {
        page: HilosPages.DAEMON,
        label: 'Daemon',
        lead: ownerLead,
        icon: 'bi-cpu',
      },
      pageBreadcrumb: [
        { page: HilosPages.DASHBOARD, label: 'Hilos' },
        { page: HilosPages.DAEMON, label: 'Daemon' },
      ],
      pageTabs: [
        { page: HilosPages.DAEMON, label: 'Overview' },
        { page: HilosPages.DAEMON_PLACEMENT, label: 'Agent placement' },
        ...(clustered
          ? [{ page: HilosPages.DAEMON_ENV_MISMATCH, label: 'Env mismatch' }]
          : []),
      ],
      pageChildren: [],
    },
  })
  ingest(scopes.session, {
    entities: { currentUser: { id: 7, admin: !viewer } },
    data: { adminViewMode: viewer },
  })
  const releaseAccess = bindAdminAccess(scopes)
  const readData = vi.spyOn(scopes, 'pageDataSignal')
  const listeners = new Map<string, Set<(value: never) => void>>()
  const register = vi.fn<HilosConnection['registerTableWindow']>()
  const unregister = vi.fn()
  const sendAction = vi.fn()
  const connection = {
    on(event: string, listener: (value: never) => void) {
      const group = listeners.get(event) ?? new Set()
      listeners.set(event, group)
      group.add(listener)
      return () => {
        group.delete(listener)
      }
    },
    registerTableWindow: register,
    unregisterTableWindow: unregister,
    sendTableViewport: vi.fn(() => true),
    sendTableRendered: vi.fn(() => true),
    sendAction,
  } as unknown as HilosConnection
  const router = {
    currentRoute: createSignal({
      page,
      params: {},
      admin: true,
    }),
    currentPath: createSignal(createAppPageRouter({}).path(page, {})!),
    pageIdentity: pageIdentity(scopes),
    pageError: createSignal(null),
    resolvePath: createAppPageRouter({}).path,
    navigate: vi.fn(),
  } as unknown as HilosRouter
  const context = { scopes, connection }
  const Component =
    page === HilosPages.DAEMON
      ? HilosDaemonPage
      : page === HilosPages.DAEMON_ENV_MISMATCH
        ? HilosDaemonEnvMismatchPage
        : HilosDaemonPlacementPage
  const root = document.createElement('div')
  document.body.append(root)
  let view: RenderResult
  let mounted = false
  const mountPage = () => {
    mounted = true
    view = render(
      <HilosRouterContext.Provider value={router}>
        <Component context={context} />
      </HilosRouterContext.Provider>,
      { container: root.appendChild(document.createElement('div')) },
    )
  }
  const unmountPage = () => {
    if (!mounted) return
    mounted = false
    view.unmount()
    root.replaceChildren()
  }
  mountPage()
  return {
    scopes,
    scope,
    register,
    unregister,
    readData,
    sendAction,
    root,
    mountPage,
    unmountPage,
    releaseAccess: () => {
      releaseAccess()
      root.remove()
    },
    listeners: () =>
      [...listeners.values()].reduce((sum, group) => sum + group.size, 0),
    controller: () =>
      register.mock.calls.at(
        -1,
      )![1] as TableViewportController<HilosDaemonPlacementRow>,
    update: (value: HilosDaemonOverviewData | null) =>
      scope.data.set('daemon', value),
    el: (id: string) => root.querySelector<HTMLElement>(`[data-id="${id}"]`),
  }
}

describe('HilosDaemonPlacementPage', () => {
  it.each([
    [true, false],
    [false, false],
    [true, true],
    [false, true],
  ])(
    'draws the shared section head and links (cluster=%s, viewer=%s)',
    (clustered, viewer) => {
      const h = harness(clustered, viewer)
      try {
        expect(hilosAdminAccess.get()).toBe(viewer ? 'view' : 'full')
        expect(h.root.querySelectorAll('h1')).toHaveLength(1)
        expect(h.root.querySelector('h1')!.textContent?.trim()).toBe('Daemon')
        expect(
          h.root.querySelector('.bi-cpu')!.closest('[aria-hidden="true"]'),
        ).not.toBeNull()
        expect(h.el('hilos-page-head-block')!.textContent).toContain(ownerLead)
        const nav = h.root.querySelector('nav[aria-label="Daemon pages"]')!
        const links = [...nav.querySelectorAll('a')]
        expect(
          links.map((link) => [
            link.textContent?.trim(),
            link.getAttribute('href'),
          ]),
        ).toEqual(
          clustered
            ? [
                ['Overview', '/hilos/daemon'],
                ['Agent placement', '/hilos/daemon/placement'],
                ['Env mismatch', '/hilos/daemon/env-mismatch'],
              ]
            : [
                ['Overview', '/hilos/daemon'],
                ['Agent placement', '/hilos/daemon/placement'],
              ],
        )
        expect(nav.querySelectorAll('[aria-current="page"]')).toHaveLength(1)
        expect(links[1]!.getAttribute('aria-current')).toBe('page')
        expect(h.el('hilos-page-tab-hilos_daemon_placement')).toBe(links[1])
        expect(h.root.querySelector('[role="tablist"]')).toBeNull()
        expect(h.el('hilos-daemon-placement-view')!.textContent).toContain(
          'Waiting for the node picture',
        )
        expect(h.root.querySelector('table')).toBeNull()
        act(() => h.update(picture(clustered)))
        expect(h.root.querySelectorAll('table')).toHaveLength(1)
        expect(
          h.root.querySelector('table')!.getAttribute('aria-labelledby'),
        ).toBe(h.root.querySelector('h1')!.id)
        expect(
          h.el('hilos-daemon-placement-instances')!.textContent?.trim(),
        ).toBe('7')
        expect(h.sendAction).not.toHaveBeenCalled()
      } finally {
        h.unmountPage()
        h.releaseAccess()
      }
    },
  )

  it('reads reactive totals and node columns only from the complete daemon scope', () => {
    const h = harness()
    try {
      act(() => h.update(picture()))
      expect(h.el('hilos-daemon-placement-types')!.textContent?.trim()).toBe(
        '3',
      )
      expect(
        h.el('hilos-daemon-placement-cluster-types')!.textContent?.trim(),
      ).toBe('2')
      expect(h.el('hilos-daemon-placement-unplaced')!.textContent?.trim()).toBe(
        '1',
      )
      expect(h.root.querySelector('thead')!.textContent).toContain('n1 ★')
      expect(h.root.querySelector('thead')!.textContent).toContain('n2 ★')
      act(() => h.controller().setSearch('room:42'))
      expect(
        h.el('hilos-daemon-placement-instances')!.textContent?.trim(),
      ).toBe('7')
      const next = picture(false)
      act(() =>
        h.update({
          ...next,
          placement: {
            instances: 0,
            types: 4,
            clusterTypes: 1,
            unplacedTypes: [],
          },
        }),
      )
      expect(
        h.el('hilos-daemon-placement-instances')!.textContent?.trim(),
      ).toBe('0')
      expect(h.el('hilos-daemon-placement-types')!.textContent?.trim()).toBe(
        '4',
      )
      expect(
        h.el('hilos-daemon-placement-cluster-types')!.textContent?.trim(),
      ).toBe('1')
      expect(h.el('hilos-daemon-placement-unplaced')!.textContent?.trim()).toBe(
        '0',
      )
      expect(h.root.querySelector('thead')!.textContent).toContain('standalone')
      expect(h.root.querySelector('thead')!.textContent).not.toContain('n2')
      for (const id of [
        'hilos-daemon-database',
        'hilos-daemon-nodes-view',
        'hilos-daemon-inspector',
        'hilos-daemon-placement-tab',
        'hilos-daemon-nodes-tab',
      ]) {
        expect(h.el(id)).toBeNull()
      }
      act(() => h.update(null))
      expect(h.el('hilos-daemon-placement-view')!.textContent).toContain(
        'Waiting for the node picture',
      )
      expect(h.root.querySelector('table')).toBeNull()
      expect(h.sendAction).not.toHaveBeenCalled()
      expect(h.readData).not.toHaveBeenCalledWith('database')
    } finally {
      h.unmountPage()
      h.releaseAccess()
    }
  })

  it('releases the mounted view and remounts a fresh controller without local search', () => {
    const h = harness()
    try {
      expect(h.register).toHaveBeenCalledTimes(1)
      expect(h.listeners()).toBeGreaterThan(0)
      const previous = h.controller()
      act(() => h.update(picture()))
      act(() => previous.setSearch('room:42'))
      h.unmountPage()
      expect(h.listeners()).toBe(0)
      expect(h.unregister).toHaveBeenCalledTimes(1)
      expect(h.unregister.mock.calls[0]![1]).toBe(previous)
      act(() => h.update(picture(false)))
      h.mountPage()

      expect(h.register).toHaveBeenCalledTimes(2)
      expect(h.controller()).not.toBe(previous)
      expect(h.controller().search.get()).toBe('')
      expect(h.el('hilos-daemon-placement-selection')).toBeNull()
      expect(h.root.querySelector('thead')!.textContent).toContain('standalone')
      h.unmountPage()
      expect(h.listeners()).toBe(0)
      expect(h.unregister).toHaveBeenCalledTimes(2)
    } finally {
      h.unmountPage()
      h.releaseAccess()
    }
  })
})

function liveNode(
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

function livePicture(
  nodes: readonly HilosDaemonOverviewNode[],
  clustered = true,
): HilosDaemonOverviewData {
  return {
    clustered,
    nodes,
    entryPoints: null,
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

function liveHarness(
  envBuilt = true,
  initial?: HilosDaemonOverviewData,
  initialRows?: readonly TableRow[],
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
  let pathname = '/hilos/daemon/placement'
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
        page: HilosPages.DAEMON_PLACEMENT,
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
            pageHeadOwner: {
              page: HilosPages.DAEMON,
              label: 'Daemon from catalog',
              lead: 'Technical overview',
              icon: 'bi-cpu',
            },
            pageBreadcrumb: [
              { page: HilosPages.DASHBOARD, label: 'Hilos' },
              { page: HilosPages.DAEMON, label: 'Daemon from catalog' },
            ],
            pageTabs: [
              { page: HilosPages.DAEMON, label: 'Overview' },
              { page: HilosPages.DAEMON_PLACEMENT, label: 'Agent placement' },
              ...(value?.clustered
                ? [
                    {
                      page: HilosPages.DAEMON_ENV_MISMATCH,
                      label: 'Env mismatch',
                    },
                  ]
                : []),
            ],
            pageChildren: [],
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
      <HilosDaemonPlacementPage context={{ scopes, connection }} />
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
          page: HilosPages.DAEMON_PLACEMENT,
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
    ...livePicture(
      [liveNode('n1', { state: 'leader' }), liveNode('n2')],
      clustered,
    ),
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

describe('HilosDaemonPlacementPage live windows', () => {
  it('retains its matrix, search and open cell on data-only source changes', () => {
    const h = liveHarness(false, placementPicture(), placementRows())

    const matrix = h.find('hilos-daemon-placement-view')
    const table = matrix!.querySelector('table')!
    expect(table.textContent).toContain('n1 ★')
    expect(table.textContent).toContain('Policy-placed · lazy')
    fireEvent.change(h.find('hilos-table-search')!, {
      target: { value: 'room' },
    })
    act(() => h.window(placementRows()))
    h.click(CELL, table)
    act(() =>
      h.update({
        ...placementPicture(),
        entryPoints: { nodeId: 'n2', commandPort: 9014, httpPort: 8082 },
      }),
    )
    expect(h.find('hilos-daemon-placement-view')).toBe(matrix)
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
    const h = liveHarness(false, placementPicture(), placementRows())

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
    const h = liveHarness(false, placementPicture(), placementRows())

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
      const h = liveHarness(false, placementPicture(), placementRows())

      h.click(CELL)
      act(() => {
        if (state === 'silent')
          h.update({
            ...placementPicture(),
            nodes: [liveNode('n1', { online: false, state: 'silent' })],
          })
        else if (state === 'removed') h.window([])
        else
          h.emit(
            state === 'frozen' ? 'tableViewportFrozen' : 'tableWindowRefused',
            {
              data: {
                page: HilosPages.DAEMON_PLACEMENT,
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
    const h = liveHarness(false, placementPicture(), placementRows())
    h.scopes.session.data.set('adminViewMode', true)
    let release = () => {}
    act(() => {
      release = bindAdminAccess(h.scopes)
    })
    expect(hilosAdminAccess.get()).toBe('view')

    expect((h.find(CELL) as HTMLButtonElement).disabled).toBe(false)
    h.click(CELL)
    expect(h.find(PANEL)).not.toBeNull()
    h.click('hilos-daemon-placement-collapse')
    expect(h.find(PANEL)).toBeNull()
    act(() => release())
  })
})

describe('Daemon section head parity', () => {
  it.each([HilosPages.DAEMON, HilosPages.DAEMON_ENV_MISMATCH])(
    'shares the adopted head on %s',
    (page) => {
      for (const clustered of [true, false]) {
        const h = harness(clustered, false, page)
        try {
          expect(h.root.querySelectorAll('h1')).toHaveLength(1)
          expect(h.root.querySelector('h1')!.textContent?.trim()).toBe('Daemon')
          expect(h.root.querySelector('.bi-cpu')).not.toBeNull()
          expect(h.el('hilos-page-head-block')!.textContent).toContain(
            ownerLead,
          )
          const nav = h.root.querySelector('nav[aria-label="Daemon pages"]')!
          expect(nav.querySelectorAll('a')).toHaveLength(clustered ? 3 : 2)
          const current = nav.querySelectorAll('[aria-current="page"]')
          expect(current).toHaveLength(
            !clustered && page === HilosPages.DAEMON_ENV_MISMATCH ? 0 : 1,
          )
          expect(h.el('hilos-page-tab-hilos_daemon_node')).toBeNull()
          if (page === HilosPages.DAEMON) {
            expect(h.register).not.toHaveBeenCalled()
            expect(h.root.querySelector('table')).toBeNull()
          } else if (!clustered) {
            expect(
              h.el('hilos-daemon-env-mismatch-standalone-node')!.textContent,
            ).toContain('standalone')
          }
        } finally {
          h.unmountPage()
          h.releaseAccess()
        }
      }
    },
  )
})
