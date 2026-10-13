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

function harness(envBuilt = true) {
  const scopes = new ScopeManager()
  const sendAction = vi.fn()
  const send = vi.fn(() => true)
  const listeners: ((signal: ProjectSignal) => void)[] = []
  const on = vi.fn((event: string, listener: (payload: never) => void) => {
    if (event === 'projectSignal')
      listeners.push(listener as (signal: ProjectSignal) => void)
    return () => {}
  })
  const connection = {
    state: 'connected',
    send,
    sendAction,
    on,
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
  ) => {
    const signal = {
      type: 'page_response',
      data: {
        page: HilosPages.DAEMON,
        payload: {
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
    for (const listener of listeners) listener(signal)
  }
  const view = mount(HilosDaemonPage, {
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
    expect(h.on).not.toHaveBeenCalled()
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
    expect(h.on).not.toHaveBeenCalled()
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
    ).toBeDefined()
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
