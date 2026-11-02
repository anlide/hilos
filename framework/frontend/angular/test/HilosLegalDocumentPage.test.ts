import { TestBed } from '@angular/core/testing'
import { HIDDEN_VALUE } from '@hilos/core/base'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { HilosLegalDocumentPage } from '../src/admin/legal/HilosLegalDocumentPage.js'
import {
  DOCUMENT,
  legalDocumentBench,
  legalDocumentHarness,
  text,
} from './helpers/hilosLegalDocument.js'

afterEach(() => {
  TestBed.resetTestingModule()
  vi.restoreAllMocks()
})

const harness = () => legalDocumentHarness(HilosLegalDocumentPage)

describe('HilosLegalDocumentPage Overview', () => {
  it('reads source classes and all three live tallies from page data', () => {
    const h = harness()
    h.answer()
    expect(text(h.root, '[data-id="legal-document-sources"]')).toBe(
      'Standard sets: Hilos\\Legal\\StandardSetCatalog. Project revisions and deviations: Project\\Legal\\LegalCatalog. These declarations are read-only; publishing a revision means deploying it.',
    )
    const counts = () =>
      ['covered', 'window', 'lapsed'].map((key) =>
        text(h.root, `[data-id="legal-count-${key}"]`),
      )
    expect(counts()).toEqual(['8', '3', '2'])
    h.data('legalDocument', {
      ...DOCUMENT,
      covered: 15,
      window: 0,
      lapsed: 7,
      projectCatalogClass: null,
    })
    expect(counts()).toEqual(['15', '0', '7'])
    expect(text(h.root, '[data-id="legal-document-sources"]')).toBe(
      'Standard sets: Hilos\\Legal\\StandardSetCatalog. These declarations are read-only; publishing a revision means deploying it.',
    )
    expect(h.root.querySelector('table')).toBeNull()
    for (const old of [
      'legal-set',
      'legal-deviation-row',
      'legal-revision-row',
    ])
      expect(h.root.querySelector(`[data-id="${old}"]`)).toBeNull()
    expect(h.sendTableViewport).not.toHaveBeenCalled()
    expect(h.dispatch).not.toHaveBeenCalled()
    h.fixture.destroy()
  })

  it('links a nonzero lapsed count to its document and draws zero as plain text', () => {
    const h = harness()
    h.answer()
    expect(
      h.root
        .querySelector('[data-id="legal-count-lapsed-link"]')
        ?.getAttribute('href'),
    ).toBe('/hilos/users/terms')
    h.data('legalDocument', { ...DOCUMENT, document: 'privacy', lapsed: 1 })
    const link = h.root.querySelector<HTMLAnchorElement>(
      '[data-id="legal-count-lapsed-link"]',
    )!
    expect(link.getAttribute('href')).toBe('/hilos/users/privacy')
    expect(text(link, '[data-id="legal-count-lapsed"]')).toBe('1')
    link.click()
    expect(h.router.navigate).toHaveBeenCalledWith('/hilos/users/privacy')
    h.data('legalDocument', { ...DOCUMENT, lapsed: 0 })
    expect(
      h.root.querySelector('[data-id="legal-count-lapsed-link"]'),
    ).toBeNull()
    expect(text(h.root, '[data-id="legal-count-lapsed"]')).toBe('0')
    h.fixture.destroy()
  })

  it('names the lapsed count from the hidden settings carrier for freeze, remind and Hidden', () => {
    const h = harness()
    h.answer()
    expect(text(h.root, '[data-id="legal-document-lapsed"] h3')).toBe('Frozen')
    for (const [value, label] of [
      ['remind', 'Past deadline'],
      [HIDDEN_VALUE, 'Past deadline'],
      ['freeze', 'Frozen'],
    ] as const) {
      h.policy(value)
      expect(text(h.root, '[data-id="legal-document-lapsed"] h3')).toBe(label)
      expect(text(h.root, '[data-id="legal-count-lapsed"]')).toBe('2')
    }
    expect(h.root.querySelector('table')).toBeNull()
    expect(h.sendTableViewport).not.toHaveBeenCalled()
    h.fixture.destroy()
  })

  it('shows a newer set with its publication, significance and changes, and removes it when absent', () => {
    const h = harness()
    h.answer()
    expect(h.root.querySelector('[data-id="legal-set-newer"]')).toBeNull()
    h.data('legalDocument', {
      ...DOCUMENT,
      newerSet: {
        version: 4,
        publishedOn: '2026-10-20',
        significance: 'editorial',
        changes: [
          {
            clauseKey: 'retention',
            title: 'Retention period',
            kind: 'changed',
            before: null,
            after: null,
          },
          {
            clauseKey: 'export',
            title: 'Personal data copy',
            kind: 'added',
            before: null,
            after: null,
          },
        ],
      },
    })
    const warning = h.root.querySelector('[data-id="legal-set-newer"]')!
    expect(text(warning, 'h2')).toBe(
      'Hilos has released set 4 for this document',
    )
    expect(text(warning, 'p')).toBe(
      '2026-10-20 · editorial. The project keeps its declared set until it publishes a new revision.',
    )
    expect(
      [...warning.querySelectorAll('li')].map((item) =>
        item.textContent?.replace(/\s+/g, ' ').trim(),
      ),
    ).toEqual([
      'changed: Retention period retention',
      'added: Personal data copy export',
    ])
    h.data('legalDocument', DOCUMENT)
    expect(h.root.querySelector('[data-id="legal-set-newer"]')).toBeNull()
    h.fixture.destroy()
  })

  it('starts only the hidden settings carrier and fully releases the former binding on context change and destruction', () => {
    const h = harness()
    h.answer()
    expect([...h.registered.keys()]).toEqual(['hilosLegalSettings'])
    expect(h.controllers).toHaveLength(1)
    expect(h.controllers[0]!.rows.get()).toHaveLength(1)
    expect(h.sendTableViewport).not.toHaveBeenCalled()
    const replacement = legalDocumentBench()
    replacement.answer({ ...DOCUMENT, covered: 90 })
    h.fixture.componentRef.setInput('context', replacement.context)
    h.fixture.detectChanges()
    expect(h.registered.size).toBe(0)
    expect(h.listenerCount()).toBe(0)
    expect(h.controllers[0]!.rows.get()).toEqual([])
    expect([...replacement.registered.keys()]).toEqual(['hilosLegalSettings'])
    expect(text(h.root, '[data-id="legal-count-covered"]')).toBe('90')
    h.data('legalDocument', { ...DOCUMENT, covered: 999 })
    h.policy('remind')
    expect(text(h.root, '[data-id="legal-count-covered"]')).toBe('90')
    expect(text(h.root, '[data-id="legal-document-lapsed"] h3')).toBe('Frozen')
    replacement.policy('remind')
    h.fixture.detectChanges()
    expect(text(h.root, '[data-id="legal-document-lapsed"] h3')).toBe(
      'Past deadline',
    )
    h.fixture.destroy()
    expect(replacement.registered.size).toBe(0)
    expect(replacement.listenerCount()).toBe(0)
    expect(replacement.controllers[0]!.rows.get()).toEqual([])
    expect(replacement.sendTableViewport).not.toHaveBeenCalled()
  })
})
