// The React Maintenance Words tab over the real core table factory, binder, scopes,
// shared section head and viewport table; only the transport is stubbed.
import { act, cleanup, render, within } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
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
  HILOS_MAINTENANCE_WORDS_COPY,
  HILOS_MAINTENANCE_WORDS_TABLE,
  type HilosMaintenanceContext,
  type HilosMaintenanceWordsRow,
} from '@hilos/core/admin/maintenance'
import { HilosMaintenanceWordsPage as exportedPage } from '@hilos/react/admin/maintenance'

import { HilosMaintenanceWordsPage } from '../src/admin/maintenance/HilosMaintenanceWordsPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

afterEach(() => {
  cleanup()
  vi.restoreAllMocks()
})

type Listener = (signal: { type?: string; data: unknown }) => void

const paths = createAppPageRouter({})

function identity(): HilosPageIdentity {
  return {
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
      lead: 'Node freeze and manual maintenance.',
      icon: 'bi-tools',
    },
    pageTabs: [
      { page: HilosPages.MAINTENANCE, label: 'Overview' },
      { page: HilosPages.MAINTENANCE_CIRCLE, label: 'Circle' },
      { page: HilosPages.MAINTENANCE_WORDS, label: 'Words' },
    ],
  }
}

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
  }
}

function stub(
  operation: string,
  overrides: Partial<HilosMaintenanceWordsRow> = {},
): HilosMaintenanceWordsRow {
  return {
    operation,
    title: null,
    message: null,
    bannerMessage: null,
    usesDefault: false,
    ...overrides,
  }
}

function wireRow(row: HilosMaintenanceWordsRow) {
  return { rowKey: row.operation, slots: { stub: { ...row } } }
}

