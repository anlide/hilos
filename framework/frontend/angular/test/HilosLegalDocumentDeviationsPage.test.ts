import { TestBed } from '@angular/core/testing'
import { HilosPages } from '@hilos/core/base'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { HilosLegalDocumentDeviationsPage } from '../src/admin/legal/HilosLegalDocumentDeviationsPage.js'
import {
  DEVIATION_ROWS,
  DOCUMENT,
  legalDocumentBench,
  legalDocumentHarness,
  text,
} from './helpers/hilosLegalDocument.js'

afterEach(() => {
  TestBed.resetTestingModule()
  vi.restoreAllMocks()
})

const PAGE = HilosPages.LEGAL_DOCUMENT_DEVIATIONS
const TABLE = 'hilosLegalDocumentDeviations'
const harness = (options = {}) =>
  legalDocumentHarness(
    HilosLegalDocumentDeviationsPage,
    PAGE,
    {},
    { initialAnswer: true, tableKey: TABLE, rows: DEVIATION_ROWS, ...options },
  )

describe('HilosLegalDocumentDeviationsPage', () => {
  it('renders the first window and sends document-scoped search and order', () => {
    const h = harness()
    const table = h.root.querySelector('table')!
    expect(text(table, 'tbody')).toContain('Messages stay')
    expect(table.textContent).toContain('Standard: Retention is limited')
    expect(table.textContent).toContain('The project retains its messages.')
    expect(table.textContent).toContain('Deletion follows the schedule.')
    expect(table.textContent).toContain('stricter')
    expect(h.sendTableViewport).not.toHaveBeenCalled()
    expect(h.dispatch).not.toHaveBeenCalled()

    const search = h.root.querySelector<HTMLInputElement>(
      '[data-id="hilos-table-search"]',
    )!
    expect(search.getAttribute('aria-label')).toBe('Statement, text or clause')
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
      .querySelector<HTMLElement>('[data-id="hilos-table-sort-direction"]')!
      .click()
    h.fixture.detectChanges()
    expect(h.sendTableViewport).toHaveBeenLastCalledWith(
      PAGE,
      TABLE,
      expect.objectContaining({
        filter: { document: 'terms', search: 'retention' },
        sort: [{ field: 'direction', direction: 'asc' }],
      }),
    )
    expect(h.dispatch).not.toHaveBeenCalled()
    h.fixture.destroy()
    expect(h.registered.size).toBe(0)
    expect(h.listenerCount()).toBe(0)
  })

  it('distinguishes an empty window from a refused one and restores rows', () => {
    const h = legalDocumentHarness(
      HilosLegalDocumentDeviationsPage,
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
    h.window(DEVIATION_ROWS)
    expect(
      h.root.querySelector('[data-id="hilos-table-unavailable"]'),
    ).toBeNull()
    expect(h.root.querySelector('table')?.textContent).toContain(
      'Messages stay',
    )
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
      rows: DEVIATION_ROWS,
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
    expect(replacement.controllers[0]!.rows.get()).toHaveLength(1)
    expect(replacement.sendTableViewport).not.toHaveBeenCalled()

    h.fixture.destroy()
    expect(replacement.registered.size).toBe(0)
    expect(replacement.listenerCount()).toBe(0)
    expect(replacement.controllers[0]!.rows.get()).toEqual([])
    expect(replacement.sendTableViewport).not.toHaveBeenCalled()
  })
})
