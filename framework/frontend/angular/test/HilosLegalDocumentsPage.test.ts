// The real Angular head, table and links over the Legal Documents core.
import { TestBed } from '@angular/core/testing'
import {
  computedSignal,
  createSignal,
  HilosPages,
  pageIdentity,
  resolveHilosPath,
  ScopeManager,
  type HilosLegalContext,
  type HilosRouter,
  type TableRow,
  type TableViewportController,
} from '@hilos/core/base'
import { HilosLegalDocumentsPage as exportedPage } from '@hilos/angular/admin/legal'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { HilosLegalDocumentsPage } from '../src/admin/legal/HilosLegalDocumentsPage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

afterEach(() => {
  TestBed.resetTestingModule()
  vi.restoreAllMocks()
})

const PAGE = HilosPages.LEGAL_DOCUMENTS
const DOCUMENTS = 'hilosLegalDocuments'
const SETTINGS = 'hilosLegalSettings'
const revision = {
  revisionId: 'current',
  publishedOn: '2026-09-27',
  effectiveOn: '2026-10-01',
  significance: 'substantial',
  setVersion: 1,
  deviationCount: 0,
}
const ROWS: TableRow[] = [
  {
    rowKey: 'terms',
    slots: {
      document: {
        documentLabel: 'Server terms label',
        publishedOn: '2026-09-27',
        declared: true,
        revision,
        covered: 4,
        window: 2,
        lapsed: 1,
      },
    },
  },
  {
    rowKey: 'retired',
    slots: {
      document: {
        documentLabel: 'retired',
        publishedOn: null,
        declared: false,
        revision: null,
        covered: 0,
        window: 0,
        lapsed: 0,
      },
    },
  },
]
type Listener = (signal: { type?: string; data: unknown }) => void

/**
 * The first page answer: both table windows the page subscribes to.
 *
 * @param policy The refusal setting's value, hidden for a viewer.
 * @param rows The document rows of the first window.
 */
function pageAnswer(policy: unknown, rows: TableRow[]) {
  return {
    page: PAGE,
    payload: {
      windows: {
        [DOCUMENTS]: {
          filter: {},
          rows,
          totalCount: rows.length,
          totalExact: true,
          firstAnchor: null,
          lastAnchor: null,
          limit: 25,
          sort: [{ field: 'publishedOn', direction: 'desc' }],
        },
        [SETTINGS]: {
          filter: {},
          rows: [
            {
              rowKey: 'legal.refusal_after_deadline',
              slots: { setting: { value: policy, defaultValue: policy } },
            },
          ],
          totalCount: 1,
          totalExact: true,
          firstAnchor: null,
          lastAnchor: null,
          limit: 25,
          sort: null,
        },
      },
    },
  }
}

/**
 * Real carriers, head and table; the connection replays the first page answer.
 *
 * @param policy The refusal setting's value in the first answer.
 * @param rows The document rows of the first window.
 */
