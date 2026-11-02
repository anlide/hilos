import { StrictMode } from 'react'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  createSignal,
  HIDDEN_VALUE,
  HilosPages,
  pageIdentity,
  resolveHilosPath,
  ScopeManager,
  type HilosLegalContext,
  type HilosRouter,
  type TableViewportController,
} from '@hilos/core/base'
import { consentTermsWithClauses } from '../../core/test/legal/consentFixture.js'
import { reconsentPreview } from '../../core/test/legal/reconsentFixture.js'
import { HilosLegalDocumentPage } from '../src/admin/legal/HilosLegalDocumentPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

afterEach(cleanup)

const legalDocument = {
  document: 'terms',
  declared: true,
  covered: 21,
  window: 7,
  lapsed: 3,
  standardSetCatalogClass: 'Hilos\\Legal\\StandardSetCatalog',
  projectCatalogClass: 'Project\\Legal\\LegalCatalog',
  revision: {
    revisionId: 'current',
    publishedOn: '2026-09-27',
    effectiveOn: '2026-10-05',
    significance: 'substantial',
    setVersion: 1,
    deviationCount: 1,
  },
  set: {
    version: 1,
    publishedOn: '2026-01-01',
    significance: 'substantial',
    clauses: [{ clauseKey: 'retention', statement: 'Retention is limited' }],
  },
  newerSet: {
    version: 2,
    publishedOn: '2026-10-01',
    significance: 'editorial',
    changes: [
      {
        clauseKey: 'retention',
        kind: 'changed',
        title: 'Retention clarified',
        before: null,
        after: null,
      },
    ],
  },
  deviations: [
    {
      clauseKey: 'retention',
      standardStatement: 'Retention is limited',
      statement: 'Messages stay',
      text: 'The project retains its messages.',
      direction: 'stricter',
    },
  ],
}

/** Real core factories and page head; the transport replays the first settings window. */
function harness(strictMode = false) {
  const scopes = new ScopeManager()
  const scope = scopes.openPage(HilosPages.LEGAL_DOCUMENT)
  for (const [key, value] of Object.entries({
    pageLabel: 'Overview',
    pageLead: '',
    pageChildren: [],
    pageHeadOwner: {
      page: HilosPages.LEGAL_DOCUMENT,
      label: 'Document',
      lead: '',
      icon: 'bi-file-earmark-text',
    },
    pageBreadcrumb: [],
    pageTabs: [
      { page: HilosPages.LEGAL_DOCUMENT, label: 'Overview' },
      { page: HilosPages.LEGAL_DOCUMENT_SET, label: 'Standard set' },
      { page: HilosPages.LEGAL_DOCUMENT_DEVIATIONS, label: 'Deviations' },
      { page: HilosPages.LEGAL_DOCUMENT_REVISIONS, label: 'Revisions' },
    ],
    legalDocument,
    legalCatalogRefusal: null,
  }))
    scope.data.set(key, value)
  type Listener = (frame: never) => void
  const listeners = new Map<string, Set<Listener>>()
  const live = new Map<string, number>()
  const controllers: TableViewportController<unknown>[] = []
  const sendViewport = vi.fn(() => true)
  const sendRendered = vi.fn(() => true)
  const dispatch = vi.fn()
  const settingsWindow = (value: unknown) => ({
    rows: [
      {
        rowKey: 'legal.refusal_after_deadline',
        slots: { setting: { value, defaultValue: 'freeze' } },
      },
    ],
    totalCount: 1,
    totalExact: true,
    firstAnchor: null,
    lastAnchor: null,
    limit: 25,
    sort: null,
  })
  const answer = {
    type: 'page_response',
    data: {
      page: HilosPages.LEGAL_DOCUMENT,
      payload: { windows: { hilosLegalSettings: settingsWindow('freeze') } },
    },
  }
  const context = {
    scopes,
    connection: {
      on(event: string, listener: Listener) {
        const group = listeners.get(event) ?? new Set()
        listeners.set(event, group)
        group.add(listener)
        if (event === 'projectSignal') listener(answer as never)
        return () => group.delete(listener)
      },
      registerTableWindow(
        key: string,
        controller: TableViewportController<unknown>,
      ) {
        live.set(key, (live.get(key) ?? 0) + 1)
        controllers.push(controller)
      },
      unregisterTableWindow(key: string) {
        live.set(key, (live.get(key) ?? 0) - 1)
      },
      sendTableViewport: sendViewport,
      sendTableRendered: sendRendered,
    },
    actions: { dispatch },
  } as unknown as HilosLegalContext
  const router = {
    currentRoute: createSignal({
      page: HilosPages.LEGAL_DOCUMENT,
      params: { documentKey: 'terms' },
      admin: true,
    }),
    currentPath: createSignal('/hilos/legal/document/terms'),
    pageIdentity: pageIdentity(scopes),
    resolvePath: resolveHilosPath,
  } as unknown as HilosRouter
  const page = (
    <HilosRouterContext.Provider value={router}>
      <HilosLegalDocumentPage context={context} />
    </HilosRouterContext.Provider>
  )
  const view = render(strictMode ? <StrictMode>{page}</StrictMode> : page)
  return {
    view,
    scope,
    dispatch,
    sendViewport,
    sendRendered,
    controllers,
    liveWindows: () =>
      Object.fromEntries([...live].filter(([, count]) => count !== 0)),
    listenerCount: () =>
      [...listeners.values()].reduce((sum, group) => sum + group.size, 0),
    policy(value: unknown) {
      act(() => {
        for (const listener of listeners.get('tableWindow') ?? [])
          listener({
            type: 'table_window',
            data: {
              page: HilosPages.LEGAL_DOCUMENT,
              tableKey: 'hilosLegalSettings',
              ...settingsWindow(value),
            },
          } as never)
      })
    },
  }
}

