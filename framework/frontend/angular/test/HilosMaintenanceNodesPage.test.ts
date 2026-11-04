// The real Angular Nodes tab of Maintenance over the core cluster reports: its
// table bound to Nodes and drawn from the page's own answer, what each node
// reports (acknowledged, not acknowledged, not known), both roles, a report that
// is missing or silent, a shown row replaced live, a table that reads and never
// writes — for an administrator and for a viewer of the admin view mode alike —
// what leaving the page releases, and the shared section head.
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  HilosPages,
  ScopeManager,
  TABLE_ORDER_COPY,
  bindAdminAccess,
  bindSessionScope,
  createSignal,
} from '@hilos/core'
import type {
  HilosConnection,
  HilosPageIdentity,
  HilosRouter,
  PageRouteMatch,
  ProjectSignal,
  TableViewportController,
  WritableSignal,
} from '@hilos/core'
import {
  HILOS_MAINTENANCE_NODES_COPY,
  type HilosMaintenanceContext,
  type HilosMaintenanceNodeRow,
} from '@hilos/core/admin/maintenance'
import { HilosMaintenanceNodesPage as exportedPage } from '@hilos/angular/admin/maintenance'

import { HilosMaintenanceNodesPage } from '../src/admin/maintenance/HilosMaintenanceNodesPage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

const NODES_TABLE = 'hilosMaintenanceNodes'
const MAINTENANCE_LEAD =
  'Node freeze under a destructive operation and manual maintenance: its state, the verifier circle, closing the system to visitors, and the way out of the window.'

const OVERVIEW_TAB = { page: HilosPages.MAINTENANCE, label: 'Overview' }
const CIRCLE_TAB = { page: HilosPages.MAINTENANCE_CIRCLE, label: 'Circle' }
const WORDS_TAB = { page: HilosPages.MAINTENANCE_WORDS, label: 'Words' }
const NODES_TAB = { page: HilosPages.MAINTENANCE_NODES, label: 'Nodes' }

function identity(tabs: HilosPageIdentity['pageTabs']): HilosPageIdentity {
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
      lead: MAINTENANCE_LEAD,
      icon: 'bi-tools',
    },
    pageTabs: tabs,
  }
}

/**
 * The router of a tab standing on Nodes. The tabs are what the server's answer
 * carried: Nodes is among them in cluster mode only.
 */
