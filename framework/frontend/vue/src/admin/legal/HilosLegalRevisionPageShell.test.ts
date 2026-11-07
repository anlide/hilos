import { HilosPages, pageIdentity, resolvePageTitle } from '@hilos/core/base'
import { describe, expect, it } from 'vitest'
import { nextTick } from 'vue'
import { readTextWithoutAriaHidden } from '../../../../test/breadcrumb.js'
import HilosAdminPage from '../../HilosAdminPage.vue'
import HilosPageHead from '../../HilosPageHead.vue'
import HilosLegalRevisionPageShell from './HilosLegalRevisionPageShell.vue'
import HilosLegalRevisionPage from './HilosLegalRevisionPage.vue'
import HilosLegalRevisionTextPage from './HilosLegalRevisionTextPage.vue'
import {
  legalRevision,
  recordedRevision,
  revisionHarness,
  revisionTabs,
} from '../../../test/legalRevisionFixture.js'

const pages = [HilosLegalRevisionPage, HilosLegalRevisionTextPage]

describe('Revision shell', () => {
  it('uses one named entity head, matching title and ordinary links on both pages', async () => {
    for (const [index, { page, label }] of revisionTabs.entries()) {
      const h = revisionHarness(pages[index]!, page)
      try {
        await nextTick()
        expect(h.view.findAllComponents(HilosAdminPage)).toHaveLength(1)
        expect(h.view.findAllComponents(HilosPageHead)).toHaveLength(1)
        expect(h.view.findAll('h1').map((node) => node.text())).toEqual([
          'Revision of 2026-01-01',
        ])
        const identity = pageIdentity(h.scopes).get()!
        expect(
          resolvePageTitle(
            page,
            {},
            'Chat',
            identity.label,
            true,
            identity.pageHeadOwner?.label,
          ),
        ).toBe('Revision of 2026-01-01 · Chat')
        const crumbs = h.view.get('nav[aria-label="Breadcrumb"]')
        expect(
          crumbs
            .findAll('li')
            .map((crumb) => readTextWithoutAriaHidden(crumb.element)),
        ).toEqual([
          'Hilos',
          'Legal',
          'Documents',
          'Terms of use',
          'Revisions',
          'Revision of 2026-01-01',
        ])
        expect(
          crumbs.findAll('a').map((node) => node.attributes('href')),
        ).toEqual([
          '/hilos',
          '/hilos/legal',
          '/hilos/legal/documents',
          '/hilos/legal/document/terms',
          '/hilos/legal/document/terms/revisions',
        ])
        expect(
          crumbs
            .findAll('[aria-current="page"]')
            .map((node) => readTextWithoutAriaHidden(node.element)),
        ).toEqual(['Revision of 2026-01-01'])
        const nav = h.view.get('nav[aria-label="Revision pages"]')
        expect(
          nav
            .findAll('a')
            .map((node) => [node.text(), node.attributes('href')]),
        ).toEqual([
          [
            'Overview',
            '/hilos/legal/document/terms/revision/historical%2Frevision',
          ],
          [
            'Text',
            '/hilos/legal/document/terms/revision/historical%2Frevision/text',
          ],
        ])
        expect(
          nav.findAll('[aria-current="page"]').map((node) => node.text()),
        ).toEqual([label])
        expect(h.view.find('[role="tablist"]').exists()).toBe(false)
        expect(h.view.get('[data-id="legal-revision-metadata"]').text()).toBe(
          'Terms of use · Published 2026-01-01 · Effective 2026-01-15 · Set 1',
        )
        expect(
          h.view.get('[data-id="legal-revision-significance"]').text(),
        ).toBe('editorial')
        expect(h.view.find('[data-id="legal-revision-current"]').exists()).toBe(
          false,
        )
        h.scope.data.set('legalRevision', { ...legalRevision, current: true })
        await nextTick()
        expect(h.view.get('[data-id="legal-revision-current"]').text()).toBe(
          'Current',
        )
      } finally {
        h.view.unmount()
      }
    }
  })

  it('updates both entity names from the current answer and disposes their registrations', async () => {
    const h = revisionHarness(HilosLegalRevisionPageShell)
    const identity = pageIdentity(h.scopes)
    try {
      h.scope.data.set('legalRevision', {
        ...legalRevision,
        document: 'privacy',
        revision: { ...legalRevision.revision!, publishedOn: '2025-12-31' },
      })
      await nextTick()
      expect(h.view.get('h1').text()).toBe('Revision of 2025-12-31')
      expect(
        identity
          .get()
          ?.breadcrumb.find((crumb) => crumb.page === HilosPages.LEGAL_DOCUMENT)
          ?.label,
      ).toBe('Privacy policy')
      h.scopes.openPage(HilosPages.LEGAL_REVISION_TEXT)
      await nextTick()
      expect(h.view.find('[data-id="legal-revision-metadata"]').exists()).toBe(
        false,
      )
      expect(identity.get()).toBeUndefined()
      for (const [key, value] of Object.entries({
        pageLabel: 'Other page',
        pageLead: '',
        pageChildren: [],
        pageHeadOwner: {
          page: HilosPages.LEGAL_REVISION,
          label: 'Revision',
          lead: '',
          icon: null,
        },
        pageBreadcrumb: [
          { page: HilosPages.LEGAL_DOCUMENT, label: 'Document' },
          { page: HilosPages.LEGAL_REVISION, label: 'Revision' },
        ],
        legalRevision,
      }))
        h.scopes.page()!.data.set(key, value)
      expect(identity.get()?.pageHeadOwner?.label).toBe('Revision')
    } finally {
      h.view.unmount()
    }
    h.scopes.openPage(HilosPages.LEGAL_REVISION)
    h.scopes.page()!.data.set('pageLabel', 'Revision')
    h.scopes.page()!.data.set('pageLead', '')
    h.scopes.page()!.data.set('pageChildren', [])
    h.scopes.page()!.data.set('pageHeadOwner', {
      page: HilosPages.LEGAL_REVISION,
      label: 'Revision',
      lead: '',
      icon: null,
    })
    h.scopes
      .page()!
      .data.set('pageBreadcrumb', [
        { page: HilosPages.LEGAL_DOCUMENT, label: 'Document' },
      ])
    h.scopes.page()!.data.set('legalRevision', legalRevision)
    expect(identity.get()?.pageHeadOwner?.label).toBe('Revision')
    expect(identity.get()?.breadcrumb[0]?.label).toBe('Document')
  })

  it('uses historical keys and revision ids for recorded-only data without inventing metadata', () => {
    const h = revisionHarness(
      HilosLegalRevisionPage,
      HilosPages.LEGAL_REVISION,
      {
        details: recordedRevision,
        tabs: [revisionTabs[0]!],
      },
    )
    try {
      expect(h.view.get('h1').text()).toBe('Revision removed/revision')
      expect(
        readTextWithoutAriaHidden(
          h.view.get('nav[aria-label="Breadcrumb"]').element,
        ),
      ).toContain('retired/document')
      expect(h.view.get('[data-id="hilos-page-head-block"]').text()).toContain(
        'Not in code',
      )
      expect(h.view.get('[data-id="legal-revision-metadata"]').text()).toBe(
        'retired/document',
      )
      expect(
        h.view.find('[data-id="legal-revision-significance"]').exists(),
      ).toBe(false)
      expect(
        h.view.get('[data-id="legal-revision-undeclared"]').text(),
      ).toContain('no longer in code')
      expect(h.view.find('nav[aria-label="Revision pages"]').exists()).toBe(
        false,
      )
    } finally {
      h.view.unmount()
    }
  })

  it('keeps unanswered data skeletal and clears stale content for null, malformed and refused answers', async () => {
    const cold = revisionHarness(
      HilosLegalRevisionTextPage,
      HilosPages.LEGAL_REVISION_TEXT,
      { answered: false },
    )
    try {
      expect(cold.view.find('h1').exists()).toBe(false)
      expect(cold.view.find('nav').exists()).toBe(false)
      expect(cold.view.find('[data-id="hilos-skeleton"]').exists()).toBe(true)
    } finally {
      cold.view.unmount()
    }
    const h = revisionHarness(
      HilosLegalRevisionTextPage,
      HilosPages.LEGAL_REVISION_TEXT,
    )
    try {
      for (const value of [
        undefined,
        null,
        { ...legalRevision, clauses: 'invalid' },
      ]) {
        h.scope.data.set('legalRevision', value)
        await nextTick()
        expect(h.view.get('h1').text()).toBe('Revision')
        expect(h.view.find('[data-id="legal-revision-text"]').exists()).toBe(
          false,
        )
        expect(
          h.view.find('[data-id="legal-revision-metadata"]').exists(),
        ).toBe(false)
        expect(h.view.find('[data-id="hilos-skeleton"]').exists()).toBe(true)
        h.scope.data.set('legalRevision', legalRevision)
        await nextTick()
        expect(h.view.get('[data-id="legal-revision-text"]').text()).toContain(
          'historical project retention',
        )
      }
      h.scope.data.set('legalCatalogRefusal', '<b>A clause is missing</b>')
      await nextTick()
      expect(h.view.findAll('[role="alert"]')).toHaveLength(1)
      expect(h.view.get('[role="alert"]').text()).toContain(
        '<b>A clause is missing</b>',
      )
      expect(h.view.get('[role="alert"]').find('b').exists()).toBe(false)
      expect(h.view.find('[data-id="legal-revision-text"]').exists()).toBe(
        false,
      )
      expect(h.view.find('[data-id="legal-revision-metadata"]').exists()).toBe(
        false,
      )
      expect(h.view.get('h1').text()).toBe('Revision')
    } finally {
      h.view.unmount()
    }
  })
})