function harness(policy: unknown = 'freeze', rows: TableRow[] = ROWS) {
  const scopes = new ScopeManager()
  const scope = scopes.openPage(PAGE)
  const listeners = new Map<string, Set<Listener>>()
  const registered = new Map<string, TableViewportController<unknown>>()
  const controllers = new Map<string, TableViewportController<unknown>>()
  const sendTableViewport = vi.fn(() => true)
  const sendTableRendered = vi.fn(() => true)
  const dispatch = vi.fn()
  const context = {
    scopes,
    connection: {
      on(event: string, listener: Listener) {
        const set = listeners.get(event) ?? new Set<Listener>()
        listeners.set(event, set)
        set.add(listener)
        if (event === 'projectSignal')
          listener({ type: 'page_response', data: pageAnswer(policy, rows) })
        return () => set.delete(listener)
      },
      registerTableWindow(
        key: string,
        controller: TableViewportController<unknown>,
      ) {
        registered.set(key, controller)
        controllers.set(key, controller)
      },
      unregisterTableWindow(key: string) {
        registered.delete(key)
      },
      sendTableViewport,
      sendTableRendered,
    },
    actions: { dispatch },
  } as unknown as HilosLegalContext
  const identity = pageIdentity(scopes)
  const navigate = vi.fn()
  const router: HilosRouter = {
    currentRoute: createSignal({ page: PAGE, params: {}, admin: true }),
    currentPath: createSignal('/hilos/legal/documents'),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: computedSignal(() => identity.get()),
    dashboardSections: createSignal(undefined),
    resolvePath: resolveHilosPath,
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    onLeave: () => () => {},
    navigate,
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: router }],
  })
  const fixture = TestBed.createComponent(HilosLegalDocumentsPage)
  fixture.componentRef.setInput('context', context)
  fixture.detectChanges()

  return {
    fixture,
    scope,
    registered,
    controllers,
    sendTableViewport,
    sendTableRendered,
    dispatch,
    navigate,
    root: fixture.nativeElement as HTMLElement,
    /** The page answer's identity: the Legal head with its four tabs. */
    identity() {
      scope.data.set('pageLabel', 'Documents')
      scope.data.set('pageLead', 'Declared documents and their tallies.')
      scope.data.set('pageHeadOwner', {
        page: HilosPages.LEGAL,
        label: 'Legal',
        lead: 'Documents people agree to, and who agreed.',
        icon: 'bi-file-earmark-text',
      })
      scope.data.set('pageBreadcrumb', [
        { page: HilosPages.DASHBOARD, label: 'Hilos' },
        { page: HilosPages.LEGAL, label: 'Legal' },
      ])
      scope.data.set('pageTabs', [
        { page: HilosPages.LEGAL, label: 'Overview' },
        { page: PAGE, label: 'Documents' },
        { page: HilosPages.LEGAL_ACCEPTANCES, label: 'Acceptances' },
        { page: HilosPages.LEGAL_SETTINGS, label: 'Settings' },
      ])
      scope.data.set('pageChildren', [])
      fixture.detectChanges()
    },
    /**
     * A later settings window carrying another refusal value.
     *
     * @param value The refusal setting's new value.
     */
    policy(value: unknown) {
      for (const listener of listeners.get('tableWindow') ?? [])
        listener({
          data: {
            page: PAGE,
            tableKey: SETTINGS,
            rows: [
              {
                rowKey: 'legal.refusal_after_deadline',
                slots: { setting: { value, defaultValue: value } },
              },
            ],
            totalCount: 1,
            totalExact: true,
            limit: 25,
            firstAnchor: null,
            lastAnchor: null,
          },
        })
      fixture.detectChanges()
    },
    listenerCount: () =>
      [...listeners.values()].reduce((sum, set) => sum + set.size, 0),
  }
}

type Harness = ReturnType<typeof harness>

function get(root: ParentNode, selector: string): HTMLElement {
  const found = root.querySelector<HTMLElement>(selector)
  expect(found, selector).not.toBeNull()
  return found!
}

function table(h: Harness): HTMLElement {
  return get(h.root, 'table')
}

/** The header labels of the sortable columns, with their sort state. */
function sortHeaders(h: Harness): [string, string | null][] {
  return [...table(h).querySelectorAll('th[aria-sort]')].map((header) => [
    header.querySelector('button')?.textContent?.trim() ?? '',
    header.getAttribute('aria-sort'),
  ])
}

