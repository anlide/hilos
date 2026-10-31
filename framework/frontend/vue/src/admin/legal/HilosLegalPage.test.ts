import { flushPromises, mount } from '@vue/test-utils'
import { consentTermsWithClauses } from '../../../../core/test/legal/consentFixture.js'
import {
  createSignal,
  createAppPageRouter,
  HIDDEN_VALUE,
  HilosPages,
  pageIdentity,
  ScopeManager,
  type HilosLegalContext,
  type HilosRouter,
  type TableRow,
} from '@hilos/core/base'
import { describe, expect, it, vi } from 'vitest'
import { markRaw, nextTick } from 'vue'
import HilosLegalPage from './HilosLegalPage.vue'
import HilosLegalDocumentPage from './HilosLegalDocumentPage.vue'
import HilosLegalRevisionPage from './HilosLegalRevisionPage.vue'
import HilosLegalDocumentsPage from './HilosLegalDocumentsPage.vue'
import HilosLegalSettingsPage from './HilosLegalSettingsPage.vue'
import HilosLegalAcceptancesPage from './HilosLegalAcceptancesPage.vue'
import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosPageHead from '../../HilosPageHead.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

const revision = {
  revisionId: 'current',
  publishedOn: '2026-09-27',
  effectiveOn: '2026-09-27',
  significance: 'editorial',
  setVersion: 1,
  deviationCount: 1,
}

/** Data sections remain reactive; only the surrounding shell and table drawing are stubbed. */
function harness() {
  const scopes = new ScopeManager()
  const scope = scopes.openPage('hilos_legal')
  const context = {
    scopes,
    connection: {
      on: vi.fn(() => () => {}),
      registerTableWindow: vi.fn(),
      unregisterTableWindow: vi.fn(),
      sendTableViewport: vi.fn(),
      sendTableRendered: vi.fn(),
    },
    actions: {},
  } as unknown as HilosLegalContext
  const global = {
    provide: {
      [hilosRouterKey as symbol]: {
        currentRoute: createSignal({
          page: 'hilos_legal_document',
          params: { documentKey: 'terms', revisionId: 'current' },
          admin: true,
        }),
      },
    },
    stubs: {
      HilosAdminPage: { template: '<main><slot /></main>' },
      HilosViewportTable: true,
      HilosLink: { template: '<a><slot /></a>' },
    },
  }
  return { scope, context, global }
}

