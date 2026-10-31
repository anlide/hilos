import { HilosPages } from '@hilos/core/base'
import { describe, expect, it } from 'vitest'
import { nextTick } from 'vue'
import {
  revisionRows,
  documentHarness,
  historicalDocument,
} from '../../../test/legalDocumentFixture.js'
import HilosLegalDocumentRevisionsPage from './HilosLegalDocumentRevisionsPage.vue'

describe('Document Revisions', () => {
  it('consumes its first window, renders its fields and sends scoped server search', async () => {
    const h = documentHarness(
      HilosLegalDocumentRevisionsPage,
      HilosPages.LEGAL_DOCUMENT_REVISIONS,
      { rows: revisionRows },
    )
    try {
      await nextTick()
      expect(h.registered).toEqual(new Set([h.tableKey]))
      expect(h.view.get('table').text()).toContain('current')
      expect(h.sendViewport).not.toHaveBeenCalled()
      expect(h.dispatch).not.toHaveBeenCalled()

      const links = h.view
        .get('table')
        .findAll('[data-id="legal-revision-open"]')
      expect(links.map((node) => node.attributes('href'))).toEqual([
        '/hilos/legal/document/terms/revision/current',
        '/hilos/legal/document/terms/revision/removed%2Frevision',
      ])
      const historical = h.view
        .get('table tbody')
        .findAll('tr')
        .find((node) => node.text().includes('removed/revision'))!
      expect(historical.text()).toContain('Not in code')
      expect(historical.text()).toContain('14')
      expect(historical.text()).not.toContain('2026-09-27')
      expect(
        historical
          .findAll('td')
          .slice(1, 4)
          .map((node) => node.text()),
      ).toEqual(['—', '—', '—'])
      expect(h.view.get('th[aria-sort="descending"]').text()).toContain(
        'Revision',
      )

      const search = h.view.get('input[type="search"]')
      expect(search.attributes('aria-label')).toBe(
        'Revision or publication date',
      )
      await search.setValue('retention')
      expect(h.sendViewport).toHaveBeenLastCalledWith(
        HilosPages.LEGAL_DOCUMENT_REVISIONS,
        h.tableKey,
        expect.objectContaining({
          filter: { document: 'terms', search: 'retention' },
        }),
      )
      expect(h.dispatch).not.toHaveBeenCalled()
      await h.view
        .get('[data-id="hilos-table-sort-heldCount"]')
        .trigger('click')
      expect(h.sendViewport).toHaveBeenLastCalledWith(
        HilosPages.LEGAL_DOCUMENT_REVISIONS,
        h.tableKey,
        expect.objectContaining({
          filter: { document: 'terms', search: 'retention' },
          sort: [{ field: 'heldCount', direction: 'asc' }],
        }),
      )
    } finally {
      h.view.unmount()
    }
    expect(h.registered.size).toBe(0)
    expect(h.listenerCount()).toBe(0)
  })

  it('refreshes held counts in place and applies a server-announced move on request', async () => {
    const h = documentHarness(
      HilosLegalDocumentRevisionsPage,
      HilosPages.LEGAL_DOCUMENT_REVISIONS,
      { rows: revisionRows },
    )
    try {
      const changed = {
        ...revisionRows[0]!,
        slots: {
          revision: { ...revisionRows[0]!.slots.revision, heldCount: 6 },
        },
      }
      const address = {
        page: HilosPages.LEGAL_DOCUMENT_REVISIONS,
        tableKey: h.tableKey,
        rowKey: 'current',
      }
      h.emit('tableViewportDelta', {
        type: 'table_viewport_delta',
        data: { ...address, kind: 'row_updated', row: changed },
      })
      await nextTick()
      expect(
        h.view
          .get('table tbody')
          .findAll('tr[data-id^="hilos-table-row-"]')[0]!
          .findAll('td')[4]!
          .text(),
      ).toBe('6')
      expect(h.view.find('[data-id="hilos-table-apply"]').exists()).toBe(false)
      const order = () =>
        h.view
          .get('table tbody')
          .findAll('tr[data-id^="hilos-table-row-"]')
          .map((node) => node.attributes('data-id'))
      h.emit('tableViewportDelta', {
        type: 'table_viewport_delta',
        data: { ...address, kind: 'row_moved', position: 1, row: changed },
      })
      await nextTick()
      expect(order()).toEqual([
        'hilos-table-row-current',
        'hilos-table-row-removed/revision',
      ])
      await h.view.get('[data-id="hilos-table-apply"]').trigger('click')
      expect(order()).toEqual([
        'hilos-table-row-removed/revision',
        'hilos-table-row-current',
      ])
      expect(h.dispatch).not.toHaveBeenCalled()
    } finally {
      h.view.unmount()
    }
  })

  it.each([true, false])(
    'shows a refused window as unavailable and recovers (first response: %s)',
    async (refused) => {
      const h = documentHarness(
        HilosLegalDocumentRevisionsPage,
        HilosPages.LEGAL_DOCUMENT_REVISIONS,
        { rows: revisionRows, refused },
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
        h.window(revisionRows)
        await nextTick()
        expect(
          h.view.find('[data-id="hilos-table-unavailable"]').exists(),
        ).toBe(false)
        expect(h.view.get('table').text()).toContain('current')
      } finally {
        h.view.unmount()
      }
    },
  )

  it('keeps historical metadata absent without losing the successful window', async () => {
    const h = documentHarness(
      HilosLegalDocumentRevisionsPage,
      HilosPages.LEGAL_DOCUMENT_REVISIONS,
      { document: historicalDocument, rows: revisionRows },
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
      expect(h.view.get('table').text()).toContain('removed/revision')
    } finally {
      h.view.unmount()
    }
  })
})
