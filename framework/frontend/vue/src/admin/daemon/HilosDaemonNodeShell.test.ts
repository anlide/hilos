import { enableAutoUnmount, flushPromises } from '@vue/test-utils'
import {
  bindAdminAccess,
  bindTimeZoneState,
  HilosPages,
  hilosAdminAccess,
  hilosTimeZone,
} from '@hilos/core/base'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'

import {
  nodeHarness,
  nodeHeading,
  nodeOverview,
  nodeTabs,
} from '../../../test/helpers/hilosDaemonNode.js'

const id = (name: string) => `[data-id="hilos-daemon-node-${name}"]`
enableAutoUnmount(afterEach)
afterEach(() => vi.restoreAllMocks())

describe('HilosDaemonNodeShell', () => {
  it('uses the shared entity skeleton until the permitted answer arrives', async () => {
    const h = nodeHarness()
    expect(h.view.find('[data-id="hilos-page-head-skeleton"]').exists()).toBe(
      true,
    )
    expect(h.view.find('h1').exists()).toBe(false)
    expect(h.view.find('nav').exists()).toBe(false)
    expect(h.view.find(id('visual')).exists()).toBe(false)
    h.answer()
    await nextTick()
    expect(h.view.findAll('h1')).toHaveLength(1)
    expect(h.view.get('h1').text()).toBe('n1')
    expect(h.view.get('h1').attributes('data-id')).toBe('hilos-page-head-title')
    expect(h.view.find('[data-id="hilos-admin-children"]').exists()).toBe(false)
    expect(h.view.find('[data-id="hilos-daemon-node-line"]').exists()).toBe(
      false,
    )
    expect(h.view.get(id('visual')).get('i').classes()).toContain(
      'bi-hdd-network',
    )
    expect(h.view.get(id('visual')).attributes('aria-hidden')).toBe('true')
    const crumbs = h.view.get('nav[aria-label="Breadcrumb"]')
    expect(crumbs.findAll('li').map((crumb) => crumb.text())).toEqual([
      'Hilos',
      'Daemon',
      'n1',
    ])
    expect(crumbs.findAll('a').map((link) => link.attributes('href'))).toEqual([
      '/hilos',
      '/hilos/daemon',
    ])
    expect(crumbs.get('[aria-current="page"]').element.tagName).toBe('LI')
    expect(crumbs.findAll('a').some((link) => link.text() === 'n1')).toBe(false)
    expect(h.router.pageIdentity.get()?.pageHeadOwner?.label).toBe('n1')
  })

  it.each(nodeTabs.filter((tab) => tab.page !== HilosPages.DAEMON_WEBSOCKETS))(
    'marks $label by its page key in one link row',
    async ({ page }) => {
      const nodeId = 'node /?#%2F🌍'
      const h = nodeHarness({ shell: true, page, nodeId })
      h.answer(nodeOverview({ nodeId }))
      await nextTick()
      const nav = h.view.get('nav[aria-label="Node pages"]')
      expect(h.view.findAll('nav[aria-label="Node pages"]')).toHaveLength(1)
      expect(nav.findAll('a').map((link) => link.text())).toEqual([
        'Overview',
        'Workers',
        'Agents',
        'Cron',
        'HTTP',
        'Environment',
      ])
      expect(nav.findAll('[aria-current="page"]')).toHaveLength(1)
      const active = nodeTabs.find((tab) => tab.page === page)!
      expect(nav.get('[aria-current="page"]').text()).toBe(active.label)
      for (const tab of nodeTabs.filter(
        (tab) => tab.page !== HilosPages.DAEMON_WEBSOCKETS,
      )) {
        const link = nav.findAll('a').find((link) => link.text() === tab.label)!
        expect(link.element.tagName).toBe('A')
        expect(link.attributes('href')).toBe(
          h.router.resolvePath(tab.page, { nodeId }),
        )
        expect(link.attributes('role')).toBeUndefined()
      }
      expect(h.view.get('h1').text()).toBe(nodeId)
      expect(h.view.text()).not.toContain('hilos_daemon_')
      expect(h.send).not.toHaveBeenCalled()
      expect(h.sendAction).not.toHaveBeenCalled()
      expect(h.registerTableWindow).not.toHaveBeenCalled()
    },
  )

  it('filters unbuilt or unavailable links through the navigator', async () => {
    const h = nodeHarness({
      unavailable: [
        HilosPages.LOGS_VIEW,
        HilosPages.DAEMON_CRON,
        HilosPages.DAEMON_ENV,
        HilosPages.DAEMON_ENV_MISMATCH,
        HilosPages.DAEMON,
      ],
    })
    h.answer()
    await nextTick()
    expect(
      h.view
        .get('nav[aria-label="Node pages"]')
        .findAll('a')
        .map((link) => link.text()),
    ).toEqual(['Overview', 'Workers', 'Agents', 'HTTP'])
    expect(h.view.find(id('log')).exists()).toBe(false)
    expect(h.view.find('[data-id="hilos-page-head-action"]').exists()).toBe(
      false,
    )
    expect(h.view.find('[data-id="hilos-daemon-mismatch-link"]').exists()).toBe(
      false,
    )
    expect(
      h.view.find('[data-id="hilos-daemon-environment-link"]').exists(),
    ).toBe(false)
    expect(
      h.view.get('[data-id="hilos-daemon-environment-unbuilt"]').text(),
    ).toBe('Environment page is not available yet')
    expect(h.view.get('nav[aria-label="Breadcrumb"]').text()).toContain(
      'Daemon',
    )
  })

  it.each([
    ['leader', 'master', 'Leader', 'warning'],
    ['standby', 'master', 'Standby', 'secondary'],
    ['data', 'slave', 'Data', 'info'],
    ['silent', null, 'Silent', 'danger'],
  ] as const)(
    'separates %s from declared role %s',
    async (state, role, label, tone) => {
      const h = nodeHarness({ shell: true, page: HilosPages.DAEMON_AGENTS })
      h.answer(
        null,
        nodeHeading({
          state,
          role,
          silentSince: state === 'silent' ? 1_700_000_000_000 : null,
        }),
      )
      await nextTick()
      expect(h.view.get(id('state')).text()).toBe(label)
      expect(h.view.get(id('state')).classes()).toContain(`bg-${tone}-subtle`)
      expect(h.view.get(id('role')).text()).toBe(`Role: ${role ?? '—'}`)
      expect(h.view.get('[data-id="hilos-page-head-action"]').text()).toBe(
        'Change on the diagram',
      )
      expect(
        h.view.get('[data-id="hilos-page-head-action"]').attributes('href'),
      ).toBe('/hilos/daemon')
      if (state === 'silent') {
        expect(h.view.get(id('visual')).get('i').classes()).toContain('bi-plug')
        expect(h.view.get(id('silent')).text()).toMatch(/^Silent since /)
      }
    },
  )

  it('uses Environment’s own heading without deriving a cluster state from role', async () => {
    const h = nodeHarness({ shell: true, page: HilosPages.DAEMON_ENV })
    h.answer(null, nodeHeading({ state: null, role: 'master' }))
    await nextTick()
    expect(h.view.get('h1').text()).toBe('n1')
    expect(h.view.get(id('role')).text()).toBe('Role: master')
    expect(h.view.find(id('state')).exists()).toBe(false)
    expect(h.view.text()).not.toContain('Leader')
    h.publish({ node: nodeHeading({ state: 'silent', role: 'slave' }) })
    await nextTick()
    expect(h.view.get(id('state')).text()).toBe('Silent')
    expect(h.view.get(id('role')).text()).toBe('Role: slave')
    expect(h.view.find(id('silent')).exists()).toBe(false)
    h.publish({ node: nodeHeading({ state: null, role: null }) })
    await nextTick()
    expect(h.view.find(id('state')).exists()).toBe(false)
    expect(h.view.get(id('role')).text()).toBe('Role: —')
  })

  it('keeps standalone free of state, role and a diagram transition', async () => {
    const h = nodeHarness()
    h.answer(
      nodeOverview(),
      nodeHeading({
        clustered: false,
        state: 'silent',
        role: 'master',
        silentSince: 1_700_000_000_000,
      }),
    )
    await nextTick()
    expect(h.view.get(id('solo')).text()).toBe('Single installation')
    expect(h.view.find(id('state')).exists()).toBe(false)
    expect(h.view.find(id('role')).exists()).toBe(false)
    expect(h.view.find(id('silent')).exists()).toBe(false)
    expect(h.view.get(id('visual')).get('i').classes()).toContain(
      'bi-hdd-network',
    )
    expect(h.view.find('[data-id="hilos-page-head-action"]').exists()).toBe(
      false,
    )
    expect(h.view.find('[data-id="hilos-daemon-mismatch-link"]').exists()).toBe(
      false,
    )
  })

  it('updates actual node links and drops the old name on a scope switch, clear and unmount', async () => {
    const h = nodeHarness()
    h.answer()
    await nextTick()
    expect(h.view.get(id('log')).attributes('href')).toBe(
      '/hilos/logs/view/n1/live',
    )
    h.router.navigate(
      h.router.resolvePath(HilosPages.DAEMON_NODE, { nodeId: 'n2' })!,
    )
    await nextTick()
    expect(h.router.pageIdentity.get()).toBeUndefined()
    expect(h.view.find('h1').exists()).toBe(false)
    expect(h.view.find('[data-id="hilos-daemon-node-overview"]').exists()).toBe(
      false,
    )
    h.answer(nodeOverview({ nodeId: 'n2' }))
    await nextTick()
    expect(h.view.get('h1').text()).toBe('n2')
    expect(h.view.get(id('log')).attributes('href')).toBe(
      '/hilos/logs/view/n2/live',
    )
    expect(
      h.view
        .get('[data-id="hilos-daemon-environment-link"]')
        .attributes('href'),
    ).toBe('/hilos/daemon/n2/env')
    h.scopes.openPage(HilosPages.DAEMON_NODE)
    await nextTick()
    expect(h.view.find('h1').exists()).toBe(false)
    expect(h.view.find('[data-id="hilos-daemon-node-overview"]').exists()).toBe(
      false,
    )
    h.answer(nodeOverview({ nodeId: 'n2' }))
    await nextTick()
    h.view.unmount()
    expect(h.router.pageIdentity.get()?.pageHeadOwner?.label).toBe('Node')
    expect(h.router.pageIdentity.get()?.breadcrumb.at(-1)?.label).toBe('Node')
  })

  it('inherits the outlet’s refusal and recovery without keeping an old node name', async () => {
    const h = nodeHarness({ outlet: true })
    h.answer()
    await flushPromises()
    expect(h.view.get('h1').text()).toBe('n1')
    h.refuse()
    await flushPromises()
    expect(h.view.text()).toContain('This page is closed.')
    expect(h.view.find('[data-id="hilos-page-head"]').exists()).toBe(false)
    expect(h.view.find('[data-id="hilos-daemon-node-overview"]').exists()).toBe(
      false,
    )
    expect(h.router.pageIdentity.get()).toBeUndefined()
    h.answer(nodeOverview({ nodeId: 'n1', uptimeMinutes: 126 }))
    await flushPromises()
    expect(h.view.get('h1').text()).toBe('n1')
    expect(h.view.get('[data-id="hilos-daemon-uptime"]').text()).toBe(
      '2 h 6 min',
    )
    expect(h.send).not.toHaveBeenCalled()
  })

  it('keeps a child table’s window, expansion and mount through stable heading updates', async () => {
    const h = nodeHarness({
      shell: true,
      workers: true,
      page: HilosPages.DAEMON_WORKERS,
    })
    h.answer(null)
    const rows = [
      {
        rowKey: 'regular:0',
        slots: {
          worker: {
            index: 0,
            kind: 'regular',
            pid: 123,
            memoryBytes: 1024,
            agentCount: 1,
            agentIds: ['room:1'],
            logStream: 'worker-regular-0.log',
          },
        },
      },
    ]
    h.publish(
      {},
      {
        windows: {
          hilosDaemonWorkers: {
            rows,
            totalCount: 1,
            totalExact: true,
            firstAnchor: null,
            lastAnchor: null,
            limit: 25,
            sort: [],
            rowsBefore: 0,
          },
        },
      },
    )
    h.emit('tableWindow', {
      data: {
        page: HilosPages.DAEMON_WORKERS,
        tableKey: 'hilosDaemonWorkers',
        requestId: h.sendViewport.mock.calls.at(-1)?.[2].requestId,
        rows,
        totalCount: 1,
        totalExact: true,
        firstAnchor: null,
        lastAnchor: null,
        limit: 25,
        rowsBefore: 0,
      },
    })
    h.workers!.controller.expandRow('regular:0', true)
    await nextTick()
    const table = h.view.get('table').element
    const window = h.workers!.controller.rows.get()
    const descriptor = h.workers!.controller.descriptor()
    expect(
      h.view.find('[data-id="hilos-table-row-detail-regular:0"]').exists(),
    ).toBe(true)
    const requests = h.sendViewport.mock.calls.length
    h.publish({ node: nodeHeading({ state: 'leader' }) })
    await nextTick()
    expect(h.view.get('table').element).toBe(table)
    expect(h.workers!.controller.rows.get()).toBe(window)
    expect(h.workers!.controller.descriptor()).toEqual(descriptor)
    expect(
      h.view.find('[data-id="hilos-table-row-detail-regular:0"]').exists(),
    ).toBe(true)
    expect(h.sendViewport).toHaveBeenCalledTimes(requests)
    expect(h.registerTableWindow).toHaveBeenCalledTimes(1)
    expect(h.view.get('h1').text()).toBe('n1')
  })

  it('offers the same value-free diagnostics and links to a view-mode viewer', async () => {
    const h = nodeHarness()
    h.scopes.session.data.set('adminViewMode', true)
    const release = bindAdminAccess(h.scopes)
    try {
      h.answer()
      await nextTick()
      expect(hilosAdminAccess.get()).toBe('view')
      expect(h.view.get('h1').text()).toBe('n1')
      expect(h.view.findAll('nav[aria-label="Node pages"] a')).toHaveLength(6)
      expect(
        h.view.get('[data-id="hilos-daemon-environment-link"]').text(),
      ).toBe('On the environment page')
      expect(h.view.text()).not.toMatch(/APP_ENV\s+prod/)
      expect(h.view.find('input').exists()).toBe(false)
      expect(h.sendAction).not.toHaveBeenCalled()
    } finally {
      release()
    }
  })
})