function router(
  tabs: HilosPageIdentity['pageTabs'] = [
    OVERVIEW_TAB,
    CIRCLE_TAB,
    WORDS_TAB,
    NODES_TAB,
  ],
): HilosRouter & {
  pageIdentity: WritableSignal<HilosPageIdentity | undefined>
} {
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
    pageIdentity: createSignal<HilosPageIdentity | undefined>(identity(tabs)),
    dashboardSections: createSignal(undefined),
    resolvePath: (page) =>
      (
        ({
          [HilosPages.DASHBOARD]: '/hilos',
          [HilosPages.MAINTENANCE]: '/hilos/maintenance',
          [HilosPages.MAINTENANCE_CIRCLE]: '/hilos/maintenance/circle',
          [HilosPages.MAINTENANCE_WORDS]: '/hilos/maintenance/words',
          [HilosPages.MAINTENANCE_NODES]: '/hilos/maintenance/nodes',
        }) as Record<string, string>
      )[page],
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

/** One node's report; unless said otherwise, an idle one that reported just now. */
function node(
  nodeId: string,
  overrides: Partial<HilosMaintenanceNodeRow> = {},
): HilosMaintenanceNodeRow {
  return {
    nodeId,
    nodeName: null,
    phase: 'inactive',
    quiesced: null,
    isLeader: false,
    isInitiator: false,
    reportState: 'current',
    ...overrides,
  }
}

function wireRow(row: HilosMaintenanceNodeRow): {
  rowKey: string
  slots: Record<string, unknown>
} {
  return { rowKey: row.nodeId, slots: { node: { ...row } } }
}

/**
 * A context whose connection replays Nodes' own page answer, carrying the first
 * window, to every listener of project signals — the window is never asked for on
 * mount. Only transport is stubbed: the page uses the real table and binder.
 *
 * @param initial The reports of the first window.
 */
function seededContext(initial: HilosMaintenanceNodeRow[]): {
  context: HilosMaintenanceContext
  registered: Map<string, TableViewportController<unknown>>
  listenerCount: () => number
  dispatch: ReturnType<typeof vi.fn>
  updateRow: (row: HilosMaintenanceNodeRow) => void
} {
  const registered = new Map<string, TableViewportController<unknown>>()
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.MAINTENANCE_NODES)
  const listeners = new Map<string, Set<(signal: { data: unknown }) => void>>()
  const dispatch = vi.fn(() => {
    throw new Error('Nodes has no actions')
  })
  const pageAnswer = () => ({
    type: 'page_response',
    data: {
      page: HilosPages.MAINTENANCE_NODES,
      payload: {
        windows: {
          [NODES_TABLE]: {
            filter: {},
            rows: initial.map(wireRow),
            totalCount: initial.length,
            totalExact: true,
            firstAnchor: initial.length
              ? { nodeName: initial[0].nodeName, nodeId: initial[0].nodeId }
              : null,
            lastAnchor: initial.length
              ? {
                  nodeName: initial[initial.length - 1].nodeName,
                  nodeId: initial[initial.length - 1].nodeId,
                }
              : null,
            rowsBefore: 0,
            limit: 25,
            sort: [{ field: 'nodeId', direction: 'asc' }],
          },
        },
      },
    },
  })

  const connection = {
    registerTableWindow(
      tableKey: string,
      controller: TableViewportController<unknown>,
    ): void {
      registered.set(tableKey, controller)
    },
    unregisterTableWindow(tableKey: string): void {
      registered.delete(tableKey)
    },
    tableWindowDescriptors: () => ({}),
    sendTableViewport: vi.fn(() => true),
    sendTableRendered: vi.fn(() => true),
    on(
      event: string,
      listener: (signal: { data: unknown }) => void,
    ): () => void {
      const bucket = listeners.get(event) ?? new Set()
      listeners.set(event, bucket)
      bucket.add(listener)
      if (event === 'projectSignal') {
        listener(pageAnswer())
      }

      return () => bucket.delete(listener)
    },
  }

  return {
    context: {
      connection:
        connection as unknown as HilosMaintenanceContext['connection'],
      scopes,
      actions: {
        dispatch,
      } as unknown as HilosMaintenanceContext['actions'],
    },
    registered,
    dispatch,
    listenerCount: () =>
      [...listeners.values()].reduce((count, bucket) => count + bucket.size, 0),
    // A shown report replaced by a newer one, as the table delta carries it.
    updateRow(row: HilosMaintenanceNodeRow): void {
      for (const listener of listeners.get('tableViewportDelta') ?? []) {
        listener({
          data: {
            page: HilosPages.MAINTENANCE_NODES,
            tableKey: NODES_TABLE,
            kind: 'row_updated',
            rowKey: row.nodeId,
            row: wireRow(row),
          },
        })
      }
    },
  }
}

function mountPage(
  context: HilosMaintenanceContext,
  navigation: HilosRouter = router(),
): ComponentFixture<HilosMaintenanceNodesPage> {
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: navigation }],
  })
  const fixture = TestBed.createComponent(HilosMaintenanceNodesPage)
  fixture.componentRef.setInput('context', context)
  fixture.detectChanges()

  return fixture
}

/**
 * Leave the page as a route change does: the view is destroyed and its element
 * leaves the document — a destroyed root view alone stays drawn.
 */
function leavePage(): void {
  TestBed.resetTestingModule()
}

