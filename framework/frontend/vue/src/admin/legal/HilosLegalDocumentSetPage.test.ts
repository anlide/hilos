import { HilosPages } from '@hilos/core/base'
import { describe, expect, it } from 'vitest'
import { nextTick } from 'vue'
import {
  clauseRows,
  documentHarness,
  historicalDocument,
} from '../../../test/legalDocumentFixture.js'
import HilosLegalDocumentSetPage from './HilosLegalDocumentSetPage.vue'

describe('Document Set', () => {
  it('consumes its first window, renders its fields and sends scoped server search', async () => {
    const h = documentHarness(
      HilosLegalDocumentSetPage,
      HilosPages.LEGAL_DOCUMENT_SET,
      { rows: clauseRows },
    )
    try {
      await nextTick()
      expect(h.registered).toEqual(new Set([h.tableKey]))
      expect(h.view.get('table').text()).toContain('Retention is limited')
      expect(h.sendViewport).not.toHaveBeenCalled()
      expect(h.dispatch).not.toHaveBeenCalled()

      expect(h.view.get('[data-id="legal-set-metadata"]').text()).toContain(
        'Set 1 from 2026-01-01',
      )
      expect(h.view.text()).not.toContain('Retention clarified')

      const search = h.view.get('input[type="search"]')
      expect(search.attributes('aria-label')).toBe('Clause or statement')
      await search.setValue('retention')
      expect(h.sendViewport).toHaveBeenLastCalledWith(
        HilosPages.LEGAL_DOCUMENT_SET,
        h.tableKey,
        expect.objectContaining({
          filter: { document: 'terms', search: 'retention' },
        }),
      )
      expect(h.dispatch).not.toHaveBeenCalled()
      await h.view
        .get('[data-id="hilos-table-sort-clauseKey"]')
        .trigger('click')
      expect(h.sendViewport).toHaveBeenLastCalledWith(
        HilosPages.LEGAL_DOCUMENT_SET,
        h.tableKey,
        expect.objectContaining({
          filter: { document: 'terms', search: 'retention' },
          sort: [{ field: 'clauseKey', direction: 'asc' }],
        }),
      )
    } finally {
      h.view.unmount()
    }
    expect(h.registered.size).toBe(0)
    expect(h.listenerCount()).toBe(0)
  })

  it.each([true, false])(
    'shows a refused window as unavailable and recovers (first response: %s)',
    async (refused) => {
      const h = documentHarness(
        HilosLegalDocumentSetPage,
        HilosPages.LEGAL_DOCUMENT_SET,
        { rows: clauseRows, refused },
      )
      try {
        if (!refused) h.refuse()
        await nextTick()
        expect(
          h.view.get('[data-id="hilos-table-unavailable"]').attributes('role'),
        ).toBe('status')
        expect(h.view.find('[data-id="hilos-table-empty"]').exists()).toBe(
          false,
        )
        h.window(clauseRows)
        await nextTick()
        expect(
          h.view.find('[data-id="hilos-table-unavailable"]').exists(),
        ).toBe(false)
        expect(h.view.get('table').text()).toContain('Retention is limited')
      } finally {
        h.view.unmount()
      }
    },
  )

  it('keeps historical metadata absent without losing the successful window', async () => {
    const h = documentHarness(
      HilosLegalDocumentSetPage,
      HilosPages.LEGAL_DOCUMENT_SET,
      { document: historicalDocument, rows: [] },
    )
    try {
      await nextTick()
      expect(h.view.get('h1').text()).toBe('retired/document')
      expect(
        h.view.find('[data-id="legal-document-set-version"]').exists(),
      ).toBe(false)
      expect(h.view.find('[data-id="legal-document-revision"]').exists()).toBe(
        false,
      )
      expect(h.view.find('[data-id="hilos-table-unavailable"]').exists()).toBe(
        false,
      )
      expect(h.view.find('tbody tr[data-id^=hilos-table-row-]').exists()).toBe(
        false,
      )
    } finally {
      h.view.unmount()
    }
  })
})
