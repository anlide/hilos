import { Component, Input } from '@angular/core'
import { TestBed } from '@angular/core/testing'
import { By } from '@angular/platform-browser'
import {
  bindAdminAccess,
  HilosPages,
  pageIdentity,
  TableViewportController,
  type HilosLegalContext,
} from '@hilos/core/base'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { readTextWithoutAriaHidden } from '../../test/breadcrumb.js'
import { HilosAdminPage } from '../src/HilosAdminPage.js'
import { HilosPageHead } from '../src/HilosPageHead.js'
import { HilosViewportTable } from '../src/HilosViewportTable.js'
import { HilosLegalRevisionPageShell } from '../src/admin/legal/HilosLegalRevisionPageShell.js'
import { HilosLegalRevisionPage } from '../src/admin/legal/HilosLegalRevisionPage.js'
import { HilosLegalRevisionTextPage } from '../src/admin/legal/HilosLegalRevisionTextPage.js'
import { text } from './helpers/hilosLegalDocument.js'
import {
  legalRevision,
  legalRevisionBench,
  recordedRevision,
  revisionHarness,
  revisionTabs,
} from './helpers/hilosLegalRevision.js'

/** A caller whose body must inherit the real heading and viewer providers. */
@Component({
  selector: 'test-revision-content',
  imports: [HilosLegalRevisionPageShell, HilosViewportTable],
  template: `
    <hilos-legal-revision-page-shell [context]="context" [page]="page">
      <ng-template #pageBody>
        <hilos-viewport-table [controller]="table" />
      </ng-template>
    </hilos-legal-revision-page-shell>
  `,
})
class RevisionContent {
  @Input({ required: true }) context!: HilosLegalContext
  @Input() page: string = HilosPages.LEGAL_REVISION
  readonly mutate = vi.fn()
  readonly table = new TableViewportController({
    sendViewport: vi.fn(),
    resolve: (row) => ({ rowKey: row.rowKey }),
    frame: {
      columns: [{ key: 'rowKey', label: 'Clause' }],
      bulkActions: [
        {
          key: 'change',
          label: 'Change clause',
          refusalTitle: 'Could not change clause',
          run: this.mutate,
        },
      ],
    },
  })
  ngOnDestroy(): void {
    this.table.dispose()
  }
}

afterEach(() => {
  TestBed.resetTestingModule()
  vi.restoreAllMocks()
})

