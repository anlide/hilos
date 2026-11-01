// The React legal Documents tab over the real core factories, scopes, head and table.
import { StrictMode } from 'react'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
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
import { HilosLegalDocumentsPage as exportedPage } from '@hilos/react/admin/legal'

import { HilosLegalDocumentsPage } from '../src/admin/legal/HilosLegalDocumentsPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

afterEach(cleanup)

const revision = {
  revisionId: 'current',
  publishedOn: '2026-09-27',
  effectiveOn: '2026-10-01',
  significance: 'substantial',
  setVersion: 1,
  deviationCount: 0,
}
const rows: TableRow[] = [
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
type Listener = (signal: never) => void

/** Real carriers, head and table renderer; the connection replays the first page answer. */
function harness(
  policy: unknown = 'freeze',
  refusal: string | null = null,
  documents = rows,
) {
  const scopes = new ScopeManager()
  const scope = scopes.openPage(HilosPages.LEGAL_DOCUMENTS)
  scope.data.set('legalCatalogRefusal', refusal)
  scope.data.set('pageLabel', 'Documents')
  scope.data.set('pageLead', '')
  scope.data.set('pageBreadcrumb', [])
  scope.data.set('pageChildren', [])
  const listeners = new Map<string, Set<Listener>>()
  const live = new Map<string, number>()
  const controllers = new Map<string, TableViewportController<unknown>>()
  const sendViewport = vi.fn(() => true)
  const sendRendered = vi.fn<(page: string, table: string) => boolean>(
    () => true,
  )
  const dispatch = vi.fn()
  const answer = (value: unknown) => ({
    type: 'page_response',
    data: {
      page: 'hilos_legal_documents',
      payload: {
        windows: {
          hilosLegalDocuments: {
            rows: documents,
            totalCount: documents.length,
            totalExact: true,
            firstAnchor: null,
            lastAnchor: null,
            limit: 25,
            sort: [{ field: 'publishedOn', direction: 'desc' }],
          },
          hilosLegalSettings: {
            rows: [
              {
                rowKey: 'legal.refusal_after_deadline',
                slots: { setting: { value, defaultValue: value } },
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
    },
  })
  const context = {
    scopes,
    connection: {
      on(event: string, listener: Listener) {
        const group = listeners.get(event) ?? new Set()
        listeners.set(event, group)
        group.add(listener)
        if (event === 'projectSignal') listener(answer(policy) as never)
        return () => group.delete(listener)
      },
      registerTableWindow(
        key: string,
        controller: TableViewportController<unknown>,
      ) {
        live.set(key, (live.get(key) ?? 0) + 1)
        controllers.set(key, controller)
      },
      unregisterTableWindow(key: string) {
        live.set(key, (live.get(key) ?? 0) - 1)
      },
      sendTableViewport: sendViewport,
      sendTableRendered: sendRendered,
    },
    actions: { dispatch },
  } as unknown as HilosLegalContext
  const router: HilosRouter = {
    currentRoute: createSignal({
      page: HilosPages.LEGAL_DOCUMENTS,
      params: {},
      admin: true,
    }),
    currentPath: createSignal('/hilos/legal/documents'),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: pageIdentity(scopes),
    dashboardSections: createSignal(undefined),
    resolvePath: resolveHilosPath,
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    onLeave: () => () => {},
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }

  return {
    context,
    router,
    scope,
    controllers,
    sendViewport,
    sendRendered,
    dispatch,
    mount(strictMode = false) {
      const page = (
        <HilosRouterContext.Provider value={router}>
          <HilosLegalDocumentsPage context={context} />
        </HilosRouterContext.Provider>
      )
      return render(strictMode ? <StrictMode>{page}</StrictMode> : page)
    },
    policy(value: unknown) {
      act(() => {
        for (const listener of listeners.get('projectSignal') ?? [])
          listener(answer(value) as never)
      })
    },
    /** The table windows registered and not yet unregistered, by key. */
    liveWindows: () =>
      Object.fromEntries([...live].filter(([, count]) => count !== 0)),
    listenerCount: () =>
      [...listeners.values()].reduce((sum, group) => sum + group.size, 0),
  }
}

/** The data rows of the one table, without its narrow-screen cards. */
function bodyRows(container: HTMLElement): HTMLElement[] {
  return Array.from(container.querySelectorAll<HTMLElement>('tbody tr'))
}

/** One element the row must carry. */
function inRow(row: HTMLElement | undefined, selector: string): HTMLElement {
  const element = row?.querySelector<HTMLElement>(selector)
  expect(element).not.toBeNull()
  return element!
}

/** The sortable headers of the one table, as caption and sort state. */
function headers(container: HTMLElement): [string, string | null][] {
  return Array.from(container.querySelectorAll('table th[aria-sort]')).map(
    (header) => [
      header.textContent?.trim() ?? '',
      header.getAttribute('aria-sort'),
    ],
  )
}

describe('React Legal Documents with the real table frame', () => {
  it('is exported from the package entry', () => {
    expect(exportedPage).toBe(HilosLegalDocumentsPage)
  })

  it('renders the first server window, search, counts, links and opening order without a mount request', () => {
    const h = harness()
    const view = h.mount()
    try {
      expect(h.liveWindows()).toEqual({
        hilosLegalDocuments: 1,
        hilosLegalSettings: 1,
      })
      expect(h.sendViewport).not.toHaveBeenCalled()
      expect(h.dispatch).not.toHaveBeenCalled()
      expect(
        h.sendRendered.mock.calls.map(([page, key]) => [page, key]),
      ).toEqual([
        ['hilos_legal_documents', 'hilosLegalSettings'],
        ['hilos_legal_documents', 'hilosLegalDocuments'],
      ])
      const headings = Array.from(view.container.querySelectorAll('h1'))
      expect(headings.map((heading) => heading.textContent)).toEqual([
        'Documents',
      ])
      const tables = view.container.querySelectorAll('table')
      expect(tables).toHaveLength(1)
      expect(tables[0]!.getAttribute('aria-labelledby')).toBe(headings[0]!.id)
      expect(
        view.container.querySelector('input[aria-label="Document or key"]'),
      ).not.toBeNull()
      expect(headers(view.container)).toEqual([
        ['Document', 'none'],
        ['Revision', 'descending'],
        ['Covered', 'none'],
        ['In the window', 'none'],
        ['Frozen', 'none'],
      ])
      const body = bodyRows(view.container)
      expect(
        body.map(
          (row) => inRow(row, '[data-id="legal-document-row"]').textContent,
        ),
      ).toEqual(['Server terms label', 'retired'])
      expect(body[0]!.textContent).toContain('2026-09-27 · substantial')
      expect(body[0]!.textContent).toContain('Effective from 2026-10-01')
      expect(
        inRow(body[0], '[data-id="legal-count-covered"]').textContent,
      ).toBe('4')
      expect(inRow(body[0], '[data-id="legal-count-window"]').textContent).toBe(
        '2',
      )
      const lapsed = inRow(body[0], '[data-id="legal-count-lapsed-link"]')
      expect(lapsed.getAttribute('href')).toBe('/hilos/users/terms')
      expect(lapsed.textContent).toBe('1')
      expect(
        inRow(body[0], '[data-id="legal-document-open"]').getAttribute('href'),
      ).toBe('/hilos/legal/document/terms')
      expect(body[1]!.textContent).toContain('Not in code')
      expect(
        body[1]!.querySelector('[data-id="legal-count-lapsed-link"]'),
      ).toBeNull()
      expect(inRow(body[1], '[data-id="legal-count-lapsed"]').textContent).toBe(
        '0',
      )
      expect(
        inRow(body[1], '[data-id="legal-document-open"]').getAttribute('href'),
      ).toBe('/hilos/legal/document/retired')
      expect(tables[0]!.textContent).not.toContain('Freeze access')
      fireEvent.click(
        view.container.querySelector('table th[aria-sort] button')!,
      )
      expect(h.sendViewport).toHaveBeenLastCalledWith(
        'hilos_legal_documents',
        'hilosLegalDocuments',
        expect.objectContaining({
          sort: [{ field: 'documentLabel', direction: 'asc' }],
        }),
      )
    } finally {
      view.unmount()
    }
    expect(h.liveWindows()).toEqual({})
    expect(h.listenerCount()).toBe(0)
    for (const controller of h.controllers.values())
      expect(controller.rows.get()).toEqual([])
  })

  it.each([
    ['document', '/hilos/legal/document/document'],
    ['documents', '/hilos/legal/document/documents'],
    ['acceptances', '/hilos/legal/document/acceptances'],
    ['settings', '/hilos/legal/document/settings'],
    ['terms /?#%', '/hilos/legal/document/terms%20%2F%3F%23%25'],
    ['terms%2Fprivacy', '/hilos/legal/document/terms%252Fprivacy'],
  ])('opens the actual row key %s through the shared resolver', (key, path) => {
    const h = harness('freeze', null, [{ ...rows[1]!, rowKey: key }])
    const view = h.mount()
    const open = inRow(
      bodyRows(view.container)[0],
      '[data-id="legal-document-open"]',
    )
    expect(open.getAttribute('data-document')).toBe(key)
    expect(open.getAttribute('href')).toBe(path)
  })

  it('links the past-deadline count to the people it counts, only when there are any (HIL-945)', () => {
    const h = harness('freeze', null, [])
    const windowListeners: ((signal: { data: unknown }) => void)[] = []
    Object.assign(h.context.connection, {
      on(event: string, listener: (signal: never) => void): () => void {
        if (event === 'tableWindow') {
          windowListeners.push(
            listener as unknown as (signal: { data: unknown }) => void,
          )
        }

        return () => {}
      },
    })
    const revision = {
      revisionId: 'current',
      publishedOn: '2026-09-27',
      effectiveOn: '2026-09-27',
      significance: 'editorial',
      setVersion: 1,
      deviationCount: 0,
    }
    const view = h.mount()

    act(() => {
      for (const listener of windowListeners) {
        listener({
          data: {
            page: 'hilos_legal_documents',
            tableKey: 'hilosLegalDocuments',
            rows: [
              {
                rowKey: 'terms',
                documentLabel: 'Terms of use',
                publishedOn: '2026-09-27',
                covered: 1,
                window: 0,
                lapsed: 2,
              },
              {
                rowKey: 'privacy',
                documentLabel: 'Privacy policy',
                publishedOn: '2026-09-27',
                covered: 3,
                window: 0,
                lapsed: 0,
              },
            ].map(({ rowKey, ...counts }) => ({
              rowKey,
              slots: { document: { declared: true, revision, ...counts } },
            })),
            totalCount: 2,
          },
        })
      }
    })

    // The table draws each cell twice — its rows and its narrow-screen cards — so
    // every copy is checked, not the first.
    const links = Array.from(
      view.container.querySelectorAll('[data-id="legal-count-lapsed-link"]'),
    )
    expect(links.length).toBeGreaterThan(0)
    for (const link of links) {
      expect(link.getAttribute('href')).toBe('/hilos/users/terms')
      expect(link.getAttribute('data-document')).toBe('terms')
      expect(
        link.querySelector('[data-id="legal-count-lapsed"]')?.textContent,
      ).toBe('2')
    }
    const unlinked = Array.from(
      view.container.querySelectorAll('[data-id="legal-count-lapsed"]'),
    )
      .filter((count) => count.closest('a') === null)
      .map((count) => count.textContent)
    expect(unlinked.length).toBeGreaterThan(0)
    expect(new Set(unlinked)).toEqual(new Set(['0']))
  })

  it('says "Past deadline" over the count while the refusal setting is hidden (HIL-1260)', () => {
    const h = harness('freeze', null, [])
    const windowListeners: ((signal: { data: unknown }) => void)[] = []
    Object.assign(h.context.connection, {
      on(event: string, listener: (signal: never) => void): () => void {
        if (event === 'tableWindow') {
          windowListeners.push(
            listener as unknown as (signal: { data: unknown }) => void,
          )
        }

        return () => {}
      },
    })
    const revision = {
      revisionId: 'current',
      publishedOn: '2026-09-27',
      effectiveOn: '2026-09-27',
      significance: 'editorial',
      setVersion: 1,
      deviationCount: 0,
    }
    const view = h.mount()

    // The policy word moved from the count's cell to its column header.
    const label = () =>
      view.container.querySelectorAll('table th[aria-sort]')[4]?.textContent

    act(() => {
      for (const listener of windowListeners) {
        listener({
          data: {
            page: 'hilos_legal_documents',
            tableKey: 'hilosLegalDocuments',
            rows: [
              {
                rowKey: 'terms',
                slots: {
                  document: {
                    documentLabel: 'Terms of use',
                    publishedOn: '2026-09-27',
                    declared: true,
                    revision,
                    covered: 1,
                    window: 0,
                    lapsed: 2,
                  },
                },
              },
            ],
            totalCount: 1,
          },
        })
      }
    })
    expect(label()).toContain('Frozen')

    act(() => {
      for (const listener of windowListeners) {
        listener({
          data: {
            page: 'hilos_legal_documents',
            tableKey: 'hilosLegalSettings',
            rows: [
              {
                rowKey: 'legal.refusal_after_deadline',
                slots: {
                  setting: {
                    value: { _hidden: true },
                    defaultValue: { _hidden: true },
                  },
                },
              },
            ],
            totalCount: 1,
          },
        })
      }
    })
    expect(label()).toContain('Past deadline')
  })

  it('reacts to remind and hidden policy in the header without putting policy words in cells', () => {
    const h = harness('remind')
    const view = h.mount()
    for (const [value, label] of [
      ['freeze', 'Frozen'],
      ['remind', 'Past deadline'],
      [{ _hidden: true }, 'Past deadline'],
    ] as const) {
      h.policy(value)
      expect(headers(view.container).at(-1)?.[0]).toBe(label)
      expect(view.container.querySelector('tbody')?.textContent).not.toContain(
        label,
      )
    }
    expect(h.sendViewport).not.toHaveBeenCalled()
  })

  it('uses the standard empty state', () => {
    const h = harness('freeze', null, [])
    const view = h.mount()
    expect(view.container.textContent).toContain(
      'The project has not declared any documents.',
    )
    expect(h.sendViewport).not.toHaveBeenCalled()
  })

  it('shows one catalog refusal as text in place of the table and disposes both carriers', () => {
    const h = harness('freeze', '<b>Broken legal catalog</b>')
    const view = h.mount()
    const alerts = view.container.querySelectorAll('[role="alert"]')
    expect(alerts).toHaveLength(1)
    expect(alerts[0]!.textContent).toContain('<b>Broken legal catalog</b>')
    expect(alerts[0]!.querySelector('b')).toBeNull()
    expect(view.container.querySelector('table')).toBeNull()
    view.unmount()
    expect(h.liveWindows()).toEqual({})
    expect(h.listenerCount()).toBe(0)
  })

  it('keeps one live window registration per table across the StrictMode effect replay', () => {
    const h = harness()
    const view = h.mount(true)
    expect(h.liveWindows()).toEqual({
      hilosLegalDocuments: 1,
      hilosLegalSettings: 1,
    })
    expect(bodyRows(view.container)).toHaveLength(2)
    expect(h.sendViewport).not.toHaveBeenCalled()
    view.unmount()
    expect(h.liveWindows()).toEqual({})
    expect(h.listenerCount()).toBe(0)
  })
})
