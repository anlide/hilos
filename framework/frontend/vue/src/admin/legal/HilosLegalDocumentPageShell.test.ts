import { HilosPages, pageIdentity } from '@hilos/core/base'
import { describe, expect, it } from 'vitest'
import { nextTick } from 'vue'
import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosPageHead from '../../HilosPageHead.vue'
import HilosLegalDocumentPageShell from './HilosLegalDocumentPageShell.vue'
import HilosLegalDocumentPage from './HilosLegalDocumentPage.vue'
import HilosLegalDocumentSetPage from './HilosLegalDocumentSetPage.vue'
import HilosLegalDocumentDeviationsPage from './HilosLegalDocumentDeviationsPage.vue'
import HilosLegalDocumentRevisionsPage from './HilosLegalDocumentRevisionsPage.vue'
import {
  clauseRows,
  documentHarness,
  documentTabs,
  historicalDocument,
  legalDocument,
} from '../../../test/legalDocumentFixture.js'

const pages = [
  HilosLegalDocumentPage,
  HilosLegalDocumentSetPage,
  HilosLegalDocumentDeviationsPage,
  HilosLegalDocumentRevisionsPage,
]

describe('Document shell and assembled pages', () => {
  it('uses one real entity head and one ordinary link row on all four pages', async () => {
    for (const [index, { page, label }] of documentTabs.entries()) {
      const h = documentHarness(pages[index]!, page)
      try {
        await nextTick()
        expect(h.view.findAllComponents(HilosAdminPage)).toHaveLength(1)
        expect(h.view.findAllComponents(HilosPageHead)).toHaveLength(1)
        expect(h.view.findAll('h1').map((node) => node.text())).toEqual([
          'Terms',
        ])
        const crumbs = h.view.get('[data-id="hilos-breadcrumb"]')
        expect(crumbs.text()).toBe('HilosLegalDocumentsTerms')
        expect(
          crumbs.findAll('a').map((node) => node.attributes('href')),
        ).toEqual(['/hilos', '/hilos/legal', '/hilos/legal/documents'])
        const nav = h.view.get('nav[aria-label="Document pages"]')
        expect(
          nav
            .findAll('a')
            .map((node) => [node.text(), node.attributes('href')]),
        ).toEqual([
          ['Overview', '/hilos/legal/document/terms'],
          ['Standard set', '/hilos/legal/document/terms/set'],
          ['Deviations', '/hilos/legal/document/terms/deviations'],
          ['Revisions', '/hilos/legal/document/terms/revisions'],
        ])
        expect(
          nav.findAll('[aria-current="page"]').map((node) => node.text()),
        ).toEqual([label])
        expect(h.view.find('[role="tablist"]').exists()).toBe(false)
        expect(h.view.find('[data-id="hilos-admin-children"]').exists()).toBe(
          false,
        )
        expect(h.view.get('[data-id="legal-document-key"]').text()).toBe(
          'terms',
        )
        expect(
          h.view.get('[data-id="legal-document-set-version"]').text(),
        ).toBe('Set 1')
        expect(
          h.view.get('[data-id="legal-document-revision"]').text(),
        ).toContain('2026-09-27 · substantial · Effective 2026-10-05')
        expect(h.view.find('h2[data-id="hilos-table-title"]').exists()).toBe(
          false,
        )
        if (page !== HilosPages.LEGAL_DOCUMENT) {
          expect(h.view.findAll('table')).toHaveLength(1)
          expect(h.view.get('table').attributes('aria-labelledby')).toBe(
            h.view.get('h1').attributes('id'),
          )
          expect(h.view.findAll('h2')).toHaveLength(0)
        }
        expect(h.sendViewport).not.toHaveBeenCalled()
        expect(h.dispatch).not.toHaveBeenCalled()
      } finally {
        h.view.unmount()
      }
      expect(h.registered.size).toBe(0)
      expect(h.listenerCount()).toBe(0)
    }
  })

  it('binds the permitted data name reactively and disposes it on departure', async () => {
    const h = documentHarness(HilosLegalDocumentPageShell)
    const identity = pageIdentity(h.scopes)
    try {
      expect(identity.get()?.pageHeadOwner?.label).toBe('Terms')
      h.scope.data.set('legalDocument', {
        ...legalDocument,
        document: 'privacy',
      })
      await nextTick()
      expect(h.view.get('h1').text()).toBe('Privacy policy')
      expect(identity.get()?.pageHeadOwner?.label).toBe('Privacy policy')
      h.scope.data.set('legalDocument', historicalDocument)
      await nextTick()
      expect(h.view.get('h1').text()).toBe('retired/document')
      expect(h.view.get('[data-id="hilos-page-head-block"]').text()).toContain(
        'Not in code',
      )
      expect(
        h.view.find('[data-id="legal-document-set-version"]').exists(),
      ).toBe(false)
      expect(h.view.find('[data-id="legal-document-revision"]').exists()).toBe(
        false,
      )
    } finally {
      h.view.unmount()
    }
    expect(identity.get()?.pageHeadOwner?.label).toBe('Document')
  })

  it.each([undefined, null, { ...legalDocument, covered: -1 }])(
    'clears stale metadata when common data is absent or malformed: %j',
    async (value) => {
      const h = documentHarness(
        HilosLegalDocumentSetPage,
        HilosPages.LEGAL_DOCUMENT_SET,
        { rows: clauseRows },
      )
      try {
        h.scope.data.set('legalDocument', value)
        await nextTick()
        expect(
          h.view.find('[data-id="legal-document-revision"]').exists(),
        ).toBe(false)
        expect(h.view.find('table').exists()).toBe(false)
        expect(h.view.find('[data-id="hilos-skeleton"]').exists()).toBe(true)
        expect(h.view.get('h1').text()).toBe('Document')
        h.scope.data.set('legalDocument', legalDocument)
        await nextTick()
        expect(h.view.get('table').text()).toContain('Retention is limited')
      } finally {
        h.view.unmount()
      }
    },
  )

  it('leaves the unanswered head and body as skeletons without navigation or counters', () => {
    const h = documentHarness(
      HilosLegalDocumentPage,
      HilosPages.LEGAL_DOCUMENT,
      { answered: false },
    )
    try {
      expect(h.view.find('h1').exists()).toBe(false)
      expect(h.view.find('nav').exists()).toBe(false)
      expect(h.view.find('[data-id="legal-count-covered"]').exists()).toBe(
        false,
      )
      expect(h.view.find('[data-id="hilos-skeleton"]').exists()).toBe(true)
      expect(h.sendViewport).not.toHaveBeenCalled()
      expect(h.dispatch).not.toHaveBeenCalled()
    } finally {
      h.view.unmount()
    }
  })

  it('replaces every body with one catalog refusal while retaining the common navigation', async () => {
    for (const [index, { page }] of documentTabs.entries()) {
      const h = documentHarness(pages[index]!, page)
      try {
        h.scope.data.set('legalCatalogRefusal', '<b>A clause is missing</b>')
        h.scope.data.set('legalDocument', null)
        await nextTick()
        expect(h.view.findAll('[role="alert"]')).toHaveLength(1)
        const refusal = h.view.get('[data-id="legal-catalog-refusal"]')
        expect(refusal.text()).toContain('<b>A clause is missing</b>')
        expect(refusal.find('b').exists()).toBe(false)
        expect(h.view.find('table').exists()).toBe(false)
        expect(h.view.find('[data-id="legal-count-covered"]').exists()).toBe(
          false,
        )
        expect(h.view.find('[data-id="hilos-table-empty"]').exists()).toBe(
          false,
        )
        expect(
          h.view.get('nav[aria-label="Document pages"]').findAll('a'),
        ).toHaveLength(4)
        expect(h.view.findAll('h1')).toHaveLength(1)
      } finally {
        h.view.unmount()
      }
    }
  })
})
