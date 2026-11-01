import { consentTermsWithClauses } from '../../core/test/legal/consentFixture.js'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { type Type } from '@angular/core'
import { TestBed } from '@angular/core/testing'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'
import {
  computedSignal,
  createSignal,
  HIDDEN_VALUE,
  HilosPages,
  pageIdentity,
  resolveHilosPath,
  ScopeManager,
  type HilosLegalContext,
  type HilosRouter,
  type TableRow,
} from '@hilos/core'
import { type HilosLegalOverview } from '@hilos/core/admin/legal'
import { HilosLegalPage } from '../src/admin/legal/HilosLegalPage.js'
import { HilosLegalDocumentPage } from '../src/admin/legal/HilosLegalDocumentPage.js'
import { HilosLegalDocumentsPage } from '../src/admin/legal/HilosLegalDocumentsPage.js'
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

afterEach(() => TestBed.resetTestingModule())
describe('legal admin Angular pages', () => {
  it.each([
    HilosLegalPage,
    HilosLegalDocumentsPage,
    HilosLegalDocumentPage,
    HilosLegalRevisionPage,
  ])(
    'replaces declaration content with the catalog refusal',
    async (component) => {
      const h = harness()
      await TestBed.configureTestingModule({
        imports: [component],
        providers: [{ provide: HILOS_ROUTER, useValue: h.router }],
      }).compileComponents()
      const fixture = TestBed.createComponent(component as Type<unknown>)
      fixture.componentRef.setInput('context', h.context)
      fixture.detectChanges()
      await fixture.whenStable()
      h.scope.data.set(
        'legalCatalogRefusal',
        'The catalog names a missing clause',
      )
      fixture.detectChanges()
      const element = fixture.nativeElement as HTMLElement
      expect(
        element.querySelector('[data-id="legal-catalog-refusal"]')?.textContent,
      ).toContain('The catalog names a missing clause')
      expect(element.querySelector('[data-id="legal-set"]')).toBeNull()
      expect(
        element.querySelector('[data-id="legal-revision-text"]'),
      ).toBeNull()
      fixture.destroy()
    },
  )
})

it('opens both consent documents in a read-only Angular preview', async () => {
  const h = harness()
  h.scope.data.set('legalDocument', {
    covered: 0,
    window: 0,
    lapsed: 0,
    standardSetCatalogClass: 'Hilos\\Legal\\StandardSetCatalog',
    projectCatalogClass: 'Project\\Legal\\LegalCatalog',
    document: 'terms',
    declared: true,
    revision: {
      revisionId: 'current',
      publishedOn: '2026-09-17',
      effectiveOn: '2026-09-17',
      significance: 'substantial',
      setVersion: 1,
      deviationCount: 0,
    },
    set: null,
    newerSet: null,
    deviations: [],
  })
  Object.assign(h.context.actions, {
    dispatch: () => ({
      done: Promise.resolve({ reply: consentTermsWithClauses() }),
    }),
  })
  await TestBed.configureTestingModule({
    imports: [HilosLegalDocumentPage],
    providers: [{ provide: HILOS_ROUTER, useValue: h.router }],
  }).compileComponents()
  const fixture = TestBed.createComponent(HilosLegalDocumentPage)
  fixture.componentRef.setInput('context', h.context)
  fixture.detectChanges()
  await fixture.whenStable()
  ;(
    fixture.nativeElement.querySelector(
      '[data-id="legal-preview-consent"]',
    ) as HTMLElement
  ).click()
  fixture.detectChanges()
  await Promise.resolve()
  await Promise.resolve()
  await fixture.whenStable()
  fixture.detectChanges()
  const preview = (fixture.nativeElement as HTMLElement).querySelector(
    '[data-id="legal-consent-preview"]',
  )!
  expect(preview.textContent).toContain('Project retention')
  expect(
    preview.querySelectorAll('[data-id="legal-consent-deviation"]'),
  ).toHaveLength(4)
  expect(preview.querySelector('[data-id="auth-submit"]')).toBeNull()
  ;(
    fixture.nativeElement.querySelector(
      '[data-id="legal-consent-preview-close"]',
    ) as HTMLElement
  ).click()
  fixture.detectChanges()
  await fixture.whenStable()
  expect(
    fixture.nativeElement.querySelector('[data-id="legal-consent-preview"]'),
  ).toBeNull()
})

