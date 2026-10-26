import { mount, type VueWrapper } from '@vue/test-utils'
import { nextTick } from 'vue'
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
  bindPageScope,
  createHilosRouter,
  hilosUnbuiltPages,
  type ProjectSignal,
  type TableRow,
} from '@hilos/core/base'
import {
  type HilosDaemonOverviewData,
  type HilosDaemonPlacementRow,
  type HilosDaemonOverviewNode,
  type HilosDaemonDatabaseData,
} from '@hilos/core/admin/daemon'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  HilosDaemonPlacementPage,
  HilosDaemonPage,
  HilosDaemonEnvMismatchPage,
} from './index.js'
import { hilosRouterKey } from '../../hilosRouterKey.js'

afterEach(() => vi.restoreAllMocks())

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
  const component =
    page === HilosPages.DAEMON
      ? HilosDaemonPage
      : page === HilosPages.DAEMON_ENV_MISMATCH
        ? HilosDaemonEnvMismatchPage
        : HilosDaemonPlacementPage
  const root = document.createElement('div')
  document.body.append(root)
  let view: VueWrapper
  let mounted = false
  const mountPage = () => {
    mounted = true
    view = mount(component, {
      props: { context },
      attachTo: root,
      global: { provide: { [hilosRouterKey as symbol]: router } },
    })
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
    async (clustered, viewer) => {
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
        h.update(picture(clustered))
        await nextTick()
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

  it('reads reactive totals and node columns only from the complete daemon scope', async () => {
    const h = harness()
    try {
      h.update(picture())
      await nextTick()
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
      h.controller().setSearch('room:42')
      await nextTick()
      expect(
        h.el('hilos-daemon-placement-instances')!.textContent?.trim(),
      ).toBe('7')
      const next = picture(false)
      h.update({
        ...next,
        placement: {
          instances: 0,
          types: 4,
          clusterTypes: 1,
          unplacedTypes: [],
        },
      })
      await nextTick()
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
      h.update(null)
      await nextTick()
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

  it('releases the mounted view and remounts a fresh controller without local search', async () => {
    const h = harness()
    try {
      expect(h.register).toHaveBeenCalledTimes(1)
      expect(h.listeners()).toBeGreaterThan(0)
      const previous = h.controller()
      h.update(picture())
      await nextTick()
      previous.setSearch('room:42')
      await nextTick()
      h.unmountPage()
      expect(h.listeners()).toBe(0)
      expect(h.unregister).toHaveBeenCalledTimes(1)
      expect(h.unregister.mock.calls[0]![1]).toBe(previous)
      h.update(picture(false))
      await nextTick()
      h.mountPage()
      await nextTick()
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
  const unbuilt = new Set(hilosUnbuiltPages('vue'))
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
  const view = mount(HilosDaemonPlacementPage, {
    attachTo: document.body,
    props: {
      context: { scopes, connection },
    },
    global: { provide: { [hilosRouterKey as symbol]: router } },
  })
  return {
    view,
    page,
    scopes,
    connection,
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

afterEach(() => vi.restoreAllMocks())

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

const cell = '[data-id="hilos-daemon-placement-cell-chat_room-n1"]'
const panel = '[data-id="hilos-daemon-placement-selection"]'
const alerts = '[data-id="hilos-daemon-placement-alerts"]'

describe('HilosDaemonPlacementPage live windows', () => {
  it('replays the first Placement response and keeps local state on data-only updates', async () => {
    const h = liveHarness(false, placementPicture(), placementRows())
    await nextTick()
    expect(h.controller().descriptor()).not.toBeNull()
    expect(
      h.view.find('[data-id="hilos-daemon-placement-view"]').exists(),
    ).toBe(true)
    expect(h.register).toHaveBeenCalledTimes(1)
    expect(h.sendRendered).toHaveBeenCalledTimes(1)
    expect(h.view.findAll('h1')).toHaveLength(1)
    expect(h.view.find('h1').text()).toBe('Daemon from catalog')
    expect(h.view.find('table').text()).toContain('n1 ★')
    expect(h.view.find('table').text()).toContain('n2')
    expect(h.view.find('table').text()).toContain('Policy-placed · lazy')
    const badge = h.view
      .findAll('table .badge')
      .find((liveNode) => !liveNode.element.closest('[inert]'))
    expect(badge?.text()).toBe('Unplaced')
    expect(h.view.find('table tr.table-danger').exists()).toBe(false)
    const cards = h.view.find('[data-id="hilos-table-cards"]')
    expect(cards.text()).toContain('Policy-placed · lazy')
    expect(cards.text()).toContain('Unplaced')
    expect(cards.find(cell).attributes('aria-label')).toBe(
      'chat_room on n1: 2 instances',
    )
    expect(h.view.find(cell).attributes('aria-label')).toBe(
      'chat_room on n1: 2 instances',
    )
    await h.view.find(cell).trigger('keydown', { key: 'Enter' })
    expect(h.view.find(panel).text()).toContain('chat_room:1')
    expect(h.view.find(cell).attributes('aria-expanded')).toBe('true')
    const table = h.view.find('table').element
    const search = h.view.find('[data-id="hilos-table-search"]')
    await search.setValue('room:1')
    h.window(placementRows(['chat_room:1']))
    await nextTick()
    expect(h.view.find(panel).exists()).toBe(false)
    expect(h.view.find(cell).attributes('aria-expanded')).toBe('false')
    await h.view.find(cell).trigger('click')
    h.update({
      ...placementPicture(),
      entryPoints: { nodeId: 'n2', commandPort: 9014, httpPort: 8082 },
    })
    await nextTick()
    expect(h.view.find('[data-id="hilos-daemon-database"]').exists()).toBe(
      false,
    )
    expect(h.view.find('table').element).toBe(table)
    expect(
      (
        h.view.find('[data-id="hilos-table-search"]')
          .element as HTMLInputElement
      ).value,
    ).toBe('room:1')
    expect(h.view.find(panel).isVisible()).toBe(true)
    await h.view.find(cell).trigger('keydown', { key: ' ' })
    expect(h.view.find(panel).exists()).toBe(false)
    expect(h.view.find(cell).attributes('aria-expanded')).toBe('false')
    await h.view.find(cell).trigger('click')
    expect(h.view.find(panel).exists()).toBe(true)
    await h.view
      .find('[data-id="hilos-daemon-placement-collapse"]')
      .trigger('click')
    expect(h.view.find(panel).exists()).toBe(false)
    expect(h.sendViewport).toHaveBeenCalledTimes(1)
    expect(h.send).not.toHaveBeenCalled()
    expect(h.sendAction).not.toHaveBeenCalled()
    expect(h.navigate).not.toHaveBeenCalled()
    expect(h.replacePath).not.toHaveBeenCalled()
    expect(h.register).toHaveBeenCalledTimes(1)
    h.view.unmount()
    expect(h.unregister).toHaveBeenCalledWith(
      'hilosDaemonPlacement',
      h.register.mock.calls[0]?.[1],
    )
    // Only the central page binder remains; every per-table listener is gone.
    for (const [event, group] of h.listeners) {
      if (event.startsWith('table')) expect(group.size).toBe(0)
    }
  })

  it('draws one inline detail under its type and inside its card, with shared count and chevron toggles', async () => {
    const rows = [
      ...placementRows(['chat_room:1'], ['chat_room:55']),
      {
        rowKey: 'hilos_daemon_node',
        slots: {
          type: {
            agentType: 'hilos_daemon_node',
            placement: 'node',
            idle: false,
            nodes: [{ nodeId: 'n1', agentIds: ['hilos_daemon_node'] }],
            unplacedAgentIds: [],
          },
        },
      },
    ]
    const h = liveHarness(false, placementPicture(), rows)
    await nextTick()
    const table = h.view.find('table')
    const cards = h.view.find('[data-id="hilos-table-cards"]')
    const expand = '[data-id="hilos-table-expand-chat_room"]'
    const detail = '[data-id="hilos-table-row-detail-chat_room"]'
    const card = cards.find('[data-id="hilos-table-card-chat_room"]')
    expect(table.find(expand).exists()).toBe(false)
    expect(card.find(expand).exists()).toBe(false)
    await table.find(cell).trigger('click')
    const row = table.find('[data-id="hilos-table-row-chat_room"]')
    expect(row.element.nextElementSibling).toBe(table.find(detail).element)
    expect(table.find(detail).find(panel).text()).toContain('chat_room:1')
    expect(table.find(detail).find('td').attributes('colspan')).toBe(
      String(row.findAll('td').length),
    )
    expect(table.find(detail).find('dl > .col-md-12').exists()).toBe(true)
    expect(card.find(detail).find(panel).text()).toContain('chat_room:1')
    expect(card.find(detail).find('dd').classes()).toContain('col-12')
    expect(h.view.findAll(panel)).toHaveLength(2)
    expect(table.find(expand).attributes('aria-expanded')).toBe('true')
    expect(card.find(expand).attributes('aria-expanded')).toBe('true')
    expect(card.find(cell).attributes('aria-expanded')).toBe('true')
    await table.find(expand).trigger('click')
    expect(h.view.findAll(panel)).toHaveLength(0)
    expect(table.find(cell).attributes('aria-expanded')).toBe('false')
    expect(card.find(expand).attributes('aria-expanded')).toBe('false')
    await card.find(expand).trigger('click')
    expect(table.find(detail).find(panel).text()).toContain('chat_room:1')
    await card.find(cell).trigger('click')
    expect(h.view.findAll(panel)).toHaveLength(0)
    await table.find(cell).trigger('click')
    await table
      .find('[data-id="hilos-daemon-placement-cell-chat_room-n2"]')
      .trigger('click')
    expect(table.find(detail).find(panel).text()).toContain('chat_room:55')
    expect(table.find(cell).attributes('aria-expanded')).toBe('false')
    expect(
      card
        .find('[data-id="hilos-daemon-placement-cell-chat_room-n2"]')
        .attributes('aria-expanded'),
    ).toBe('true')
    await table
      .find('[data-id="hilos-daemon-placement-cell-hilos_daemon_node-n1"]')
      .trigger('click')
    const moved = table.find(
      '[data-id="hilos-table-row-detail-hilos_daemon_node"]',
    )
    expect(
      table.find('[data-id="hilos-table-row-hilos_daemon_node"]').element
        .nextElementSibling,
    ).toBe(moved.element)
    expect(table.find(detail).exists()).toBe(false)
    expect(table.findAll(panel)).toHaveLength(1)
    expect(cards.findAll(panel)).toHaveLength(1)
    expect(moved.find(panel).text()).toContain('hilos_daemon_node')
    await cards
      .find('[data-id="hilos-daemon-placement-collapse"]')
      .trigger('click')
    expect(h.view.findAll(panel)).toHaveLength(0)
    h.view.unmount()
  })

  it('draws four complete totals and every unplaced type independently of server search', async () => {
    const h = liveHarness()
    h.update(placementPicture(), null, placementRows())
    await nextTick()

    expect(
      h.view.find('[data-id="hilos-daemon-placement-instances"]').text(),
    ).toBe('27')
    expect(h.view.find('[data-id="hilos-daemon-placement-types"]').text()).toBe(
      '12',
    )
    expect(
      h.view.find('[data-id="hilos-daemon-placement-cluster-types"]').text(),
    ).toBe('4')
    expect(
      h.view.find('[data-id="hilos-daemon-placement-unplaced"]').text(),
    ).toBe('2')
    expect(h.view.find(alerts).text()).toContain('chat_room is unplaced')
    expect(h.view.find(alerts).text()).toContain('unshown_type is unplaced')
    const summary = h.view
      .find('[data-id="hilos-daemon-placement-view"]')
      .find('.row')
      .text()
    await h.view.find('[data-id="hilos-table-search"]').setValue('absent')
    h.window([])
    await nextTick()
    expect(
      h.view
        .find('[data-id="hilos-daemon-placement-view"]')
        .find('.row')
        .text(),
    ).toBe(summary)
    expect(h.view.find(alerts).findAll('p')).toHaveLength(2)
    expect(h.view.text()).toContain('Nothing found')
    await h.view.find('[data-id="hilos-table-search-clear"]').trigger('click')
    expect(h.sendViewport.mock.calls.at(-1)?.[2].filter).toEqual({})
    h.window(placementRows())
    await nextTick()
    h.view.unmount()
  })

  it('updates current ids, closes empty cells and never discloses an offline node’s old window', async () => {
    const h = liveHarness()
    const full = placementPicture()
    h.update(full, null, placementRows())
    await nextTick()

    await h.view.find(cell).trigger('click')
    const longId = `chat_room:${'a'.repeat(200)}`
    h.window(placementRows([longId]))
    await nextTick()
    expect(h.view.find(panel).text()).toContain(longId)
    expect(h.view.find(panel).text()).not.toContain('chat_room:1')
    expect(h.view.find(panel).findAll('a')).toHaveLength(0)
    expect(h.view.find(panel).find('code.text-break').exists()).toBe(true)
    h.window(placementRows([longId], ['chat_room:55']))
    await nextTick()
    await h.view
      .find('[data-id="hilos-daemon-placement-cell-chat_room-n2"]')
      .trigger('click')
    expect(h.view.find('table').findAll(panel)).toHaveLength(1)
    expect(
      h.view.find('[data-id="hilos-table-cards"]').findAll(panel),
    ).toHaveLength(1)
    expect(h.view.find(panel).text()).toContain('chat_room:55')
    expect(h.view.find(panel).text()).not.toContain(longId)
    expect(h.view.find(cell).attributes('aria-expanded')).toBe('false')
    await h.view.find(cell).trigger('click')
    h.update({
      ...full,
      nodes: [
        liveNode('n1', { online: false, state: 'silent' }),
        liveNode('n2'),
      ],
    })
    await nextTick()
    expect(h.view.find(panel).exists()).toBe(false)
    expect(h.view.find(`button${cell}`).exists()).toBe(false)
    expect(h.view.find(cell).element.tagName).toBe('SPAN')
    expect(h.view.find(cell).text()).toBe('?')
    expect(h.view.find('table span[title]').attributes('title')).toBe(
      'Node is silent or has not reported its agents',
    )
    h.update(full)
    await nextTick()
    expect(h.view.find(panel).exists()).toBe(false)
    await h.view.find(cell).trigger('click')
    h.window(placementRows([]))
    await nextTick()
    expect(h.view.find(panel).exists()).toBe(false)
    expect(h.view.find(`button${cell}`).exists()).toBe(false)
    expect(h.view.find(cell).element.tagName).toBe('SPAN')
    expect(h.view.find(cell).text()).toBe('·')
    expect(h.view.find('table').text()).toContain('·')
    h.window(placementRows(null))
    await nextTick()
    expect(h.view.find('table').text()).toContain('?')
    h.view.unmount()
  })

  it('keeps a searched panel closed after loading and forgets freeze and refusal', async () => {
    vi.useFakeTimers()
    const h = liveHarness()
    try {
      h.update(placementPicture(), null, placementRows())
      await nextTick()

      await h.view.find(cell).trigger('click')
      await h.view.find('[data-id="hilos-table-search"]').setValue('99')
      vi.advanceTimersByTime(1000)
      await nextTick()
      expect(h.view.find(panel).exists()).toBe(false)
      h.window(
        placementRows(['chat_room:99']),
        h.sendViewport.mock.calls.at(-1)?.[2].requestId,
      )
      await nextTick()
      expect(h.view.find(panel).exists()).toBe(false)
      expect(h.view.find(cell).attributes('aria-expanded')).toBe('false')
      await h.view.find(cell).trigger('click')
      expect(h.view.find(panel).text()).toContain('chat_room:99')
      h.emit('tableViewportFrozen', {
        data: {
          page: HilosPages.DAEMON_PLACEMENT,
          tableKey: 'hilosDaemonPlacement',
          since: 123,
        },
      })
      await nextTick()
      expect(h.view.find(panel).exists()).toBe(false)
      h.window(placementRows())
      await nextTick()
      expect(h.view.find(panel).exists()).toBe(false)
      await h.view.find(cell).trigger('click')
      h.emit('tableWindowRefused', {
        data: {
          page: HilosPages.DAEMON_PLACEMENT,
          tableKey: 'hilosDaemonPlacement',
          errorCode: 'unavailable',
        },
      })
      await nextTick()
      expect(h.view.find(panel).exists()).toBe(false)
      expect(h.view.text()).toContain('List unavailable')
      expect(h.view.find(alerts).text()).toContain('unshown_type is unplaced')
    } finally {
      h.view.unmount()
      vi.useRealTimers()
    }
  })

  it('keeps unknown, empty and standalone totals distinct, including an empty node picture', async () => {
    const h = liveHarness()

    expect(h.view.text()).toContain('Waiting for the node picture')
    expect(
      h.view.find('[data-id="hilos-daemon-placement-unplaced"]').exists(),
    ).toBe(false)
    h.update(livePicture([]), null, [])
    await nextTick()
    expect(
      h.view.find('[data-id="hilos-daemon-placement-instances"]').text(),
    ).toBe('—')
    expect(
      h.view.find('[data-id="hilos-daemon-placement-unplaced"]').text(),
    ).toBe('—')
    expect(h.view.text()).toContain('Placement status unknown')
    h.update(
      {
        ...livePicture([liveNode('n1')], false),
        placement: {
          instances: 0,
          types: 0,
          clusterTypes: 0,
          unplacedTypes: [],
        },
      },
      null,
      [],
    )
    await nextTick()
    expect(
      h.view.find('[data-id="hilos-daemon-placement-unplaced"]').text(),
    ).toBe('0')
    expect(
      h.view.find('[data-id="hilos-daemon-placement-instances"]').text(),
    ).toBe('0')
    expect(h.view.text()).toContain('All placed')
    expect(h.view.find(alerts).text()).toBe('')
    h.update(
      { ...placementPicture(false), nodes: [liveNode('n1', { state: null })] },
      null,
      placementRows(),
    )
    await nextTick()
    expect(h.view.find('table').text()).not.toContain('★')
    expect(h.view.find('table').text()).toContain('Lazy')
    expect(
      h.view.find('[data-id="hilos-daemon-placement-cluster-types"]').text(),
    ).toBe('4')
    expect(h.view.text()).toContain('Declared in the project')
    expect(h.view.text()).toContain('Could not place these types')
    expect(h.view.text()).not.toContain('Leader could not')
    h.view.unmount()
  })

  it('lets an admin view mode viewer search, open and collapse cells', async () => {
    const h = liveHarness()
    h.scopes.session.data.set('adminViewMode', true)
    const release = bindAdminAccess(h.scopes)
    try {
      h.update(placementPicture(), null, placementRows())
      await nextTick()

      expect(hilosAdminAccess.get()).toBe('view')
      expect(h.view.find(cell).attributes('disabled')).toBeUndefined()
      await h.view.find(cell).trigger('click')
      expect(h.view.find(panel).text()).toContain('chat_room:2')
      await h.view
        .find('[data-id="hilos-daemon-placement-collapse"]')
        .trigger('click')
      expect(h.view.find(panel).exists()).toBe(false)
      await h.view.find('[data-id="hilos-table-search"]').setValue('room')
      expect(h.sendViewport).toHaveBeenCalledTimes(1)
      h.window(placementRows())
      expect(h.sendAction).not.toHaveBeenCalled()
    } finally {
      h.view.unmount()
      release()
    }
  })
})

describe('Daemon section head parity', () => {
  it.each([HilosPages.DAEMON, HilosPages.DAEMON_ENV_MISMATCH])(
    'shares the adopted head on %s',
    async (page) => {
      for (const clustered of [true, false]) {
        const h = harness(clustered, false, page)
        try {
          await nextTick()
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
