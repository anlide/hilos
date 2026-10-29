import { mount, type VueWrapper } from '@vue/test-utils'
import { markRaw, nextTick } from 'vue'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  HilosPages,
  ScopeManager,
  bindAdminAccess,
  createAppPageRouter,
  createSignal,
  type ActionLifecycle,
  type HilosConnection,
  type HilosPageIdentity,
  type HilosRouter,
  type PageRouteMatch,
} from '@hilos/core/base'
import {
  HILOS_MAINTENANCE_NODES_COPY,
  HILOS_MAINTENANCE_NODES_TABLE,
  type HilosMaintenanceContext,
  type HilosMaintenanceNodeRow,
} from '@hilos/core/admin/maintenance'

import HilosMaintenanceNodesPage from './HilosMaintenanceNodesPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

function identity(cluster = true): HilosPageIdentity {
  return {
    label: 'Nodes',
    lead: 'Maintenance reports from cluster nodes.',
    breadcrumb: [
      { page: HilosPages.DASHBOARD, label: 'Hilos' },
      { page: HilosPages.MAINTENANCE, label: 'Maintenance' },
    ],
    children: [],
    pageHeadOwner: {
      page: HilosPages.MAINTENANCE,
      label: 'Maintenance',
      lead: 'Node freeze and manual maintenance.',
      icon: 'bi-tools',
    },
    pageTabs: [
      { page: HilosPages.MAINTENANCE, label: 'Overview' },
      { page: HilosPages.MAINTENANCE_CIRCLE, label: 'Circle' },
      { page: HilosPages.MAINTENANCE_WORDS, label: 'Words' },
      ...(cluster
        ? [{ page: HilosPages.MAINTENANCE_NODES, label: 'Nodes' }]
        : []),
    ],
  }
}

function router(cluster = true) {
  const paths = createAppPageRouter({})
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.MAINTENANCE_NODES,
      params: {},
      admin: true,
    }),
    currentPath: createSignal('/hilos/maintenance/nodes'),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal<HilosPageIdentity | undefined>(
      identity(cluster),
    ),
    dashboardSections: createSignal(undefined),
    resolvePath: (page, params) => paths.path(page, params),
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    onLeave: () => () => {},
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  } satisfies HilosRouter
}

function node(
  nodeId: string,
  overrides: Partial<HilosMaintenanceNodeRow> = {},
): HilosMaintenanceNodeRow {
  return {
    nodeId,
    phase: 'inactive',
    quiesced: null,
    isLeader: false,
    isInitiator: false,
    reportState: 'current',
    ...overrides,
  }
}

function wireRow(row: HilosMaintenanceNodeRow) {
  return { rowKey: row.nodeId, slots: { node: { ...row } } }
}

/** Only transport is stubbed; the component uses the real table and binder. */
function seededContext(initial: HilosMaintenanceNodeRow[] = []) {
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.MAINTENANCE_NODES)
  const listeners = new Map<
    string,
    Set<(signal: { type?: string; data: unknown }) => void>
  >()
  const registered = new Set<string>()
  const sendTableViewport = vi.fn(() => true)
  const sendTableFacets = vi.fn(() => true)
  const sendAction = vi.fn(() => true)
  const dispatch = vi.fn(() => {
    throw new Error('Nodes has no actions')
  })

  const emit = (event: string, data: unknown): void => {
    for (const listener of [...(listeners.get(event) ?? [])]) {
      listener({
        type: event === 'projectSignal' ? 'page_response' : event,
        data,
      })
    }
  }
  const serveWindow = (rows: HilosMaintenanceNodeRow[]): void => {
    emit('projectSignal', {
      page: HilosPages.MAINTENANCE_NODES,
      payload: {
        windows: {
          hilosMaintenanceNodes: {
            rows: rows.map(wireRow),
            totalCount: rows.length,
            totalExact: true,
            firstAnchor: null,
            lastAnchor: null,
            rowsBefore: 0,
            limit: 25,
            sort: [{ field: 'nodeId', direction: 'asc' }],
          },
        },
      },
    })
  }
  const connection = {
    projectTimeZone: 'UTC',
    on(
      event: string,
      listener: (signal: { type?: string; data: unknown }) => void,
    ): () => void {
      const bucket = listeners.get(event) ?? new Set()
      listeners.set(event, bucket)
      bucket.add(listener)
      return () => bucket.delete(listener)
    },
    registerTableWindow(tableKey: string): void {
      registered.add(tableKey)
      serveWindow(initial)
    },
    unregisterTableWindow(tableKey: string): void {
      registered.delete(tableKey)
    },
    sendTableViewport,
    sendTableFacets,
    sendAction,
    sendTableRendered: vi.fn(() => true),
  } as unknown as HilosConnection

  return {
    context: {
      connection,
      scopes,
      actions: { dispatch } as unknown as ActionLifecycle,
    } satisfies HilosMaintenanceContext,
    registered,
    sendTableViewport,
    sendTableFacets,
    sendAction,
    dispatch,
    listenerCount: () =>
      [...listeners.values()].reduce((count, bucket) => count + bucket.size, 0),
    updateRow(row: HilosMaintenanceNodeRow): void {
      emit('tableViewportDelta', {
        page: HilosPages.MAINTENANCE_NODES,
        tableKey: 'hilosMaintenanceNodes',
        kind: 'row_updated',
        rowKey: row.nodeId,
        row: wireRow(row),
      })
    },
  }
}