/** The row of a node in the table, not in the card the same row becomes. */
function row(nodeId: string): HTMLElement | null {
  return document.querySelector(`table [data-id="hilos-table-row-${nodeId}"]`)
}

/** The roles a read-only surface is checked for, with the elements that imply them. */
const IMPLIED_ROLES = {
  button:
    'button, input[type="button"], input[type="submit"], input[type="reset"], input[type="image"]',
  checkbox: 'input[type="checkbox"]',
  combobox: 'select, input[list]',
  dialog: 'dialog',
  link: 'a[href]',
  searchbox: 'input[type="search"]',
  textbox:
    'textarea, input:not([type]), input[type="text"], input[type="email"], input[type="tel"], input[type="url"]',
} as const

/**
 * The elements under a root that carry an ARIA role — written out, or implied by
 * the element itself — leaving out only what no one can reach: a hidden or an
 * inert subtree.
 *
 * @param root Where to look.
 * @param role The role.
 */
function byRole(
  root: ParentNode,
  role: keyof typeof IMPLIED_ROLES,
): HTMLElement[] {
  return [
    ...root.querySelectorAll<HTMLElement>(
      `${IMPLIED_ROLES[role]}, [role="${role}"]`,
    ),
  ].filter(
    (element) =>
      (element.getAttribute('role') ?? role) === role &&
      element.closest('[inert], [hidden]') === null,
  )
}

/**
 * Nothing on the page writes, searches, filters or selects: no field, no choice,
 * no row to tick and no dialog. The only buttons there are sort by the node: the
 * header of its column, and the order menu that stands in for the headers on a
 * narrow screen, where they are out of reach.
 */
function expectReadOnly(): void {
  for (const role of [
    'textbox',
    'searchbox',
    'combobox',
    'checkbox',
    'dialog',
  ] as const) {
    expect(byRole(document, role), role).toEqual([])
  }
  const buttons = byRole(document, 'button')
  const headers = buttons.filter((button) => button.closest('th') !== null)
  expect(headers.map((button) => button.textContent?.trim())).toEqual(['Node'])
  expect(headers[0]?.closest('th')?.getAttribute('aria-sort')).not.toBeNull()
  const menus = buttons.filter((button) => button.closest('th') === null)
  expect(menus).toHaveLength(1)
  expect(menus[0]?.getAttribute('aria-haspopup')).toBe('listbox')
  const listbox = document.getElementById(
    menus[0]?.getAttribute('aria-controls') ?? '',
  )
  expect(listbox?.getAttribute('aria-label')).toBe(TABLE_ORDER_COPY.menu)
  const orders = [...(listbox?.querySelectorAll('[role="option"]') ?? [])]
  expect(orders.length).toBeGreaterThan(0)
  for (const order of orders) {
    expect(order.textContent).toContain('Node')
  }
  for (const table of document.querySelectorAll<HTMLElement>('table')) {
    expect(byRole(table, 'link')).toEqual([])
  }
}

/** What the four cells of a node's row say — the merged tail counted once. */
function cells(nodeId: string): string[] {
  return [...(row(nodeId)?.querySelectorAll('td') ?? [])]
    .slice(0, -1)
    .map((cell) => cell.textContent?.trim() ?? '')
}

