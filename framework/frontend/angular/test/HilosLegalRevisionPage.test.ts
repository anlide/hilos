import { TestBed } from '@angular/core/testing'
import { HilosPages } from '@hilos/core/base'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { HilosLegalRevisionPage } from '../src/admin/legal/HilosLegalRevisionPage.js'
import { text } from './helpers/hilosLegalDocument.js'
import {
  acceptanceRows,
  legalRevision,
  legalRevisionBench,
  revisionHarness,
} from './helpers/hilosLegalRevision.js'

afterEach(() => {
  TestBed.resetTestingModule()
  vi.restoreAllMocks()
})

describe('HilosLegalRevisionPage', () => {
  it('shows the selected comparison or the first-revision state and leaves full text to Text', () => {
    const h = revisionHarness(HilosLegalRevisionPage)
    expect(text(h.root, '[data-id="legal-changes"]')).toContain(
      'Previous retention',
    )
    expect(text(h.root, '[data-id="legal-changes"]')).toContain(
      'Historical deviation',
    )
    expect(h.root.querySelector('[data-id="legal-revision-text"]')).toBeNull()
    expect(h.root.querySelector('a[href$="/text"]')).not.toBeNull()
    h.data('legalRevision', {
      ...legalRevision,
      predecessorId: null,
      changes: null,
    })
    expect(h.root.querySelector('[data-id="legal-changes"]')).toBeNull()
    expect(h.root.textContent).toContain(
      'This is the first revision; there is nothing to compare it with.',
    )
    h.fixture.destroy()
    expect(h.registered.size).toBe(0)
    expect(h.listenerCount()).toBe(0)
  })

  it('keeps the exact scoped count distinct across waiting, zero, refusal and live updates and releases its controller', () => {
    const h = revisionHarness(
      HilosLegalRevisionPage,
      HilosPages.LEGAL_REVISION,
      { window: false },
    )
    const summary = () => text(h.root, '[data-id="legal-revision-accepted"]')
    const former = h.controllers[0]!
    expect(former.filter.get()).toEqual({
      document: 'terms',
      revision: 'historical/revision',
    })
    expect(summary()).toBe('Loading acceptance count…')
    h.subscriptionWindow([])
    expect(summary()).toBe('0 acceptances')
    h.refuse()
    expect(summary()).toBe('Acceptance count unavailable.')
    h.window(acceptanceRows(8))
    expect(summary()).toBe('8 acceptances')
    const row = acceptanceRows(9)[0]!
    h.emit('tableViewportDelta', {
      type: 'table_viewport_delta',
      data: {
        page: HilosPages.LEGAL_REVISION,
        tableKey: 'hilosLegalRevisions',
        kind: 'row_updated',
        rowKey: row.rowKey,
        row,
      },
    })
    h.fixture.detectChanges()
    expect(summary()).toBe('9 acceptances')
    expect(h.registered.size).toBe(1)
    expect(h.sendTableViewport).not.toHaveBeenCalled()
    h.route.set({
      page: HilosPages.LEGAL_REVISION,
      params: { documentKey: 'privacy', revisionId: 'older' },
      admin: true,
    })
    h.fixture.detectChanges()
    expect(former.filter.get()).toEqual({
      document: 'privacy',
      revision: 'older',
    })
    expect(summary()).toBe('Acceptance count unavailable.')
    h.window(acceptanceRows(20))
    expect(summary()).toBe('Acceptance count unavailable.')
    h.window([{ ...row, rowKey: 'older' }])
    expect(summary()).toBe('9 acceptances')
    const replacement = legalRevisionBench(HilosPages.LEGAL_REVISION, {
      window: false,
    })
    h.fixture.componentRef.setInput('context', replacement.context)
    h.fixture.detectChanges()
    expect(h.registered.size).toBe(0)
    expect(h.listenerCount()).toBe(0)
    expect(former.rows.get()).toEqual([])
    expect([...replacement.registered.keys()]).toEqual(['hilosLegalRevisions'])
    expect(replacement.controllers[0]!.filter.get()).toEqual({
      document: 'privacy',
      revision: 'older',
    })
    expect(summary()).toBe('Loading acceptance count…')
    h.fixture.destroy()
    expect(replacement.registered.size).toBe(0)
    expect(replacement.listenerCount()).toBe(0)
    expect(replacement.controllers[0]!.rows.get()).toEqual([])
  })

  it('keeps Open acceptances as the read-only link to the general page', () => {
    const h = revisionHarness(HilosLegalRevisionPage)
    const link = h.root.querySelector<HTMLAnchorElement>(
      '[data-id="legal-open-acceptances"]',
    )!
    expect(link.textContent?.trim()).toBe('Open acceptances')
    expect(link.getAttribute('href')).toBe('/hilos/legal/acceptances')
    link.click()
    expect(h.router.navigate).toHaveBeenCalledWith('/hilos/legal/acceptances')
    expect(h.root.querySelectorAll('button, input, select, form')).toHaveLength(
      0,
    )
    expect(h.dispatch).not.toHaveBeenCalled()
    h.fixture.destroy()
  })
})
