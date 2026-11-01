// The real Angular Words tab of Maintenance over the core words table: its
// window bound to Words and drawn from the page's own answer, an operation with
// and without an entry of its own, words from the registry kept as plain text,
// a table that reads and never writes, what leaving the page releases, and the
// shared section head.
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import { describe, expect, it, vi } from 'vitest'
import { HilosPages, ScopeManager, createSignal } from '@hilos/core'
import type {
  HilosPageIdentity,
  HilosRouter,
  PageRouteMatch,
  TableViewportController,
} from '@hilos/core'
import {
  HILOS_MAINTENANCE_WORDS_COPY,
  type HilosMaintenanceContext,
} from '@hilos/core/admin/maintenance'
import { HilosMaintenanceWordsPage as exportedPage } from '@hilos/angular/admin/maintenance'

import { HilosMaintenanceWordsPage } from '../src/admin/maintenance/HilosMaintenanceWordsPage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

const WORDS_TABLE = 'hilosMaintenanceWords'
const MAINTENANCE_LEAD =
  'Node freeze under a destructive operation and manual maintenance: its state, the verifier circle, closing the system to visitors, and the way out of the window.'

/** The router of a tab standing on Words, with the identity its answer carries. */
function router(): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.MAINTENANCE_WORDS,
      params: {},
      admin: true,
    }),
    currentPath: createSignal('/hilos/maintenance/words'),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal<HilosPageIdentity | undefined>({
      label: 'Words',
      lead: 'Words used to announce maintenance on visitor and admin screens.',
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
      pageTabs: [
        { page: HilosPages.MAINTENANCE, label: 'Overview' },
        { page: HilosPages.MAINTENANCE_CIRCLE, label: 'Circle' },
        { page: HilosPages.MAINTENANCE_WORDS, label: 'Words' },
        { page: HilosPages.MAINTENANCE_NODES, label: 'Nodes' },
      ],
    }),
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

interface Stub {
  operation: string
  title?: string | null
  message?: string | null
  bannerMessage?: string | null
  usesDefault?: boolean
}

/** The wire row of one operation: the name is the row key, the rest rides the slot. */
function wireRow(stub: Stub): {
  rowKey: string
  slots: Record<string, unknown>
} {
  return {
    rowKey: stub.operation,
    slots: {
      stub: {
        operation: stub.operation,
        title: stub.title ?? null,
        message: stub.message ?? null,
        bannerMessage: stub.bannerMessage ?? null,
        usesDefault: stub.usesDefault ?? false,
      },
    },
  }
}

/**
 * A context whose connection replays Words' own page answer, carrying the first
 * window, to every listener of project signals — the window is never asked for on
 * mount. Only transport is stubbed: the page uses the real table and binder.
 *
 * @param initial The operations of the first window.
 */
function seededContext(initial: Stub[]): {
  context: HilosMaintenanceContext
  registered: Map<string, TableViewportController<unknown>>
  listenerCount: () => number
} {
  const registered = new Map<string, TableViewportController<unknown>>()
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.MAINTENANCE_WORDS)
  const listeners = new Map<string, Set<(signal: { data: unknown }) => void>>()
  const pageAnswer = () => ({
    type: 'page_response',
    data: {
      page: HilosPages.MAINTENANCE_WORDS,
      payload: {
        windows: {
          [WORDS_TABLE]: {
            rows: initial.map(wireRow),
            totalCount: initial.length,
            totalExact: true,
            firstAnchor: null,
            lastAnchor: null,
            offset: 0,
            limit: 10,
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
    sendTableViewport: () => true,
    sendTableRendered: () => true,
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
        dispatch: () => {
          throw new Error('Words has no actions')
        },
      } as unknown as HilosMaintenanceContext['actions'],
    },
    registered,
    listenerCount: () =>
      [...listeners.values()].reduce((count, bucket) => count + bucket.size, 0),
  }
}

function mountPage(
  context: HilosMaintenanceContext,
): ComponentFixture<HilosMaintenanceWordsPage> {
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: router() }],
  })
  const fixture = TestBed.createComponent(HilosMaintenanceWordsPage)
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

/** The text of every cell of a row of the table, the merged tail counted once. */
function cells(operation: string): string[] {
  return [
    ...document.querySelectorAll(
      `table [data-id="hilos-table-row-${operation}"] td`,
    ),
  ]
    .slice(0, -1)
    .map((cell) => cell.textContent?.trim() ?? '')
}

const OWN: Stub = {
  operation: 'manual_maintenance',
  title: 'Closed for maintenance',
  message: 'The application is closed for maintenance.',
  bannerMessage: 'Manual maintenance is on.',
}

const FALLBACK: Stub = { operation: 'restore', usesDefault: true }