describe('legal admin views', () => {
  it.each([HilosLegalPage, HilosLegalDocumentPage, HilosLegalRevisionPage])(
    'renders a catalog refusal instead of declaration content',
    async (component) => {
      const h = harness()
      const view = mount(component, {
        props: { context: h.context },
        global: h.global,
      })
      h.scope.data.set(
        'legalCatalogRefusal',
        'A deviation names a missing clause',
      )
      await nextTick()
      expect(view.find('[data-id="legal-catalog-refusal"]').text()).toContain(
        'A deviation names a missing clause',
      )
      expect(view.find('[data-id="legal-set"]').exists()).toBe(false)
      expect(view.find('[data-id="legal-revision-text"]').exists()).toBe(false)
      view.unmount()
    },
  )

  it('keeps document lists off Overview and shows confirmed aggregate zeros', () => {
    const h = harness()
    h.scope.data.set('legalDocument', {
      covered: 0,
      window: 0,
      lapsed: 0,
      standardSetCatalogClass: 'Hilos\\Legal\\StandardSetCatalog',
      projectCatalogClass: 'Project\\Legal\\LegalCatalog',
      document: 'terms',
      declared: true,
      revision,
      set: {
        version: 1,
        publishedOn: '2026-01-01',
        significance: 'substantial',
        clauses: [
          {
            clauseKey: 'standard.retention',
            statement: 'Retention is limited',
          },
        ],
      },
      newerSet: null,
      deviations: [
        {
          clauseKey: 'standard.retention',
          standardStatement: 'Retention is limited',
          statement: 'Messages stay',
          text: 'The project retains its messages.',
          direction: 'stricter',
        },
      ],
    })
    const view = mount(HilosLegalDocumentPage, {
      props: { context: h.context },
      global: h.global,
    })
    expect(view.find('[data-id="legal-set"]').exists()).toBe(false)
    expect(view.find('[data-id="legal-deviation-row"]').exists()).toBe(false)
    expect(view.get('[data-id="legal-count-covered"]').text()).toBe('0')
    expect(view.get('[data-id="legal-count-window"]').text()).toBe('0')
    expect(view.get('[data-id="legal-count-lapsed"]').text()).toBe('0')
    view.unmount()
  })

  it('opens the shared consent preview with both documents and no registration action', async () => {
    const h = harness()
    h.scope.data.set('legalDocument', {
      covered: 0,
      window: 0,
      lapsed: 0,
      standardSetCatalogClass: 'Hilos\\Legal\\StandardSetCatalog',
      projectCatalogClass: 'Project\\Legal\\LegalCatalog',
      document: 'terms',
      declared: true,
      revision,
      set: null,
      newerSet: null,
      deviations: [],
    })
    const dispatch = vi.fn(() => ({
      done: Promise.resolve({ reply: consentTermsWithClauses() }),
    }))
    Object.assign(h.context.actions, { dispatch })
    const view = mount(HilosLegalDocumentPage, {
      props: { context: h.context },
      global: h.global,
      attachTo: document.body,
    })
    await view.get('[data-id="legal-preview-consent"]').trigger('click')
    await flushPromises()
    const preview = document.querySelector('[data-id="legal-consent-preview"]')!
    expect(
      preview.querySelectorAll('[data-id="legal-consent-deviation"]'),
    ).toHaveLength(4)
    expect(preview.querySelector('[data-id="auth-submit"]')).toBeNull()
    expect(dispatch).toHaveBeenCalledTimes(1)
    expect(dispatch).toHaveBeenCalledWith(
      'hilos_legal_consent',
      {},
      expect.any(Object),
    )
    ;(
      document.querySelector(
        '[data-id="legal-consent-preview-close"]',
      ) as HTMLElement
    ).click()
    await flushPromises()
    expect(
      document.querySelector('[data-id="legal-consent-preview"]'),
    ).toBeNull()
    view.unmount()
  })

  it('shows the server unpublished sentence as text in the consent preview', async () => {
    const h = harness()
    h.scope.data.set('legalDocument', {
      covered: 0,
      window: 0,
      lapsed: 0,
      standardSetCatalogClass: 'Hilos\\Legal\\StandardSetCatalog',
      projectCatalogClass: 'Project\\Legal\\LegalCatalog',
      document: 'terms',
      declared: true,
      revision,
      set: null,
      newerSet: null,
      deviations: [],
    })
    const unpublishedMessage =
      '<b>The server has not published these terms.</b>'
    const dispatch = vi.fn(() => ({
      done: Promise.resolve({
        reply: {
          ...consentTermsWithClauses(),
          documents: [],
          unpublishedMessage,
        },
      }),
    }))
    Object.assign(h.context.actions, { dispatch })
    const view = mount(HilosLegalDocumentPage, {
      props: { context: h.context },
      global: h.global,
      attachTo: document.body,
    })
    try {
      await view.get('[data-id="legal-preview-consent"]').trigger('click')
      await flushPromises()
      const preview = document.querySelector(
        '[data-id="legal-consent-preview"]',
      )!
      const unpublished = preview.querySelector(
        '[data-id="legal-consent-unpublished"]',
      )!
      expect(unpublished.textContent).toBe(unpublishedMessage)
      expect(unpublished.querySelector('b')).toBeNull()
      expect(
        preview.querySelector('[data-id="auth-consent-accept"]'),
      ).toBeNull()
      expect(preview.querySelector('[data-id="auth-submit"]')).toBeNull()
      expect(dispatch).toHaveBeenCalledTimes(1)
      expect(dispatch).toHaveBeenCalledWith(
        'hilos_legal_consent',
        {},
        expect.any(Object),
      )
      ;(
        document.querySelector(
          '[data-id="legal-consent-preview-close"]',
        ) as HTMLElement
      ).click()
      await flushPromises()
      expect(
        document.querySelector('[data-id="legal-consent-preview"]'),
      ).toBeNull()
    } finally {
      view.unmount()
    }
  })

  it('does not invent text or a zero count for an undeclared revision', () => {
    const h = harness()
    h.scope.data.set('legalRevision', {
      document: 'terms',
      revisionId: 'gone',
      declared: false,
      revision: null,
      current: null,
      predecessorId: null,
      clauses: null,
      changes: null,
    })
    const view = mount(HilosLegalRevisionPage, {
      props: { context: h.context },
      global: h.global,
    })
    expect(view.find('[data-id="legal-revision-undeclared"]').exists()).toBe(
      true,
    )
    expect(view.find('[data-id="legal-revision-text"]').exists()).toBe(false)
    expect(view.find('[data-id="legal-revision-accepted"]').text()).toBe(
      'Loading acceptance count…',
    )
    view.unmount()
  })
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

/** Real page shells and carriers read the same response; no document window feeds Overview. */
function sectionHarness(
  page: string = HilosPages.LEGAL,
  summary: unknown = overview,
  checkRows = checks,
  refusedTable?: string,
) {
  const scopes = new ScopeManager()
  const scope = scopes.openPage(page)
  const listeners = new Map<string, Set<(frame: never) => void>>()
  const registered = new Set<string>()
  const sendViewport = vi.fn()
  const dispatch = vi.fn()
  const windowOf = (rows: TableRow[]) => ({
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
  const settingWindow = windowOf(settingsRows('freeze', 'checkbox'))
  const windows: Record<string, ReturnType<typeof windowOf>> = page ===
  HilosPages.LEGAL
    ? {
        hilosLegalChecks: windowOf(checkRows),
        hilosLegalSettings: settingWindow,
      }
    : page === HilosPages.LEGAL_DOCUMENTS
      ? { hilosLegalDocuments: windowOf([]), hilosLegalSettings: settingWindow }
      : page === HilosPages.LEGAL_ACCEPTANCES
        ? { hilosLegalAcceptances: windowOf([]) }
        : { hilosLegalSettings: settingWindow }
  if (refusedTable) delete windows[refusedTable]
  const data = {
    pageLabel: tabs.find((tab) => tab.page === page)?.label,
    pageLead: 'This page lead',
    pageChildren: [],
    pageHeadOwner: {
      page: 'hilos_legal',
      label: 'Legal',
      lead: 'What the project declares, and who accepted it.',
      icon: 'bi-file-earmark-check',
    },
    pageBreadcrumb: [
      { page: 'hilos', label: 'Hilos' },
      { page: 'hilos_legal', label: 'Legal' },
    ],
    pageTabs: tabs,
    legalCatalogRefusal: null,
    ...(page === HilosPages.LEGAL ? { legalOverview: summary } : {}),
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
    pageIdentity: pageIdentity(scopes),
    resolvePath: (key: string, params: Record<string, string>) =>
      routes.path(key, params),
    navigate: vi.fn(),
  } as unknown as HilosRouter
  const component =
    page === HilosPages.LEGAL
      ? HilosLegalPage
      : page === HilosPages.LEGAL_DOCUMENTS
        ? HilosLegalDocumentsPage
        : page === HilosPages.LEGAL_ACCEPTANCES
          ? HilosLegalAcceptancesPage
          : HilosLegalSettingsPage
  const view = mount(component, {
    props: { context: markRaw(context) },
    global: { provide: { [hilosRouterKey as symbol]: router } },
  })
  return {
    view,
    scope,
    registered,
    sendViewport,
    dispatch,
    refuse(tableKey: string) {
      for (const listener of listeners.get('tableWindowRefused') ?? [])
        listener({
          type: 'table_window_refused',
          data: { page, tableKey, errorCode: 'internal_error' },
        } as never)
    },
    checks(rows = checks) {
      for (const listener of listeners.get('tableWindow') ?? [])
        listener({
          type: 'table_window',
          data: { page, tableKey: 'hilosLegalChecks', ...windowOf(rows) },
        } as never)
    },
    policy(policy: unknown, consent: unknown = 'checkbox') {
      for (const listener of listeners.get('tableWindow') ?? [])
        listener({
          data: {
            page,
            tableKey: 'hilosLegalSettings',
            ...windowOf(settingsRows(policy, consent)),
          },
        } as never)
    },
    listenerCount: () =>
      [...listeners.values()].reduce((sum, group) => sum + group.size, 0),
  }
}

describe('Legal Overview and assembled section', () => {
  it('renders one real head and the four ordinary page links on every section page', async () => {
    for (const { page } of tabs) {
      const h = sectionHarness(page)
      try {
        await nextTick()
        expect(h.view.findAllComponents(HilosAdminPage)).toHaveLength(1)
        expect(h.view.findAllComponents(HilosPageHead)).toHaveLength(1)
        expect(h.view.findAll('h1').map((heading) => heading.text())).toEqual([
          'Legal',
        ])
        expect(
          h.view.get('[data-id="hilos-page-head-block"]').text(),
        ).toContain('What the project declares, and who accepted it.')
        expect(h.view.get('[data-id="hilos-breadcrumb"]').text()).toBe(
          'HilosLegal',
        )
        expect(
          h.view.get('[data-id="hilos-breadcrumb-hilos"]').attributes('href'),
        ).toBe('/hilos')
        const links = h.view.get('[data-id="hilos-page-tabs"]').findAll('a')
        expect(
          links.map((link) => [link.text(), link.attributes('href')]),
        ).toEqual([
          ['Overview', '/hilos/legal'],
          ['Documents', '/hilos/legal/documents'],
          ['Acceptances', '/hilos/legal/acceptances'],
          ['Settings', '/hilos/legal/settings'],
        ])
        expect(
          links
            .filter((link) => link.attributes('aria-current') === 'page')
            .map((link) => link.text()),
        ).toEqual([tabs.find((tab) => tab.page === page)!.label])
        expect(h.view.find('[data-id="hilos-admin-children"]').exists()).toBe(
          false,
        )
        expect(h.view.find('[role="tablist"]').exists()).toBe(false)
        expect(h.sendViewport).not.toHaveBeenCalled()
        expect(h.dispatch).not.toHaveBeenCalled()
      } finally {
        h.view.unmount()
      }
      expect(h.registered.size).toBe(0)
      expect(h.listenerCount()).toBe(0)
    }
  })

  it('uses complete page aggregates, server labels and per-document links including zeros', async () => {
    const h = sectionHarness()
    try {
      await nextTick()
      expect(h.registered).toEqual(
        new Set(['hilosLegalChecks', 'hilosLegalSettings']),
      )
      expect(h.view.get('[data-id="legal-document-count"]').text()).toBe('2')
      expect(h.view.get('[data-id="legal-newer-set-count"]').text()).toBe('1')
      const waiting = h.view.get('[data-id="legal-overview-window"]')
      expect(waiting.findAll('dt').map((row) => row.text())).toEqual([
        'Server terms',
        'Server privacy',
      ])
      expect(waiting.findAll('dd').map((row) => row.text())).toEqual(['7', '0'])
      const lapsed = h.view.get('[data-id="legal-overview-lapsed"]')
      expect(lapsed.findAll('dd').map((row) => row.text())).toEqual(['2', '0'])
      expect(
        lapsed.findAll('a').map((link) => link.attributes('href')),
      ).toEqual(['/hilos/users/terms'])
      expect(lapsed.get('[data-document="privacy"]').find('a').exists()).toBe(
        false,
      )
      expect(h.view.get('[data-id="legal-overview-consent"]').text()).toContain(
        'Checkbox',
      )
      expect(h.view.get('[data-id="legal-overview-refusal"]').text()).toContain(
        'Freeze access',
      )
      expect(h.view.findAll('table [data-id="legal-check-row"]')).toHaveLength(
        4,
      )
      expect(h.view.get('[data-check="undeclared_revision"]').text()).toContain(
        'retired: gone — 14 people.',
      )
      expect(h.view.get('[data-check="newer_standard_set"]').text()).toContain(
        'Terms: set 3 (substantial); the project uses set 1.',
      )
      expect(h.view.text()).not.toContain('What this section does not do')
      expect(h.view.text()).not.toContain('Why this is under Access & identity')
      expect(h.view.text()).toContain('Document texts live in code.')
      expect(h.view.find('[data-id="legal-document-row"]').exists()).toBe(false)
      h.scope.data.set('legalOverview', {
        ...overview,
        documents: overview.documents.map((document) => ({
          ...document,
          window: 0,
          lapsed: 0,
        })),
      })
      await nextTick()
      expect(waiting.findAll('dd').map((row) => row.text())).toEqual(['0', '0'])
      expect(lapsed.findAll('dd').map((row) => row.text())).toEqual(['0', '0'])
      expect(lapsed.find('a').exists()).toBe(false)
      expect(h.sendViewport).not.toHaveBeenCalled()
      expect(h.dispatch).not.toHaveBeenCalled()
    } finally {
      h.view.unmount()
    }
  })

  it('keeps recorded-only warnings when no document is declared and invents no checks for a wholly empty set', async () => {
    for (const checkRows of [checks, []]) {
      const h = sectionHarness(
        HilosPages.LEGAL,
        { documentCount: 0, documents: [], newerSetCount: 0 },
        checkRows,
      )
      try {
        await nextTick()
        expect(h.view.get('[data-id="legal-document-count"]').text()).toBe('0')
        expect(h.view.get('[data-id="legal-newer-set-count"]').text()).toBe('0')
        expect(h.view.get('[data-id="legal-count-window"]').text()).toBe('0')
        expect(h.view.get('[data-id="legal-count-lapsed"]').text()).toBe('0')
        expect(h.view.findAll('[data-document]')).toHaveLength(0)
        expect(
          h.view.findAll('table [data-id="legal-check-row"]'),
        ).toHaveLength(checkRows.length)
        if (checkRows.length)
          expect(
            h.view.get('[data-check="undeclared_revision"]').text(),
          ).toContain('retired: gone — 14 people.')
      } finally {
        h.view.unmount()
      }
    }
  })

  it.each([true, false])(
    'shows a Checks refusal beside the summary and recovers through windows (first response: %s)',
    async (firstResponse) => {
      const h = sectionHarness(
        HilosPages.LEGAL,
        overview,
        checks,
        firstResponse ? 'hilosLegalChecks' : undefined,
      )
      try {
        await nextTick()
        if (!firstResponse) {
          expect(
            h.view.findAll('table [data-id="legal-check-row"]'),
          ).toHaveLength(4)
          h.refuse('hilosLegalChecks')
          await nextTick()
        }
        const unavailable = h.view.get('[data-id="hilos-table-unavailable"]')
        expect(unavailable.attributes('role')).toBe('status')
        expect(
          unavailable.get('[data-id="hilos-table-unavailable-title"]').text(),
        ).toBe('List unavailable')
        expect(h.view.find('[data-id="legal-check-row"]').exists()).toBe(false)
        expect(h.view.get('[data-id="legal-document-count"]').text()).toBe('2')
        expect(h.view.get('[data-id="legal-newer-set-count"]').text()).toBe('1')
        expect(
          h.view.get('[data-id="legal-overview-consent"]').text(),
        ).toContain('Checkbox')
        expect(h.view.find('[data-id="legal-catalog-refusal"]').exists()).toBe(
          false,
        )
        expect(h.view.findAll('h1')).toHaveLength(1)
        expect(
          h.view.get('[data-id="hilos-page-tabs"]').findAll('a'),
        ).toHaveLength(4)

        h.checks([])
        await nextTick()
        expect(
          h.view.find('[data-id="hilos-table-unavailable"]').exists(),
        ).toBe(false)
        expect(h.view.find('[data-id="legal-check-row"]').exists()).toBe(false)
        expect(h.view.find('table').exists()).toBe(false)

        h.checks()
        await nextTick()
        expect(
          h.view.findAll('table [data-id="legal-check-row"]'),
        ).toHaveLength(4)
        expect(
          h.view.get('[data-check="undeclared_revision"]').text(),
        ).toContain('retired: gone — 14 people.')
        expect(
          h.view.get('[data-check="newer_standard_set"]').text(),
        ).toContain('Terms: set 3 (substantial); the project uses set 1.')
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
    'shows terminal Settings carrier refusals in the tiles and restores values (first response: %s)',
    async (firstResponse) => {
      const h = sectionHarness(
        HilosPages.LEGAL,
        overview,
        checks,
        firstResponse ? 'hilosLegalSettings' : undefined,
      )
      try {
        await nextTick()
        if (!firstResponse) {
          expect(
            h.view.get('[data-id="legal-overview-refusal"]').text(),
          ).toContain('Freeze access')
          h.refuse('hilosLegalSettings')
          await nextTick()
        }
        for (const tile of ['consent', 'refusal']) {
          const section = h.view.get(`[data-id="legal-overview-${tile}"]`)
          const unavailable = section.get('[data-id="hilos-table-unavailable"]')
          expect(unavailable.attributes('role')).toBe('status')
          expect(
            unavailable.get('[data-id="hilos-table-unavailable-title"]').text(),
          ).toBe('List unavailable')
          expect(section.find('[data-id="hilos-skeleton"]').exists()).toBe(
            false,
          )
          expect(section.find('[data-id="hilos-hidden"]').exists()).toBe(false)
          expect(section.text()).not.toContain('Checkbox')
          expect(section.text()).not.toContain('Freeze access')
        }
        expect(h.view.get('[data-id="legal-document-count"]').text()).toBe('2')
        expect(h.view.get('[data-id="legal-newer-set-count"]').text()).toBe('1')
        expect(
          h.view
            .get('[data-id="legal-overview-window"]')
            .findAll('dd')
            .map((row) => row.text()),
        ).toEqual(['7', '0'])
        expect(
          h.view.findAll('table [data-id="legal-check-row"]'),
        ).toHaveLength(4)
        expect(h.view.find('[data-id="legal-catalog-refusal"]').exists()).toBe(
          false,
        )
        expect(h.view.findAll('h1')).toHaveLength(1)
        expect(
          h.view.get('[data-id="hilos-page-tabs"]').findAll('a'),
        ).toHaveLength(4)

        h.policy('remind', 'line')
        await nextTick()
        expect(
          h.view.find('[data-id="hilos-table-unavailable"]').exists(),
        ).toBe(false)
        expect(
          h.view.get('[data-id="legal-overview-consent"]').text(),
        ).toContain('Line below the button')
        expect(
          h.view.get('[data-id="legal-overview-refusal"]').text(),
        ).toContain('Keep reminding')
        expect(h.view.get('[data-id="legal-overview-lapsed"] h2').text()).toBe(
          'Past deadline',
        )
        h.policy(HIDDEN_VALUE, HIDDEN_VALUE)
        await nextTick()
        expect(h.view.findAll('[data-id="hilos-hidden"]')).toHaveLength(2)
        expect(
          h.view.find('[data-id="hilos-table-unavailable"]').exists(),
        ).toBe(false)
        expect(h.sendViewport).not.toHaveBeenCalled()
        expect(h.dispatch).not.toHaveBeenCalled()
      } finally {
        h.view.unmount()
      }
      expect(h.registered.size).toBe(0)
      expect(h.listenerCount()).toBe(0)
    },
  )

  it('keeps hidden policy and consent hidden and changes the deadline caption through the carrier', async () => {
    const h = sectionHarness()
    try {
      for (const [policy, consent, label] of [
        ['remind', 'line', 'Past deadline'],
        [HIDDEN_VALUE, HIDDEN_VALUE, 'Past deadline'],
        ['freeze', 'checkbox', 'Frozen'],
      ] as const) {
        h.policy(policy, consent)
        await nextTick()
        expect(h.view.get('[data-id="legal-overview-lapsed"] h2').text()).toBe(
          label,
        )
        if (policy === HIDDEN_VALUE) {
          expect(h.view.findAll('[data-id="hilos-hidden"]')).toHaveLength(2)
          expect(h.view.text()).not.toContain('Freeze access')
          expect(h.view.text()).not.toContain('Keep reminding')
          expect(h.view.text()).not.toContain('Checkbox')
          expect(h.view.text()).not.toContain('Line below the button')
        } else {
          expect(h.view.find('[data-id="hilos-hidden"]').exists()).toBe(false)
        }
      }
    } finally {
      h.view.unmount()
    }
  })

  it.each([undefined, null, { ...overview, documentCount: 4 }])(
    'clears missing or malformed summary into the standard skeleton: %j',
    async (summary) => {
      const h = sectionHarness()
      try {
        h.scope.data.set('legalOverview', summary)
        await nextTick()
        expect(h.view.find('[data-id="legal-overview"]').exists()).toBe(false)
        expect(h.view.find('[data-id="hilos-skeleton"]').exists()).toBe(true)
        expect(h.view.find('[data-id="legal-document-count"]').exists()).toBe(
          false,
        )
        h.scope.data.set('legalOverview', overview)
        await nextTick()
        expect(h.view.get('[data-id="legal-document-count"]').text()).toBe('2')
      } finally {
        h.view.unmount()
      }
    },
  )

  it('replaces the body with one catalog refusal and preserves the common head and navigation', async () => {
    const h = sectionHarness()
    try {
      h.scope.data.set(
        'legalCatalogRefusal',
        'A deviation names a missing clause',
      )
      h.scope.data.set('legalOverview', null)
      await nextTick()
      expect(h.view.findAll('[role="alert"]')).toHaveLength(1)
      expect(h.view.get('[data-id="legal-catalog-refusal"]').text()).toContain(
        'A deviation names a missing clause',
      )
      expect(h.view.find('[data-id="legal-overview"]').exists()).toBe(false)
      expect(h.view.find('[data-id="legal-check-row"]').exists()).toBe(false)
      expect(h.view.text()).not.toContain('Document texts live in code.')
      expect(h.view.findAll('h1')).toHaveLength(1)
      expect(
        h.view.get('[data-id="hilos-page-tabs"]').findAll('a'),
      ).toHaveLength(4)
    } finally {
      h.view.unmount()
    }
  })
})