describe('HilosLegalDocumentsPage', () => {
  it('exports the page and draws the first server window under the Legal head without a mount request', () => {
    expect(exportedPage).toBe(HilosLegalDocumentsPage)
    const h = harness()
    h.identity()
    try {
      expect([...h.registered.keys()].sort()).toEqual([DOCUMENTS, SETTINGS])
      expect(h.sendTableViewport).not.toHaveBeenCalled()
      expect(h.dispatch).not.toHaveBeenCalled()
      expect(
        new Set(
          h.sendTableRendered.mock.calls.map(
            (call) => (call as unknown[])[0] as string,
          ),
        ),
      ).toEqual(new Set([PAGE]))

      expect(h.root.querySelectorAll('h1')).toHaveLength(1)
      const heading = get(h.root, 'h1')
      expect(heading.textContent?.trim()).toBe('Legal')
      const tabs = get(h.root, 'nav[aria-label="Page sections"]')
      expect(
        [...tabs.querySelectorAll('a')].map((link) => link.textContent?.trim()),
      ).toEqual(['Overview', 'Documents', 'Acceptances', 'Settings'])
      expect(tabs.querySelectorAll('[aria-current="page"]')).toHaveLength(1)
      expect(get(tabs, '[aria-current="page"]').textContent?.trim()).toBe(
        'Documents',
      )
      expect(h.root.querySelectorAll('table')).toHaveLength(1)
      expect(table(h).getAttribute('aria-labelledby')).toBe(heading.id)

      expect(
        get(h.root, '[data-id="hilos-table-search"]').getAttribute(
          'aria-label',
        ),
      ).toBe('Document or key')
      expect(sortHeaders(h)).toEqual([
        ['Document', 'none'],
        ['Revision', 'descending'],
        ['Covered', 'none'],
        ['In the window', 'none'],
        ['Frozen', 'none'],
      ])

      const body = [...table(h).querySelectorAll('tbody tr')]
      expect(
        body.map((row) =>
          get(row, '[data-id="legal-document-row"]').textContent?.trim(),
        ),
      ).toEqual(['Server terms label', 'retired'])
      expect(
        body.map((row) =>
          get(row, '[data-id="legal-document-row"]').getAttribute(
            'data-document',
          ),
        ),
      ).toEqual(['terms', 'retired'])
      expect(body[0]!.textContent).toContain('terms')
      expect(body[0]!.textContent).not.toContain('Not in code')
      expect(body[0]!.textContent).toContain('2026-09-27 · substantial')
      expect(body[0]!.textContent).toContain('Effective from 2026-10-01')
      expect(
        get(body[0]!, '[data-id="legal-count-covered"]').textContent?.trim(),
      ).toBe('4')
      expect(
        get(body[0]!, '[data-id="legal-count-window"]').textContent?.trim(),
      ).toBe('2')
      expect(
        get(body[0]!, '[data-id="legal-document-open"]').getAttribute('href'),
      ).toBe('/hilos/legal/document/terms')
      expect(body[1]!.textContent).toContain('retired')
      expect(body[1]!.textContent?.match(/Not in code/g) ?? []).toHaveLength(2)
      expect(
        get(body[1]!, '[data-id="legal-document-open"]').getAttribute('href'),
      ).toBe('/hilos/legal/document/retired')
      expect(table(h).querySelector('tbody')?.textContent).not.toContain(
        'Frozen',
      )

      get(h.root, '[data-id="hilos-table-sort-documentLabel"]').click()
      h.fixture.detectChanges()
      expect(h.sendTableViewport).toHaveBeenLastCalledWith(
        PAGE,
        DOCUMENTS,
        expect.objectContaining({
          sort: [{ field: 'documentLabel', direction: 'asc' }],
        }),
      )

      get(body[0]!, '[data-id="legal-document-open"]').click()
      expect(h.navigate).toHaveBeenCalledWith('/hilos/legal/document/terms')
    } finally {
      h.fixture.destroy()
    }
    expect(h.registered.size).toBe(0)
    expect(h.listenerCount()).toBe(0)
    for (const controller of h.controllers.values())
      expect(controller.rows.get()).toEqual([])
  })

  it('makes every sortable column of the five sortable on the server', () => {
    const h = harness()
    try {
      for (const field of [
        'documentLabel',
        'publishedOn',
        'covered',
        'window',
        'lapsed',
      ]) {
        get(h.root, `[data-id="hilos-table-sort-${field}"]`).click()
        h.fixture.detectChanges()
        expect(h.sendTableViewport.mock.calls.at(-1)).toEqual([
          PAGE,
          DOCUMENTS,
          expect.objectContaining({
            sort: [expect.objectContaining({ field })],
          }),
        ])
      }
    } finally {
      h.fixture.destroy()
    }
  })

  it.each([
    ['document', '/hilos/legal/document/document'],
    ['documents', '/hilos/legal/document/documents'],
    ['acceptances', '/hilos/legal/document/acceptances'],
    ['settings', '/hilos/legal/document/settings'],
    ['terms /?#%', '/hilos/legal/document/terms%20%2F%3F%23%25'],
    ['terms%2Fprivacy', '/hilos/legal/document/terms%252Fprivacy'],
  ])('opens the actual row key %s through the shared resolver', (key, path) => {
    const h = harness('freeze', [{ ...ROWS[1]!, rowKey: key }])
    try {
      const opens = [
        ...h.root.querySelectorAll('[data-id="legal-document-open"]'),
      ]
      expect(opens.length).toBeGreaterThan(0)
      for (const open of opens) {
        expect(open.getAttribute('data-document')).toBe(key)
        expect(open.getAttribute('href')).toBe(path)
      }
    } finally {
      h.fixture.destroy()
    }
  })

  it('links the past-deadline count to the people it counts, only when there are any (HIL-945)', () => {
    const h = harness(
      'freeze',
      [
        ['terms', 'Terms of use', 2],
        ['privacy', 'Privacy policy', 0],
      ].map(([rowKey, documentLabel, lapsed]) => ({
        rowKey: rowKey as string,
        slots: {
          document: {
            documentLabel,
            publishedOn: '2026-09-27',
            declared: true,
            revision,
            covered: 1,
            window: 0,
            lapsed,
          },
        },
      })),
    )
    try {
      // The table draws each cell twice — its rows and its narrow-screen
      // cards — so every copy is checked, not the first.
      const links = [
        ...h.root.querySelectorAll('[data-id="legal-count-lapsed-link"]'),
      ]
      expect(links.length).toBeGreaterThan(0)
      for (const link of links) {
        expect(link.getAttribute('href')).toBe('/hilos/users/terms')
        expect(link.getAttribute('data-document')).toBe('terms')
        expect(
          link.querySelector('[data-id="legal-count-lapsed"]')?.textContent,
        ).toBe('2')
      }
      const unlinked = [
        ...h.root.querySelectorAll('[data-id="legal-count-lapsed"]'),
      ]
        .filter((count) => count.closest('a') === null)
        .map((count) => count.textContent)
      expect(unlinked.length).toBeGreaterThan(0)
      expect(new Set(unlinked)).toEqual(new Set(['0']))
    } finally {
      h.fixture.destroy()
    }
  })

  it('names the past-deadline column by the policy and says "Past deadline" while it is hidden (HIL-1260), never in the cells', () => {
    const h = harness('remind')
    try {
      for (const [value, label] of [
        ['freeze', 'Frozen'],
        ['remind', 'Past deadline'],
        [{ _hidden: true }, 'Past deadline'],
      ] as const) {
        h.policy(value)
        expect(sortHeaders(h).at(-1)?.[0]).toBe(label)
        for (const cell of h.root.querySelectorAll(
          '[data-id="legal-count-lapsed-link"]',
        )) {
          expect(cell.textContent?.trim()).toBe('1')
          expect(cell.parentElement?.textContent?.trim()).toBe('1')
        }
        expect(table(h).querySelector('tbody')?.textContent).not.toContain(
          label,
        )
      }
      expect(h.sendTableViewport).not.toHaveBeenCalled()
    } finally {
      h.fixture.destroy()
    }
  })

  it('uses the standard empty state', () => {
    const h = harness('freeze', [])
    try {
      expect(h.root.textContent).toContain(
        'The project has not declared any documents.',
      )
      expect(h.sendTableViewport).not.toHaveBeenCalled()
    } finally {
      h.fixture.destroy()
    }
  })

  it('shows a catalog refusal as text in place of the table and brings the table back when it clears', () => {
    const h = harness()
    try {
      h.scope.data.set('legalCatalogRefusal', '<b>Broken legal catalog</b>')
      h.fixture.detectChanges()
      expect(h.root.querySelectorAll('[role="alert"]')).toHaveLength(1)
      const alert = get(h.root, '[role="alert"]')
      expect(alert.textContent).toContain('<b>Broken legal catalog</b>')
      expect(alert.querySelector('b')).toBeNull()
      expect(h.root.querySelector('table')).toBeNull()

      h.scope.data.set('legalCatalogRefusal', null)
      h.fixture.detectChanges()
      expect(h.root.querySelector('[role="alert"]')).toBeNull()
      expect(
        [
          ...table(h).querySelectorAll('tbody [data-id="legal-document-row"]'),
        ].map((row) => row.textContent?.trim()),
      ).toEqual(['Server terms label', 'retired'])
    } finally {
      h.fixture.destroy()
    }
    expect(h.registered.size).toBe(0)
    expect(h.listenerCount()).toBe(0)
  })
})
