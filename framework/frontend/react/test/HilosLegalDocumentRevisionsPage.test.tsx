import { cleanup, fireEvent } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import { HilosPages, type TableRow } from '@hilos/core/base'
import { HilosLegalDocumentRevisionsPage as exportedPage } from '@hilos/react/admin/legal'
import { HilosLegalDocumentRevisionsPage } from '../src/admin/legal/HilosLegalDocumentRevisionsPage.js'
import {
  historicalDocument,
  legalDocumentTestHarness,
  revisionRows,
} from './legalDocumentTestFixture.js'

afterEach(cleanup)

describe('React legal Revisions page', () => {
  it('is exported from the package entry', () => {
    expect(exportedPage).toBe(HilosLegalDocumentRevisionsPage)
  })

  it('uses the server window, preserves absent metadata, applies live changes and searches in scope', () => {
    const h = legalDocumentTestHarness(HilosPages.LEGAL_DOCUMENT_REVISIONS, {
      rows: revisionRows,
    })
    const view = h.mount(
      <HilosLegalDocumentRevisionsPage context={h.context} />,
      true,
    )

    expect(h.liveWindows()).toEqual({ [h.tableKey]: 1 })
    expect(h.sendViewport).not.toHaveBeenCalled()
    expect(h.dispatch).not.toHaveBeenCalled()
    const rows = () =>
      Array.from(
        view.container.querySelectorAll<HTMLElement>(
          'tbody tr[data-id^="hilos-table-row-"]',
        ),
      )
    expect(rows()).toHaveLength(2)
    const currentRow = rows()[0]!
    expect(currentRow.textContent).toContain('Current')
    const historicalRow = rows()[1]!
    expect(historicalRow.textContent).toContain('Not in code')
    expect(historicalRow.textContent).toContain('14')
    expect(historicalRow.textContent).not.toContain('2026-09-27')
    expect(
      Array.from(historicalRow.querySelectorAll('td'))
        .slice(1, 4)
        .map((cell) => cell.textContent?.trim()),
    ).toEqual(['—', '—', '—'])
    expect(
      Array.from(
        view
          .getByRole('table')
          .querySelectorAll('[data-id="legal-revision-open"]'),
      ).map((link) => (link as HTMLAnchorElement).getAttribute('href')),
    ).toEqual([
      '/hilos/legal/document/terms/revision/current',
      '/hilos/legal/document/terms/revision/removed%2Frevision',
    ])

    const changed: TableRow = {
      ...revisionRows[0]!,
      slots: {
        revision: {
          ...(revisionRows[0]!.slots.revision as Record<string, unknown>),
          heldCount: 6,
        },
      },
    }
    const address = {
      page: HilosPages.LEGAL_DOCUMENT_REVISIONS,
      tableKey: h.tableKey,
      rowKey: 'current',
    }
    h.emit('tableViewportDelta', {
      ...address,
      kind: 'row_updated',
      row: changed,
    })
    expect(rows()[0]!.querySelectorAll('td')[4]!.textContent).toBe('6')
    expect(
      view.container.querySelector('[data-id="hilos-table-apply"]'),
    ).toBeNull()

    h.emit('tableViewportDelta', {
      ...address,
      kind: 'row_moved',
      position: 1,
      row: changed,
    })
    expect(rows().map((row) => row.getAttribute('data-id'))).toEqual([
      'hilos-table-row-current',
      'hilos-table-row-removed/revision',
    ])
    fireEvent.click(
      view.container.querySelector('[data-id="hilos-table-apply"]')!,
    )
    expect(rows().map((row) => row.getAttribute('data-id'))).toEqual([
      'hilos-table-row-removed/revision',
      'hilos-table-row-current',
    ])

    fireEvent.change(
      view.getByRole('searchbox', { name: 'Revision or publication date' }),
      { target: { value: 'retention' } },
    )
    expect(h.sendViewport).toHaveBeenLastCalledWith(
      HilosPages.LEGAL_DOCUMENT_REVISIONS,
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

  it('keeps revision history when the historical document has no current metadata', () => {
    const h = legalDocumentTestHarness(HilosPages.LEGAL_DOCUMENT_REVISIONS, {
      document: historicalDocument,
      rows: revisionRows,
    })
    const view = h.mount(
      <HilosLegalDocumentRevisionsPage context={h.context} />,
    )

    expect(view.getByRole('heading', { level: 1 }).textContent).toBe(
      'retired/document',
    )
    expect(
      view.container.querySelector('[data-id="legal-document-set-version"]'),
    ).toBeNull()
    expect(
      view.container.querySelector('[data-id="legal-document-revision"]'),
    ).toBeNull()
    expect(view.container.textContent).toContain('removed/revision')
    expect(
      view.container.querySelector('[data-id="hilos-table-unavailable"]'),
    ).toBeNull()
    view.unmount()
    expect(h.liveWindows()).toEqual({})
  })
})