describe('HilosLegalRevisionPageShell', () => {
  it('uses one live entity name for the heading, crumbs and title on both addressed pages', () => {
    for (const [index, { page, label }] of revisionTabs.entries()) {
      const component = [HilosLegalRevisionPage, HilosLegalRevisionTextPage][
        index
      ]!
      const h = revisionHarness<unknown>(component, page)
      expect(
        h.fixture.debugElement.queryAll(By.directive(HilosAdminPage)),
      ).toHaveLength(1)
      expect(
        h.fixture.debugElement.queryAll(By.directive(HilosPageHead)),
      ).toHaveLength(1)
      expect(h.root.querySelectorAll('h1')).toHaveLength(1)
      expect(text(h.root, 'h1')).toBe('Revision of 2026-01-01')
      expect(h.router.currentTitle.get()).toBe('Revision of 2026-01-01 · Polls')
      const crumbs = h.root.querySelector('nav[aria-label="Breadcrumb"]')!
      expect(
        [...crumbs.querySelectorAll('li')].map((crumb) =>
          readTextWithoutAriaHidden(crumb),
        ),
      ).toEqual([
        'Hilos',
        'Legal',
        'Documents',
        'Terms of use',
        'Revisions',
        'Revision of 2026-01-01',
      ])
      expect(
        [...crumbs.querySelectorAll('a')].map((node) =>
          node.getAttribute('href'),
        ),
      ).toEqual([
        '/hilos',
        '/hilos/legal',
        '/hilos/legal/documents',
        '/hilos/legal/document/terms',
        '/hilos/legal/document/terms/revisions',
      ])
      expect(
        readTextWithoutAriaHidden(
          crumbs.querySelector('[aria-current="page"]')!,
        ),
      ).toBe('Revision of 2026-01-01')
      expect(crumbs.querySelector('[aria-current="page"] a')).toBeNull()
      const nav = h.root.querySelector('nav[aria-label="Revision pages"]')!
      const links = [...nav.querySelectorAll('a')]
      expect(
        links.map((node) => [
          node.textContent?.trim(),
          node.getAttribute('href'),
        ]),
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
      expect(nav.querySelectorAll('[aria-current="page"]')).toHaveLength(1)
      expect(text(nav, '[aria-current="page"]')).toBe(label)
      expect(h.root.querySelector('[role="tablist"]')).toBeNull()
      links[1]!.click()
      expect(h.router.navigate).toHaveBeenCalledWith(
        '/hilos/legal/document/terms/revision/historical%2Frevision/text',
      )
      expect(text(h.root, '[data-id="legal-revision-metadata"]')).toBe(
        'Terms of use · Published 2026-01-01 · Effective 2026-01-15 · Set 1',
      )
      expect(text(h.root, '[data-id="legal-revision-significance"]')).toBe(
        'editorial',
      )
      expect(
        h.root.querySelector('[data-id="legal-revision-current"]'),
      ).toBeNull()
      h.data('legalRevision', { ...legalRevision, current: true })
      expect(text(h.root, '[data-id="legal-revision-current"]')).toBe('Current')
      h.fixture.destroy()
      TestBed.resetTestingModule()
    }
  })

  it('clears missing and malformed data and releases both names on page, input and context changes', () => {
    const h = revisionHarness(
      HilosLegalRevisionTextPage,
      HilosPages.LEGAL_REVISION_TEXT,
      { answered: false },
    )
    expect(h.root.querySelector('h1')).toBeNull()
    expect(h.root.querySelector('nav')).toBeNull()
    expect(h.root.querySelector('[data-id="hilos-skeleton"]')).not.toBeNull()
    h.answer()
    for (const value of [
      undefined,
      null,
      { ...legalRevision, clauses: 'invalid' },
    ]) {
      h.data('legalRevision', value)
      expect(text(h.root, 'h1')).toBe('Revision')
      expect(h.root.querySelector('[data-id="legal-revision-text"]')).toBeNull()
      expect(
        h.root.querySelector('[data-id="legal-revision-metadata"]'),
      ).toBeNull()
      expect(h.router.pageIdentity.get()?.breadcrumb[3]?.label).toBe('Document')
      h.answer()
      expect(text(h.root, '[data-id="legal-revision-text"]')).toContain(
        'historical project retention',
      )
    }
    h.data('legalRevision', {
      ...legalRevision,
      document: 'privacy',
      revisionId: 'older',
      revision: {
        ...legalRevision.revision!,
        revisionId: 'older',
        publishedOn: '2025-12-31',
      },
    })
    expect(text(h.root, 'h1')).toBe('Revision of 2025-12-31')
    expect(h.router.pageIdentity.get()?.breadcrumb[3]?.label).toBe(
      'Privacy policy',
    )
    expect(h.router.currentTitle.get()).toBe('Revision of 2025-12-31 · Polls')
    h.scopes.openPage(HilosPages.LEGAL_REVISION)
    h.answer()
    expect(h.root.querySelector('[data-id="legal-revision-text"]')).toBeNull()
    expect(
      h.root.querySelector('[data-id="legal-revision-metadata"]'),
    ).toBeNull()
    expect(h.router.pageIdentity.get()?.pageHeadOwner?.label).toBe('Revision')
    expect(h.router.pageIdentity.get()?.breadcrumb[3]?.label).toBe('Document')
    h.fixture.destroy()
    TestBed.resetTestingModule()

    const shell = revisionHarness(
      HilosLegalRevisionPageShell,
      HilosPages.LEGAL_REVISION,
      {},
      { page: HilosPages.LEGAL_REVISION },
    )
    shell.fixture.componentRef.setInput('page', HilosPages.LEGAL_REVISION_TEXT)
    shell.fixture.detectChanges()
    expect(shell.router.pageIdentity.get()?.pageHeadOwner?.label).toBe(
      'Revision',
    )
    expect(shell.router.pageIdentity.get()?.breadcrumb[3]?.label).toBe(
      'Document',
    )
    shell.scopes.openPage(HilosPages.LEGAL_REVISION_TEXT)
    shell.answer()
    expect(shell.router.pageIdentity.get()?.pageHeadOwner?.label).toBe(
      'Revision of 2026-01-01',
    )
    expect(shell.router.pageIdentity.get()?.breadcrumb[3]?.label).toBe(
      'Terms of use',
    )
    const replacement = legalRevisionBench(HilosPages.LEGAL_REVISION_TEXT)
    shell.fixture.componentRef.setInput('context', replacement.context)
    shell.fixture.detectChanges()
    expect(shell.router.pageIdentity.get()?.pageHeadOwner?.label).toBe(
      'Revision',
    )
    expect(shell.router.pageIdentity.get()?.breadcrumb[3]?.label).toBe(
      'Document',
    )
    expect(pageIdentity(replacement.scopes).get()?.pageHeadOwner?.label).toBe(
      'Revision of 2026-01-01',
    )
    shell.fixture.destroy()
    expect(pageIdentity(replacement.scopes).get()?.pageHeadOwner?.label).toBe(
      'Revision',
    )
    expect(pageIdentity(replacement.scopes).get()?.breadcrumb[3]?.label).toBe(
      'Document',
    )
  })

  it('keeps recorded-only keys, the warning and count without invented revision metadata or a singleton row', () => {
    const h = revisionHarness(
      HilosLegalRevisionPage,
      HilosPages.LEGAL_REVISION,
      {
        details: recordedRevision,
        tabs: [revisionTabs[0]!],
      },
    )
    expect(text(h.root, 'h1')).toBe('Revision removed/revision')
    expect(text(h.root, 'nav[aria-label="Breadcrumb"]')).toContain(
      'retired/document',
    )
    expect(text(h.root, '[data-id="legal-revision-metadata"]')).toBe(
      'retired/document',
    )
    expect(h.root.textContent).toContain('Not in code')
    expect(
      h.root.querySelector('[data-id="legal-revision-significance"]'),
    ).toBeNull()
    expect(
      h.root.querySelector('[data-id="legal-revision-current"]'),
    ).toBeNull()
    expect(text(h.root, '[data-id="legal-revision-undeclared"]')).toContain(
      'no longer in code',
    )
    expect(h.root.querySelector('nav[aria-label="Revision pages"]')).toBeNull()
    h.window([
      {
        rowKey: recordedRevision.revisionId,
        slots: {
          revision: {
            declared: false,
            revision: null,
            publishedOn: null,
            effectiveOn: null,
            current: false,
            origin: null,
            heldCount: 2,
            acceptedCount: 7,
          },
        },
      },
    ])
    expect(text(h.root, '[data-id="legal-revision-accepted"]')).toBe(
      '7 acceptances',
    )
    h.fixture.destroy()
  })

  it('creates the body under the real heading and live viewer provider and replaces stale content with literal refusals', () => {
    const h = revisionHarness(RevisionContent)
    h.scopes.session.data.set('adminViewMode', true)
    const release = bindAdminAccess(h.scopes)
    try {
      const controller = h.fixture.componentInstance.table
      controller.ingestWindow(
        [{ rowKey: 'one', slots: {} }],
        1,
        true,
        null,
        null,
        25,
      )
      controller.selectRow('one', true)
      h.fixture.detectChanges()
      const table = h.root.querySelector('table')!
      const heading = h.root.querySelector('h1')!
      expect(heading.id).not.toBe('')
      expect(table.getAttribute('aria-labelledby')).toBe(heading.id)
      const operation = h.root.querySelector<HTMLButtonElement>(
        '[data-id="hilos-table-bulk-change"]',
      )!
      expect(operation.disabled).toBe(true)
      expect(operation.getAttribute('aria-describedby')).toBe(
        'hilos-view-mode-strip-text',
      )
      operation.click()
      expect(h.fixture.componentInstance.mutate).not.toHaveBeenCalled()
      h.scopes.session.data.set('adminViewMode', false)
      h.fixture.detectChanges()
      expect(operation.disabled).toBe(false)
      for (const reason of ['<b>Missing clause</b>', '']) {
        h.data('legalCatalogRefusal', reason)
        expect(h.root.querySelectorAll('[role="alert"]')).toHaveLength(1)
        expect(text(h.root, '[role="alert"] p')).toBe(reason)
        expect(h.root.querySelector('[role="alert"] b')).toBeNull()
        expect(h.root.querySelector('table')).toBeNull()
        expect(
          h.root.querySelector('[data-id="legal-revision-metadata"]'),
        ).toBeNull()
        expect(text(h.root, 'h1')).toBe('Revision')
        expect(h.router.pageIdentity.get()?.breadcrumb[3]?.label).toBe(
          'Document',
        )
        h.data('legalCatalogRefusal', null)
        expect(h.root.querySelector('[role="alert"]')).toBeNull()
        expect(h.root.querySelector('table')).not.toBeNull()
        expect(text(h.root, 'h1')).toBe('Revision of 2026-01-01')
      }
    } finally {
      h.fixture.destroy()
      release()
    }
  })
})
