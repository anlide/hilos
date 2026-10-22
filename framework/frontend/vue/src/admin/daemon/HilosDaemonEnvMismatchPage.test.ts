import { mount } from '@vue/test-utils'
import {
  createSignal,
  HilosPages,
  ScopeManager,
  type HilosConnection,
  type HilosRouter,
  type PageRouteMatch,
} from '@hilos/core/base'
import {
  DAEMON_ENV_MISMATCH_DATA,
  DAEMON_ENV_SENSITIVE_HINT,
  type HilosDaemonEnvMismatchSummary,
} from '@hilos/core/admin/daemon'
import { describe, expect, it } from 'vitest'
import { nextTick } from 'vue'

import HilosDaemonEnvMismatchPage from './HilosDaemonEnvMismatchPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

function harness(initialSummary?: HilosDaemonEnvMismatchSummary | null) {
  const scopes = new ScopeManager()
  const scope = scopes.openPage(HilosPages.DAEMON_ENV_MISMATCH)
  if (initialSummary !== undefined) {
    scope.data.set(DAEMON_ENV_MISMATCH_DATA, initialSummary)
  }
  const listeners: Array<(signal: { data: unknown }) => void> = []
  const sentViewports: Array<{
    page: string
    tableKey: string
    descriptor: unknown
  }> = []
  const registeredTables = new Set<string>()

  const connection = {
    on(event: string, listener: (signal: never) => void): () => void {
      if (event === 'tableWindow') {
        listeners.push(listener as (signal: { data: unknown }) => void)
      }
      return () => {}
    },
    registerTableWindow(tableKey: string): void {
      registeredTables.add(tableKey)
    },
    unregisterTableWindow(tableKey: string): void {
      registeredTables.delete(tableKey)
    },
    tableWindowDescriptors: () => ({}),
    sendTableViewport(
      page: string,
      tableKey: string,
      descriptor: unknown,
    ): boolean {
      sentViewports.push({ page, tableKey, descriptor })
      return true
    },
    sendTableRendered(): boolean {
      return true
    },
  } as unknown as HilosConnection

  const router: HilosRouter = {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.DAEMON_ENV_MISMATCH,
      params: {},
      admin: true,
    }),
    currentPath: createSignal('/hilos/daemon/env-mismatch'),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal(undefined),
    dashboardSections: createSignal(undefined),
    resolvePath: () => undefined,
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    onLeave: () => () => {},
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }

  const view = mount(HilosDaemonEnvMismatchPage, {
    props: { context: { connection, scopes } },
    global: {
      provide: { [hilosRouterKey as symbol]: router },
      stubs: {
        HilosAdminPage: { template: '<main><slot /></main>' },
        HilosSkeleton: { template: '<div data-id="stub-skeleton" />' },
      },
    },
  })

  return {
    view,
    scope,
    connection,
    sentViewports,
    registeredTables,
    setSummary(value: HilosDaemonEnvMismatchSummary | null): void {
      scope.data.set(DAEMON_ENV_MISMATCH_DATA, value)
    },
    window(tableKey: string, rows: Record<string, unknown>[]): void {
      for (const listener of listeners) {
        listener({
          data: {
            page: HilosPages.DAEMON_ENV_MISMATCH,
            tableKey,
            rows: rows.map((key) => ({
              rowKey: String(key.key ?? key.rowKey),
              slots: { key },
            })),
            totalCount: rows.length,
          },
        })
      }
    },
  }
}

