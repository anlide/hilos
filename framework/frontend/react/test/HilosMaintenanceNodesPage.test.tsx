// The React Maintenance Nodes tab over the real core table factory, binder, scopes,
// shared section head and viewport table; only the transport is stubbed.
import { act, cleanup, render, within } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  bindAdminAccess,
  createAppPageRouter,
  createSignal,
  HilosPages,
  ScopeManager,
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
import { HilosMaintenanceNodesPage as exportedPage } from '@hilos/react/admin/maintenance'

import { HilosMaintenanceNodesPage } from '../src/admin/maintenance/HilosMaintenanceNodesPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

const releases: Array<() => void> = []

afterEach(() => {
  cleanup()
  for (const release of releases.splice(0)) release()
  vi.restoreAllMocks()
})

type Listener = (signal: { type?: string; data: unknown }) => void

const paths = createAppPageRouter({})

/**
 * What the Nodes answer names for its head; the Nodes tab stands only where the
 * backend reports cluster mode.
 *
 * @param cluster Whether the backend reports cluster mode.
 */
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

function router() {
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
    pageIdentity: createSignal<HilosPageIdentity | undefined>(identity()),
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

/** Only transport is stubbed; the page uses the real table and binder. */
function seededContext(initial: HilosMaintenanceNodeRow[] = []) {
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.MAINTENANCE_NODES)
  const listeners = new Map<string, Set<Listener>>()
  const registered = new Set<string>()
  const sendTableViewport = vi.fn(() => true)
  const sendTableFacets = vi.fn(() => true)
  const sendAction = vi.fn(() => true)
  const dispatch = vi.fn(() => {
    throw new Error('Nodes has no actions')
  })
  const emit = (event: string, data: unknown): void => {
    act(() => {
      for (const listener of [...(listeners.get(event) ?? [])]) {
        listener({ type: event, data })
      }
    })
  }
  const pageWindow = (rows: HilosMaintenanceNodeRow[]) => ({
    page: HilosPages.MAINTENANCE_NODES,
    payload: {
      windows: {
        [HILOS_MAINTENANCE_NODES_TABLE]: {
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
  const connection = {
    projectTimeZone: 'UTC',
    on(event: string, listener: Listener): () => void {
      const bucket = listeners.get(event) ?? new Set<Listener>()
      listeners.set(event, bucket)
      bucket.add(listener)
      if (event === 'projectSignal') {
        listener({ type: 'page_response', data: pageWindow(initial) })
      }
      return () => bucket.delete(listener)
    },
    registerTableWindow(tableKey: string): void {
      registered.add(tableKey)
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
        tableKey: HILOS_MAINTENANCE_NODES_TABLE,
        kind: 'row_updated',
        rowKey: row.nodeId,
        row: wireRow(row),
      })
    },
  }
}

function mountPage(
  context: HilosMaintenanceContext,
  navigation: HilosRouter = router(),
) {
  let view!: ReturnType<typeof render>
  act(() => {
    view = render(
      <HilosRouterContext.Provider value={navigation}>
        <HilosMaintenanceNodesPage context={context} />
      </HilosRouterContext.Provider>,
    )
  })
  return view
}

function byId(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

function rowOf(nodeId: string): HTMLElement {
  const row = document.querySelector<HTMLElement>(
    `table [data-id="hilos-table-row-${nodeId}"]`,
  )
  expect(row, nodeId).not.toBeNull()
  return row!
}

/** The page's four columns, by the header a reader hears for each. */
const COLUMNS = ['Node', 'Quiesced', 'Phase on node', 'Role']

/**
 * What a row shows under each of the page's four columns, in the order above:
 * each cell is paired with its header the way a reader of the table pairs them,
 * a cell spanning several columns under the first, so where the framework's own
 * cells stand plays no part. A column with no cell of its own reads undefined.
 *
 * @param nodeId The row's node, its key.
 */
function cells(nodeId: string): (string | undefined)[] {
  const row = rowOf(nodeId)
  const headers = [
    ...(row.closest('table')?.querySelectorAll('thead th') ?? []),
  ].map((header) => header.textContent?.trim() ?? '')
  const read = new Map<string, string>()
  let column = 0
  for (const cell of row.querySelectorAll<HTMLTableCellElement>(
    ':scope > td',
  )) {
    read.set(headers[column] ?? '', cell.textContent?.trim() ?? '')
    column += cell.colSpan
  }
  return COLUMNS.map((name) => read.get(name))
}

function tabLabels(): string[] {
  return [...(byId('hilos-page-tabs')?.querySelectorAll('a') ?? [])].map(
    (link) => link.textContent ?? '',
  )
}

function expectReadOnly(): void {
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
    expect(document.querySelector(selector), selector).toBeNull()
  }
}

describe('HilosMaintenanceNodesPage', () => {
  it('is the public section entry with the shared head and four named read-only columns', () => {
    expect(exportedPage).toBe(HilosMaintenanceNodesPage)
    const { context } = seededContext([node('node-1')])
    mountPage(context)

    expect(byId('hilos-admin-page')?.getAttribute('data-page')).toBe(
      HilosPages.MAINTENANCE_NODES,
    )
    expect(
      [...document.querySelectorAll('h1')].map((h) => h.textContent),
    ).toEqual(['Maintenance'])
    expect(byId('hilos-breadcrumb')?.textContent).toContain('Maintenance')
    const tabs = byId('hilos-page-tabs')
    expect(tabs?.getAttribute('aria-label')).toBe('Maintenance pages')
    expect(tabs?.querySelector('[aria-current="page"]')?.textContent).toBe(
      'Nodes',
    )
    expect(tabLabels()).toEqual(['Overview', 'Circle', 'Words', 'Nodes'])

    const title = byId('hilos-table-title')
    expect(title?.textContent?.trim()).toBe(HILOS_MAINTENANCE_NODES_COPY.title)
    const table = document.querySelector<HTMLElement>(
      '[data-id="hilos-maintenance-nodes-table"] table',
    )
    expect(table?.getAttribute('aria-labelledby')).toBe(title?.id)
    for (const name of COLUMNS) {
      expect(
        within(table!).getAllByRole('columnheader', { name }),
        name,
      ).toHaveLength(1)
    }
    expectReadOnly()
  })

  it('shows inactive reports with an unknown acknowledgement and the reported leader', () => {
    const { context } = seededContext([
      node('node-1', { isLeader: true }),
      node('node-2'),
    ])
    mountPage(context)

    expect(cells('node-1')).toEqual(['node-1', '—', 'inactive', 'Leader'])
    expect(cells('node-2')).toEqual(['node-2', '—', 'inactive', '—'])
    expect(
      document.querySelector(
        'table [data-id="hilos-maintenance-nodes-row-node-1"]',
      )?.textContent,
    ).toBe('node-1')
  })

  it('shows Yes and No as words and both roles independently', () => {
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
    mountPage(context)

    expect(cells('node-1')).toEqual([
      'node-1',
      'Yes',
      'verifying',
      'Leader · Initiator here',
    ])
    expect(cells('node-2')).toEqual([
      'node-2',
      'Yes',
      'verifying',
      'Initiator here',
    ])
    expect(cells('node-3')).toEqual(['node-3', 'No', 'activating', '—'])
  })

  it('keeps a null phase and acknowledgement unknown even in a current report', () => {
    const { context } = seededContext([node('node-1', { phase: null })])
    mountPage(context)

    expect(cells('node-1')).toEqual(['node-1', '—', '—', '—'])
    expect(
      rowOf('node-1').querySelector('[data-id="hilos-table-row-message"]'),
    ).toBeNull()
  })

  it.each(['missing', 'silent'] as const)(
    'keeps acknowledgement, phase and known roles beside a %s report explanation',
    (reportState) => {
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
      mountPage(context)

      const explanation = HILOS_MAINTENANCE_NODES_COPY[reportState]
      for (const [nodeId, acknowledgement, role] of [
        ['node-1', 'No', 'Initiator here'],
        ['node-2', 'No', 'Leader'],
        ['node-3', 'Yes', 'Leader · Initiator here'],
        ['node-4', '—', null],
      ] as const) {
        const text = role ? `${role} · ${explanation}` : explanation
        expect(cells(nodeId)).toEqual([nodeId, acknowledgement, '—', text])
        const messages = rowOf(nodeId).querySelectorAll(
          '[data-id="hilos-table-row-message"]',
        )
        expect(messages).toHaveLength(1)
        expect(messages[0].textContent?.trim()).toBe(text)
      }
      expect(document.querySelector('[role="alert"]')).toBeNull()
    },
  )

  it.each(['missing', 'silent'] as const)(
    'keeps the facts of the shown row through a live %s report and its recovery',
    (reportState) => {
      const harness = seededContext([
        node('node-1', {
          phase: 'activating',
          quiesced: false,
          isInitiator: true,
        }),
      ])
      mountPage(harness.context)
      const original = rowOf('node-1')
      expect(cells('node-1')).toEqual([
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
      expect(cells('node-1')).toEqual([
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
      expect(cells('node-1')).toEqual([
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
      expect(cells('node-1')).toEqual([
        'node-1',
        'Yes',
        'verifying',
        'Leader · Initiator here',
      ])
      expect(
        rowOf('node-1').querySelector('[data-id="hilos-table-row-message"]'),
      ).toBeNull()
      expect(rowOf('node-1')).toBe(original)
      expect(harness.sendTableViewport).not.toHaveBeenCalled()
    },
  )

  it('takes the Nodes tab from the supplied tabs even when the cluster reports no rows', () => {
    const { context } = seededContext([])
    const navigation = router()
    mountPage(context, navigation)

    expect(
      byId('hilos-page-tabs')?.querySelector('a[aria-current="page"]')
        ?.textContent,
    ).toBe('Nodes')
    expect(byId('hilos-table-empty-title')?.textContent?.trim()).toBe(
      HILOS_MAINTENANCE_NODES_COPY.empty,
    )

    act(() => navigation.pageIdentity.set(identity(false)))
    expect(tabLabels()).toEqual(['Overview', 'Circle', 'Words'])
  })

  it('shows an admin viewer the same read-only facts', () => {
    const { context } = seededContext([node('node-1', { isLeader: true })])
    context.scopes.session.data.set('adminViewMode', true)
    releases.push(bindAdminAccess(context.scopes))
    mountPage(context)

    expect(cells('node-1')).toEqual(['node-1', '—', 'inactive', 'Leader'])
    expectReadOnly()
  })

  it('binds the initial page window without a request and releases it on unmount', () => {
    const harness = seededContext([node('node-1')])
    expect(harness.registered.size).toBe(0)
    expect(harness.listenerCount()).toBe(0)

    const view = mountPage(harness.context)
    expect(harness.registered).toEqual(new Set([HILOS_MAINTENANCE_NODES_TABLE]))
    expect(harness.listenerCount()).toBeGreaterThan(0)
    expect(cells('node-1')).toEqual(['node-1', '—', 'inactive', '—'])
    expect(harness.sendTableViewport).not.toHaveBeenCalled()
    expect(harness.sendTableFacets).not.toHaveBeenCalled()
    expect(harness.sendAction).not.toHaveBeenCalled()
    expect(harness.dispatch).not.toHaveBeenCalled()

    view.unmount()
    expect(harness.registered.size).toBe(0)
    expect(harness.listenerCount()).toBe(0)
  })
})