const mounted: VueWrapper[] = []
const releases: Array<() => void> = []

afterEach(() => {
  for (const wrapper of mounted.splice(0)) wrapper.unmount()
  for (const release of releases.splice(0)) release()
})

async function mountPage(
  context: HilosMaintenanceContext,
  navigation = router(),
) {
  const wrapper = mount(HilosMaintenanceNodesPage, {
    props: { context: markRaw(context) },
    attachTo: document.body,
    global: { provide: { [hilosRouterKey as symbol]: navigation } },
  })
  mounted.push(wrapper)
  await nextTick()
  return wrapper
}

function cells(wrapper: VueWrapper, nodeId: string): string[] {
  return wrapper
    .get(`table [data-id="hilos-table-row-${nodeId}"]`)
    .findAll('td')
    .slice(0, 4)
    .map((cell) => cell.text())
}

function expectReadOnly(wrapper: VueWrapper): void {
  for (const selector of [
    '[data-id="hilos-table-main-action"]',
    '[data-id="hilos-table-search"]',
    '[data-id="hilos-table-filters"]',
    '[data-id="hilos-table-selection"]',
    '[data-id^="hilos-table-select-"]',
    '[data-id^="hilos-table-bulk-"]',
    '[role="dialog"]',
    'table tbody button',
    'table tbody a',
  ]) {
    expect(wrapper.find(selector).exists(), selector).toBe(false)
  }
}

