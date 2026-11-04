import { cleanup, fireEvent, within } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import { HilosPages } from '@hilos/core/base'
import { HilosLegalDocumentSetPage as exportedPage } from '@hilos/react/admin/legal'
import { HilosLegalDocumentSetPage } from '../src/admin/legal/HilosLegalDocumentSetPage.js'
import {
  clauseRows,
  historicalDocument,
  legalDocumentTestHarness,
} from './legalDocumentTestFixture.js'

afterEach(cleanup)

describe('React legal Standard set page', () => {
  it('is exported from the package entry', () => {
    expect(exportedPage).toBe(HilosLegalDocumentSetPage)
  })

  it('uses the server window, renders clause fields and searches within its document', () => {
    const h = legalDocumentTestHarness(HilosPages.LEGAL_DOCUMENT_SET, {
      rows: clauseRows,
    })
    const view = h.mount(
      <HilosLegalDocumentSetPage context={h.context} />,
      true,
    )

    expect(h.liveWindows()).toEqual({ [h.tableKey]: 1 })
    expect(h.sendViewport).not.toHaveBeenCalled()
    expect(h.dispatch).not.toHaveBeenCalled()
    expect(view.getByRole('heading', { level: 1 }).textContent).toBe(
      'Terms of use',
    )
    expect(
      view.container.querySelector('[data-id="legal-set-metadata"]')
        ?.textContent,
    ).toContain('Set 1 from 2026-01-01 · substantial')
    const table = within(view.getByRole('table'))
    expect(table.getByText('retention').tagName).toBe('CODE')
    expect(table.getByText('Retention is limited')).not.toBeNull()

    fireEvent.change(
      view.getByRole('searchbox', { name: 'Clause or statement' }),
      {
        target: { value: 'retention' },
      },
    )
    expect(h.sendViewport).toHaveBeenLastCalledWith(
      HilosPages.LEGAL_DOCUMENT_SET,
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

  it('keeps an empty historical document window when set metadata is absent', () => {
    const h = legalDocumentTestHarness(HilosPages.LEGAL_DOCUMENT_SET, {
      document: historicalDocument,
      rows: [],
    })
    const view = h.mount(<HilosLegalDocumentSetPage context={h.context} />)

    expect(view.getByRole('heading', { level: 1 }).textContent).toBe(
      'retired/document',
    )
    expect(
      view.container.querySelector('[data-id="legal-document-set-version"]'),
    ).toBeNull()
    expect(
      view.container.querySelector('[data-id="legal-document-revision"]'),
    ).toBeNull()
    expect(view.getByRole('table')).not.toBeNull()
    expect(
      view.container.querySelector('[data-id="hilos-table-unavailable"]'),
    ).toBeNull()
    expect(h.liveWindows()).toEqual({ [h.tableKey]: 1 })
    view.unmount()
    expect(h.liveWindows()).toEqual({})
  })
})