describe('HilosMaintenanceWordsPage', () => {
  it('is exported by the maintenance entry and draws the first window of its own answer without asking for one', () => {
    expect(exportedPage).toBe(HilosMaintenanceWordsPage)
    const { context, registered } = seededContext([OWN])
    const viewport = vi.spyOn(context.connection, 'sendTableViewport')
    const rendered = vi.spyOn(context.connection, 'sendTableRendered')
    mountPage(context)

    expect([...registered.keys()]).toEqual([WORDS_TABLE])
    expect(viewport).not.toHaveBeenCalled()
    expect(cells('manual_maintenance')).toEqual([
      'manual_maintenance',
      'Closed for maintenance',
      'The application is closed for maintenance.',
      'Manual maintenance is on.',
    ])
    expect(rendered).toHaveBeenCalled()
    for (const [page] of rendered.mock.calls as unknown as [string][]) {
      expect(page).toBe(HilosPages.MAINTENANCE_WORDS)
    }
  })

  it('names the table by its title and its four columns', () => {
    const { context } = seededContext([OWN])
    mountPage(context)

    const table = document.querySelector(
      '[data-id="hilos-maintenance-words-table"] table',
    )
    const title = document.querySelector('[data-id="hilos-table-title"]')
    expect(title?.textContent?.trim()).toBe(HILOS_MAINTENANCE_WORDS_COPY.title)
    expect(table?.getAttribute('aria-labelledby')).toBe(title?.id)
    expect(
      [...(table?.querySelectorAll('thead th') ?? [])]
        .slice(0, -1)
        .map((cell) => cell.textContent?.trim()),
    ).toEqual(['Operation', 'Title', 'Message', 'Banner'])
  })

  it('says an operation without an entry of its own once, in place of the fields after its name', () => {
    const { context } = seededContext([OWN, FALLBACK])
    mountPage(context)

    expect(
      document
        .querySelector('table [data-id="hilos-maintenance-words-row-restore"]')
        ?.textContent?.trim(),
    ).toBe('restore')
    const messages = document.querySelectorAll(
      'table [data-id="hilos-table-row-message"]',
    )
    expect(messages).toHaveLength(1)
    expect(messages[0]?.textContent?.trim()).toBe(
      HILOS_MAINTENANCE_WORDS_COPY.noOwnEntry,
    )
    expect(messages[0]?.getAttribute('colspan')).toBe('3')
    expect(cells('restore')).toEqual([
      'restore',
      HILOS_MAINTENANCE_WORDS_COPY.noOwnEntry,
    ])
    // The row that has its own words shows no stand-in at all.
    expect(
      document.querySelector(
        'table [data-id="hilos-table-row-manual_maintenance"] [data-id="hilos-table-row-message"]',
      ),
    ).toBeNull()
    // The card a row becomes on a narrow screen says the same.
    expect(
      document.querySelector(
        '[data-id="hilos-table-cards"] [data-id="hilos-table-row-message"]',
      )?.textContent,
    ).toContain(HILOS_MAINTENANCE_WORDS_COPY.noOwnEntry)
  })

  it('keeps an empty field of an own entry empty instead of filling it in', () => {
    const { context } = seededContext([
      { operation: 'backup', title: 'Backing up', message: null },
    ])
    mountPage(context)

    expect(cells('backup')).toEqual(['backup', 'Backing up', '', ''])
    expect(
      document.querySelector('table [data-id="hilos-table-row-message"]'),
    ).toBeNull()
  })

  it('draws words from the registry as plain text, markup and long words included', () => {
    const markup = '<img src=x onerror="window.pwned = 1"> & <b>bold</b>'
    const long = 'Unbroken'.repeat(40)
    const { context } = seededContext([
      {
        operation: 'upgrade',
        title: markup,
        message: long,
        bannerMessage: '{{ 1 + 1 }}',
      },
    ])
    mountPage(context)

    const row = document.querySelector(
      'table [data-id="hilos-table-row-upgrade"]',
    )
    expect(cells('upgrade')).toEqual(['upgrade', markup, long, '{{ 1 + 1 }}'])
    expect(row?.querySelector('img, b')).toBeNull()
    expect((window as { pwned?: number }).pwned).toBeUndefined()
  })

  it('offers nothing to write, search, filter, select or sort', () => {
    const { context } = seededContext([OWN, FALLBACK])
    mountPage(context)

    // Not one control anywhere on the page: no field to type or search in, no
    // choice, no row to tick, and no button at all — not even a sort.
    for (const role of [
      'textbox',
      'searchbox',
      'combobox',
      'checkbox',
      'button',
      'dialog',
    ] as const) {
      expect(byRole(document, role), role).toEqual([])
    }
    expect(document.querySelectorAll('th[aria-sort]')).toHaveLength(0)
    for (const table of document.querySelectorAll<HTMLElement>('table')) {
      expect(byRole(table, 'link')).toEqual([])
    }
  })

  it('says there are no words when the registry is empty', () => {
    const { context } = seededContext([])
    mountPage(context)

    expect(
      document
        .querySelector('[data-id="hilos-table-empty-title"]')
        ?.textContent?.trim(),
    ).toBe(HILOS_MAINTENANCE_WORDS_COPY.empty)
  })

  it('binds the table on arrival and releases every listener and registration on leaving', () => {
    const { context, registered, listenerCount } = seededContext([OWN])
    const unregister = vi.spyOn(context.connection, 'unregisterTableWindow')
    expect(listenerCount()).toBe(0)

    mountPage(context)
    expect(registered.has(WORDS_TABLE)).toBe(true)
    expect(listenerCount()).toBeGreaterThan(0)

    leavePage()
    expect(registered.size).toBe(0)
    expect(unregister).toHaveBeenCalledOnce()
    expect(listenerCount()).toBe(0)
  })

  it('binds the table of a new context and lets the old one go', () => {
    const first = seededContext([OWN])
    const second = seededContext([FALLBACK])
    const fixture = mountPage(first.context)
    expect(cells('manual_maintenance')).toHaveLength(4)

    fixture.componentRef.setInput('context', second.context)
    fixture.detectChanges()

    expect(first.registered.size).toBe(0)
    expect(first.listenerCount()).toBe(0)
    expect(second.registered.has(WORDS_TABLE)).toBe(true)
    expect(cells('restore')).toHaveLength(2)
    expect(
      document.querySelector(
        'table [data-id="hilos-table-row-manual_maintenance"]',
      ),
    ).toBeNull()
  })
})

describe('HilosMaintenanceWordsPage head', () => {
  it('shares the Maintenance section head and selects only Words', () => {
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
    expect(current?.[0]?.textContent?.trim()).toBe('Words')
  })
})