it('shows the server unpublished sentence literally in the Angular consent preview', async () => {
  const h = harness()
  h.scope.data.set('legalDocument', {
    covered: 0,
    window: 0,
    lapsed: 0,
    standardSetCatalogClass: 'Hilos\\Legal\\StandardSetCatalog',
    projectCatalogClass: 'Project\\Legal\\LegalCatalog',
    document: 'terms',
    declared: true,
    revision: null,
    set: null,
    newerSet: null,
    deviations: [],
  })
  const unpublishedMessage = '<b>The server has no terms to show yet.</b>'
  Object.assign(h.context.actions, {
    dispatch: () => ({
      done: Promise.resolve({
        reply: {
          ...consentTermsWithClauses(),
          documents: [],
          unpublishedMessage,
        },
      }),
    }),
  })
  await TestBed.configureTestingModule({
    imports: [HilosLegalDocumentPage],
    providers: [{ provide: HILOS_ROUTER, useValue: h.router }],
  }).compileComponents()
  const fixture = TestBed.createComponent(HilosLegalDocumentPage)
  fixture.componentRef.setInput('context', h.context)
  fixture.detectChanges()
  await fixture.whenStable()
  const element = fixture.nativeElement as HTMLElement
  element
    .querySelector<HTMLElement>('[data-id="legal-preview-consent"]')!
    .click()
  fixture.detectChanges()
  await Promise.resolve()
  await Promise.resolve()
  await fixture.whenStable()
  fixture.detectChanges()

  const preview = element.querySelector('[data-id="legal-consent-preview"]')!
  const unpublished = preview.querySelector(
    '[data-id="legal-consent-unpublished"]',
  )!
  expect(unpublished.textContent?.trim()).toBe(unpublishedMessage)
  expect(unpublished.querySelector('b')).toBeNull()
  expect(preview.querySelector('[data-id="auth-consent-accept"]')).toBeNull()
  expect(preview.querySelector('[data-id="auth-submit"]')).toBeNull()
  element
    .querySelector<HTMLElement>('[data-id="legal-consent-preview-close"]')!
    .click()
  fixture.detectChanges()
  await fixture.whenStable()
  expect(element.querySelector('[data-id="legal-consent-preview"]')).toBeNull()
  fixture.destroy()
})
const OVERVIEW: HilosLegalOverview = {
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
const CHECKS: TableRow[] = [
  {
    rowKey: 'undeclared_revision',
    ok: false,
    items: [{ document: 'retired', revisionId: 'gone', people: 14 }],
  },
  { rowKey: 'zero_window', ok: true, items: [] },
].map(({ rowKey, ...check }) => ({ rowKey, slots: { check } }))
type Listener = (signal: { type?: string; data: unknown }) => void

/**
 * One table window of the page answer.
 *
 * @param rows The rows of the window.
 */
function windowOf(rows: TableRow[]) {
  return {
    rows,
    totalCount: rows.length,
    totalExact: true,
    firstAnchor: null,
    lastAnchor: null,
    limit: 25,
    sort: null,
  }
}

/**
 * The settings rows of the two legal settings.
 *
 * @param policy The refusal setting's value, hidden for a viewer.
 * @param consent The consent setting's value, hidden for a viewer.
 */
function settingsRows(policy: unknown, consent: unknown): TableRow[] {
  return [
    {
      rowKey: 'legal.consent_form',
      slots: { setting: { value: consent, defaultValue: consent } },
    },
    {
      rowKey: 'legal.refusal_after_deadline',
      slots: { setting: { value: policy, defaultValue: policy } },
    },
  ]
}

/**
 * Real head, carriers and links on Overview; the connection replays the first
 * page answer with its checks and settings windows, and no documents window.
 *
 * @param options What the first answer carries.
 * @param options.summary The legalOverview section; absent when the key is left out.
 * @param options.checks The rows of the checks window.
 * @param options.refusedTable A window the first answer refuses instead of sending.
 */
function overviewHarness(
  options: {
    summary?: unknown
    checks?: TableRow[]
    refusedTable?: string
  } = {},
) {
  const summary = 'summary' in options ? options.summary : OVERVIEW
  const { checks = CHECKS, refusedTable } = options
  const page = HilosPages.LEGAL
  const scopes = new ScopeManager()
  const scope = scopes.openPage(page)
  const data: Record<string, unknown> = {
    pageLabel: 'Overview',
    pageLead: 'This page lead',
    pageChildren: [],
    pageHeadOwner: {
      page,
      label: 'Legal',
      lead: 'Documents people agree to, and who agreed.',
      icon: 'bi-file-earmark-text',
    },
    pageBreadcrumb: [
      { page: HilosPages.DASHBOARD, label: 'Hilos' },
      { page, label: 'Legal' },
    ],
    pageTabs: [
      { page, label: 'Overview' },
      { page: HilosPages.LEGAL_DOCUMENTS, label: 'Documents' },
      { page: HilosPages.LEGAL_ACCEPTANCES, label: 'Acceptances' },
      { page: HilosPages.LEGAL_SETTINGS, label: 'Settings' },
    ],
    legalCatalogRefusal: null,
  }
  if (summary !== undefined) data.legalOverview = summary
  for (const [key, value] of Object.entries(data)) scope.data.set(key, value)
  const windows: Record<string, ReturnType<typeof windowOf>> = {
    hilosLegalChecks: windowOf(checks),
    hilosLegalSettings: windowOf(settingsRows('freeze', 'checkbox')),
  }
  if (refusedTable) delete windows[refusedTable]
  const answer = {
    page,
    payload: {
      windows,
      refusedWindows: refusedTable
        ? { [refusedTable]: { errorCode: 'internal_error' } }
        : {},
    },
  }
  const listeners = new Map<string, Set<Listener>>()
  const registered = new Set<string>()
  const sendTableViewport = vi.fn(() => true)
  const dispatch = vi.fn()
  const context = {
    scopes,
    connection: {
      on(event: string, listener: Listener) {
        const set = listeners.get(event) ?? new Set<Listener>()
        listeners.set(event, set)
        set.add(listener)
        if (event === 'projectSignal')
          listener({ type: 'page_response', data: answer })
        return () => set.delete(listener)
      },
      registerTableWindow(key: string) {
        registered.add(key)
      },
      unregisterTableWindow(key: string) {
        registered.delete(key)
      },
      sendTableViewport,
      sendTableRendered: vi.fn(() => true),
    },
    actions: { dispatch },
  } as unknown as HilosLegalContext
  const identity = pageIdentity(scopes)
  const router: HilosRouter = {
    currentRoute: createSignal({ page, params: {}, admin: true }),
    currentPath: createSignal('/hilos/legal'),
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
    navigate: vi.fn(),
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: router }],
  })
  const fixture = TestBed.createComponent(HilosLegalPage)
  fixture.componentRef.setInput('context', context)
  fixture.detectChanges()
  const send = (event: string, frame: unknown) => {
    for (const listener of listeners.get(event) ?? []) listener({ data: frame })
    fixture.detectChanges()
  }

  return {
    fixture,
    scope,
    registered,
    sendTableViewport,
    dispatch,
    root: fixture.nativeElement as HTMLElement,
    /**
     * Set one section of the page data and redraw.
     *
     * @param key The page-data key.
     * @param value Its new value.
     */
    data(key: string, value: unknown) {
      scope.data.set(key, value)
      fixture.detectChanges()
    },
    /**
     * A later settings window carrying other values.
     *
     * @param policy The refusal setting's new value.
     * @param consent The consent setting's new value.
     */
    settings(policy: unknown, consent: unknown) {
      send('tableWindow', {
        page,
        tableKey: 'hilosLegalSettings',
        ...windowOf(settingsRows(policy, consent)),
      })
    },
    /**
     * The server refuses a later window of one table.
     *
     * @param tableKey The refused table.
     */
    refuse(tableKey: string) {
      send('tableWindowRefused', {
        page,
        tableKey,
        errorCode: 'internal_error',
      })
    },
    listenerCount: () =>
      [...listeners.values()].reduce((sum, set) => sum + set.size, 0),
  }
}

