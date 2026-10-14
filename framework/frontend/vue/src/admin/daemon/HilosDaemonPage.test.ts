import { mount } from '@vue/test-utils'
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
import { describe, expect, it, vi } from 'vitest'
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
  const matcher = createAppPageRouter({}, { fallback: HilosPages.DASHBOARD })
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

describe('HilosDaemonPage', () => {
  it('distinguishes a silent node without a report and keeps reported uptime frozen', async () => {
    const h = harness(false)
    const clock = vi.spyOn(Date, 'now').mockReturnValue(1_700_000_060_000)
    try {
      const silent = node('n1', {
        online: false,
        state: 'silent',
        silentSince: 1_700_000_000,
      })
      h.update(picture([silent]))
      await nextTick()
      expect(h.view.find('[data-id="hilos-daemon-uptime"]').text()).toBe(
        '2 h 5 min',
      )
      clock.mockReturnValue(1_700_003_660_000)
      h.update(picture([{ ...silent, workerRestarts24h: 1 }]))
      await nextTick()
      expect(h.view.find('[data-id="hilos-daemon-uptime"]').text()).toBe(
        '2 h 5 min',
      )
      expect(
        h.view.find('[data-id="hilos-daemon-report-notice"]').text(),
      ).toContain('values from the last report')
      h.update(
        picture([
          node('n1', {
            role: null,
            online: false,
            state: 'silent',
            silentSince: null,
            workers: null,
            workerRestarts24h: null,
            uptimeMinutes: null,
            measurements: null,
            standing: null,
            environment: null,
          }),
        ]),
      )
      await nextTick()
      expect(
        h.view.find('[data-id="hilos-daemon-report-notice"]').text(),
      ).toContain('Silent · this node has not reported yet')
      expect(
        h.view.find('[data-id="hilos-daemon-report-notice"]').text(),
      ).not.toContain('last report')
      expect(h.view.find('[data-id="hilos-daemon-uptime"]').text()).toBe('—')
      expect(h.view.find('[data-id="hilos-daemon-cores"]').text()).toBe('—')
      expect(h.send).not.toHaveBeenCalled()
    } finally {
      clock.mockRestore()
      h.view.unmount()
    }
  })

  it('selects nodes with Enter and Space without navigating or sending another request', async () => {
    const h = harness(false)
    h.update(picture([node('n1', { state: 'leader' }), node('n2')]))
    await nextTick()
    await h.view
      .find('[data-id="hilos-daemon-node-n2"]')
      .trigger('keydown', { key: 'Enter' })
    expect(
      h.view
        .find('[data-id="hilos-daemon-inspector"]')
        .attributes('data-node-id'),
    ).toBe('n2')
    await h.view
      .find('[data-id="hilos-daemon-node-n1"]')
      .trigger('keydown', { key: ' ' })
    expect(
      h.view
        .find('[data-id="hilos-daemon-inspector"]')
        .attributes('data-node-id'),
    ).toBe('n1')
    expect(h.send).not.toHaveBeenCalled()
    expect(h.sendAction).not.toHaveBeenCalled()
    expect(h.navigate).not.toHaveBeenCalled()
    expect(h.replacePath).not.toHaveBeenCalled()
    h.view.unmount()
  })

  it('draws identity, overview and database from one whole response', async () => {
    const h = harness(false)
    h.update(picture([node('standalone', { state: null })], false), {
      host: 'db',
      port: 3306,
      name: 'hilos',
      socket: null,
      migrationIndex: 0,
    })
    await nextTick()
    expect(h.view.find('h1').text()).toBe('Daemon from catalog')
    expect(
      h.view
        .find('[data-id="hilos-daemon-inspector"]')
        .attributes('data-node-id'),
    ).toBe('standalone')
    expect(
      h.view.find('[data-id="hilos-daemon-database-migration"]').text(),
    ).toBe('Migration: 0')
    expect(h.router.pageLoading.get()).toBe(false)
    expect(h.send).not.toHaveBeenCalled()
    expect(h.register).toHaveBeenCalledTimes(1)
    expect(h.sendAction).not.toHaveBeenCalled()
    h.view.unmount()
  })

  it('keeps waiting, a known empty roster and an unreported node distinct', async () => {
    const h = harness()
    expect(
      h.view.find('[data-id="hilos-daemon-overview-waiting"]').exists(),
    ).toBe(true)
    expect(h.view.find('[data-id="hilos-daemon-inspector"]').exists()).toBe(
      false,
    )
    h.update(picture([]))
    await nextTick()
    expect(h.view.find('[data-id="hilos-daemon-overview-empty"]').text()).toBe(
      'No nodes in the picture',
    )
    expect(
      h.view.find('[data-id="hilos-daemon-overview-waiting"]').exists(),
    ).toBe(false)
    h.update(
      picture([
        node('n1', {
          state: null,
          role: null,
          workers: null,
          measurements: null,
          standing: null,
          environment: null,
          workerRestarts24h: null,
          uptimeMinutes: null,
        }),
      ]),
    )
    await nextTick()
    expect(h.view.find('[data-id="hilos-daemon-workers-n1"]').text()).toBe(
      'Workers: —',
    )
    expect(
      h.view.find('[data-id="hilos-daemon-monopolistic-count"]').text(),
    ).toBe('—')
    expect(
      h.view.find('[data-id="hilos-daemon-report-notice"]').text(),
    ).toContain('not reported yet')
    h.update(picture([node('n1', { workers: [] })]))
    await nextTick()
    expect(h.view.find('[data-id="hilos-daemon-workers-n1"]').text()).toBe(
      'No workers',
    )
    expect(
      h.view.find('[data-id="hilos-daemon-monopolistic-count"]').text(),
    ).toBe('0')
    h.view.unmount()
  })

  it('selects real node buttons locally, retains silence and updates every node address', async () => {
    const h = harness()
    const id = 'node /?#%2F🌍'
    h.update(picture([node('n1', { state: 'leader' }), node(id)]))
    await nextTick()
    expect(h.view.findAll('h1')).toHaveLength(1)
    expect(h.view.find('h1').text()).toBe('Daemon from catalog')
    expect(
      h.view
        .find('[data-id="hilos-daemon-inspector"]')
        .attributes('data-node-id'),
    ).toBe('n1')
    const box = h.view.find(`[data-id="hilos-daemon-node-${id}"]`)
    expect(box.element.tagName).toBe('BUTTON')
    expect(box.attributes('type')).toBe('button')
    expect(box.findAll('button, a')).toHaveLength(0)
    await box.trigger('click')
    expect(box.attributes('aria-pressed')).toBe('true')
    expect(
      h.view
        .find('[data-id="hilos-daemon-inspector"]')
        .attributes('data-node-id'),
    ).toBe(id)
    expect(
      h.view
        .find('[data-id="hilos-admin-child-hilos_daemon_workers"]')
        .attributes('href'),
    ).toBe('/hilos/daemon/node%20%2F%3F%23%252F%F0%9F%8C%8D/workers')
    expect(
      h.view
        .find('[data-id="hilos-daemon-environment-link"]')
        .attributes('href'),
    ).toBe('/hilos/daemon/node%20%2F%3F%23%252F%F0%9F%8C%8D/env')
    expect(
      h.view.find('[data-id="hilos-daemon-node-log"]').attributes('href'),
    ).toBe('/hilos/logs/view/node%20%2F%3F%23%252F%F0%9F%8C%8D/live')
    for (const selector of [
      'hilos-admin-child-hilos_daemon_workers',
      'hilos-admin-child-hilos_daemon_agents',
      'hilos-admin-child-hilos_daemon_cron',
      'hilos-admin-child-hilos_daemon_http_server',
      'hilos-daemon-environment-link',
      'hilos-daemon-node-log',
    ]) {
      const href = h.view.find(`[data-id="${selector}"]`).attributes('href')!
      expect(h.matcher.match(href).params.nodeId).toBe(id)
    }
    expect(
      h.view
        .find('[data-id="hilos-admin-child-hilos_daemon_websockets"]')
        .exists(),
    ).toBe(false)
    h.update(
      picture([
        node('n1', { state: 'leader' }),
        node(id, {
          state: 'silent',
          online: false,
          silentSince: 1_700_000_000,
        }),
      ]),
    )
    await nextTick()
    expect(
      h.view
        .find('[data-id="hilos-daemon-inspector"]')
        .attributes('data-node-id'),
    ).toBe(id)
    expect(
      h.view.find('[data-id="hilos-daemon-report-notice"]').text(),
    ).toContain('values from the last report')
    expect(h.view.find('[data-id="hilos-daemon-uptime"]').text()).toBe(
      '2 h 5 min',
    )
    expect(
      h.view
        .find(`[data-id="hilos-daemon-link-${id}"]`)
        .attributes('data-silent'),
    ).toBe('true')
    expect(
      h.view.find('[data-id="hilos-daemon-environment-differences"]').text(),
    ).toBe('—')
    h.update(picture([node('n1', { state: 'leader' })]))
    await nextTick()
    expect(
      h.view
        .find('[data-id="hilos-daemon-inspector"]')
        .attributes('data-node-id'),
    ).toBe('n1')
    expect(h.sendAction).not.toHaveBeenCalled()
    expect(h.send).not.toHaveBeenCalled()
    expect(h.register).toHaveBeenCalledTimes(1)
    expect(h.navigate).not.toHaveBeenCalled()
    expect(h.replacePath).not.toHaveBeenCalled()
    for (const link of h.view.findAll('[data-id^="hilos-admin-child-"]')) {
      expect(h.matcher.match(link.attributes('href')!).params.nodeId).toBe('n1')
    }
    h.view.unmount()
  })

  it('uses a consensus report, actual workers and honest memory coverage', async () => {
    const h = harness()
    h.update(picture([node('n1', { state: 'leader' }), node('n2'), node('n3')]))
    await nextTick()
    expect(h.view.find('[data-id="hilos-daemon-quorum"]').text()).toContain(
      '1 of 5',
    )
    expect(h.view.find('[data-id="hilos-daemon-quorum"]').text()).toContain(
      'Unavailable',
    )
    expect(h.view.find('[data-id="hilos-daemon-term"]').text()).toBe('Term: 7')
    expect(
      h.view
        .find('[data-id="hilos-daemon-workers-n1"]')
        .findAll('[data-occupancy]'),
    ).toHaveLength(3)
    expect(
      h.view
        .find('[data-id="hilos-daemon-worker-n1-monopolistic-9"]')
        .attributes('data-occupancy'),
    ).toBe('free')
    expect(
      h.view
        .find('[data-id="hilos-daemon-worker-n1-monopolistic-4"]')
        .attributes('data-occupancy'),
    ).toBe('occupied')
    expect(
      h.view.find('[data-id="hilos-daemon-monopolistic-count"]').text(),
    ).toBe('2')
    expect(h.view.text()).toContain('Pool grows on demand')
    expect(h.view.text()).not.toContain('places left')
    expect(
      h.view.find('[data-id="hilos-daemon-workers-measured"]').text(),
    ).toBe('2 of 3 worker processes measured')
    expect(h.view.find('[data-id="hilos-daemon-total-memory"]').text()).toBe(
      '4.0 KB',
    )
    expect(h.view.find('[data-id="hilos-daemon-cpu-percent"]').text()).toBe(
      '150% of available CPU',
    )
    expect(h.view.find('[data-id="hilos-daemon-memory-percent"]').text()).toBe(
      '200% of ceiling',
    )
    const progress = h.view.find(
      '[data-id="hilos-daemon-inspector"] .hilos-progress',
    )
    expect(progress.attributes('style')).toContain('--hilos-progress: 100')
    expect(
      h.view.find('[data-id="hilos-daemon-environment-attention"]').text(),
    ).toBe('10')
    expect(
      h.view.find('[data-id="hilos-daemon-environment-differences"]').text(),
    ).toBe('3')
    const actions = h.view
      .find('[data-id="hilos-daemon-maintenance"]')
      .findAll('button')
    expect(actions).toHaveLength(3)
    expect(
      actions.every((button) => button.attributes('disabled') !== undefined),
    ).toBe(true)
    expect(
      h.view
        .find('[data-id="hilos-daemon-placement-tab"]')
        .attributes('disabled'),
    ).toBeUndefined()
    h.view.unmount()
  })

  it('shows the standalone identity and omits unavailable environment links', async () => {
    const h = harness(false)
    h.update(picture([node('installation-id', { state: null })], false))
    await nextTick()
    expect(
      h.view
        .find('[data-id="hilos-daemon-inspector"]')
        .attributes('data-node-id'),
    ).toBe('installation-id')
    expect(h.view.text()).toContain('Single installation')
    expect(h.view.find('[data-id="hilos-daemon-consensus"]').exists()).toBe(
      false,
    )
    expect(
      h.view.find('[data-id="hilos-daemon-inspector-state"]').exists(),
    ).toBe(false)
    expect(h.view.findAll('[data-silent]')).toHaveLength(0)
    expect(h.view.text()).not.toContain('Role:')
    expect(
      h.view.find('[data-id="hilos-daemon-environment-link"]').exists(),
    ).toBe(false)
    expect(
      h.view.find('[data-id="hilos-daemon-environment-unbuilt"]').text(),
    ).toContain('not available yet')
    expect(h.view.find('[data-id="hilos-daemon-mismatch-link"]').exists()).toBe(
      false,
    )
    expect(
      h.view.find('[data-id="hilos-daemon-environment-attention"]').text(),
    ).toBe('10')
    h.view.unmount()
  })

  it('keeps technical diagnostics and local selection available in admin view mode', async () => {
    const h = harness()
    h.scopes.session.data.set('adminViewMode', true)
    const release = bindAdminAccess(h.scopes)
    try {
      expect(hilosAdminAccess.get()).toBe('view')
      h.update(picture([node('n1'), node('n2')]))
      await nextTick()
      await h.view.find('[data-id="hilos-daemon-node-n2"]').trigger('click')
      expect(
        h.view
          .find('[data-id="hilos-daemon-inspector"]')
          .attributes('data-node-id'),
      ).toBe('n2')
      expect(
        h.view.find('[data-id="hilos-daemon-environment-attention"]').text(),
      ).toBe('10')
      expect(h.view.find('[data-id="hilos-daemon-total-memory"]').text()).toBe(
        '4.0 KB',
      )
      expect(
        h.view.find('[data-id="hilos-daemon-environment-link"]').exists(),
      ).toBe(true)
      expect(
        h.view
          .find('[data-id="hilos-daemon-maintenance"]')
          .findAll('button')
          .every((button) => button.attributes('disabled') !== undefined),
      ).toBe(true)
      expect(h.sendAction).not.toHaveBeenCalled()
    } finally {
      h.view.unmount()
      release()
    }
  })

  it('keeps unknown and zero measurements apart and labels a data node without browsers', async () => {
    const h = harness()
    const n = node('n1')
    h.update(
      picture([
        {
          ...n,
          role: 'slave',
          state: 'data',
          measurements: {
            ...n.measurements!,
            daemonCores: 0,
            masterMemory: null,
            cores: 0,
          },
        },
      ]),
    )
    await nextTick()
    expect(h.view.find('[data-id="hilos-daemon-daemon-cores"]').text()).toBe(
      '0 cores',
    )
    expect(h.view.find('[data-id="hilos-daemon-cpu-percent"]').text()).toBe(
      '— of available CPU',
    )
    expect(h.view.find('[data-id="hilos-daemon-total-memory"]').text()).toBe(
      '—',
    )
    expect(h.view.find('[data-id="hilos-daemon-browsers-n1"]').text()).toBe(
      'Does not accept browsers',
    )
    expect(
      h.view.find('[data-id="hilos-daemon-inspector-connections"]').text(),
    ).toBe('Does not accept browsers')
    h.update({ ...picture([n]), consensus: null })
    await nextTick()
    expect(h.view.find('[data-id="hilos-daemon-quorum"]').text()).toBe(
      'Quorum: —',
    )
    expect(h.view.find('[data-id="hilos-daemon-term"]').text()).toBe('Term: —')
    h.view.unmount()
  })

  it('shows safe primary database fields separately from the selected node', async () => {
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
    await h.view.find('[data-id="hilos-daemon-node-n2"]').trigger('click')
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
})

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

const placementTab = '[data-id="hilos-daemon-placement-tab"]'
const nodesTab = '[data-id="hilos-daemon-nodes-tab"]'
const cell = '[data-id="hilos-daemon-placement-cell-chat_room-n1"]'
const panel = '[data-id="hilos-daemon-placement-selection"]'
const alerts = '[data-id="hilos-daemon-placement-alerts"]'

describe('HilosDaemonPage placement', () => {
  it('replays the first window on the root and mounts a single matrix lazily', async () => {
    const h = harness(false, placementPicture(), placementRows())
    expect(h.controller().descriptor()).not.toBeNull()
    expect(
      h.view.find('[data-id="hilos-daemon-placement-view"]').exists(),
    ).toBe(false)
    expect(h.register).toHaveBeenCalledTimes(1)
    expect(h.sendRendered).toHaveBeenCalledTimes(1)
    await h.view.find('[data-id="hilos-daemon-node-n2"]').trigger('click')
    await h.view.find(placementTab).trigger('click')
    expect(h.view.find(placementTab).attributes('aria-pressed')).toBe('true')
    expect(h.view.find(nodesTab).attributes('aria-pressed')).toBe('false')
    expect(h.view.find('[data-id="hilos-daemon-nodes-view"]').isVisible()).toBe(
      false,
    )
    expect(h.view.findAll('h1')).toHaveLength(1)
    expect(h.view.find('h1').text()).toBe('Daemon from catalog')
    expect(h.view.find('table').text()).toContain('n1 ★')
    expect(h.view.find('table').text()).toContain('n2')
    expect(h.view.find('table').text()).toContain('Policy-placed · lazy')
    expect(h.view.find('table .badge').text()).toBe('Unplaced')
    expect(h.view.find('table tr.table-danger').exists()).toBe(false)
    const cards = h.view.find('[data-id="hilos-table-cards"]')
    expect(cards.text()).toContain('Policy-placed · lazy')
    expect(cards.text()).toContain('Unplaced')
    expect(cards.find(cell).attributes('aria-label')).toBe(
      'chat_room on n1: 2 instances',
    )
    expect(h.view.find(nodesTab).element.tagName).toBe('BUTTON')
    expect(h.view.find(placementTab).element.tagName).toBe('BUTTON')
    expect(h.view.find(placementTab).attributes('type')).toBe('button')
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
    await h.view.find(nodesTab).trigger('click')
    expect(h.view.find(panel).isVisible()).toBe(false)
    expect(
      h.view
        .find('[data-id="hilos-daemon-inspector"]')
        .attributes('data-node-id'),
    ).toBe('n2')
    expect(h.view.find('[data-id="hilos-daemon-database"]').isVisible()).toBe(
      true,
    )
    await h.view.find(placementTab).trigger('click')
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
    expect(h.unregister).toHaveBeenCalledWith('hilosDaemonPlacement')
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
    const h = harness(false, placementPicture(), rows)
    await h.view.find(placementTab).trigger('click')
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
    const h = harness()
    h.update(placementPicture(), null, placementRows())
    await nextTick()
    await h.view.find(placementTab).trigger('click')
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
    const h = harness()
    const full = placementPicture()
    h.update(full, null, placementRows())
    await nextTick()
    await h.view.find(placementTab).trigger('click')
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
      nodes: [node('n1', { online: false, state: 'silent' }), node('n2')],
    })
    await nextTick()
    expect(h.view.find(panel).exists()).toBe(false)
    expect(h.view.find(cell).exists()).toBe(false)
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
    expect(h.view.find(cell).exists()).toBe(false)
    expect(h.view.find('table').text()).toContain('·')
    h.window(placementRows(null))
    await nextTick()
    expect(h.view.find('table').text()).toContain('?')
    h.view.unmount()
  })

  it('keeps a searched panel closed after loading and forgets freeze and refusal', async () => {
    vi.useFakeTimers()
    const h = harness()
    try {
      h.update(placementPicture(), null, placementRows())
      await nextTick()
      await h.view.find(placementTab).trigger('click')
      await h.view.find(cell).trigger('click')
      await h.view.find('[data-id="hilos-table-search"]').setValue('99')
      vi.advanceTimersByTime(1000)
      await nextTick()
      expect(h.view.find(panel).exists()).toBe(false)
      h.window(placementRows(['chat_room:99']))
      await nextTick()
      expect(h.view.find(panel).exists()).toBe(false)
      expect(h.view.find(cell).attributes('aria-expanded')).toBe('false')
      await h.view.find(cell).trigger('click')
      expect(h.view.find(panel).text()).toContain('chat_room:99')
      h.emit('tableViewportFrozen', {
        data: {
          page: HilosPages.DAEMON,
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
          page: HilosPages.DAEMON,
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
    const h = harness()
    await h.view.find(placementTab).trigger('click')
    expect(h.view.text()).toContain('Waiting for the node picture')
    expect(
      h.view.find('[data-id="hilos-daemon-placement-unplaced"]').exists(),
    ).toBe(false)
    h.update(picture([]), null, [])
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
        ...picture([node('n1')], false),
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
      { ...placementPicture(false), nodes: [node('n1', { state: null })] },
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
    const h = harness()
    h.scopes.session.data.set('adminViewMode', true)
    const release = bindAdminAccess(h.scopes)
    try {
      h.update(placementPicture(), null, placementRows())
      await nextTick()
      await h.view.find(placementTab).trigger('click')
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
