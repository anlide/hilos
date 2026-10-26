import { mount } from '@vue/test-utils'
import {
  HilosPages,
  ScopeManager,
  bindPageScope,
  bindTimeZoneState,
  hilosTimeZone,
  bindAdminAccess,
  createAppPageRouter,
  createHilosRouter,
  hilosAdminAccess,
  hilosUnbuiltPages,
  type HilosConnection,
  type ProjectSignal,
  type TableRow,
} from '@hilos/core/base'
import {
  type HilosDaemonOverviewData,
  type HilosDaemonOverviewNode,
  type HilosDaemonDatabaseData,
} from '@hilos/core/admin/daemon'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'

import HilosDaemonPage from './HilosDaemonPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

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

function harness(
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
  const view = mount(HilosDaemonPage, {
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

afterEach(() => vi.restoreAllMocks())

describe('HilosDaemonPage', () => {
  it('opens Overview without any placement controller or traffic', () => {
    const h = harness(true, picture([node('n1')]))
    expect(h.register).not.toHaveBeenCalled()
    expect(h.sendViewport).not.toHaveBeenCalled()
    expect(h.sendRendered).not.toHaveBeenCalled()
    h.view.unmount()
  })

  it('updates a mounted silent node in the reader zone without requesting another picture', async () => {
    const resolved = new Intl.DateTimeFormat().resolvedOptions()
    vi.spyOn(Intl.DateTimeFormat.prototype, 'resolvedOptions').mockReturnValue({
      ...resolved,
      timeZone: 'America/New_York',
    })
    const at = Date.parse('2026-10-09T10:11:12Z')
    const h = harness(
      true,
      picture([
        node('n1', { online: false, state: 'silent', silentSince: at }),
      ]),
    )
    const release = bindTimeZoneState(h.connection)
    try {
      await nextTick()
      const line = h.view.get('[data-id="hilos-daemon-silent-n1"]')
      const page = h.scopes.page()
      const registrations = h.register.mock.calls.length
      const requests = h.sendViewport.mock.calls.length
      for (const [own, zone] of [
        ['UTC', 'UTC'],
        ['Europe/Kyiv', 'Europe/Kyiv'],
        [null, 'America/New_York'],
      ] as const) {
        h.emit('projectSignal', {
          type: 'handshake_response',
          data: { entities: { currentUser: { id: 3, timeZone: own } } },
        })
        await nextTick()
        const date = new Intl.DateTimeFormat(undefined, {
          timeZone: zone,
          dateStyle: 'medium',
        }).format(at)
        const time = new Intl.DateTimeFormat(undefined, {
          timeZone: zone,
          hour: '2-digit',
          minute: '2-digit',
        }).format(at)
        expect(hilosTimeZone.get()).toBe(zone)
        expect(line.text()).toBe(`Silent since ${date}, ${time}`)
        expect(h.view.get('[data-id="hilos-daemon-silent-n1"]').element).toBe(
          line.element,
        )
        expect(h.scopes.page()).toBe(page)
        expect(h.register).toHaveBeenCalledTimes(registrations)
        expect(h.sendViewport).toHaveBeenCalledTimes(requests)
        expect(h.send).not.toHaveBeenCalled()
      }
    } finally {
      h.view.unmount()
      release()
    }
  })

  it('reads the lean root, identity and database from one answer and distinguishes unknown, empty and zero', async () => {
    const h = harness()
    expect(
      h.view.find('[data-id="hilos-daemon-overview-waiting"]').exists(),
    ).toBe(true)
    h.update(picture([]))
    await nextTick()
    expect(h.view.get('[data-id="hilos-daemon-overview-empty"]').text()).toBe(
      'No nodes in the picture',
    )
    h.update(
      picture([
        node('n1', { workers: null, measurements: null, standing: null }),
      ]),
    )
    await nextTick()
    expect(h.view.get('[data-id="hilos-daemon-workers-n1"]').text()).toBe(
      'Workers: —',
    )
    expect(h.view.get('[data-id="hilos-daemon-node-n1"]').text()).toContain(
      'Daemon CPU— / — cores',
    )
    expect(h.view.get('[data-id="hilos-daemon-browsers-n1"]').text()).toBe(
      'Browser connections: —',
    )
    const zero = node('n1')
    h.update(
      picture([
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
      ]),
    )
    await nextTick()
    expect(h.view.get('[data-id="hilos-daemon-workers-n1"]').text()).toBe(
      'No workers',
    )
    expect(h.view.get('[data-id="hilos-daemon-browsers-n1"]').text()).toBe(
      'Browser connections: 0',
    )
    expect(h.view.get('[data-id="hilos-daemon-node-n1"]').text()).toContain(
      '0 B / 0 B',
    )
    expect(h.view.findAll('h1')).toHaveLength(1)
    expect(h.view.get('h1').text()).toBe('Daemon from catalog')
    expect(h.view.find('[data-id="hilos-daemon-inspector"]').exists()).toBe(
      false,
    )
    expect(h.send).not.toHaveBeenCalled()
    expect(h.sendAction).not.toHaveBeenCalled()
    expect(h.register).not.toHaveBeenCalled()
    h.view.unmount()
  })

  it('uses each node’s real encoded Overview link and normal router navigation', async () => {
    const h = harness()
    const nodeId = 'node /?#%2F🌍'
    h.update(picture([node('n1', { state: 'leader' }), node(nodeId)]))
    await nextTick()
    const boxes = h.view.findAll('[data-id="hilos-daemon-nodes"] a')
    expect(boxes).toHaveLength(2)
    for (const [index, id] of ['n1', nodeId].entries()) {
      expect(boxes[index]!.element.tagName).toBe('A')
      expect(boxes[index]!.attributes('href')).toBe(
        h.router.resolvePath(HilosPages.DAEMON_NODE, { nodeId: id }),
      )
      expect(boxes[index]!.attributes('aria-label')).toBe(`Node ${id}`)
      expect(boxes[index]!.attributes('aria-pressed')).toBeUndefined()
    }
    await boxes[1]!.trigger('keydown', { key: ' ' })
    expect(h.navigate).not.toHaveBeenCalled()
    await boxes[1]!.trigger('click')
    expect(h.navigate).toHaveBeenCalledWith(
      h.router.resolvePath(HilosPages.DAEMON_NODE, { nodeId }),
    )
    expect(h.router.currentPath.get()).toBe(
      h.router.resolvePath(HilosPages.DAEMON_NODE, { nodeId }),
    )
    expect(h.send).toHaveBeenCalledTimes(1)
    expect(h.sendAction).not.toHaveBeenCalled()
    h.view.unmount()
  })

  it('shows silence and its time instead of stale metrics, then recovers the live diagram', async () => {
    const h = harness()
    const silent = node('n1', {
      online: false,
      state: 'silent',
      silentSince: 1_700_000_000_000,
    })
    h.update(picture([silent]))
    await nextTick()
    const box = () => h.view.get('[data-id="hilos-daemon-node-n1"]')
    expect(box().attributes('href')).toBe('/hilos/daemon/n1')
    expect(box().text()).toContain('Silent since')
    expect(
      h.view.get('[data-id="hilos-daemon-silent-n1"]').text(),
    ).not.toContain('—')
    expect(box().text()).not.toContain('Daemon CPU')
    expect(box().text()).not.toContain('Measured memory')
    expect(h.view.find('[data-id="hilos-daemon-workers-n1"]').exists()).toBe(
      false,
    )
    h.update(picture([node('n1')]))
    await nextTick()
    expect(box().text()).toContain('Daemon CPU3 / 2 cores')
    expect(box().text()).toContain('150%')
    expect(box().text()).toContain('4.0 KB / 2.0 KB')
    expect(box().text()).toContain('2 worker processes measured')
    expect(
      h.view
        .get('[data-id="hilos-daemon-worker-n1-monopolistic-9"]')
        .attributes('data-occupancy'),
    ).toBe('free')
    h.update(picture([node('n1', { role: 'slave', state: 'data' })]))
    await nextTick()
    expect(h.view.get('[data-id="hilos-daemon-browsers-n1"]').text()).toBe(
      'Does not accept browsers',
    )
    h.update(
      picture([
        node('n1', {
          online: false,
          state: 'silent',
          silentSince: null,
          workers: null,
          measurements: null,
          standing: null,
        }),
      ]),
    )
    await nextTick()
    expect(box().text()).toContain('Silent since —')
    expect(box().text()).not.toContain('Daemon CPU')
    expect(h.view.find('[data-id="hilos-daemon-inspector"]').exists()).toBe(
      false,
    )
    h.view.unmount()
  })

  it('keeps standalone and viewer boxes as links without inspector or log actions', async () => {
    const h = harness()
    const release = bindAdminAccess(h.scopes)
    try {
      h.scopes.session.data.set('adminViewMode', true)
      h.update(picture([node('standalone', { state: null })], false))
      await nextTick()
      expect(hilosAdminAccess.get()).toBe('view')
      const box = h.view.get('[data-id="hilos-daemon-node-standalone"]')
      expect(box.attributes('href')).toBe('/hilos/daemon/standalone')
      expect(box.text()).toContain('Single installation')
      expect(h.view.find('[data-id="hilos-daemon-consensus"]').exists()).toBe(
        false,
      )
      expect(h.view.find('[data-id="hilos-daemon-inspector"]').exists()).toBe(
        false,
      )
      expect(h.view.find('[data-id="hilos-daemon-node-log"]').exists()).toBe(
        false,
      )
      expect(h.view.text()).not.toContain('APP_ENV')
      expect(h.sendAction).not.toHaveBeenCalled()
    } finally {
      release()
      h.view.unmount()
    }
  })

  it('shows safe primary database fields independently of node updates', async () => {
    const h = harness()
    h.update(picture([node('n1'), node('n2')]), {
      host: '::1',
      port: 3306,
      name: 'hilos',
      socket: null,
      migrationIndex: null,
    })
    await nextTick()
    expect(
      h.view.find('[data-id="hilos-daemon-database-address"]').text(),
    ).toBe('mysql://[::1]:3306/hilos')
    expect(
      h.view.find('[data-id="hilos-daemon-database-migration"]').text(),
    ).toBe('Migration: —')
    h.page.data.set('daemon', picture([node('n2')]))
    await nextTick()
    expect(
      h.view.find('[data-id="hilos-daemon-database-address"]').text(),
    ).toBe('mysql://[::1]:3306/hilos')
    h.page.data.set('database', {
      host: 'db',
      port: 3306,
      name: 'hilos',
      socket: '/run/mysql.sock',
      migrationIndex: 0,
    })
    await nextTick()
    expect(
      h.view.find('[data-id="hilos-daemon-database-address"]').text(),
    ).toBe('hilos')
    expect(h.view.find('[data-id="hilos-daemon-database-socket"]').text()).toBe(
      'Unix socket: /run/mysql.sock',
    )
    expect(
      h.view.find('[data-id="hilos-daemon-database-migration"]').text(),
    ).toBe('Migration: 0')
    h.view.unmount()
  })
  it('uses only the selected source ports and resolves its metrics page', async () => {
    const h = harness()
    const base = picture([node('n1'), node('n2')])
    for (const source of [
      { nodeId: 'n2', commandPort: 9014, httpPort: 8082 },
      { nodeId: 'n1', commandPort: 0, httpPort: null },
      null,
    ]) {
      h.update({ ...base, entryPoints: source })
      await nextTick()
      expect(
        h.view.get('[data-id="hilos-daemon-entry-command-port"]').text(),
      ).toBe(source?.commandPort == null ? '—' : `:${source.commandPort}`)
      expect(
        h.view.get('[data-id="hilos-daemon-entry-http-port"]').text(),
      ).toBe(source?.httpPort == null ? '—' : `:${source.httpPort}`)
      const link = h.view.find('[data-id="hilos-daemon-entry-metrics"]')
      expect(link.exists()).toBe(source !== null)
      if (source)
        expect(link.attributes('href')).toBe(
          `/hilos/daemon/${source.nodeId}/http`,
        )
      expect(h.register).not.toHaveBeenCalled()
    }
    const resolvePath = h.router.resolvePath.bind(h.router)
    vi.spyOn(h.router, 'resolvePath').mockImplementation((page, params) =>
      page === HilosPages.DAEMON_HTTP_SERVER
        ? undefined
        : resolvePath(page, params),
    )
    h.update({
      ...base,
      entryPoints: { nodeId: 'n2', commandPort: null, httpPort: 0 },
    })
    await nextTick()
    expect(h.view.get('[data-id="hilos-daemon-entry-http-port"]').text()).toBe(
      ':0',
    )
    expect(h.view.find('[data-id="hilos-daemon-entry-metrics"]').exists()).toBe(
      false,
    )
    h.view.unmount()
  })
})
