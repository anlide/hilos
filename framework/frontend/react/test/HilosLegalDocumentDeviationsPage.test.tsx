import { cleanup, fireEvent } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import { HilosPages, type TableRow } from '@hilos/core/base'
import { HilosLegalDocumentDeviationsPage as exportedPage } from '@hilos/react/admin/legal'
import { HilosLegalDocumentDeviationsPage } from '../src/admin/legal/HilosLegalDocumentDeviationsPage.js'
import {
  deviationRows,
  historicalDocument,
  legalDocument,
  legalDocumentTestHarness,
} from './legalDocumentTestFixture.js'

afterEach(cleanup)

describe('React legal Deviations page', () => {
  it('is exported from the package entry', () => {
    expect(exportedPage).toBe(HilosLegalDocumentDeviationsPage)
  })

  it('uses its server window, draws deviation text and searches within its document', () => {
    const unclassified: TableRow = {
      rowKey: 'other-clause',
      slots: {
        deviation: {
          ...legalDocument.deviations[0],
          clauseKey: 'other-clause',
          statement: 'Another difference',
          text: 'First paragraph.\n\nSecond paragraph.',
          direction: null,
          position: 1,
        },
      },
    }
    const h = legalDocumentTestHarness(HilosPages.LEGAL_DOCUMENT_DEVIATIONS, {
      rows: [...deviationRows, unclassified],
    })
    const view = h.mount(
      <HilosLegalDocumentDeviationsPage context={h.context} />,
      true,
    )

    expect(h.liveWindows()).toEqual({ [h.tableKey]: 1 })
    expect(h.sendViewport).not.toHaveBeenCalled()
    expect(h.dispatch).not.toHaveBeenCalled()
    const firstRow = view.container.querySelector(
      'table [data-id="hilos-table-row-retention"]',
    )
    expect(firstRow?.textContent).toContain('Messages stay')
    expect(firstRow?.textContent).toContain('Standard: Retention is limited')
    expect(firstRow?.textContent).toContain('The project retains its messages.')
    const secondRow = view.container.querySelector(
      '[data-id="hilos-table-row-other-clause"]',
    )
    expect(secondRow?.textContent).toContain('First paragraph.')
    expect(secondRow?.textContent).toContain('Second paragraph.')
    expect(secondRow?.querySelectorAll('td')[1]?.textContent?.trim()).toBe('—')

    fireEvent.change(
      view.getByRole('searchbox', { name: 'Statement, text or clause' }),
      { target: { value: 'retention' } },
    )
    expect(h.sendViewport).toHaveBeenLastCalledWith(
      HilosPages.LEGAL_DOCUMENT_DEVIATIONS,
      h.tableKey,
      expect.objectContaining({
        filter: { document: 'terms', search: 'retention' },
      }),
    )
    expect(h.dispatch).not.toHaveBeenCalled()

    view.unmount()
    expect(h.liveWindows()).toEqual({})
    expect(h.listenerCount()).toBe(0)
    for (const controller of h.controllers.values())
      expect(controller.rows.get()).toEqual([])
  })

  it('keeps a successful historical deviations window without document metadata', () => {
    const h = legalDocumentTestHarness(HilosPages.LEGAL_DOCUMENT_DEVIATIONS, {
      document: historicalDocument,
      rows: [],
    })
    const view = h.mount(
      <HilosLegalDocumentDeviationsPage context={h.context} />,
    )

    expect(view.getByRole('heading', { level: 1 }).textContent).toBe(
      'retired/document',
    )
    expect(view.getByRole('table')).not.toBeNull()
    expect(
      view.container.querySelector('[data-id="hilos-table-unavailable"]'),
    ).toBeNull()
    expect(h.liveWindows()).toEqual({ [h.tableKey]: 1 })
    view.unmount()
    expect(h.liveWindows()).toEqual({})
  })
})
