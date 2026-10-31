import { HilosPages } from '@hilos/core/base'
import { describe, expect, it } from 'vitest'
import { nextTick } from 'vue'
import {
  deviationRows,
  documentHarness,
  historicalDocument,
  legalDocument,
} from '../../../test/legalDocumentFixture.js'
import HilosLegalDocumentDeviationsPage from './HilosLegalDocumentDeviationsPage.vue'

describe('Document Deviations', () => {
  it('consumes its first window, renders its fields and sends scoped server search', async () => {
    const h = documentHarness(
      HilosLegalDocumentDeviationsPage,
      HilosPages.LEGAL_DOCUMENT_DEVIATIONS,
      { rows: deviationRows },
    )
    try {
      await nextTick()
      expect(h.registered).toEqual(new Set([h.tableKey]))
      expect(h.view.get('table').text()).toContain(
        'The project retains its messages.',
      )
      expect(h.sendViewport).not.toHaveBeenCalled()
      expect(h.dispatch).not.toHaveBeenCalled()

      expect(h.view.get('table').text()).toContain('Messages stay')
      expect(h.view.get('table').text()).toContain(
        'Standard: Retention is limited',
      )
      expect(h.view.get('table').text()).toContain('stricter')
      h.window([
        {
          ...deviationRows[0]!,
          slots: {
            deviation: {
              ...legalDocument.deviations[0],
              position: 0,
              direction: 'unknown',
            },
          },
        },
      ])
      await nextTick()
      expect(h.view.get('table').text()).not.toContain('stricter')
      expect(h.view.get('table').text()).not.toContain('looser')

      const search = h.view.get('input[type="search"]')
      expect(search.attributes('aria-label')).toBe('Statement, text or clause')
      await search.setValue('retention')
      expect(h.sendViewport).toHaveBeenLastCalledWith(
        HilosPages.LEGAL_DOCUMENT_DEVIATIONS,
        h.tableKey,
        expect.objectContaining({
          filter: { document: 'terms', search: 'retention' },
        }),
      )
      expect(h.dispatch).not.toHaveBeenCalled()
      await h.view
        .get('[data-id="hilos-table-sort-direction"]')
        .trigger('click')
      expect(h.sendViewport).toHaveBeenLastCalledWith(
        HilosPages.LEGAL_DOCUMENT_DEVIATIONS,
        h.tableKey,
        expect.objectContaining({
          filter: { document: 'terms', search: 'retention' },
          sort: [{ field: 'direction', direction: 'asc' }],
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
        HilosLegalDocumentDeviationsPage,
        HilosPages.LEGAL_DOCUMENT_DEVIATIONS,
        { rows: deviationRows, refused },
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
        h.window(deviationRows)
        await nextTick()
        expect(
          h.view.find('[data-id="hilos-table-unavailable"]').exists(),
        ).toBe(false)
        expect(h.view.get('table').text()).toContain(
          'The project retains its messages.',
        )
      } finally {
        h.view.unmount()
      }
    },
  )

  it('keeps historical metadata absent without losing the successful window', async () => {
    const h = documentHarness(
      HilosLegalDocumentDeviationsPage,
      HilosPages.LEGAL_DOCUMENT_DEVIATIONS,
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