describe('HilosMaintenanceNodesPage', () => {
  it('is exported by the maintenance entry and draws the first window of its own answer without asking for one', () => {
    expect(exportedPage).toBe(HilosMaintenanceNodesPage)
    const { context, registered, dispatch } = seededContext([node('node-1')])
    const viewport = vi.spyOn(context.connection, 'sendTableViewport')
    const rendered = vi.spyOn(context.connection, 'sendTableRendered')
    mountPage(context)

    expect([...registered.keys()]).toEqual([NODES_TABLE])
    expect(viewport).not.toHaveBeenCalled()
    expect(dispatch).not.toHaveBeenCalled()
    expect(cells('node-1')).toEqual(['node-1', '—', 'inactive', '—'])
    expect(rendered).toHaveBeenCalled()
    for (const [page] of rendered.mock.calls as unknown as [string][]) {
      expect(page).toBe(HilosPages.MAINTENANCE_NODES)
    }
  })

  it('names the table by its title and its four columns, and lets only the node be sorted', () => {
    const { context } = seededContext([node('node-1')])
    mountPage(context)

    const table = document.querySelector(
      '[data-id="hilos-maintenance-nodes-table"] table',
    )
    const title = document.querySelector('[data-id="hilos-table-title"]')
    expect(title?.textContent?.trim()).toBe(HILOS_MAINTENANCE_NODES_COPY.title)
    expect(table?.getAttribute('aria-labelledby')).toBe(title?.id)
    // The last header is the row-state column the table adds for itself.
    const headers = [...(table?.querySelectorAll('thead th') ?? [])].slice(
      0,
      -1,
    )
    expect(headers.map((cell) => cell.textContent?.trim())).toEqual([
      'Node',
      'Quiesced',
      'Phase on node',
      'Role',
    ])
    expect(
      headers.map((cell) => cell.getAttribute('aria-sort') !== null),
    ).toEqual([true, false, false, false])
  })

  it('shows idle reports with the acknowledgement not known and the reported leader', () => {
    const { context } = seededContext([
      node('node-1', { isLeader: true }),
      node('node-2'),
    ])
    mountPage(context)

    expect(cells('node-1')).toEqual(['node-1', '—', 'inactive', 'Leader'])
    expect(cells('node-2')).toEqual(['node-2', '—', 'inactive', '—'])
    expect(
      document
        .querySelector('table [data-id="hilos-maintenance-nodes-row-node-1"]')
        ?.textContent?.trim(),
    ).toBe('node-1')
  })

  it('shows the reported name and separate id as text, keeping row addresses and the null-name fallback', () => {
    const harness = seededContext([
      node('node-1', { nodeName: 'Київ <b>主节点</b> & "one"' }),
      node('node-2'),
      node('node-3', { nodeName: '' }),
    ])
    const viewport = vi.spyOn(harness.context.connection, 'sendTableViewport')
    const fixture = mountPage(harness.context)
    const original = row('node-1')
    const label = (nodeId: string) =>
      row(nodeId)?.querySelector(
        `[data-id="hilos-maintenance-nodes-row-${nodeId}"]`,
      )
    const parts = (nodeId: string) =>
      [...(label(nodeId)?.children ?? [])].map((part) =>
        part.textContent?.trim(),
      )

    expect(parts('node-1')).toEqual(['Київ <b>主节点</b> & "one"', 'node-1'])
    expect(parts('node-2')).toEqual(['node-2'])
    expect(parts('node-3')).toEqual(['', 'node-3'])
    expect(label('node-1')?.querySelector('b')).toBeNull()

    harness.updateRow(node('node-1', { nodeName: 'Renamed Ω' }))
    fixture.detectChanges()
    expect(parts('node-1')).toEqual(['Renamed Ω', 'node-1'])
    expect(row('node-1')).toBe(original)

    harness.updateRow(node('node-1'))
    fixture.detectChanges()
    expect(parts('node-1')).toEqual(['node-1'])
    expect(row('node-1')).toBe(original)
    expect(viewport).not.toHaveBeenCalled()
    expect(harness.dispatch).not.toHaveBeenCalled()
  })

  it('tells yes from no and draws both independent roles', () => {
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

  it('keeps an unknown phase and acknowledgement unknown even in a current report', () => {
    const { context } = seededContext([node('node-1', { phase: null })])
    mountPage(context)

    expect(cells('node-1')).toEqual(['node-1', '—', '—', '—'])
    expect(
      document.querySelector('table [data-id="hilos-table-row-message"]'),
    ).toBeNull()
  })

  it.each(['missing', 'silent'] as const)(
    'keeps the acknowledgement, the phase and the known roles beside a %s report and explains it once',
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
        const messages =
          row(nodeId)?.querySelectorAll(
            '[data-id="hilos-table-row-message"]',
          ) ?? []
        expect(messages).toHaveLength(1)
        expect(messages[0]?.textContent?.trim()).toBe(text)
      }
      expect(document.querySelector('[role="alert"]')).toBeNull()
    },
  )

  it.each(['missing', 'silent'] as const)(
    'replaces a shown report in place through a live %s report and its recovery',
    (reportState) => {
      const harness = seededContext([
        node('node-1', {
          phase: 'activating',
          quiesced: false,
          isInitiator: true,
        }),
      ])
      const viewport = vi.spyOn(harness.context.connection, 'sendTableViewport')
      const fixture = mountPage(harness.context)
      const original = row('node-1')
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
      fixture.detectChanges()
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
      fixture.detectChanges()
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
      fixture.detectChanges()
      expect(cells('node-1')).toEqual([
        'node-1',
        'Yes',
        'verifying',
        'Leader · Initiator here',
      ])
      expect(
        document.querySelector('table [data-id="hilos-table-row-message"]'),
      ).toBeNull()
      expect(row('node-1')).toBe(original)
      expect(viewport).not.toHaveBeenCalled()
    },
  )

  it('says there are no nodes when the cluster reports none, and keeps the tab the server gave', () => {
    const { context } = seededContext([])
    const fixture = mountPage(context)
    const root = fixture.nativeElement as HTMLElement

    expect(
      root
        .querySelector('[data-id="hilos-table-empty-title"]')
        ?.textContent?.trim(),
    ).toBe(HILOS_MAINTENANCE_NODES_COPY.empty)
    expect(
      root
        .querySelector('nav[aria-label="Maintenance pages"] a[aria-current]')
        ?.textContent?.trim(),
    ).toBe('Nodes')
  })

  it('offers nothing to write, search, filter or select, and only the node to sort', () => {
    const { context } = seededContext([
      node('node-1', { isLeader: true }),
      node('node-2', { reportState: 'silent' }),
    ])
    mountPage(context)

    expectReadOnly()
  })

  it('binds the table on arrival and releases every listener and registration on leaving', () => {
    const { context, registered, listenerCount } = seededContext([
      node('node-1'),
    ])
    const unregister = vi.spyOn(context.connection, 'unregisterTableWindow')
    expect(listenerCount()).toBe(0)

    mountPage(context)
    expect(registered.has(NODES_TABLE)).toBe(true)
    expect(listenerCount()).toBeGreaterThan(0)

    leavePage()
    expect(registered.size).toBe(0)
    expect(unregister).toHaveBeenCalledOnce()
    expect(listenerCount()).toBe(0)
  })

  it('binds the table of a new context and lets the old one go', () => {
    const first = seededContext([node('node-1')])
    const second = seededContext([node('node-2')])
    const fixture = mountPage(first.context)
    expect(row('node-1')).not.toBeNull()

    fixture.componentRef.setInput('context', second.context)
    fixture.detectChanges()

    expect(first.registered.size).toBe(0)
    expect(first.listenerCount()).toBe(0)
    expect(second.registered.has(NODES_TABLE)).toBe(true)
    expect(row('node-2')).not.toBeNull()
    expect(row('node-1')).toBeNull()
  })
})

