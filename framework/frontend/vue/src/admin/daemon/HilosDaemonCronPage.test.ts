import { openNodePage } from '../../../test/helpers/hilosDaemonNode.js'
import { mount } from '@vue/test-utils'
import {
  DAEMON_CRON_IDLE_NOT_LEADER,
  DAEMON_CRON_REPORTED_DATA,
  DAEMON_NODE_DATA,
  type HilosDaemonNodeHeading,
} from '@hilos/core/admin/daemon'
import {
  HilosPages,
  ScopeManager,
  pageIdentity,
  createSignal,
  type HilosConnection,
  type HilosRouter,
  type PageRouteMatch,
} from '@hilos/core/base'
import { describe, expect, it } from 'vitest'
import { markRaw, nextTick } from 'vue'

import HilosDaemonCronPage from './HilosDaemonCronPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

const NOTICE_ROOM =
  '.position-relative > .alert-warning.invisible[aria-hidden="true"]'

function router(diagramBuilt = false, scopes: ScopeManager): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.DAEMON_CRON,
      params: { nodeId: 'n1' },
      admin: true,
    }),
    currentPath: createSignal('/hilos/daemon/n1/cron'),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: pageIdentity(scopes),
    dashboardSections: createSignal(undefined),
    resolvePath: (page) =>
      diagramBuilt && page === HilosPages.DAEMON ? '/hilos/daemon' : undefined,
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    onLeave: () => () => {},
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
}

function harness(diagramBuilt = false) {
  const scopes = markRaw(new ScopeManager())
  const scope = openNodePage(scopes, HilosPages.DAEMON_CRON)
  const listeners: Array<(signal: { data: unknown }) => void> = []
  const connection = {
    on(event: string, listener: (signal: never) => void): () => void {
      if (event === 'tableWindow') {
        listeners.push(listener as (signal: { data: unknown }) => void)
      }
      return () => {}
    },
    registerTableWindow(): void {},
    unregisterTableWindow(): void {},
    tableWindowDescriptors: () => ({}),
    sendTableViewport(): void {},
    sendTableRendered(): void {},
  } as unknown as HilosConnection
  const view = mount(HilosDaemonCronPage, {
    props: { context: { connection, scopes } },
    global: {
      provide: { [hilosRouterKey as symbol]: router(diagramBuilt, scopes) },
    },
  })

  return {
    view,
    heading(value: HilosDaemonNodeHeading): void {
      scope.data.set(DAEMON_NODE_DATA, value)
    },
    reported(value: boolean): void {
      scope.data.set(DAEMON_CRON_REPORTED_DATA, value)
    },
    window(rows: Record<string, unknown>[]): void {
      for (const listener of listeners) {
        listener({
          data: {
            page: HilosPages.DAEMON_CRON,
            tableKey: 'hilosDaemonCron',
            rows: rows.map((rule) => ({
              rowKey: String(rule.rowKey),
              slots: { rule },
            })),
            totalCount: rows.length,
          },
        })
      }
    },
  }
}

function rule(
  overrides: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    rowKey: '/cleanup_history',
    agentId: null,
    name: 'cleanup_history',
    expression: '*/30 * * * *',
    lastRunAt: 1_700_000_000,
    nextRunAt: 1_700_001_800,
    idleReason: null,
    ...overrides,
  }
}

