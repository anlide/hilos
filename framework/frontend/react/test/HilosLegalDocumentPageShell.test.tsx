import type { ComponentType } from 'react'
import { cleanup, within } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import {
  HilosPages,
  type HilosLegalContext,
  type HilosPageKey,
} from '@hilos/core/base'
import { HilosLegalDocumentPage } from '../src/admin/legal/HilosLegalDocumentPage.js'
import { HilosLegalDocumentSetPage } from '../src/admin/legal/HilosLegalDocumentSetPage.js'
import { HilosLegalDocumentDeviationsPage } from '../src/admin/legal/HilosLegalDocumentDeviationsPage.js'
import { HilosLegalDocumentRevisionsPage } from '../src/admin/legal/HilosLegalDocumentRevisionsPage.js'
import {
  documentTabs,
  legalDocument,
  legalDocumentTestHarness,
} from './legalDocumentTestFixture.js'

afterEach(cleanup)

const pages: Record<string, ComponentType<{ context: HilosLegalContext }>> = {
  [HilosPages.LEGAL_DOCUMENT]: HilosLegalDocumentPage,
  [HilosPages.LEGAL_DOCUMENT_SET]: HilosLegalDocumentSetPage,
  [HilosPages.LEGAL_DOCUMENT_DEVIATIONS]: HilosLegalDocumentDeviationsPage,
  [HilosPages.LEGAL_DOCUMENT_REVISIONS]: HilosLegalDocumentRevisionsPage,
}

/** Mounts the real page, shell, admin frame, head and navigator together. */
function harness(page: HilosPageKey, answered = true) {
  const h = legalDocumentTestHarness(page, { answered })
  const Page = pages[page]!
  const view = h.mount(<Page context={h.context} />, true)
  return { ...h, view }
}