describe('HilosMaintenanceNodesPage and the admin view mode', () => {
  const releases: (() => void)[] = []

  afterEach(() => {
    for (const release of releases.splice(0)) release()
  })

  /**
   * Bind the session scope and the admin access the way bootHilos does, over
   * handshakes this harness emits.
   */
  function bindSession() {
    const listeners: ((signal: ProjectSignal) => void)[] = []
    const connection = {
      on(event: string, listener: (payload: never) => void): () => void {
        if (event === 'projectSignal') {
          listeners.push(listener as (signal: ProjectSignal) => void)
        }

        return () => {}
      },
    } as unknown as HilosConnection
    const scopes = new ScopeManager()
    bindSessionScope(connection, scopes)
    releases.push(bindAdminAccess(scopes))

    return {
      /**
       * One handshake: who is behind the session, if anybody, and the node's
       * admin view mode, both as the backend stamps them (HIL-1253).
       *
       * @param user The person behind the session, or null for a guest.
       * @param viewMode The node's admin view mode.
       */
      handshake(
        user: { id: number; admin: boolean } | null,
        viewMode: boolean,
      ): void {
        const signal = {
          kind: 'project',
          type: 'handshake_response',
          data: {
            entities: {
              currentUser: user === null ? null : { ...user, name: 'Olena' },
            },
            data: { adminViewMode: viewMode },
          },
          envelope: {},
        } as unknown as ProjectSignal
        for (const listener of listeners) {
          listener(signal)
        }
      },
    }
  }

  it('renders the same read-only facts for an admin viewer', () => {
    const { handshake } = bindSession()
    handshake(null, true)
    const { context, dispatch } = seededContext([
      node('node-1', { isLeader: true }),
    ])
    mountPage(context)

    expect(cells('node-1')).toEqual(['node-1', '—', 'inactive', 'Leader'])
    expectReadOnly()
    expect(dispatch).not.toHaveBeenCalled()
  })
})