/** Only transport is stubbed; the page uses the real table and binder. */
function seededContext(initial: HilosMaintenanceWordsRow[] = []) {
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.MAINTENANCE_WORDS)
  const listeners = new Map<string, Set<Listener>>()
  const registered = new Set<string>()
  const sendTableViewport = vi.fn(() => true)
  const sendTableFacets = vi.fn(() => true)
  const sendAction = vi.fn(() => true)
  const dispatch = vi.fn(() => {
    throw new Error('Words has no actions')
  })
  const pageWindow = (rows: HilosMaintenanceWordsRow[]) => ({
    page: HilosPages.MAINTENANCE_WORDS,
    payload: {
      windows: {
        [HILOS_MAINTENANCE_WORDS_TABLE]: {
          rows: rows.map(wireRow),
          totalCount: rows.length,
          totalExact: true,
          firstAnchor: null,
          lastAnchor: null,
          rowsBefore: 0,
          limit: 25,
          // The words table declares no order of its own, and the server says so.
          sort: [],
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
  }
}

function mountPage(context: HilosMaintenanceContext) {
  let view!: ReturnType<typeof render>
  act(() => {
    view = render(
      <HilosRouterContext.Provider value={router()}>
        <HilosMaintenanceWordsPage context={context} />
      </HilosRouterContext.Provider>,
    )
  })
  return view
}

function byId(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

/** The page's own columns, by the header a reader hears for each. */
const COLUMNS = ['Operation', 'Title', 'Message', 'Banner']

/**
 * What a row shows under each of the page's columns, each cell paired with its
 * header the way a reader of the table pairs them: a cell spanning several
 * columns is read under the first, and a column it covers has no entry of its
 * own. Where the framework's own cells stand plays no part.
 *
 * @param operation The row's operation, its key.
 */
function cells(operation: string): Record<string, string> {
  const row = document.querySelector(
    `table [data-id="hilos-table-row-${operation}"]`,
  )
  expect(row, operation).not.toBeNull()
  const headers = [
    ...(row!.closest('table')?.querySelectorAll('thead th') ?? []),
  ].map((header) => header.textContent?.trim() ?? '')
  const read: Record<string, string> = {}
  let column = 0
  for (const cell of row!.querySelectorAll<HTMLTableCellElement>(
    ':scope > td',
  )) {
    const header = headers[column] ?? ''
    if (COLUMNS.includes(header)) {
      read[header] = cell.textContent?.trim() ?? ''
    }
    column += cell.colSpan
  }
  return read
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
    'table tbody input',
  ]) {
    expect(document.querySelector(selector), selector).toBeNull()
  }
}

describe('HilosMaintenanceWordsPage', () => {
  it('is the public section entry and stands under the shared Maintenance head', () => {
    expect(exportedPage).toBe(HilosMaintenanceWordsPage)
    const { context } = seededContext([stub('backup')])
    mountPage(context)

    expect(byId('hilos-admin-page')?.getAttribute('data-page')).toBe(
      HilosPages.MAINTENANCE_WORDS,
    )
    expect(
      [...document.querySelectorAll('h1')].map((h) => h.textContent),
    ).toEqual(['Maintenance'])
    expect(byId('hilos-breadcrumb')?.textContent).toContain('Maintenance')
    const tabs = byId('hilos-page-tabs')
    expect(tabs?.getAttribute('aria-label')).toBe('Maintenance pages')
    expect(
      [...(tabs?.querySelectorAll('a') ?? [])].map((link) => [
        link.textContent,
        link.getAttribute('href'),
      ]),
    ).toEqual([
      ['Overview', '/hilos/maintenance'],
      ['Circle', '/hilos/maintenance/circle'],
      ['Words', '/hilos/maintenance/words'],
    ])
    expect(tabs?.querySelector('[aria-current="page"]')?.textContent).toBe(
      'Words',
    )

    const title = byId('hilos-table-title')
    expect(title?.textContent?.trim()).toBe(HILOS_MAINTENANCE_WORDS_COPY.title)
    const table = document.querySelector<HTMLElement>(
      '[data-id="hilos-maintenance-words-table"] table',
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

  it("draws the project's own words as plain text, however long", () => {
    const longMessage =
      'The shop is closed while the catalog is rebuilt from the supplier feed; ' +
      'orders placed before the freeze are kept and will be confirmed by mail ' +
      'once <b>every</b> node is back.'
    const { context } = seededContext([
      stub('manual_maintenance', {
        title: 'Maintenance in progress',
        message: longMessage,
        bannerMessage: 'Maintenance underway',
      }),
    ])
    mountPage(context)

    expect(
      document.querySelector(
        'table [data-id="hilos-maintenance-words-row-manual_maintenance"]',
      )?.textContent,
    ).toBe('manual_maintenance')
    expect(cells('manual_maintenance')).toEqual({
      Operation: 'manual_maintenance',
      Title: 'Maintenance in progress',
      Message: longMessage,
      Banner: 'Maintenance underway',
    })
    const row = document.querySelector(
      'table [data-id="hilos-table-row-manual_maintenance"]',
    )
    expect(row?.querySelector('b')).toBeNull()
    expect(row?.querySelector('[data-id="hilos-table-row-message"]')).toBeNull()
  })

  it('replaces the tail of an operation without its own entry with the one default phrase', () => {
    const { context } = seededContext([
      stub('backup', { usesDefault: true }),
      stub('restore', { title: 'Restoring', message: 'Back soon.' }),
    ])
    mountPage(context)

    expect(
      document.querySelector(
        'table [data-id="hilos-maintenance-words-row-backup"]',
      )?.textContent,
    ).toBe('backup')
    expect(cells('backup')).toEqual({
      Operation: 'backup',
      Title: HILOS_MAINTENANCE_WORDS_COPY.noOwnEntry,
    })
    const messages = document.querySelectorAll(
      'table [data-id="hilos-table-row-backup"] [data-id="hilos-table-row-message"]',
    )
    expect(messages).toHaveLength(1)
    expect(messages[0].getAttribute('colspan')).toBe('3')
    expect(messages[0].textContent?.trim()).toBe(
      HILOS_MAINTENANCE_WORDS_COPY.noOwnEntry,
    )
    expect(cells('restore')).toEqual({
      Operation: 'restore',
      Title: 'Restoring',
      Message: 'Back soon.',
      Banner: '',
    })
  })

  it('binds the initial page window without a request and releases it on unmount', () => {
    const harness = seededContext([stub('backup', { usesDefault: true })])
    expect(harness.registered.size).toBe(0)
    expect(harness.listenerCount()).toBe(0)

    const view = mountPage(harness.context)
    expect(harness.registered).toEqual(new Set([HILOS_MAINTENANCE_WORDS_TABLE]))
    expect(harness.listenerCount()).toBeGreaterThan(0)
    expect(cells('backup').Operation).toBe('backup')
    expect(harness.sendTableViewport).not.toHaveBeenCalled()
    expect(harness.sendTableFacets).not.toHaveBeenCalled()
    expect(harness.sendAction).not.toHaveBeenCalled()
    expect(harness.dispatch).not.toHaveBeenCalled()

    view.unmount()
    expect(harness.registered.size).toBe(0)
    expect(harness.listenerCount()).toBe(0)
  })
})