describe('React legal Document Overview', () => {
  it('shows server counts and sources with only a hidden settings window', () => {
    const h = harness()
    expect(h.liveWindows()).toEqual({ hilosLegalSettings: 1 })
    for (const [key, value] of [
      ['covered', '21'],
      ['window', '7'],
      ['lapsed', '3'],
    ]) {
      expect(
        h.view.container.querySelector(`[data-id="legal-count-${key}"]`)
          ?.textContent,
      ).toBe(value)
    }
    expect(h.view.getByRole('link', { name: '3' }).getAttribute('href')).toBe(
      '/hilos/users/terms',
    )
    const sources = h.view.container.querySelector(
      '[data-id="legal-document-sources"]',
    )!
    expect(sources.textContent).toContain(legalDocument.standardSetCatalogClass)
    expect(sources.textContent).toContain(legalDocument.projectCatalogClass)
    expect(
      h.view.container.querySelector('[data-id="legal-set-newer"]')
        ?.textContent,
    ).toContain('Hilos has released set 2')
    expect(
      h.view.container.querySelector('[data-id="legal-set-newer"]')
        ?.textContent,
    ).toContain('Retention clarified')
    expect(
      h.view.container.querySelector('[data-id="legal-document-set-version"]')
        ?.textContent,
    ).toBe('Set 1')
    expect(h.view.queryByRole('table')).toBeNull()
    expect(h.view.container.textContent).not.toContain(
      'The project retains its messages.',
    )
    expect(h.view.container.querySelector('[data-id="legal-set"]')).toBeNull()
    expect(
      h.view.container.querySelector('[data-id="legal-deviation-row"]'),
    ).toBeNull()
    expect(h.sendViewport).not.toHaveBeenCalled()
    expect(h.dispatch).not.toHaveBeenCalled()
    expect(h.sendRendered).toHaveBeenCalledWith(
      HilosPages.LEGAL_DOCUMENT,
      'hilosLegalSettings',
      expect.any(Array),
    )
    act(() =>
      h.scope.data.set('legalDocument', {
        ...legalDocument,
        covered: 0,
        window: 0,
        lapsed: 0,
        projectCatalogClass: null,
        newerSet: null,
      }),
    )
    for (const key of ['covered', 'window', 'lapsed'])
      expect(
        h.view.container.querySelector(`[data-id="legal-count-${key}"]`)
          ?.textContent,
      ).toBe('0')
    expect(
      h.view.container.querySelector('[data-id="legal-count-lapsed-link"]'),
    ).toBeNull()
    expect(sources.textContent).not.toContain(legalDocument.projectCatalogClass)
    expect(
      h.view.container.querySelector('[data-id="legal-set-newer"]'),
    ).toBeNull()
    h.view.unmount()
    expect(h.liveWindows()).toEqual({})
    expect(h.listenerCount()).toBe(0)
    for (const controller of h.controllers)
      expect(controller.rows.get()).toEqual([])
  })

  it.each([undefined, null, { ...legalDocument, covered: -1 }])(
    'clears the previous Overview for missing or malformed page data: %j',
    (value) => {
      const h = harness()
      expect(
        h.view.getByRole('heading', { name: 'Current revision' }),
      ).not.toBeNull()
      act(() => h.scope.data.set('legalDocument', value))
      expect(
        h.view.queryByRole('heading', { name: 'Current revision' }),
      ).toBeNull()
      for (const id of [
        'legal-document-sources',
        'legal-count-covered',
        'legal-count-window',
        'legal-count-lapsed',
        'legal-set-newer',
        'legal-preview-consent',
        'legal-preview-reconsent',
      ])
        expect(h.view.container.querySelector(`[data-id="${id}"]`)).toBeNull()
      expect(
        h.view.container.querySelector('[data-id="hilos-skeleton"]'),
      ).not.toBeNull()
      expect(h.liveWindows()).toEqual({ hilosLegalSettings: 1 })
      act(() =>
        h.scope.data.set('legalDocument', { ...legalDocument, covered: 42 }),
      )
      expect(
        h.view.container.querySelector('[data-id="legal-count-covered"]')
          ?.textContent,
      ).toBe('42')
      expect(h.sendViewport).not.toHaveBeenCalled()
      expect(h.dispatch).not.toHaveBeenCalled()
    },
  )

  it('follows the live refusal policy and uses neutral words when it is hidden', () => {
    const h = harness()
    for (const [policy, label] of [
      ['freeze', 'Frozen'],
      ['remind', 'Past deadline'],
      [HIDDEN_VALUE, 'Past deadline'],
    ] as const) {
      h.policy(policy)
      expect(
        h.view.container.querySelector('[data-id="legal-document-lapsed"] h3')
          ?.textContent,
      ).toBe(label)
      expect(h.view.container.textContent).not.toContain('Freeze access')
      expect(h.view.container.textContent).not.toContain('Keep reminding')
      expect(h.view.queryByRole('table')).toBeNull()
    }
    expect(h.sendViewport).not.toHaveBeenCalled()
  })

  it('keeps one live settings window across StrictMode replay and frees every controller', () => {
    const h = harness(true)
    expect(h.liveWindows()).toEqual({ hilosLegalSettings: 1 })
    expect(
      h.view.container.querySelector('[data-id="legal-document-lapsed"] h3')
        ?.textContent,
    ).toBe('Frozen')
    h.policy('remind')
    expect(
      h.view.container.querySelector('[data-id="legal-document-lapsed"] h3')
        ?.textContent,
    ).toBe('Past deadline')
    expect(h.sendViewport).not.toHaveBeenCalled()
    expect(h.dispatch).not.toHaveBeenCalled()
    h.view.unmount()
    expect(h.liveWindows()).toEqual({})
    expect(h.listenerCount()).toBe(0)
    for (const controller of h.controllers)
      expect(controller.rows.get()).toEqual([])
  })

  it('opens both consent documents in a read-only React preview', async () => {
    const h = harness()
    h.dispatch.mockReturnValue({
      done: Promise.resolve({ reply: consentTermsWithClauses() }),
    })
    await act(async () => {
      fireEvent.click(
        h.view.getByRole('button', { name: 'Consent screen at registration' }),
      )
    })
    const preview = document.querySelector('[data-id="legal-consent-preview"]')!
    expect(
      preview.querySelectorAll('[data-id="legal-consent-deviation"]'),
    ).toHaveLength(4)
    expect(preview.querySelector('[data-id="auth-submit"]')).toBeNull()
    fireEvent.click(
      document.querySelector('[data-id="legal-consent-preview-close"]')!,
    )
    expect(
      document.querySelector('[data-id="legal-consent-preview"]'),
    ).toBeNull()
    await act(async () => {
      fireEvent.click(
        h.view.getByRole('button', { name: 'Consent screen at registration' }),
      )
    })
    expect(
      h.dispatch.mock.calls.map(([name, payload]) => [name, payload]),
    ).toEqual([
      ['hilos_legal_consent', {}],
      ['hilos_legal_consent', {}],
    ])
    expect(
      document.querySelector('[data-id="legal-consent-preview"]'),
    ).not.toBeNull()
    h.view.unmount()
    expect(document.querySelector('[role="dialog"]')).toBeNull()
    expect(h.liveWindows()).toEqual({})
    expect(h.listenerCount()).toBe(0)
  })

  it('shows the server unpublished sentence as text in the React preview', async () => {
    const h = harness()
    act(() =>
      h.scope.data.set('legalDocument', {
        ...legalDocument,
        revision: null,
        set: null,
        newerSet: null,
        deviations: [],
      }),
    )
    const unpublishedMessage = '<b>This preview has no published terms yet.</b>'
    h.dispatch.mockReturnValue({
      done: Promise.resolve({
        reply: {
          ...consentTermsWithClauses(),
          documents: [],
          unpublishedMessage,
        },
      }),
    })
    await act(async () => {
      fireEvent.click(
        h.view.getByRole('button', { name: 'Consent screen at registration' }),
      )
    })
    const preview = document.querySelector('[data-id="legal-consent-preview"]')!
    const unpublished = preview.querySelector(
      '[data-id="legal-consent-unpublished"]',
    )!
    expect(unpublished.textContent).toBe(unpublishedMessage)
    expect(unpublished.querySelector('b')).toBeNull()
    expect(preview.querySelector('[data-id="auth-submit"]')).toBeNull()
    fireEvent.click(
      document.querySelector('[data-id="legal-consent-preview-close"]')!,
    )
    expect(
      document.querySelector('[data-id="legal-consent-preview"]'),
    ).toBeNull()
  })

  it('opens the read-only re-consent screen and frees the open preview on departure', async () => {
    const h = harness()
    h.dispatch.mockReturnValue({
      done: Promise.resolve({ reply: reconsentPreview('window') }),
    })
    await act(async () => {
      fireEvent.click(h.view.getByRole('button', { name: 'Re-consent screen' }))
    })
    expect(
      document.querySelector('[data-id="legal-reconsent-preview"]'),
    ).not.toBeNull()
    expect(
      document.querySelector<HTMLButtonElement>(
        '[data-id="legal-reconsent-accept"]',
      )?.disabled,
    ).toBe(true)
    expect(
      h.dispatch.mock.calls.map(([name, payload]) => [name, payload]),
    ).toEqual([['hilos_legal_reconsent_preview', { document: 'terms' }]])
    h.view.unmount()
    expect(document.querySelector('[role="dialog"]')).toBeNull()
    expect(h.liveWindows()).toEqual({})
    expect(h.listenerCount()).toBe(0)
  })

  it.each([
    ['consent', 'legal-consent-preview-retry', 'legal-consent-preview-close'],
    ['reconsent', 'legal-reconsent-retry', 'legal-reconsent-preview-close'],
  ])(
    'retries and closes the read-only %s preview',
    async (kind, retry, close) => {
      const h = harness()
      h.dispatch
        .mockReturnValueOnce({ done: Promise.reject(new Error('Unavailable')) })
        .mockReturnValueOnce({
          done: Promise.resolve({
            reply:
              kind === 'consent'
                ? consentTermsWithClauses()
                : reconsentPreview('window'),
          }),
        })
      await act(async () => {
        fireEvent.click(
          h.view.container.querySelector(`[data-id="legal-preview-${kind}"]`)!,
        )
      })
      await act(async () => {
        fireEvent.click(document.querySelector(`[data-id="${retry}"]`)!)
      })
      expect(
        h.dispatch.mock.calls.map(([name, payload]) => [name, payload]),
      ).toEqual(
        kind === 'consent'
          ? [
              ['hilos_legal_consent', {}],
              ['hilos_legal_consent', {}],
            ]
          : [
              ['hilos_legal_reconsent_preview', { document: 'terms' }],
              ['hilos_legal_reconsent_preview', { document: 'terms' }],
            ],
      )
      expect(document.querySelector(`[data-id="${retry}"]`)).toBeNull()
      fireEvent.click(document.querySelector(`[data-id="${close}"]`)!)
      expect(document.querySelector('[role="dialog"]')).toBeNull()
    },
  )

  it.each(['consent', 'reconsent'])(
    'ignores a late %s reply after leaving the page',
    async (kind) => {
      const h = harness()
      let reply!: (value: unknown) => void
      h.dispatch.mockReturnValue({
        done: new Promise((resolve) => {
          reply = resolve
        }),
      })
      fireEvent.click(
        h.view.container.querySelector(`[data-id="legal-preview-${kind}"]`)!,
      )
      h.view.unmount()
      await act(async () => {
        reply({
          reply:
            kind === 'consent'
              ? consentTermsWithClauses()
              : reconsentPreview('window'),
        })
      })
      expect(document.querySelector('[role="dialog"]')).toBeNull()
      expect(h.liveWindows()).toEqual({})
      expect(h.listenerCount()).toBe(0)
    },
  )
})
