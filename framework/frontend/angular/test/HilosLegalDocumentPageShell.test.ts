import { Component, Input } from '@angular/core'
import { TestBed } from '@angular/core/testing'
import {
  bindAdminAccess,
  HilosPages,
  pageIdentity,
  TableViewportController,
  type HilosLegalContext,
} from '@hilos/core/base'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { HilosLegalDocumentPageShell } from '../src/admin/legal/HilosLegalDocumentPageShell.js'
import { HilosViewportTable } from '../src/HilosViewportTable.js'
import {
  DOCUMENT,
  DOCUMENT_TABS,
  legalDocumentBench,
  legalDocumentHarness,
  text,
} from './helpers/hilosLegalDocument.js'

/** A real caller supplies a body that must inherit the admin shell's injector. */
@Component({
  selector: 'test-document-content',
  imports: [HilosLegalDocumentPageShell, HilosViewportTable],
  template: `
    <hilos-legal-document-page-shell [context]="context" [page]="page">
      <ng-template #pageBody>
        <hilos-viewport-table [controller]="table" />
      </ng-template>
    </hilos-legal-document-page-shell>
  `,
})
class DocumentContent {
  @Input({ required: true }) context!: HilosLegalContext
  @Input() page: string = HilosPages.LEGAL_DOCUMENT
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

describe('HilosLegalDocumentPageShell', () => {
  it('shows the first answer as one named entity head with key, set and revision metadata', () => {
    const h = legalDocumentHarness(
      HilosLegalDocumentPageShell,
      HilosPages.LEGAL_DOCUMENT,
      { page: HilosPages.LEGAL_DOCUMENT },
    )
    expect(h.root.querySelector('h1')).toBeNull()
    expect(h.root.querySelector('[data-id="hilos-skeleton"]')).not.toBeNull()
    h.answer()
    expect(h.root.querySelectorAll('h1')).toHaveLength(1)
    expect(text(h.root, 'h1')).toBe('Terms')
    expect(text(h.root, '[data-id="legal-document-key"]')).toBe('terms')
    expect(text(h.root, '[data-id="legal-document-set-version"]')).toBe('Set 1')
    expect(text(h.root, '[data-id="legal-document-revision"]')).toBe(
      '2026-09-27 · substantial · Effective 2026-10-01',
    )
    const crumbs = h.root.querySelector('nav[aria-label="Breadcrumb"]')!
    expect(
      [...crumbs.querySelectorAll('li')].map((node) =>
        node.textContent?.replace(/\//g, '').trim(),
      ),
    ).toEqual(['Hilos', 'Legal', 'Documents', 'Terms'])
    expect(crumbs.querySelector('[aria-current="page"] a')).toBeNull()
    expect(h.sendTableViewport).not.toHaveBeenCalled()
    expect(h.dispatch).not.toHaveBeenCalled()
    h.fixture.destroy()
  })

  it('offers four addressed links with exactly the current page marked on each tab', () => {
    for (const { page, label } of DOCUMENT_TABS) {
      const h = legalDocumentHarness(HilosLegalDocumentPageShell, page, {
        page,
      })
      h.answer()
      const nav = h.root.querySelector('nav[aria-label="Document pages"]')!
      const links = [...nav.querySelectorAll('a')]
      expect(links.map((link) => link.textContent?.trim())).toEqual([
        'Overview',
        'Standard set',
        'Deviations',
        'Revisions',
      ])
      expect(links.map((link) => link.getAttribute('href'))).toEqual([
        '/hilos/legal/document/terms',
        '/hilos/legal/document/terms/set',
        '/hilos/legal/document/terms/deviations',
        '/hilos/legal/document/terms/revisions',
      ])
      expect(nav.querySelectorAll('[aria-current="page"]')).toHaveLength(1)
      expect(text(nav, '[aria-current="page"]')).toBe(label)
      expect(nav.querySelector('[role="tablist"]')).toBeNull()
      links[3]!.click()
      expect(h.router.navigate).toHaveBeenCalledWith(
        '/hilos/legal/document/terms/revisions',
      )
      h.fixture.destroy()
      TestBed.resetTestingModule()
    }
  })

  it('updates the live name and releases it on another page, context replacement and unmount', () => {
    const h = legalDocumentHarness(
      HilosLegalDocumentPageShell,
      HilosPages.LEGAL_DOCUMENT,
      { page: HilosPages.LEGAL_DOCUMENT },
    )
    h.answer()
    h.data('legalDocument', { ...DOCUMENT, document: 'privacy' })
    expect(text(h.root, 'h1')).toBe('Privacy policy')
    expect(h.router.pageIdentity.get()?.breadcrumb.at(-1)?.label).toBe(
      'Privacy policy',
    )
    h.scopes.openPage(HilosPages.LEGAL_SETTINGS)
    h.scopes.page()!.data.set('legalDocument', DOCUMENT)
    h.scopes.page()!.data.set('pageLabel', 'Other page')
    h.scopes.page()!.data.set('pageHeadOwner', {
      page: HilosPages.LEGAL_DOCUMENT,
      label: 'Document',
      lead: '',
      icon: null,
    })
    h.fixture.detectChanges()
    expect(h.router.pageIdentity.get()?.pageHeadOwner?.label).toBe('Document')
    const replacement = legalDocumentBench()
    replacement.answer({ ...DOCUMENT, document: 'privacy' })
    h.fixture.componentRef.setInput('context', replacement.context)
    h.fixture.detectChanges()
    expect(pageIdentity(replacement.scopes).get()?.pageHeadOwner?.label).toBe(
      'Privacy policy',
    )
    h.fixture.destroy()
    expect(pageIdentity(replacement.scopes).get()?.pageHeadOwner?.label).toBe(
      'Document',
    )
    expect(
      pageIdentity(replacement.scopes).get()?.breadcrumb.at(-1)?.label,
    ).toBe('Document')
  })

  it('clears malformed or null data and replaces the body and metadata with literal refusal until recovery', () => {
    const h = legalDocumentHarness(DocumentContent)
    for (const value of [null, { document: 'broken' }, undefined]) {
      h.answer()
      expect(h.root.querySelector('table')).not.toBeNull()
      h.data('legalDocument', value)
      expect(h.root.querySelector('table')).toBeNull()
      expect(h.root.querySelector('[data-id="legal-document-key"]')).toBeNull()
      expect(
        h.root.querySelector('[data-id="legal-document-revision"]'),
      ).toBeNull()
      expect(text(h.root, 'h1')).toBe('Document')
      expect(h.root.querySelector('[data-id="hilos-skeleton"]')).not.toBeNull()
    }
    h.answer()
    for (const reason of ['<b>Missing clause</b>', '']) {
      h.data('legalCatalogRefusal', reason)
      expect(text(h.root, '[role="alert"] p')).toBe(reason)
      expect(h.root.querySelector('[role="alert"] b')).toBeNull()
      expect(h.root.querySelector('table')).toBeNull()
      expect(
        h.root.querySelector('[data-id="legal-document-set-version"]'),
      ).toBeNull()
      expect(text(h.root, 'h1')).toBe('Document')
      h.data('legalCatalogRefusal', null)
      expect(h.root.querySelector('[role="alert"]')).toBeNull()
      expect(h.root.querySelector('table')).not.toBeNull()
      expect(text(h.root, 'h1')).toBe('Terms')
    }
    h.fixture.destroy()
  })

  it('names a historical document by its key and shows no invented revision or set', () => {
    const h = legalDocumentHarness(
      HilosLegalDocumentPageShell,
      HilosPages.LEGAL_DOCUMENT,
      { page: HilosPages.LEGAL_DOCUMENT },
    )
    h.answer({
      ...DOCUMENT,
      document: 'retired-policy',
      declared: false,
      revision: null,
      set: null,
    })
    expect(text(h.root, 'h1')).toBe('retired-policy')
    expect(text(h.root, '[data-id="legal-document-key"]')).toBe(
      'retired-policy',
    )
    expect(h.root.textContent).toContain('Not in code')
    expect(
      h.root.querySelector('[data-id="legal-document-set-version"]'),
    ).toBeNull()
    expect(
      h.root.querySelector('[data-id="legal-document-revision"]'),
    ).toBeNull()
    h.fixture.destroy()
  })

  it('instantiates its table only with data under the real heading and live admin-view-mode provider', () => {
    const h = legalDocumentHarness(DocumentContent)
    h.scopes.session.data.set('adminViewMode', true)
    const release = bindAdminAccess(h.scopes)
    try {
      expect(h.root.querySelector('table')).toBeNull()
      h.answer()
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
      expect(h.root.querySelectorAll('h1')).toHaveLength(1)
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
      h.data('legalDocument', { ...DOCUMENT, covered: 12 })
      expect(h.root.querySelector('table')).toBe(table)
      h.data('legalCatalogRefusal', 'Catalog unavailable')
      expect(h.root.querySelector('table')).toBeNull()
    } finally {
      h.fixture.destroy()
      release()
    }
  })
})
