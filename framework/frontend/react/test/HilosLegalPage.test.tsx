import { afterEach, describe, expect, it, vi } from 'vitest'
import { act, cleanup, render } from '@testing-library/react'
import { HilosRouterContext } from '../src/hilosRouterContext.js'
import {
  ScopeManager,
  createAppPageRouter,
  createSignal,
  HIDDEN_VALUE,
  HilosPages,
  pageIdentity,
  type HilosLegalContext,
  type HilosRouter,
  type TableRow,
} from '@hilos/core/base'
import { HilosLegalPage } from '../src/admin/legal/HilosLegalPage.js'
import { HilosLegalRevisionPage } from '../src/admin/legal/HilosLegalRevisionPage.js'

/** Real reactive page data with the transport replaced at its boundary. */
function harness() {
  const scopes = new ScopeManager()
  const scope = scopes.openPage('hilos_legal')
  const context = {
    scopes,
    connection: {
      on: () => () => {},
      registerTableWindow() {},
      unregisterTableWindow() {},
      sendTableViewport() {},
      sendTableRendered() {},
    },
    actions: {},
  } as unknown as HilosLegalContext
  const router = {
    currentRoute: createSignal({
      page: 'hilos_legal',
      params: { documentKey: 'terms', revisionId: 'current' },
      admin: true,
    }),
    pageIdentity: createSignal(undefined),
    resolvePath: () => undefined,
  } as unknown as HilosRouter
  return { scope, context, router }
}

afterEach(cleanup)
describe('legal admin React pages', () => {
  it.each([HilosLegalPage, HilosLegalRevisionPage])(
    'replaces declaration content with the catalog refusal',
    (View) => {
      const h = harness()
      const view = render(
        <HilosRouterContext.Provider value={h.router}>
          <View context={h.context} />
        </HilosRouterContext.Provider>,
      )
      act(() => {
        h.scope.data.set(
          'legalCatalogRefusal',
          'The catalog names a missing clause',
        )
      })
      expect(
        view.container.querySelector('[data-id="legal-catalog-refusal"]')
          ?.textContent,
      ).toContain('The catalog names a missing clause')
      expect(view.container.querySelector('[data-id="legal-set"]')).toBeNull()
      expect(
        view.container.querySelector('[data-id="legal-revision-text"]'),
      ).toBeNull()
    },
  )
})

const overview = {
  documentCount: 2,
  documents: [
    { document: 'terms', documentLabel: 'Server terms', window: 7, lapsed: 2 },
    {
      document: 'privacy',
      documentLabel: 'Server privacy',
      window: 0,
      lapsed: 0,
    },
  ],
  newerSetCount: 1,
}
const tabs = [
  { page: HilosPages.LEGAL, label: 'Overview' },
  { page: HilosPages.LEGAL_DOCUMENTS, label: 'Documents' },
  { page: HilosPages.LEGAL_ACCEPTANCES, label: 'Acceptances' },
  { page: HilosPages.LEGAL_SETTINGS, label: 'Settings' },
]
const checks = [
  {
    rowKey: 'undeclared_revision',
    ok: false,
    items: [{ document: 'retired', revisionId: 'gone', people: 14 }],
  },
  { rowKey: 'zero_window', ok: true, items: [] },
  {
    rowKey: 'newer_standard_set',
    ok: false,
    items: [
      {
        document: 'terms',
        setVersion: 3,
        documentSetVersion: 1,
        significance: 'substantial',
      },
    ],
  },
  {
    rowKey: 'deviations',
    ok: true,
    items: [{ document: 'terms', deviations: 0 }],
  },
].map(({ rowKey, ...check }) => ({ rowKey, slots: { check } }))

/**
 * The legal root inside the real admin page and its shared head: the first
 * page answer carries the identity, the overview and the two bound windows; no
 * document window feeds Overview.
 *
 * @param summary The `legalOverview` the answer carries.
 * @param checkRows The rows of the checks window.
 * @param refusedTable A window the answer refuses instead of carrying.
 */
