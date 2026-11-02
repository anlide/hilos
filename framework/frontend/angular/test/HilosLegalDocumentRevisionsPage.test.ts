import { TestBed } from '@angular/core/testing'
import { HilosPages } from '@hilos/core/base'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { HilosLegalDocumentRevisionsPage } from '../src/admin/legal/HilosLegalDocumentRevisionsPage.js'
import {
  DOCUMENT,
  legalDocumentBench,
  legalDocumentHarness,
  REVISION_ROWS,
} from './helpers/hilosLegalDocument.js'

afterEach(() => {
  TestBed.resetTestingModule()
  vi.restoreAllMocks()
})

const PAGE = HilosPages.LEGAL_DOCUMENT_REVISIONS
const TABLE = 'hilosLegalRevisions'
const harness = (options = {}) =>
  legalDocumentHarness(
    HilosLegalDocumentRevisionsPage,
    PAGE,
    {},
    { initialAnswer: true, tableKey: TABLE, rows: REVISION_ROWS, ...options },
  )

describe('HilosLegalDocumentRevisionsPage', () => {
  it('renders revision metadata and exact Open routes, then sends scoped search and order', () => {
    const h = harness()
    const table = h.root.querySelector('table')!
    expect(table.textContent).toContain('Current')
    expect(table.textContent).toContain('Not in code')
    expect(table.textContent).toContain('14')
    const links = [
      ...table.querySelectorAll<HTMLAnchorElement>(
        '[data-id="legal-revision-open"]',
      ),
    ]
    expect(links.map((link) => link.getAttribute('href'))).toEqual([
      '/hilos/legal/document/terms/revision/current',
      '/hilos/legal/document/terms/revision/removed%2Frevision',
    ])
    expect(links.map((link) => link.getAttribute('data-revision'))).toEqual([
      'current',
      'removed/revision',
    ])
    expect(h.sendTableViewport).not.toHaveBeenCalled()
    expect(h.dispatch).not.toHaveBeenCalled()

    const search = h.root.querySelector<HTMLInputElement>(
      '[data-id="hilos-table-search"]',
    )!
    expect(search.getAttribute('aria-label')).toBe(
      'Revision or publication date',
    )
    search.value = 'retention'
    search.dispatchEvent(new Event('input', { bubbles: true }))
    h.fixture.detectChanges()
    expect(h.sendTableViewport).toHaveBeenLastCalledWith(
      PAGE,
      TABLE,
      expect.objectContaining({
        filter: { document: 'terms', search: 'retention' },
      }),
    )

    h.root
      .querySelector<HTMLElement>('[data-id="hilos-table-sort-heldCount"]')!
      .click()
    h.fixture.detectChanges()
    expect(h.sendTableViewport).toHaveBeenLastCalledWith(
      PAGE,
      TABLE,
      expect.objectContaining({
        filter: { document: 'terms', search: 'retention' },
        sort: [{ field: 'heldCount', direction: 'asc' }],
      }),
    )
    expect(h.dispatch).not.toHaveBeenCalled()
    h.fixture.destroy()
    expect(h.registered.size).toBe(0)
    expect(h.listenerCount()).toBe(0)
  })

  it('distinguishes an empty window from a refused one and restores rows', () => {
    const h = legalDocumentHarness(
      HilosLegalDocumentRevisionsPage,
      PAGE,
      {},
      { initialAnswer: true, tableKey: TABLE, rows: [] },
    )
    expect(h.root.querySelector('[data-id="hilos-table-empty"]')).not.toBeNull()
    expect(
      h.root.querySelector('[data-id="hilos-table-unavailable"]'),
    ).toBeNull()
    expect(h.sendTableViewport).not.toHaveBeenCalled()

    h.refuseWindow()
    expect(
      h.root
        .querySelector('[data-id="hilos-table-unavailable"]')
        ?.getAttribute('role'),
    ).toBe('status')
    expect(h.root.querySelector('[data-id="hilos-table-empty"]')).toBeNull()
    h.window(REVISION_ROWS)
    expect(
      h.root.querySelector('[data-id="hilos-table-unavailable"]'),
    ).toBeNull()
    expect(h.root.querySelector('table')?.textContent).toContain('current')
    h.fixture.destroy()
  })

  it('updates the route filter and releases each controller on context change and destruction', () => {
    const h = harness()
    const former = h.controllers[0]!
    expect(former.filter.get().document).toBe('terms')
    expect(h.sendTableViewport).not.toHaveBeenCalled()

    h.route.set({ page: PAGE, params: { documentKey: 'privacy' }, admin: true })
    h.fixture.detectChanges()
    expect(former.filter.get().document).toBe('privacy')

    const replacement = legalDocumentBench(PAGE, {
      initialAnswer: true,
      tableKey: TABLE,
      rows: REVISION_ROWS,
      document: { ...DOCUMENT, document: 'privacy' },
      routeDocument: 'privacy',
    })
    h.fixture.componentRef.setInput('context', replacement.context)
    h.fixture.detectChanges()
    expect(h.registered.size).toBe(0)
    expect(h.listenerCount()).toBe(0)
    expect(former.rows.get()).toEqual([])
    expect([...replacement.registered.keys()]).toEqual([TABLE])
    expect(replacement.controllers[0]!.filter.get().document).toBe('privacy')
    expect(replacement.controllers[0]!.rows.get()).toHaveLength(2)
    expect(replacement.sendTableViewport).not.toHaveBeenCalled()

    h.fixture.destroy()
    expect(replacement.registered.size).toBe(0)
    expect(replacement.listenerCount()).toBe(0)
    expect(replacement.controllers[0]!.rows.get()).toEqual([])
    expect(replacement.sendTableViewport).not.toHaveBeenCalled()
  })
})