describe('React legal Document tabs with their real shared page head', () => {
  it.each(documentTabs)(
    'draws one live entity head and link row for $label',
    ({ page, label }) => {
      const h = harness(page)
      expect(
        h.view
          .getAllByRole('heading', { level: 1 })
          .map((node) => node.textContent),
      ).toEqual(['Terms'])

      const crumbs = h.view.getByRole('navigation', { name: 'Breadcrumb' })
      expect(
        Array.from(crumbs.querySelectorAll('a')).map((node) => [
          node.textContent,
          node.getAttribute('href'),
        ]),
      ).toEqual([
        ['Hilos', '/hilos'],
        ['Legal', '/hilos/legal'],
        ['Documents', '/hilos/legal/documents'],
      ])
      expect(
        within(crumbs.querySelector('[aria-current="page"]')!).getByText(
          'Terms',
        ),
      ).not.toBeNull()

      const nav = h.view.getByRole('navigation', { name: 'Document pages' })
      expect(
        Array.from(nav.querySelectorAll('a')).map((node) => [
          node.textContent,
          node.getAttribute('href'),
        ]),
      ).toEqual([
        ['Overview', '/hilos/legal/document/terms'],
        ['Standard set', '/hilos/legal/document/terms/set'],
        ['Deviations', '/hilos/legal/document/terms/deviations'],
        ['Revisions', '/hilos/legal/document/terms/revisions'],
      ])
      expect(
        Array.from(nav.querySelectorAll('[aria-current="page"]')).map(
          (node) => node.textContent,
        ),
      ).toEqual([label])
      expect(h.view.queryByRole('tablist')).toBeNull()
      expect(
        h.view.container.querySelector('[data-id="hilos-admin-children"]'),
      ).toBeNull()
      expect(
        h.view.container.querySelector('[data-id="legal-document-key"]')
          ?.textContent,
      ).toBe('terms')
      expect(
        h.view.container.querySelector('[data-id="legal-document-set-version"]')
          ?.textContent,
      ).toBe('Set 1')
      expect(
        h.view.container.querySelector('[data-id="legal-document-revision"]')
          ?.textContent,
      ).toBe('2026-09-27 · substantial · Effective 2026-10-05')
      expect(
        h.view.container.querySelector(
          '[data-id="hilos-page-head-block"] [aria-hidden="true"]',
        ),
      ).not.toBeNull()
      if (page === HilosPages.LEGAL_DOCUMENT)
        expect(
          h.view.container.querySelector('[data-id="legal-document-sources"]'),
        ).not.toBeNull()
      else expect(h.view.getByRole('table')).not.toBeNull()
      expect(h.liveWindows()).toEqual({ [h.tableKey]: 1 })
      expect(h.sendViewport).not.toHaveBeenCalled()
      h.view.unmount()
      expect(h.liveWindows()).toEqual({})
      expect(h.listenerCount()).toBe(0)
    },
  )

  it.each(documentTabs)(
    'binds the current document name and releases it on $label unmount',
    ({ page }) => {
      const h = harness(page)
      const identity = h.router.pageIdentity
      expect(identity.get()?.pageHeadOwner?.label).toBe('Terms')
      h.setPageData('legalDocument', {
        ...legalDocument,
        document: 'privacy',
      })
      expect(h.view.getByRole('heading', { level: 1 }).textContent).toBe(
        'Privacy policy',
      )
      expect(identity.get()?.breadcrumb.at(-1)?.label).toBe('Privacy policy')

      h.setPageData('legalDocument', {
        ...legalDocument,
        document: 'retired/document',
        declared: false,
        revision: null,
        set: null,
        projectCatalogClass: null,
      })
      expect(h.view.getByRole('heading', { level: 1 }).textContent).toBe(
        'retired/document',
      )
      const head = h.view.container.querySelector<HTMLElement>(
        '[data-id="hilos-page-head-block"]',
      )!
      expect(within(head).getByText('Not in code')).not.toBeNull()
      expect(
        h.view.container.querySelector(
          '[data-id="legal-document-set-version"]',
        ),
      ).toBeNull()
      expect(
        h.view.container.querySelector('[data-id="legal-document-revision"]'),
      ).toBeNull()

      h.view.unmount()
      expect(identity.get()?.pageHeadOwner?.label).toBe('Document')
      expect(identity.get()?.breadcrumb.at(-1)?.label).toBe('Document')
    },
  )

  it('does not bind the name from a different page while leaving Overview', () => {
    const h = harness(HilosPages.LEGAL_DOCUMENT)
    const foreign = h.scopes.openPage(HilosPages.LEGAL_DOCUMENT_SET)
    for (const [key, value] of Object.entries(h.data))
      foreign.data.set(key, value)
    foreign.data.set('legalDocument', {
      ...legalDocument,
      document: 'privacy',
    })
    expect(h.router.pageIdentity.get()?.pageHeadOwner?.label).toBe('Document')
  })

  it.each(documentTabs)(
    'shows one catalog refusal in place of $label body while keeping the head',
    ({ page, label }) => {
      const h = harness(page)
      h.setPageData('legalCatalogRefusal', '<b>A clause is missing</b>')
      const refusal = h.view.getByRole('alert')
      expect(refusal.querySelector('h2')?.textContent).toBe(
        'The legal document catalog could not be loaded',
      )
      expect(refusal.textContent).toContain('<b>A clause is missing</b>')
      expect(refusal.querySelector('b')).toBeNull()
      expect(h.view.container.querySelector('table')).toBeNull()
      expect(h.view.getAllByRole('heading', { level: 1 })).toHaveLength(1)
      expect(h.view.getByRole('heading', { level: 1 }).textContent).toBe(
        'Document',
      )
      const nav = h.view.getByRole('navigation', { name: 'Document pages' })
      expect(nav.querySelectorAll('a')).toHaveLength(4)
      expect(nav.querySelector('[aria-current="page"]')?.textContent).toBe(
        label,
      )
      h.setPageData('legalDocument', null)
      expect(h.view.getAllByRole('alert')).toHaveLength(1)
      expect(h.view.getAllByRole('heading', { level: 1 })).toHaveLength(1)
      expect(
        h.view
          .getByRole('navigation', { name: 'Document pages' })
          .querySelectorAll('a'),
      ).toHaveLength(4)
      h.view.unmount()
      expect(h.liveWindows()).toEqual({})
    },
  )

  it.each(documentTabs)(
    'shows only the shared skeleton before the $label page reply',
    ({ page }) => {
      const h = harness(page, false)
      expect(h.view.queryByRole('heading', { level: 1 })).toBeNull()
      expect(h.view.queryByRole('navigation')).toBeNull()
      expect(
        h.view.container.querySelector('[data-id="legal-document-key"]'),
      ).toBeNull()
      expect(
        h.view.container.querySelector('[data-id="hilos-page-head-skeleton"]'),
      ).not.toBeNull()
      expect(
        h.view.container.querySelector('[data-id="hilos-skeleton"]'),
      ).not.toBeNull()
      h.view.unmount()
      expect(h.liveWindows()).toEqual({})
      expect(h.listenerCount()).toBe(0)
    },
  )
})