function sectionHarness(
  summary: unknown = overview,
  checkRows: TableRow[] = checks,
  refusedTable?: string,
) {
  const page = HilosPages.LEGAL
  const scopes = new ScopeManager()
  const scope = scopes.openPage(page)
  const listeners = new Map<string, Set<(frame: never) => void>>()
  const registered = new Set<string>()
  const sendViewport = vi.fn()
  const dispatch = vi.fn()
  const windowOf = (rows: TableRow[]) => ({
    filter: {},
    rows,
    totalCount: rows.length,
    totalExact: true,
    firstAnchor: null,
    lastAnchor: null,
    limit: 25,
    sort: null,
  })
  const settingsRows = (policy: unknown, consent: unknown) => [
    {
      rowKey: 'legal.consent_form',
      slots: { setting: { value: consent, defaultValue: consent } },
    },
    {
      rowKey: 'legal.refusal_after_deadline',
      slots: { setting: { value: policy, defaultValue: policy } },
    },
  ]
  const windows: Record<string, ReturnType<typeof windowOf>> = {
    hilosLegalChecks: windowOf(checkRows),
    hilosLegalSettings: windowOf(settingsRows('freeze', 'checkbox')),
  }
  if (refusedTable) delete windows[refusedTable]
  const data = {
    pageLabel: 'Overview',
    pageLead: 'This page lead',
    pageChildren: [],
    pageHeadOwner: {
      page: HilosPages.LEGAL,
      label: 'Legal',
      lead: 'What the project declares, and who accepted it.',
      icon: 'bi-file-earmark-check',
    },
    pageBreadcrumb: [
      { page: HilosPages.DASHBOARD, label: 'Hilos' },
      { page: HilosPages.LEGAL, label: 'Legal' },
    ],
    pageTabs: tabs,
    legalCatalogRefusal: null,
    legalOverview: summary,
  }
  for (const [key, value] of Object.entries(data)) scope.data.set(key, value)
  const answer = {
    type: 'page_response',
    data: {
      page,
      payload: {
        data,
        windows,
        refusedWindows: refusedTable
          ? { [refusedTable]: { errorCode: 'internal_error' } }
          : {},
      },
    },
  }
  const context = {
    scopes,
    connection: {
      on(event: string, listener: (frame: never) => void) {
        const group = listeners.get(event) ?? new Set()
        listeners.set(event, group)
        group.add(listener)
        if (event === 'projectSignal') listener(answer as never)
        return () => group.delete(listener)
      },
      registerTableWindow(key: string) {
        registered.add(key)
      },
      unregisterTableWindow(key: string) {
        registered.delete(key)
      },
      sendTableViewport: sendViewport,
      sendTableRendered: vi.fn(),
      sendTableFacets: vi.fn(),
      sendTableRowFocus: vi.fn(),
    },
    actions: { dispatch },
  } as unknown as HilosLegalContext
  const routes = createAppPageRouter({})
  const router = {
    currentRoute: createSignal({ page, params: {}, admin: true }),
    currentPath: createSignal(routes.path(page, {})!),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: pageIdentity(scopes),
    dashboardSections: createSignal(undefined),
    resolvePath: (key: string, params: Record<string, string>) =>
      routes.path(key, params),
    navigate: vi.fn(),
  } as unknown as HilosRouter
  const view = render(
    <HilosRouterContext.Provider value={router}>
      <HilosLegalPage context={context} />
    </HilosRouterContext.Provider>,
  )
  const emit = (event: string, frame: unknown) => {
    act(() => {
      for (const listener of listeners.get(event) ?? [])
        listener(frame as never)
    })
  }
  const find = (selector: string) => view.container.querySelector(selector)
  const get = (selector: string): Element => {
    const found = find(selector)
    if (!found) throw new Error(`Nothing matches ${selector}.`)
    return found
  }
  const texts = (root: Element, selector: string) =>
    [...root.querySelectorAll(selector)].map((node) => node.textContent?.trim())

  return {
    view,
    scope,
    registered,
    sendViewport,
    dispatch,
    find,
    get,
    texts,
    setData(key: string, value: unknown) {
      act(() => {
        scope.data.set(key, value)
      })
    },
    refuse(tableKey: string) {
      emit('tableWindowRefused', {
        type: 'table_window_refused',
        data: { page, tableKey, errorCode: 'internal_error' },
      })
    },
    checks(rows: TableRow[] = checks) {
      emit('tableWindow', {
        type: 'table_window',
        data: { page, tableKey: 'hilosLegalChecks', ...windowOf(rows) },
      })
    },
    policy(policy: unknown, consent: unknown = 'checkbox') {
      emit('tableWindow', {
        data: {
          page,
          tableKey: 'hilosLegalSettings',
          ...windowOf(settingsRows(policy, consent)),
        },
      })
    },
    listenerCount: () =>
      [...listeners.values()].reduce((sum, group) => sum + group.size, 0),
  }
}