describe('actual node child pages', () => {
  it.each([
    {
      page: HilosPages.DAEMON_WORKERS,
      table: 'hilosDaemonWorkers',
      slot: 'worker',
      row: {
        index: 0,
        kind: 'regular',
        pid: 123,
        memoryBytes: 1024,
        agentCount: 0,
        agentIds: [],
        logStream: 'worker.log',
      },
      notice: 'hilos-daemon-workers-silent',
    },
    {
      page: HilosPages.DAEMON_AGENTS,
      table: 'hilosDaemonAgents',
      slot: 'agent',
      row: {
        agentId: 'library',
        restarting: false,
        workerIndex: 0,
        workerKind: 'regular',
        placement: 'node',
        idle: false,
        ownsRt: [],
        ownsDb: [],
        logStream: 'agent.log',
      },
      notice: 'hilos-daemon-agents-silent',
    },
    {
      page: HilosPages.DAEMON_HTTP_SERVER,
      table: 'hilosDaemonHttpRoutes',
      slot: 'route',
      row: {
        method: 'GET',
        path: '/status',
        agentType: null,
        requests: 1,
        serverErrors: 0,
        slowestMs: 12,
      },
      notice: 'hilos-daemon-http-status',
    },
  ])(
    'updates $page silence in its saved-report notice and shared head without a new window',
    async ({ page, table, slot, row, notice }) => {
      const resolved = new Intl.DateTimeFormat().resolvedOptions()
      vi.spyOn(
        Intl.DateTimeFormat.prototype,
        'resolvedOptions',
      ).mockReturnValue({ ...resolved, timeZone: 'America/New_York' })
      const h = nodeHarness({ child: true, page })
      const release = bindTimeZoneState(h.connection)
      try {
        const at = Date.parse('2026-10-09T10:11:12Z')
        h.answer(null, nodeHeading({ state: 'silent', silentSince: at }))
        h.publish({
          http: {
            host: '0.0.0.0',
            port: 8080,
            countingHours: 24,
            requests: 1,
            serverErrors: 0,
            slow: 0,
          },
        })
        h.emit('tableWindow', {
          data: {
            page,
            tableKey: table,
            rows: [{ rowKey: 'one', slots: { [slot]: row } }],
            totalCount: 1,
          },
        })
        await nextTick()
        const controller = h.registerTableWindow.mock.calls[0][1]
        const window = controller.rows.get()
        const rowElement = h.view.get('[data-id="hilos-table-row-one"]').element
        const header = h.view.get('h1').element
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
          expect(h.view.get(id('silent')).text()).toBe(
            `Silent since ${date}, ${time}`,
          )
          expect(h.view.get(`[data-id="${notice}"]`).text()).toBe(
            `This node has been silent since ${date}, ${time}. The ${page === HilosPages.DAEMON_HTTP_SERVER ? 'values' : page === HilosPages.DAEMON_WORKERS ? 'workers' : 'agents'} below are from its last report.`,
          )
          expect(h.view.get('h1').element).toBe(header)
          expect(h.view.get('[data-id="hilos-table-row-one"]').element).toBe(
            rowElement,
          )
          expect(controller.rows.get()).toBe(window)
          expect(h.sendViewport).toHaveBeenCalledTimes(requests)
          expect(h.registerTableWindow).toHaveBeenCalledTimes(1)
          expect(h.send).not.toHaveBeenCalled()
        }
      } finally {
        h.view.unmount()
        release()
      }
    },
  )

  it.each(
    nodeTabs.filter(
      (tab) =>
        tab.page !== HilosPages.DAEMON_NODE &&
        tab.page !== HilosPages.DAEMON_WEBSOCKETS,
    ),
  )(
    'keeps $label tables mounted under one live shared head',
    async ({ page, label }) => {
      const h = nodeHarness({ child: true, page })
      h.answer()
      await nextTick()
      expect(h.view.findAll('h1')).toHaveLength(1)
      expect(h.view.get('h1').text()).toBe('n1')
      expect(
        h.view.get('nav[aria-label="Node pages"] [aria-current="page"]').text(),
      ).toBe(label)
      expect(h.view.find('[data-id="hilos-admin-children"]').exists()).toBe(
        false,
      )
      expect(h.view.find('[data-id="hilos-daemon-node-line"]').exists()).toBe(
        false,
      )
      const tables = h.view
        .findAll('table.table-hover')
        .map((table) => table.element)
      expect(tables).toHaveLength(page === HilosPages.DAEMON_ENV ? 2 : 1)
      const windows = h.registerTableWindow.mock.calls.length
      h.publish({
        node: nodeHeading({
          role: 'slave',
          state: page === HilosPages.DAEMON_ENV ? null : 'data',
        }),
      })
      await nextTick()
      expect(h.view.get(id('role')).text()).toBe('Role: slave')
      expect(
        h.view.findAll('table.table-hover').map((table) => table.element),
      ).toEqual(tables)
      expect(h.registerTableWindow).toHaveBeenCalledTimes(windows)
      if (page === HilosPages.DAEMON_ENV) {
        expect(
          tables.map((table) =>
            h.view.element
              .querySelector(`[id="${table.getAttribute('aria-labelledby')}"]`)
              ?.textContent?.trim(),
          ),
        ).toEqual(['Catalog keys', 'Orphans in .env'])
      } else {
        expect(tables[0]!.getAttribute('aria-labelledby')).toBe(
          h.view.get('h1').attributes('id'),
        )
      }
    },
  )
})