describe('HilosDaemonEnvMismatchPage', () => {
  it('shows standalone alert without tiles or tables and does not request viewports', async () => {
    const h = harness({
      clustered: false,
      servingNodeId: 'standalone',
      comparison: null,
    })
    await nextTick()

    const standalone = h.view.find(
      '[data-id="hilos-daemon-env-mismatch-standalone"]',
    )
    expect(standalone.exists()).toBe(true)
    expect(standalone.text()).toContain(
      'This page does not exist on a single-node installation',
    )
    expect(
      h.view
        .find('[data-id="hilos-daemon-env-mismatch-standalone-node"]')
        .text(),
    ).toBe('standalone')

    expect(
      h.view
        .find('[data-id="hilos-daemon-env-mismatch-tile-answering"]')
        .exists(),
    ).toBe(false)
    expect(
      h.view.find('[data-id="hilos-daemon-env-mismatch-waiting"]').exists(),
    ).toBe(false)
    expect(h.sentViewports).toHaveLength(0)
  })

  it('shows waiting picture when summary or comparison is null', async () => {
    const h = harness(null)
    await nextTick()

    expect(
      h.view.find('[data-id="hilos-daemon-env-mismatch-waiting"]').exists(),
    ).toBe(true)
    expect(
      h.view.find('[data-id="hilos-daemon-env-mismatch-waiting"]').text(),
    ).toContain('Waiting for the cluster picture…')
    expect(
      h.view.find('[data-id="hilos-daemon-env-mismatch-standalone"]').exists(),
    ).toBe(false)

    h.setSummary({
      clustered: true,
      servingNodeId: 'n1',
      comparison: null,
    })
    await nextTick()

    expect(
      h.view.find('[data-id="hilos-daemon-env-mismatch-waiting"]').exists(),
    ).toBe(true)
  })

  it('renders summary tiles with values and tones', async () => {
    const h = harness({
      clustered: true,
      servingNodeId: 'n1',
      comparison: {
        nodes: ['n1', 'n2', 'n3'],
        answering: ['n1', 'n2'],
        diverged: 2,
        sourcesDiffer: 1,
        perNode: ['CLUSTER_NODE_ID'],
      },
    })
    await nextTick()

    const answeringTile = h.view.find(
      '[data-id="hilos-daemon-env-mismatch-tile-answering"]',
    )
    expect(answeringTile.attributes('data-tone')).toBe('warning')
    expect(answeringTile.text()).toContain('2 of 3')
    expect(answeringTile.text()).toContain('Silent nodes show as ?')

    const divergedTile = h.view.find(
      '[data-id="hilos-daemon-env-mismatch-tile-diverged"]',
    )
    expect(divergedTile.attributes('data-tone')).toBe('danger')
    expect(divergedTile.text()).toContain('2')

    const sourcesTile = h.view.find(
      '[data-id="hilos-daemon-env-mismatch-tile-sources"]',
    )
    expect(sourcesTile.attributes('data-tone')).toBe('warning')
    expect(sourcesTile.text()).toContain('1')

    const perNodeTile = h.view.find(
      '[data-id="hilos-daemon-env-mismatch-tile-per-node"]',
    )
    expect(perNodeTile.attributes('data-tone')).toBe('')
    expect(perNodeTile.text()).toContain('1')

    h.setSummary({
      clustered: true,
      servingNodeId: 'n1',
      comparison: {
        nodes: ['n1', 'n2'],
        answering: ['n1', 'n2'],
        diverged: 0,
        sourcesDiffer: 0,
        perNode: [],
      },
    })
    await nextTick()

    expect(
      h.view
        .find('[data-id="hilos-daemon-env-mismatch-tile-answering"]')
        .attributes('data-tone'),
    ).toBe('')
    expect(
      h.view
        .find('[data-id="hilos-daemon-env-mismatch-tile-diverged"]')
        .attributes('data-tone'),
    ).toBe('success')
    expect(
      h.view
        .find('[data-id="hilos-daemon-env-mismatch-tile-sources"]')
        .attributes('data-tone'),
    ).toBe('success')
  })

  it('hides second table when sourcesDiffer is zero, shows when greater than zero', async () => {
    const h = harness({
      clustered: true,
      servingNodeId: 'n1',
      comparison: {
        nodes: ['n1', 'n2'],
        answering: ['n1', 'n2'],
        diverged: 0,
        sourcesDiffer: 0,
        perNode: [],
      },
    })
    await nextTick()

    expect(h.view.text()).not.toContain('Values match, sources differ')

    h.setSummary({
      clustered: true,
      servingNodeId: 'n1',
      comparison: {
        nodes: ['n1', 'n2'],
        answering: ['n1', 'n2'],
        diverged: 0,
        sourcesDiffer: 1,
        perNode: [],
      },
    })
    await nextTick()

    expect(h.view.text()).toContain('Values match, sources differ')
  })

  it('hides per_node section when list is empty, shows badges when populated', async () => {
    const h = harness({
      clustered: true,
      servingNodeId: 'n1',
      comparison: {
        nodes: ['n1', 'n2'],
        answering: ['n1', 'n2'],
        diverged: 0,
        sourcesDiffer: 0,
        perNode: [],
      },
    })
    await nextTick()

    expect(
      h.view.find('[data-id="hilos-daemon-env-mismatch-per-node"]').exists(),
    ).toBe(false)
    expect(
      h.view.findAll('h2').some((h2) => h2.text() === 'Differ by design'),
    ).toBe(false)

    h.setSummary({
      clustered: true,
      servingNodeId: 'n1',
      comparison: {
        nodes: ['n1', 'n2'],
        answering: ['n1', 'n2'],
        diverged: 0,
        sourcesDiffer: 0,
        perNode: ['CLUSTER_NODE_ID', 'CLUSTER_PEER_HOST'],
      },
    })
    await nextTick()

    expect(
      h.view.find('[data-id="hilos-daemon-env-mismatch-per-node"]').exists(),
    ).toBe(true)
    expect(
      h.view
        .find('[data-id="hilos-daemon-env-mismatch-per-node-CLUSTER_NODE_ID"]')
        .text(),
    ).toBe('CLUSTER_NODE_ID')
    expect(
      h.view
        .find(
          '[data-id="hilos-daemon-env-mismatch-per-node-CLUSTER_PEER_HOST"]',
        )
        .text(),
    ).toBe('CLUSTER_PEER_HOST')
  })

  it('renders cell kinds, badges, stands-out, from-example, and sensitive lock', async () => {
    const h = harness({
      clustered: true,
      servingNodeId: 'n1',
      comparison: {
        nodes: ['n1', 'n2', 'n3', 'n4', 'n5'],
        answering: ['n1', 'n2', 'n3', 'n5'],
        diverged: 1,
        sourcesDiffer: 1,
        perNode: [],
      },
    })
    await nextTick()

    h.window('hilosDaemonEnvDiverged', [
      {
        rowKey: 'SECRET_KEY',
        key: 'SECRET_KEY',
        sensitive: true,
        value: { kind: 'text', text: 'secret-val' },
        cells: [
          {
            nodeId: 'n1',
            state: 'known',
            type: 'string',
            source: 'process',
            digest: 'aaaa1111',
          },
          {
            nodeId: 'n2',
            state: 'known',
            type: 'string',
            source: 'env',
            digest: 'bbbb2222',
          },
          {
            nodeId: 'n3',
            state: 'undeclared',
            type: null,
            source: null,
            digest: null,
          },
          {
            nodeId: 'n4',
            state: 'unknown',
            type: null,
            source: null,
            digest: null,
          },
          {
            nodeId: 'n5',
            state: 'known',
            type: 'string',
            source: 'missing',
            digest: null,
          },
        ],
      },
    ])
    await nextTick()

    const lock = h.view.find('.bi-shield-lock')
    expect(lock.exists()).toBe(true)
    expect(lock.attributes('title')).toBe(DAEMON_ENV_SENSITIVE_HINT)

    const cellN1 = h.view.find(
      '[data-id="hilos-daemon-env-mismatch-cell-SECRET_KEY-n1"]',
    )
    expect(cellN1.attributes('data-kind')).toBe('value')
    expect(cellN1.text()).toContain('secret-val')
    expect(cellN1.text()).toContain('Launch environment')

    const cellN2 = h.view.find(
      '[data-id="hilos-daemon-env-mismatch-cell-SECRET_KEY-n2"]',
    )
    expect(cellN2.attributes('data-kind')).toBe('digest')
    expect(cellN2.text()).toContain('#bbbb')
    expect(cellN2.text()).toContain('.env')
    expect(
      cellN2
        .find(
          '[data-id="hilos-daemon-env-mismatch-cell-SECRET_KEY-n2-stands-out"]',
        )
        .text(),
    ).toContain('Differs from the others')

    const cellN3 = h.view.find(
      '[data-id="hilos-daemon-env-mismatch-cell-SECRET_KEY-n3"]',
    )
    expect(cellN3.attributes('data-kind')).toBe('undeclared')
    expect(cellN3.text()).toContain('Not declared')
    expect(
      cellN3
        .find(
          '[data-id="hilos-daemon-env-mismatch-cell-SECRET_KEY-n3-stands-out"]',
        )
        .text(),
    ).toContain('Differs from the others')

    const cellN4 = h.view.find(
      '[data-id="hilos-daemon-env-mismatch-cell-SECRET_KEY-n4"]',
    )
    expect(cellN4.attributes('data-kind')).toBe('unknown')
    expect(cellN4.text()).toContain('?')

    const cellN5 = h.view.find(
      '[data-id="hilos-daemon-env-mismatch-cell-SECRET_KEY-n5"]',
    )
    expect(cellN5.attributes('data-kind')).toBe('none')
    expect(cellN5.text()).toContain('—')

    h.window('hilosDaemonEnvSourcesDiffer', [
      {
        rowKey: 'MAIL_HOST',
        key: 'MAIL_HOST',
        sensitive: false,
        value: { kind: 'text', text: 'mail.example.test' },
        cells: [
          {
            nodeId: 'n1',
            state: 'known',
            type: 'string',
            source: 'env',
            digest: 'cccc3333',
          },
          {
            nodeId: 'n2',
            state: 'known',
            type: 'string',
            source: 'example',
            digest: 'cccc3333',
          },
        ],
      },
    ])
    await nextTick()

    const fromExample = h.view.find(
      '[data-id="hilos-daemon-env-mismatch-cell-MAIL_HOST-n2-from-example"]',
    )
    expect(fromExample.exists()).toBe(true)
    expect(fromExample.text()).toContain('From .env.example')
  })

  it('displays empty state for alone and agree', async () => {
    const h = harness({
      clustered: true,
      servingNodeId: 'n1',
      comparison: {
        nodes: ['n1', 'n2'],
        answering: ['n1'],
        diverged: 0,
        sourcesDiffer: 0,
        perNode: [],
      },
    })
    await nextTick()

    h.window('hilosDaemonEnvDiverged', [])
    await nextTick()

    expect(
      h.view.find('[data-id="hilos-daemon-env-mismatch-empty-alone"]').text(),
    ).toContain(
      'Fewer than two nodes answer, so there is nothing to compare yet.',
    )

    h.setSummary({
      clustered: true,
      servingNodeId: 'n1',
      comparison: {
        nodes: ['n1', 'n2'],
        answering: ['n1', 'n2'],
        diverged: 0,
        sourcesDiffer: 0,
        perNode: [],
      },
    })
    await nextTick()

    expect(
      h.view.find('[data-id="hilos-daemon-env-mismatch-empty-agree"]').text(),
    ).toContain('The answering nodes agree on every key they share.')
  })

  it('rebuilds table columns when page summary changes nodes', async () => {
    const h = harness({
      clustered: true,
      servingNodeId: 'n1',
      comparison: {
        nodes: ['n1', 'n2'],
        answering: ['n1', 'n2'],
        diverged: 0,
        sourcesDiffer: 0,
        perNode: [],
      },
    })
    await nextTick()

    expect(
      h.view
        .find('main')
        .findAll('th')
        .map((th) => th.text()),
    ).toEqual([
      'Key',
      'n1 · this node',
      'n2',
      'Will move Will leaveRow state and controls',
    ])

    h.setSummary({
      clustered: true,
      servingNodeId: 'n1',
      comparison: {
        nodes: ['n1', 'n2', 'n3'],
        answering: ['n1', 'n2', 'n3'],
        diverged: 0,
        sourcesDiffer: 0,
        perNode: [],
      },
    })
    await nextTick()

    expect(
      h.view
        .find('main')
        .findAll('th')
        .map((th) => th.text()),
    ).toEqual([
      'Key',
      'n1 · this node',
      'n2',
      'n3',
      'Will move Will leaveRow state and controls',
    ])
  })
})
