import { StrictMode, type ComponentType } from 'react'
import { act, cleanup, within } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import {
  HilosPages,
  resolvePageTitle,
  type HilosLegalContext,
} from '@hilos/core/base'
import { HilosLegalRevisionPageShell } from '../src/admin/legal/HilosLegalRevisionPageShell.js'
import { HilosLegalRevisionPage } from '../src/admin/legal/HilosLegalRevisionPage.js'
import { HilosLegalRevisionTextPage } from '../src/admin/legal/HilosLegalRevisionTextPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'
import {
  legalRevision,
  recordedRevision,
  legalRevisionTestHarness,
  revisionTabs,
} from './legalRevisionTestFixture.js'

afterEach(cleanup)

const pages: Record<string, ComponentType<{ context: HilosLegalContext }>> = {
  [HilosPages.LEGAL_REVISION]: HilosLegalRevisionPage,
  [HilosPages.LEGAL_REVISION_TEXT]: HilosLegalRevisionTextPage,
}

describe('React Revision shell', () => {
  it('uses one named entity head, matching title and ordinary links on both pages', () => {
    for (const { page, label } of revisionTabs) {
      const h = legalRevisionTestHarness(page)
      const Page = pages[page]!
      const view = h.mount(<Page context={h.context} />, true)
      expect(
        view
          .getAllByRole('heading', { level: 1 })
          .map((node) => node.textContent),
      ).toEqual(['Revision of 2026-01-01'])
      const identity = h.router.pageIdentity.get()!
      expect(
        resolvePageTitle(
          page,
          {},
          'Tasks',
          identity.label,
          true,
          identity.pageHeadOwner?.label,
        ),
      ).toBe('Revision of 2026-01-01 · Tasks')
      const crumbs = view.getByRole('navigation', { name: 'Breadcrumb' })
      expect(
        Array.from(crumbs.querySelectorAll('a')).map((node) => [
          node.textContent,
          node.getAttribute('href'),
        ]),
      ).toEqual([
        ['Hilos', '/hilos'],
        ['Legal', '/hilos/legal'],
        ['Documents', '/hilos/legal/documents'],
        ['Terms', '/hilos/legal/document/terms'],
        ['Revisions', '/hilos/legal/document/terms/revisions'],
      ])
      const currentCrumbs = crumbs.querySelectorAll('[aria-current="page"]')
      expect(currentCrumbs).toHaveLength(1)
      expect(
        within(currentCrumbs[0] as HTMLElement).getByText(
          'Revision of 2026-01-01',
        ),
      ).not.toBeNull()
      expect(currentCrumbs[0]?.querySelector('a')).toBeNull()
      const nav = view.getByRole('navigation', { name: 'Revision pages' })
      expect(
        within(nav)
          .getAllByRole('link')
          .map((node) => [node.textContent, node.getAttribute('href')]),
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
        Array.from(nav.querySelectorAll('[aria-current="page"]')).map(
          (node) => node.textContent,
        ),
      ).toEqual([label])
      expect(view.queryByRole('tablist')).toBeNull()
      expect(
        view.container.querySelector('[data-id="legal-revision-metadata"]')
          ?.textContent,
      ).toBe('Terms · Published 2026-01-01 · Effective 2026-01-15 · Set 1')
      expect(
        view.container.querySelector('[data-id="legal-revision-significance"]')
          ?.textContent,
      ).toBe('editorial')
      expect(
        view.container.querySelector('[data-id="legal-revision-current"]'),
      ).toBeNull()
      expect(
        view.container.querySelector(
          '[data-id="hilos-page-head-block"] [aria-hidden="true"]',
        ),
      ).not.toBeNull()
      h.setPageData('legalRevision', { ...legalRevision, current: true })
      expect(
        view.container.querySelector('[data-id="legal-revision-current"]')
          ?.textContent,
      ).toBe('Current')
      view.unmount()
    }
  })

  it('updates both names from the current answer and releases bindings across StrictMode cleanup and new scopes', () => {
    const h = legalRevisionTestHarness()
    const view = h.mount(
      <HilosLegalRevisionPageShell
        context={h.context}
        page={HilosPages.LEGAL_REVISION}
      />,
      true,
    )
    const identity = h.router.pageIdentity
    h.setPageData('legalRevision', {
      ...legalRevision,
      document: 'privacy',
      revision: { ...legalRevision.revision!, publishedOn: '2025-12-31' },
    })
    expect(view.getByRole('heading', { level: 1 }).textContent).toBe(
      'Revision of 2025-12-31',
    )
    expect(
      identity
        .get()
        ?.breadcrumb.find((crumb) => crumb.page === HilosPages.LEGAL_DOCUMENT)
        ?.label,
    ).toBe('Privacy policy')
    act(() => {
      const foreign = h.scopes.openPage(HilosPages.LEGAL_REVISION_TEXT)
      for (const [key, value] of Object.entries(h.data))
        foreign.data.set(key, value)
    })
    expect(identity.get()?.pageHeadOwner?.label).toBe('Revision')
    expect(
      view.container.querySelector('[data-id="legal-revision-metadata"]'),
    ).toBeNull()

    const other = legalRevisionTestHarness()
    view.rerender(
      <StrictMode>
        <HilosRouterContext.Provider value={other.router}>
          <HilosLegalRevisionPageShell
            context={other.context}
            page={HilosPages.LEGAL_REVISION}
          />
        </HilosRouterContext.Provider>
      </StrictMode>,
    )
    expect(other.router.pageIdentity.get()?.pageHeadOwner?.label).toBe(
      'Revision of 2026-01-01',
    )
    view.unmount()
    for (const harness of [h, other]) {
      act(() => {
        const fresh = harness.scopes.openPage(HilosPages.LEGAL_REVISION)
        for (const [key, value] of Object.entries(harness.data))
          fresh.data.set(key, value)
      })
      expect(harness.router.pageIdentity.get()?.pageHeadOwner?.label).toBe(
        'Revision',
      )
      expect(
        harness.router.pageIdentity
          .get()
          ?.breadcrumb.find((crumb) => crumb.page === HilosPages.LEGAL_DOCUMENT)
          ?.label,
      ).toBe('Document')
    }
  })

  it('shows recorded-only identity and count without inventing dates or a Text tab', () => {
    const h = legalRevisionTestHarness(HilosPages.LEGAL_REVISION, {
      details: recordedRevision,
      tabs: [revisionTabs[0]!],
    })
    const view = h.mount(<HilosLegalRevisionPage context={h.context} />)
    expect(view.getByRole('heading', { level: 1 }).textContent).toBe(
      'Revision removed/revision',
    )
    expect(
      view.getByRole('navigation', { name: 'Breadcrumb' }).textContent,
    ).toContain('retired/document')
    expect(view.getByText('Not in code')).not.toBeNull()
    expect(
      view.container.querySelector('[data-id="legal-revision-metadata"]')
        ?.textContent,
    ).toBe('retired/document')
    expect(
      view.container.querySelector('[data-id="legal-revision-significance"]'),
    ).toBeNull()
    expect(
      view.container.querySelector('[data-id="legal-revision-undeclared"]')
        ?.textContent,
    ).toContain('no longer in code')
    expect(
      view.container.querySelector('[data-id="legal-revision-accepted"]'),
    ).toHaveProperty('textContent', '8 acceptances')
    expect(
      view.queryByRole('navigation', { name: 'Revision pages' }),
    ).toBeNull()
    expect(view.container.querySelector('[data-id="legal-changes"]')).toBeNull()
  })

  it('keeps unanswered data skeletal and clears stale content for null, malformed and refused answers', () => {
    for (const { page } of revisionTabs) {
      const Page = pages[page]!
      const cold = legalRevisionTestHarness(page, { answered: false })
      const coldView = cold.mount(<Page context={cold.context} />)
      expect(coldView.queryByRole('heading', { level: 1 })).toBeNull()
      expect(coldView.queryByRole('navigation')).toBeNull()
      expect(
        coldView.container.querySelector('[data-id="hilos-skeleton"]'),
      ).not.toBeNull()
      act(() => {
        for (const [key, value] of Object.entries(cold.data))
          cold.scope.data.set(key, value)
      })
      expect(coldView.getByRole('heading', { level: 1 }).textContent).toBe(
        'Revision of 2026-01-01',
      )
      coldView.unmount()

      const h = legalRevisionTestHarness(page)
      const view = h.mount(<Page context={h.context} />)
      for (const value of [
        undefined,
        null,
        { ...legalRevision, clauses: 'invalid' },
      ]) {
        h.setPageData('legalRevision', value)
        expect(view.getByRole('heading', { level: 1 }).textContent).toBe(
          'Revision',
        )
        expect(
          view.container.querySelector('[data-id="legal-revision-metadata"]'),
        ).toBeNull()
        expect(
          h.router.pageIdentity
            .get()
            ?.breadcrumb.find(
              (crumb) => crumb.page === HilosPages.LEGAL_DOCUMENT,
            )?.label,
        ).toBe('Document')
        expect(
          view.container.querySelector('[data-id="legal-revision-text"]'),
        ).toBeNull()
        expect(
          view.container.querySelector('[data-id="legal-changes"]'),
        ).toBeNull()
        expect(
          view.container.querySelector('[data-id="hilos-skeleton"]'),
        ).not.toBeNull()
        h.setPageData('legalRevision', legalRevision)
        expect(view.getByRole('heading', { level: 1 }).textContent).toBe(
          'Revision of 2026-01-01',
        )
      }
      h.setPageData('legalCatalogRefusal', '<b>A clause is missing</b>')
      expect(view.getAllByRole('alert')).toHaveLength(1)
      expect(view.getByRole('alert').textContent).toContain(
        '<b>A clause is missing</b>',
      )
      expect(view.getByRole('alert').querySelector('b')).toBeNull()
      expect(view.getByRole('heading', { level: 1 }).textContent).toBe(
        'Revision',
      )
      expect(
        view.container.querySelector('[data-id="legal-revision-metadata"]'),
      ).toBeNull()
      expect(
        view.container.querySelector('[data-id="legal-revision-text"]'),
      ).toBeNull()
      expect(
        view.container.querySelector('[data-id="legal-changes"]'),
      ).toBeNull()
      view.unmount()
    }
  })
})