describe('React Legal Overview', () => {
  it('renders one real head and the four ordinary page links', () => {
    const h = sectionHarness()
    try {
      expect(h.texts(h.view.container, 'h1')).toEqual(['Legal'])
      expect(h.get('[data-id="hilos-page-head-block"]').textContent).toContain(
        'What the project declares, and who accepted it.',
      )
      const links = [
        ...h.get('[data-id="hilos-page-tabs"]').querySelectorAll('a'),
      ]
      expect(
        links.map((link) => [
          link.textContent?.trim(),
          link.getAttribute('href'),
        ]),
      ).toEqual([
        ['Overview', '/hilos/legal'],
        ['Documents', '/hilos/legal/documents'],
        ['Acceptances', '/hilos/legal/acceptances'],
        ['Settings', '/hilos/legal/settings'],
      ])
      expect(
        links
          .filter((link) => link.getAttribute('aria-current') === 'page')
          .map((link) => link.textContent?.trim()),
      ).toEqual(['Overview'])
      expect(h.find('[role="tablist"]')).toBeNull()
      expect(h.sendViewport).not.toHaveBeenCalled()
      expect(h.dispatch).not.toHaveBeenCalled()
    } finally {
      h.view.unmount()
    }
    expect(h.registered.size).toBe(0)
    expect(h.listenerCount()).toBe(0)
  })

  it('uses complete page aggregates, server labels and per-document links including zeros', () => {
    const h = sectionHarness()
    try {
      expect(h.registered).toEqual(
        new Set(['hilosLegalChecks', 'hilosLegalSettings']),
      )
      expect(h.get('[data-id="legal-document-count"]').textContent).toBe('2')
      expect(h.get('[data-id="legal-newer-set-count"]').textContent).toBe('1')
      const waiting = h.get('[data-id="legal-overview-window"]')
      expect(h.texts(waiting, 'dt')).toEqual(['Server terms', 'Server privacy'])
      expect(h.texts(waiting, 'dd')).toEqual(['7', '0'])
      const lapsed = h.get('[data-id="legal-overview-lapsed"]')
      expect(h.texts(lapsed, 'dd')).toEqual(['2', '0'])
      expect(
        [...lapsed.querySelectorAll('a')].map((link) =>
          link.getAttribute('href'),
        ),
      ).toEqual(['/hilos/users/terms'])
      expect(lapsed.querySelector('[data-document="privacy"] a')).toBeNull()
      expect(h.get('[data-id="legal-overview-consent"]').textContent).toContain(
        'Checkbox',
      )
      expect(h.get('[data-id="legal-overview-refusal"]').textContent).toContain(
        'Freeze access',
      )
      expect(
        h.view.container.querySelectorAll('table [data-id="legal-check-row"]'),
      ).toHaveLength(4)
      expect(
        h.get('table [data-check="undeclared_revision"]').textContent,
      ).toContain('retired: gone — 14 people.')
      expect(
        h.get('table [data-check="newer_standard_set"]').textContent,
      ).toContain('Terms of use: set 3 (substantial); the project uses set 1.')
      const text = h.view.container.textContent
      expect(text).not.toContain('What this section does not do')
      expect(text).not.toContain('Why this is under Access & identity')
      expect(text).toContain('Document texts live in code.')
      expect(h.find('[data-id="legal-document-row"]')).toBeNull()

      h.setData('legalOverview', {
        ...overview,
        documents: overview.documents.map((document) => ({
          ...document,
          window: 0,
          lapsed: 0,
        })),
      })
      expect(h.texts(h.get('[data-id="legal-overview-window"]'), 'dd')).toEqual(
        ['0', '0'],
      )
      const zeroLapsed = h.get('[data-id="legal-overview-lapsed"]')
      expect(h.texts(zeroLapsed, 'dd')).toEqual(['0', '0'])
      expect(zeroLapsed.querySelector('a')).toBeNull()
      expect(h.sendViewport).not.toHaveBeenCalled()
      expect(h.dispatch).not.toHaveBeenCalled()
    } finally {
      h.view.unmount()
    }
  })

  it('keeps recorded-only warnings when no document is declared and invents no checks for a wholly empty set', () => {
    for (const checkRows of [checks, []]) {
      const h = sectionHarness(
        { documentCount: 0, documents: [], newerSetCount: 0 },
        checkRows,
      )
      try {
        expect(h.get('[data-id="legal-document-count"]').textContent).toBe('0')
        expect(h.get('[data-id="legal-newer-set-count"]').textContent).toBe('0')
        expect(h.get('[data-id="legal-count-window"]').textContent).toBe('0')
        expect(h.get('[data-id="legal-count-lapsed"]').textContent).toBe('0')
        expect(
          h.view.container.querySelectorAll('[data-document]'),
        ).toHaveLength(0)
        expect(
          h.view.container.querySelectorAll(
            'table [data-id="legal-check-row"]',
          ),
        ).toHaveLength(checkRows.length)
        if (checkRows.length)
          expect(
            h.get('table [data-check="undeclared_revision"]').textContent,
          ).toContain('retired: gone — 14 people.')
      } finally {
        h.view.unmount()
      }
    }
  })

  it.each([true, false])(
    'shows a Checks refusal beside the summary and recovers through windows (first response: %s)',
    (firstResponse) => {
      const h = sectionHarness(
        overview,
        checks,
        firstResponse ? 'hilosLegalChecks' : undefined,
      )
      try {
        if (!firstResponse) {
          expect(
            h.view.container.querySelectorAll(
              'table [data-id="legal-check-row"]',
            ),
          ).toHaveLength(4)
          h.refuse('hilosLegalChecks')
        }
        const unavailable = h.get('[data-id="hilos-table-unavailable"]')
        expect(unavailable.getAttribute('role')).toBe('status')
        expect(
          unavailable.querySelector('[data-id="hilos-table-unavailable-title"]')
            ?.textContent,
        ).toBe('List unavailable')
        expect(h.find('[data-id="legal-check-row"]')).toBeNull()
        expect(h.get('[data-id="legal-document-count"]').textContent).toBe('2')
        expect(
          h.get('[data-id="legal-overview-consent"]').textContent,
        ).toContain('Checkbox')
        expect(h.find('[data-id="legal-catalog-refusal"]')).toBeNull()
        expect(h.view.container.querySelectorAll('h1')).toHaveLength(1)

        h.checks([])
        expect(h.find('[data-id="hilos-table-unavailable"]')).toBeNull()
        expect(h.find('[data-id="legal-check-row"]')).toBeNull()
        expect(h.find('table')).toBeNull()

        h.checks()
        expect(
          h.view.container.querySelectorAll(
            'table [data-id="legal-check-row"]',
          ),
        ).toHaveLength(4)
        expect(h.sendViewport).not.toHaveBeenCalled()
        expect(h.dispatch).not.toHaveBeenCalled()
      } finally {
        h.view.unmount()
      }
      expect(h.registered.size).toBe(0)
      expect(h.listenerCount()).toBe(0)
    },
  )

  it.each([true, false])(
    'shows a Settings window refusal as unavailable in the tiles, not an endless skeleton, and restores values (first response: %s)',
    (firstResponse) => {
      const h = sectionHarness(
        overview,
        checks,
        firstResponse ? 'hilosLegalSettings' : undefined,
      )
      try {
        if (!firstResponse) {
          expect(
            h.get('[data-id="legal-overview-refusal"]').textContent,
          ).toContain('Freeze access')
          h.refuse('hilosLegalSettings')
        }
        for (const tile of ['consent', 'refusal']) {
          const section = h.get(`[data-id="legal-overview-${tile}"]`)
          const unavailable = section.querySelector(
            '[data-id="hilos-table-unavailable"]',
          )
          expect(unavailable?.getAttribute('role')).toBe('status')
          expect(
            unavailable?.querySelector(
              '[data-id="hilos-table-unavailable-title"]',
            )?.textContent,
          ).toBe('List unavailable')
          expect(section.querySelector('[data-id="hilos-skeleton"]')).toBeNull()
          expect(section.querySelector('[data-id="hilos-hidden"]')).toBeNull()
          expect(section.textContent).not.toContain('Checkbox')
          expect(section.textContent).not.toContain('Freeze access')
        }
        expect(h.get('[data-id="legal-document-count"]').textContent).toBe('2')
        expect(
          h.texts(h.get('[data-id="legal-overview-window"]'), 'dd'),
        ).toEqual(['7', '0'])
        expect(
          h.view.container.querySelectorAll(
            'table [data-id="legal-check-row"]',
          ),
        ).toHaveLength(4)
        expect(h.find('[data-id="legal-catalog-refusal"]')).toBeNull()
        expect(h.view.container.querySelectorAll('h1')).toHaveLength(1)
        expect(
          h.get('[data-id="hilos-page-tabs"]').querySelectorAll('a'),
        ).toHaveLength(4)

        h.policy('remind', 'line')
        expect(h.find('[data-id="hilos-table-unavailable"]')).toBeNull()
        expect(
          h.get('[data-id="legal-overview-consent"]').textContent,
        ).toContain('Line below the button')
        expect(
          h.get('[data-id="legal-overview-refusal"]').textContent,
        ).toContain('Keep reminding')
        expect(h.get('[data-id="legal-overview-lapsed"] h2').textContent).toBe(
          'Past deadline',
        )
        expect(h.sendViewport).not.toHaveBeenCalled()
        expect(h.dispatch).not.toHaveBeenCalled()
      } finally {
        h.view.unmount()
      }
      expect(h.registered.size).toBe(0)
      expect(h.listenerCount()).toBe(0)
    },
  )

  it('keeps hidden policy and consent hidden and changes the deadline caption through the carrier', () => {
    const h = sectionHarness()
    try {
      for (const [policy, consent, label] of [
        ['remind', 'line', 'Past deadline'],
        [HIDDEN_VALUE, HIDDEN_VALUE, 'Past deadline'],
        ['freeze', 'checkbox', 'Frozen'],
      ] as const) {
        h.policy(policy, consent)
        expect(h.get('[data-id="legal-overview-lapsed"] h2').textContent).toBe(
          label,
        )
        const text = h.view.container.textContent
        if (policy === HIDDEN_VALUE) {
          expect(
            h.view.container.querySelectorAll('[data-id="hilos-hidden"]'),
          ).toHaveLength(2)
          expect(text).not.toContain('Freeze access')
          expect(text).not.toContain('Keep reminding')
          expect(text).not.toContain('Checkbox')
          expect(text).not.toContain('Line below the button')
        } else {
          expect(h.find('[data-id="hilos-hidden"]')).toBeNull()
        }
      }
    } finally {
      h.view.unmount()
    }
  })

  it.each([undefined, null, { ...overview, documentCount: 4 }])(
    'clears missing or malformed summary into the standard skeleton: %j',
    (summary) => {
      const h = sectionHarness()
      try {
        h.setData('legalOverview', summary)
        expect(h.find('[data-id="legal-overview"]')).toBeNull()
        expect(h.find('[data-id="hilos-skeleton"]')).not.toBeNull()
        expect(h.find('[data-id="legal-document-count"]')).toBeNull()
        h.setData('legalOverview', overview)
        expect(h.get('[data-id="legal-document-count"]').textContent).toBe('2')
      } finally {
        h.view.unmount()
      }
    },
  )

  it('replaces the body with one catalog refusal and preserves the common head and navigation', () => {
    const h = sectionHarness()
    try {
      h.setData('legalCatalogRefusal', 'A deviation names a missing clause')
      h.setData('legalOverview', null)
      expect(h.view.container.querySelectorAll('[role="alert"]')).toHaveLength(
        1,
      )
      expect(h.get('[data-id="legal-catalog-refusal"]').textContent).toContain(
        'A deviation names a missing clause',
      )
      expect(h.find('[data-id="legal-overview"]')).toBeNull()
      expect(h.find('[data-id="legal-check-row"]')).toBeNull()
      expect(h.view.container.textContent).not.toContain(
        'Document texts live in code.',
      )
      expect(h.texts(h.view.container, 'h1')).toEqual(['Legal'])
      expect(
        h.get('[data-id="hilos-page-tabs"]').querySelectorAll('a'),
      ).toHaveLength(4)
    } finally {
      h.view.unmount()
    }
  })
})