describe('HilosMaintenanceNodesPage head', () => {
  /** The names of the tabs, as a set: the order they are drawn in is not asked. */
  function tabNames(root: HTMLElement): string[] {
    return [...root.querySelectorAll('nav[aria-label="Maintenance pages"] a')]
      .map((link) => link.textContent?.trim() ?? '')
      .sort()
  }

  it('shares the Maintenance section head and selects only Nodes', () => {
    const { context } = seededContext([])
    const fixture = mountPage(context)
    const root = fixture.nativeElement as HTMLElement

    expect(root.querySelectorAll('h1')).toHaveLength(1)
    expect(root.querySelector('h1')?.textContent?.trim()).toBe('Maintenance')
    expect(
      root.querySelector('[data-id="hilos-page-head-block"]')?.textContent,
    ).toContain(MAINTENANCE_LEAD)
    const tabs = root.querySelector('nav[aria-label="Maintenance pages"]')
    const links = [...(tabs?.querySelectorAll('a') ?? [])].map((link) => [
      link.textContent?.trim(),
      link.getAttribute('href'),
    ])
    expect(links).toHaveLength(4)
    expect(links).toEqual(
      expect.arrayContaining([
        ['Overview', '/hilos/maintenance'],
        ['Circle', '/hilos/maintenance/circle'],
        ['Words', '/hilos/maintenance/words'],
        ['Nodes', '/hilos/maintenance/nodes'],
      ]),
    )
    const current = tabs?.querySelectorAll('a[aria-current="page"]')
    expect(current).toHaveLength(1)
    expect(current?.[0]?.textContent?.trim()).toBe('Nodes')
  })

  it('takes whether Nodes is among the tabs from the server and not from the rows', () => {
    const navigation = router()
    // No node reports at all, and the server still names Nodes: it is shown.
    const { context } = seededContext([])
    const fixture = mountPage(context, navigation)
    const root = fixture.nativeElement as HTMLElement
    expect(tabNames(root)).toContain('Nodes')
    expect(tabNames(root)).toEqual(['Circle', 'Nodes', 'Overview', 'Words'])

    // A standalone node: the server leaves Nodes out and the page puts nothing
    // in its place.
    navigation.pageIdentity.set(identity([OVERVIEW_TAB, CIRCLE_TAB, WORDS_TAB]))
    fixture.detectChanges()
    expect(tabNames(root)).not.toContain('Nodes')
    expect(tabNames(root)).toEqual(['Circle', 'Overview', 'Words'])
  })
})