describe('HilosDaemonCronPage', () => {
  it('shows a cluster state and the diagram only when the root is built', async () => {
    const built = harness(true)
    built.heading({
      clustered: true,
      role: null,
      state: 'leader',
      silentSince: null,
    })
    await nextTick()
    expect(built.view.find('[data-id="hilos-page-head-title"]').text()).toBe(
      'n1',
    )
    expect(built.view.find('[data-id="hilos-daemon-node-state"]').text()).toBe(
      'Leader',
    )
    expect(
      built.view.find('[data-id="hilos-daemon-node-state"]').classes(),
    ).toContain('bg-warning-subtle')
    expect(
      built.view.find('[data-id="hilos-page-head-action"]').attributes('href'),
    ).toBe('/hilos/daemon')
    built.view.unmount()

    const staged = harness()
    staged.heading({
      clustered: true,
      role: null,
      state: 'leader',
      silentSince: null,
    })
    await nextTick()
    expect(
      staged.view.find('[data-id="hilos-page-head-action"]').exists(),
    ).toBe(false)
    staged.view.unmount()
  })

  it('shows a single installation without a cluster state or diagram', async () => {
    const h = harness(true)
    h.heading({ clustered: false, role: null, state: null, silentSince: null })
    await nextTick()
    expect(h.view.find('[data-id="hilos-daemon-node-solo"]').text()).toBe(
      'Single installation',
    )
    expect(h.view.find('[data-id="hilos-daemon-node-state"]').exists()).toBe(
      false,
    )
    expect(h.view.find('[data-id="hilos-page-head-action"]').exists()).toBe(
      false,
    )
    h.view.unmount()
  })

  it('distinguishes an unreported schedule, silence and a reported empty schedule', async () => {
    const h = harness()
    h.window([])
    await nextTick()
    const waiting = h.view.find('[data-id="hilos-daemon-cron-empty-waiting"]')
    expect(waiting.text()).toContain(
      'The node has not reported its schedule yet',
    )
    expect(waiting.text()).toContain(
      'Nothing has arrived from this node yet — not zero rules.',
    )

    h.heading({
      clustered: true,
      role: null,
      state: 'silent',
      silentSince: 1_700_000_000,
    })
    await nextTick()
    const silent = h.view.find('[data-id="hilos-daemon-cron-empty-silent"]')
    expect(silent.text()).toContain(
      'The node is silent and never reported its schedule',
    )
    expect(silent.text()).toContain(
      'Its rules will appear when it reports again.',
    )
    expect(h.view.find('[data-id="hilos-daemon-cron-silent"]').exists()).toBe(
      false,
    )

    h.reported(true)
    await nextTick()
    expect(h.view.find('[data-id="hilos-daemon-cron-empty-none"]').text()).toBe(
      'The node reports no cron rules',
    )
    expect(h.view.find('[data-id="hilos-daemon-cron-silent"]').exists()).toBe(
      false,
    )
    h.view.unmount()
  })

  it('keeps the last rule rows and shows the silence line only while rows remain', async () => {
    const h = harness()
    h.heading({
      clustered: true,
      role: null,
      state: 'leader',
      silentSince: null,
    })
    h.reported(true)
    h.window([rule()])
    await nextTick()
    const row = h.view.find('[data-id="hilos-table-row-/cleanup_history"]')
    expect(h.view.find('[data-id="hilos-daemon-cron-silent"]').exists()).toBe(
      false,
    )

    h.heading({
      clustered: true,
      role: null,
      state: 'silent',
      silentSince: 1_700_000_000,
    })
    await nextTick()
    expect(
      h.view.find('[data-id="hilos-daemon-cron-silent"]').text(),
    ).toContain('The rules below are from its last report.')
    expect(h.view.find('[data-id="hilos-daemon-node-state"]').text()).toBe(
      'Silent',
    )
    expect(
      h.view.find('[data-id="hilos-table-row-/cleanup_history"]').element,
    ).toBe(row.element)

    h.window([])
    await nextTick()
    expect(h.view.find('[data-id="hilos-daemon-cron-silent"]').exists()).toBe(
      false,
    )
    h.view.unmount()
  })

  it.each([
    { state: 'standby', notice: 'idle_until_leader', secondPhrase: true },
    { state: 'data', notice: 'idle', secondPhrase: false },
    { state: 'silent', notice: 'idle', secondPhrase: false },
  ] as const)(
    'explains idle daemon rules on a $state node',
    async ({ state, notice, secondPhrase }) => {
      const h = harness()
      h.heading({
        clustered: true,
        role: null,
        state,
        silentSince: state === 'silent' ? 1_700_000_000 : null,
      })
      h.reported(true)
      h.window([rule({ idleReason: DAEMON_CRON_IDLE_NOT_LEADER })])
      await nextTick()
      const banner = h.view.find('[data-id="hilos-daemon-cron-not-leader"]')
      expect(banner.attributes('data-notice')).toBe(notice)
      expect(banner.text()).toContain(
        'Daemon rules do not run on this node: only the leader runs them.',
      )
      expect(
        banner
          .text()
          .includes('They will start here if leadership moves to this node.'),
      ).toBe(secondPhrase)
      expect(banner.attributes('role')).toBeUndefined()
      expect(banner.attributes('aria-live')).toBeUndefined()
      expect(banner.find('i').attributes('aria-hidden')).toBe('true')
      const reserve = h.view.find(NOTICE_ROOM)
      expect(reserve.text()).toContain(
        'Daemon rules do not run on this node: only the leader runs them.',
      )
      expect(reserve.text()).toContain(
        'They will start here if leadership moves to this node.',
      )
      expect(reserve.attributes('data-id')).toBeUndefined()
      h.view.unmount()
    },
  )

  it('holds the same notice room when the node becomes the leader', async () => {
    const h = harness()
    h.heading({
      clustered: true,
      role: null,
      state: 'standby',
      silentSince: null,
    })
    h.window([rule({ idleReason: DAEMON_CRON_IDLE_NOT_LEADER })])
    await nextTick()
    const reserve = h.view.find(NOTICE_ROOM)

    h.heading({
      clustered: true,
      role: null,
      state: 'leader',
      silentSince: null,
    })
    h.window([rule()])
    await nextTick()
    expect(
      h.view.find('[data-id="hilos-daemon-cron-not-leader"]').exists(),
    ).toBe(false)
    expect(h.view.find(NOTICE_ROOM).element).toBe(reserve.element)
    h.view.unmount()
  })

  it('takes no notice room on a standalone node', async () => {
    const h = harness()
    h.heading({ clustered: false, role: null, state: null, silentSince: null })
    h.window([rule()])
    await nextTick()
    expect(
      h.view.find('[data-id="hilos-daemon-cron-not-leader"]').exists(),
    ).toBe(false)
    expect(h.view.find(NOTICE_ROOM).exists()).toBe(false)
    h.view.unmount()
  })

  it.each([{ rows: [] }, { rows: [rule({ agentId: 'hilos_log_store:1' })] }])(
    'takes no notice room without daemon rules',
    async ({ rows }) => {
      const h = harness()
      h.heading({
        clustered: true,
        role: null,
        state: 'standby',
        silentSince: null,
      })
      h.window(rows)
      await nextTick()
      expect(
        h.view.find('[data-id="hilos-daemon-cron-not-leader"]').exists(),
      ).toBe(false)
      expect(h.view.find(NOTICE_ROOM).exists()).toBe(false)
      h.view.unmount()
    },
  )

  it('draws daemon and agent executors, rule names and expressions', async () => {
    const h = harness()
    h.heading({
      clustered: true,
      role: null,
      state: 'leader',
      silentSince: null,
    })
    h.window([
      rule(),
      rule({
        rowKey: 'hilos_sessions_library/hilos_sessions_expire',
        agentId: 'hilos_sessions_library',
        name: 'hilos_sessions_expire',
        expression: '*/15 * * * *',
      }),
    ])
    await nextTick()
    const daemon = h.view.find('[data-id="hilos-table-row-/cleanup_history"]')
    const agent = h.view.find(
      '[data-id="hilos-table-row-hilos_sessions_library/hilos_sessions_expire"]',
    )
    expect(daemon.findAll('td')[0]?.find('code').text()).toBe('cleanup_history')
    expect(daemon.findAll('td')[2]?.find('code').text()).toBe('*/30 * * * *')
    const daemonExecutor = daemon.find(
      '[data-id="hilos-daemon-cron-executor-/cleanup_history"]',
    )
    expect(daemonExecutor.text()).toBe('Daemon (leader)')
    expect(daemonExecutor.find('i').attributes('aria-hidden')).toBe('true')
    const agentExecutor = agent.find(
      '[data-id="hilos-daemon-cron-executor-hilos_sessions_library/hilos_sessions_expire"]',
    )
    expect(agentExecutor.text()).toBe('hilos_sessions_library')
    expect(agentExecutor.classes()).toContain('font-monospace')
    expect(agentExecutor.find('i').exists()).toBe(false)

    h.heading({ clustered: false, role: null, state: null, silentSince: null })
    await nextTick()
    expect(daemonExecutor.text()).toBe('Daemon')
    h.view.unmount()
  })

  it('words and mutes idle and absent run moments without muting actual moments', async () => {
    const h = harness()
    h.heading({
      clustered: true,
      role: null,
      state: 'standby',
      silentSince: null,
    })
    h.window([
      rule({ idleReason: DAEMON_CRON_IDLE_NOT_LEADER }),
      rule({
        rowKey: 'hilos_backup/hilos_backup_yearly',
        agentId: 'hilos_backup',
        lastRunAt: null,
        nextRunAt: null,
      }),
      rule({
        rowKey: 'hilos_log_store/log-rotation',
        agentId: 'hilos_log_store',
      }),
    ])
    await nextTick()
    const idle = h.view.find('[data-id="hilos-table-row-/cleanup_history"]')
    const empty = h.view.find(
      '[data-id="hilos-table-row-hilos_backup/hilos_backup_yearly"]',
    )
    const active = h.view.find(
      '[data-id="hilos-table-row-hilos_log_store/log-rotation"]',
    )
    expect(idle.findAll('td')[3]?.text()).toBe('Not on this node')
    expect(idle.findAll('td')[4]?.text()).toBe('—')
    expect(empty.findAll('td')[3]?.text()).toBe('Not yet')
    expect(empty.findAll('td')[4]?.text()).toBe('Never')
    for (const row of [idle, empty]) {
      for (const index of [3, 4]) {
        expect(row.findAll('td')[index]?.find('span').classes()).toContain(
          'text-body-secondary',
        )
      }
    }
    for (const index of [3, 4]) {
      expect(active.findAll('td')[index]?.find('span').classes()).not.toContain(
        'text-body-secondary',
      )
    }
    h.view.unmount()
  })
})