type OverviewHarness = ReturnType<typeof overviewHarness>

/**
 * The one element a selector names; fails the test when it is absent.
 *
 * @param root Where to look.
 * @param selector What to look for.
 */
function get(root: ParentNode, selector: string): HTMLElement {
  const found = root.querySelector<HTMLElement>(selector)
  expect(found, selector).not.toBeNull()
  return found!
}

/**
 * The trimmed texts of every element a selector names.
 *
 * @param root Where to look.
 * @param selector What to look for.
 */
function texts(root: ParentNode, selector: string): (string | undefined)[] {
  return [...root.querySelectorAll(selector)].map((found) =>
    found.textContent?.trim(),
  )
}

/**
 * The trimmed text of the one element a selector names.
 *
 * @param h The Overview harness.
 * @param selector What to look for.
 */
function text(h: OverviewHarness, selector: string): string | undefined {
  return get(h.root, selector).textContent?.trim()
}

describe('HilosLegalPage Overview', () => {
  it('draws the per-document rows of the page summary with zeros in both people tiles and links only the past-deadline counts above zero', () => {
    const h = overviewHarness()
    try {
      expect(h.root.querySelectorAll('h1')).toHaveLength(1)
      expect(text(h, 'h1')).toBe('Legal')
      expect([...h.registered].sort()).toEqual([
        'hilosLegalChecks',
        'hilosLegalSettings',
      ])
      expect(h.root.querySelector('[data-id="legal-document-row"]')).toBeNull()
      expect(text(h, '[data-id="legal-document-count"]')).toBe('2')
      expect(text(h, '[data-id="legal-newer-set-count"]')).toBe('1')
      const waiting = get(h.root, '[data-id="legal-overview-window"]')
      expect(texts(waiting, 'dt')).toEqual(['Server terms', 'Server privacy'])
      expect(texts(waiting, '[data-id="legal-count-window"]')).toEqual([
        '7',
        '0',
      ])
      const lapsed = get(h.root, '[data-id="legal-overview-lapsed"]')
      expect(texts(lapsed, 'dt')).toEqual(['Server terms', 'Server privacy'])
      expect(texts(lapsed, '[data-id="legal-count-lapsed"]')).toEqual([
        '2',
        '0',
      ])
      expect(
        [...lapsed.querySelectorAll('[data-id="legal-count-lapsed-link"]')].map(
          (link) => [
            link.closest('[data-document]')?.getAttribute('data-document'),
            link.getAttribute('href'),
            link.querySelector('[data-id="legal-count-lapsed"]')?.textContent,
          ],
        ),
      ).toEqual([['terms', '/hilos/users/terms', '2']])
      expect(get(lapsed, '[data-document="privacy"]').querySelector('a')).toBe(
        null,
      )

      h.data('legalOverview', {
        ...OVERVIEW,
        documents: OVERVIEW.documents.map((document) => ({
          ...document,
          window: 0,
          lapsed: 0,
        })),
      })
      expect(texts(waiting, '[data-id="legal-count-window"]')).toEqual([
        '0',
        '0',
      ])
      expect(texts(lapsed, '[data-id="legal-count-lapsed"]')).toEqual([
        '0',
        '0',
      ])
      expect(lapsed.querySelector('a')).toBeNull()
      expect(h.sendTableViewport).not.toHaveBeenCalled()
      expect(h.dispatch).not.toHaveBeenCalled()
    } finally {
      h.fixture.destroy()
    }
    expect(h.registered.size).toBe(0)
    expect(h.listenerCount()).toBe(0)
  })

  it.each([{ checks: CHECKS }, { checks: [] }])(
    'shows an empty catalog as zeros without invented rows, and the checks while their table is not empty (checks: $checks.length)',
    ({ checks }) => {
      const h = overviewHarness({
        summary: { documentCount: 0, documents: [], newerSetCount: 0 },
        checks,
      })
      try {
        expect(text(h, '[data-id="legal-document-count"]')).toBe('0')
        expect(text(h, '[data-id="legal-newer-set-count"]')).toBe('0')
        expect(texts(h.root, '[data-id="legal-count-window"]')).toEqual(['0'])
        expect(texts(h.root, '[data-id="legal-count-lapsed"]')).toEqual(['0'])
        expect(h.root.querySelectorAll('[data-document]')).toHaveLength(0)
        expect(
          h.root.querySelector('a[data-id="legal-count-lapsed-link"]'),
        ).toBe(null)
        if (checks.length) {
          expect(
            h.root.querySelectorAll('table [data-id="legal-check-row"]'),
          ).toHaveLength(checks.length)
          expect(text(h, 'table [data-check="undeclared_revision"]')).toContain(
            'retired: gone — 14 people.',
          )
        } else {
          expect(h.root.querySelector('table')).toBeNull()
          expect(h.root.querySelector('[data-id="legal-check-row"]')).toBeNull()
        }
      } finally {
        h.fixture.destroy()
      }
    },
  )

  it.each([undefined, null, { ...OVERVIEW, documentCount: 4 }])(
    'shows the standard skeleton, not zeros, before the summary and over a broken one: %j',
    (summary) => {
      const h = overviewHarness({ summary })
      try {
        expect(h.root.querySelector('[data-id="legal-overview"]')).toBeNull()
        expect(
          h.root.querySelector('[data-id="hilos-skeleton"]'),
        ).not.toBeNull()
        expect(
          h.root.querySelector('[data-id="legal-document-count"]'),
        ).toBeNull()
        expect(
          h.root.querySelector('[data-id="legal-count-window"]'),
        ).toBeNull()
        expect(
          h.root.querySelector('[data-id="legal-count-lapsed"]'),
        ).toBeNull()

        h.data('legalOverview', OVERVIEW)
        expect(text(h, '[data-id="legal-document-count"]')).toBe('2')

        h.data('legalOverview', summary)
        expect(
          h.root.querySelector('[data-id="legal-document-count"]'),
        ).toBeNull()
        expect(
          h.root.querySelector('[data-id="hilos-skeleton"]'),
        ).not.toBeNull()
      } finally {
        h.fixture.destroy()
      }
    },
  )

  it('reads Consent and Refusal from the settings rows, keeps a hidden value Hidden, and captions the past-deadline tile by the policy', () => {
    const h = overviewHarness()
    try {
      const lapsedHeading = () =>
        text(h, '[data-id="legal-overview-lapsed"] h2')
      expect(text(h, '[data-id="legal-overview-consent"]')).toContain(
        'Checkbox',
      )
      expect(text(h, '[data-id="legal-overview-refusal"]')).toContain(
        'Freeze access',
      )
      expect(lapsedHeading()).toBe('Frozen')

      h.settings('remind', 'line')
      expect(text(h, '[data-id="legal-overview-consent"]')).toContain(
        'Line below the button',
      )
      expect(text(h, '[data-id="legal-overview-refusal"]')).toContain(
        'Keep reminding',
      )
      expect(lapsedHeading()).toBe('Past deadline')
      expect(h.root.querySelector('[data-id="hilos-hidden"]')).toBeNull()

      h.settings(HIDDEN_VALUE, HIDDEN_VALUE)
      for (const tile of ['consent', 'refusal']) {
        const section = get(h.root, `[data-id="legal-overview-${tile}"]`)
        expect(
          section.querySelectorAll('[data-id="hilos-hidden"]'),
        ).toHaveLength(1)
        expect(section.textContent).toContain('Hidden')
      }
      expect(lapsedHeading()).toBe('Past deadline')
      for (const words of [
        'Checkbox',
        'Line below the button',
        'Freeze access',
        'Keep reminding',
      ])
        expect(h.root.textContent).not.toContain(words)

      h.settings('freeze', 'checkbox')
      expect(lapsedHeading()).toBe('Frozen')
      expect(h.root.querySelector('[data-id="hilos-hidden"]')).toBeNull()
      expect(h.sendTableViewport).not.toHaveBeenCalled()
    } finally {
      h.fixture.destroy()
    }
  })

  it.each([true, false])(
    'says the standard "unavailable" in the Consent and Refusal tiles while the settings table is refused, and restores the values (first response: %s)',
    (firstResponse) => {
      const h = overviewHarness({
        refusedTable: firstResponse ? 'hilosLegalSettings' : undefined,
      })
      try {
        if (!firstResponse) {
          expect(text(h, '[data-id="legal-overview-refusal"]')).toContain(
            'Freeze access',
          )
          h.refuse('hilosLegalSettings')
        }
        for (const tile of ['consent', 'refusal']) {
          const section = get(h.root, `[data-id="legal-overview-${tile}"]`)
          const unavailable = get(
            section,
            '[data-id="hilos-table-unavailable"]',
          )
          expect(unavailable.getAttribute('role')).toBe('status')
          expect(
            get(
              unavailable,
              '[data-id="hilos-table-unavailable-title"]',
            ).textContent?.trim(),
          ).toBe('List unavailable')
          expect(section.querySelector('[data-id="hilos-skeleton"]')).toBeNull()
          expect(section.querySelector('[data-id="hilos-hidden"]')).toBeNull()
          expect(section.textContent).not.toContain('Checkbox')
          expect(section.textContent).not.toContain('Freeze access')
        }
        expect(text(h, '[data-id="legal-document-count"]')).toBe('2')
        expect(
          h.root.querySelectorAll('table [data-id="legal-check-row"]'),
        ).toHaveLength(CHECKS.length)
        expect(h.root.querySelector('[data-id="legal-catalog-refusal"]')).toBe(
          null,
        )

        h.settings('remind', 'line')
        expect(
          h.root.querySelector('[data-id="hilos-table-unavailable"]'),
        ).toBeNull()
        expect(text(h, '[data-id="legal-overview-consent"]')).toContain(
          'Line below the button',
        )
        expect(text(h, '[data-id="legal-overview-refusal"]')).toContain(
          'Keep reminding',
        )
        expect(h.sendTableViewport).not.toHaveBeenCalled()
      } finally {
        h.fixture.destroy()
      }
    },
  )

  it('replaces the body with one catalog refusal under the common head', () => {
    const h = overviewHarness()
    try {
      h.data('legalCatalogRefusal', 'A deviation names a missing clause')
      expect(h.root.querySelectorAll('[role="alert"]')).toHaveLength(1)
      expect(text(h, '[data-id="legal-catalog-refusal"]')).toContain(
        'A deviation names a missing clause',
      )
      expect(h.root.querySelector('[data-id="legal-overview"]')).toBeNull()
      expect(h.root.querySelector('[data-id="legal-check-row"]')).toBeNull()
      expect(h.root.querySelectorAll('h1')).toHaveLength(1)
    } finally {
      h.fixture.destroy()
    }
  })
})