describe('HilosMaintenanceNodesPage', () => {
  it('uses the shared section head and four named read-only table columns', async () => {
    const { context } = seededContext([node('node-1')])
    const wrapper = await mountPage(context)

    expect(
      wrapper.get('[data-id="hilos-admin-page"]').attributes('data-page'),
    ).toBe(HilosPages.MAINTENANCE_NODES)
    expect(wrapper.findAll('h1').map((heading) => heading.text())).toEqual([
      'Maintenance',
    ])
    expect(wrapper.get('[data-id="hilos-breadcrumb"]').text()).toContain(
      'Maintenance',
    )
    const tabs = wrapper.get('[data-id="hilos-page-tabs"]')
    expect(tabs.attributes('aria-label')).toBe('Maintenance pages')
    expect(tabs.get('[aria-current="page"]').text()).toBe('Nodes')
    expect(tabs.findAll('a').map((link) => link.text())).toEqual([
      'Overview',
      'Circle',
      'Words',
      'Nodes',
    ])
    const table = wrapper.get('[data-id="hilos-maintenance-nodes-table"] table')
    const title = wrapper.get('[data-id="hilos-table-title"]')
    expect(title.text()).toBe(HILOS_MAINTENANCE_NODES_COPY.title)
    expect(table.attributes('aria-labelledby')).toBe(title.attributes('id'))
    expect(
      table
        .findAll('th')
        .slice(0, -1)
        .map((cell) => cell.text()),
    ).toEqual(['Node', 'Quiesced', 'Phase on node', 'Role'])
    expectReadOnly(wrapper)
  })

  it('shows inactive reports with unknown acknowledgement and the reported leader', async () => {
    const { context } = seededContext([
      node('node-1', { isLeader: true }),
      node('node-2'),
    ])
    const wrapper = await mountPage(context)
    expect(cells(wrapper, 'node-1')).toEqual([
      'node-1',
      '—',
      'inactive',
      'Leader',
    ])
    expect(cells(wrapper, 'node-2')).toEqual(['node-2', '—', 'inactive', '—'])
    expect(
      wrapper
        .get('table [data-id="hilos-maintenance-nodes-row-node-1"]')
        .text(),
    ).toBe('node-1')
  })

  it('shows verifying and activating reports with Yes/No and both independent roles', async () => {
    const { context } = seededContext([
      node('node-1', {
        phase: 'verifying',
        quiesced: true,
        isLeader: true,
        isInitiator: true,
      }),
      node('node-2', { phase: 'verifying', quiesced: true, isInitiator: true }),
      node('node-3', { phase: 'activating', quiesced: false }),
    ])
    const wrapper = await mountPage(context)
    expect(cells(wrapper, 'node-1')).toEqual([
      'node-1',
      'Yes',
      'verifying',
      'Leader · Initiator here',
    ])
    expect(cells(wrapper, 'node-2')).toEqual([
      'node-2',
      'Yes',
      'verifying',
      'Initiator here',
    ])
    expect(cells(wrapper, 'node-3')).toEqual([
      'node-3',
      'No',
      'activating',
      '—',
    ])
  })

  it('keeps null phase and quiesced unknown even in a current report', async () => {
    const { context } = seededContext([node('node-1', { phase: null })])
    const wrapper = await mountPage(context)
    expect(cells(wrapper, 'node-1')).toEqual(['node-1', '—', '—', '—'])
    expect(
      wrapper.find('table [data-id="hilos-table-row-message"]').exists(),
    ).toBe(false)
  })

  it.each(['missing', 'silent'] as const)(
    'keeps acknowledgement, phase and known roles visible beside a %s report explanation',
    async (reportState) => {
      const { context } = seededContext([
        node('node-1', {
          phase: null,
          quiesced: false,
          isInitiator: true,
          reportState,
        }),
        node('node-2', {
          phase: null,
          quiesced: false,
          isLeader: true,
          reportState,
        }),
        node('node-3', {
          phase: null,
          quiesced: true,
          isLeader: true,
          isInitiator: true,
          reportState,
        }),
        node('node-4', { phase: null, reportState }),
      ])
      const wrapper = await mountPage(context)
      const explanation = HILOS_MAINTENANCE_NODES_COPY[reportState]
      for (const [nodeId, acknowledgement, role] of [
        ['node-1', 'No', 'Initiator here'],
        ['node-2', 'No', 'Leader'],
        ['node-3', 'Yes', 'Leader · Initiator here'],
        ['node-4', '—', null],
      ] as const) {
        const text = role ? `${role} · ${explanation}` : explanation
        expect(cells(wrapper, nodeId)).toEqual([
          nodeId,
          acknowledgement,
          '—',
          text,
        ])
        const row = wrapper.get(`table [data-id="hilos-table-row-${nodeId}"]`)
        const messages = row.findAll('[data-id="hilos-table-row-message"]')
        expect(messages).toHaveLength(1)
        expect(messages[0].text()).toBe(text)
      }
      expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    },
  )

  it.each(['missing', 'silent'] as const)(
    'preserves leader facts through a live %s report and recovery on the same row',
    async (reportState) => {
      const harness = seededContext([
        node('node-1', {
          phase: 'activating',
          quiesced: false,
          isInitiator: true,
        }),
      ])
      const wrapper = await mountPage(harness.context)
      const original = wrapper.get(
        'table [data-id="hilos-table-row-node-1"]',
      ).element
      expect(cells(wrapper, 'node-1')).toEqual([
        'node-1',
        'No',
        'activating',
        'Initiator here',
      ])
      harness.updateRow(
        node('node-1', {
          phase: null,
          quiesced: false,
          isInitiator: true,
          reportState,
        }),
      )
      await nextTick()
      expect(cells(wrapper, 'node-1')).toEqual([
        'node-1',
        'No',
        '—',
        `Initiator here · ${HILOS_MAINTENANCE_NODES_COPY[reportState]}`,
      ])
      harness.updateRow(
        node('node-1', {
          phase: null,
          quiesced: true,
          isLeader: true,
          isInitiator: true,
          reportState,
        }),
      )
      await nextTick()
      expect(cells(wrapper, 'node-1')).toEqual([
        'node-1',
        'Yes',
        '—',
        `Leader · Initiator here · ${HILOS_MAINTENANCE_NODES_COPY[reportState]}`,
      ])
      harness.updateRow(
        node('node-1', {
          phase: 'verifying',
          quiesced: true,
          isLeader: true,
          isInitiator: true,
        }),
      )
      await nextTick()
      expect(cells(wrapper, 'node-1')).toEqual([
        'node-1',
        'Yes',
        'verifying',
        'Leader · Initiator here',
      ])
      expect(
        wrapper.find('table [data-id="hilos-table-row-message"]').exists(),
      ).toBe(false)
      expect(
        wrapper.get('table [data-id="hilos-table-row-node-1"]').element,
      ).toBe(original)
      expect(harness.sendTableViewport).not.toHaveBeenCalled()
    },
  )

  it('updates a shown report in place through the real controller', async () => {
    const harness = seededContext([node('node-1')])
    const wrapper = await mountPage(harness.context)
    const original = wrapper.get(
      'table [data-id="hilos-table-row-node-1"]',
    ).element
    harness.updateRow(
      node('node-1', {
        phase: 'verifying',
        quiesced: true,
        isLeader: true,
        isInitiator: true,
      }),
    )
    await nextTick()
    expect(
      wrapper.get('table [data-id="hilos-table-row-node-1"]').element,
    ).toBe(original)
    expect(cells(wrapper, 'node-1')).toEqual([
      'node-1',
      'Yes',
      'verifying',
      'Leader · Initiator here',
    ])
    expect(harness.sendTableViewport).not.toHaveBeenCalled()
  })

  it('takes Nodes applicability from supplied tabs even when the cluster reports no rows', async () => {
    const { context } = seededContext([])
    const navigation = router()
    const wrapper = await mountPage(context, navigation)
    expect(
      wrapper.get('[data-id="hilos-page-tabs"] a[aria-current="page"]').text(),
    ).toBe('Nodes')
    expect(wrapper.get('[data-id="hilos-table-empty-title"]').text()).toBe(
      HILOS_MAINTENANCE_NODES_COPY.empty,
    )
    navigation.pageIdentity.set(identity(false))
    await nextTick()
    expect(
      wrapper
        .get('[data-id="hilos-page-tabs"]')
        .findAll('a')
        .map((link) => link.text()),
    ).toEqual(['Overview', 'Circle', 'Words'])
  })

  it('renders the same read-only facts for an admin viewer', async () => {
    const { context } = seededContext([node('node-1', { isLeader: true })])
    context.scopes.session.data.set('adminViewMode', true)
    releases.push(bindAdminAccess(context.scopes))
    const wrapper = await mountPage(context)
    expect(cells(wrapper, 'node-1')).toEqual([
      'node-1',
      '—',
      'inactive',
      'Leader',
    ])
    expectReadOnly(wrapper)
  })

  it('binds the initial page window without fetching and disposes on unmount', async () => {
    const harness = seededContext([node('node-1')])
    expect(harness.registered.size).toBe(0)
    expect(harness.listenerCount()).toBe(0)
    const wrapper = await mountPage(harness.context)
    expect(harness.registered).toEqual(new Set([HILOS_MAINTENANCE_NODES_TABLE]))
    expect(harness.listenerCount()).toBeGreaterThan(0)
    expect(cells(wrapper, 'node-1')).toEqual(['node-1', '—', 'inactive', '—'])
    expect(harness.sendTableViewport).not.toHaveBeenCalled()
    expect(harness.sendTableFacets).not.toHaveBeenCalled()
    expect(harness.sendAction).not.toHaveBeenCalled()
    expect(harness.dispatch).not.toHaveBeenCalled()
    wrapper.unmount()
    expect(harness.registered.size).toBe(0)
    expect(harness.listenerCount()).toBe(0)
  })
})
