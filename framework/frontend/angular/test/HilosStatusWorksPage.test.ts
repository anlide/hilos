import { By } from '@angular/platform-browser'
import { HilosPages } from '@hilos/core/base'
import { describe, expect, it } from 'vitest'

import { HilosAdminPage } from '../src/HilosAdminPage.js'
import {
  announcement,
  el,
  query,
  statusWorksHarness,
  workRow,
} from './helpers/hilosStatus.js'
import { worksPage } from './helpers/hilosStatusWorks.js'

const table = () => document.querySelector('table')!

describe('HilosStatusWorksPage over the real viewport', () => {
  it('passes its shared head identity and renders catalog names, plain text and live reader-zone moments without mount requests', () => {
    const h = worksPage()
    const admin = h.fixture.debugElement.query(By.directive(HilosAdminPage))
      .componentInstance as HilosAdminPage
    expect([admin.page(), admin.headKind(), admin.tabsLabel()]).toEqual([
      HilosPages.STATUS_WORKS,
      'section',
      'Status pages',
    ])
    expect(h.registered.size).toBe(1)
    expect(h.sent).toEqual([])
    expect(h.viewports).toEqual([])
    h.window([
      workRow({
        title: '<b>Upgrade</b>',
        description: '<script>text</script>',
        componentKeys: ['queue'],
      }),
    ])
    h.detect()
    expect(table().textContent).toContain('Message queue')
    expect(table().textContent).toContain('<b>Upgrade</b>')
    expect(table().querySelector('script, b')).toBeNull()
    expect(el('hilos-status-work-mail-7').textContent?.trim()).toBe('—')
    h.chooseZone('Asia/Tokyo')
    expect(table().textContent).toContain('12:04:05')
    const tokyo = table().textContent
    h.chooseZone('America/New_York')
    expect(table().textContent).not.toBe(tokyo)
    expect(h.sent).toEqual([])
    expect(h.viewports).toEqual([])
  })

  it('delegates search, component facets and indexed orders without filtering the held rows', () => {
    const h = worksPage()
    h.window()
    h.detect()
    h.act(() => {
      const search = el<HTMLInputElement>('hilos-table-search')
      search.value = 'no-match'
      search.dispatchEvent(new Event('input', { bubbles: true }))
    })
    expect(h.viewports.at(-1)).toEqual([
      HilosPages.STATUS_WORKS,
      'hilosStatusWorks',
      expect.objectContaining({ filter: { search: 'no-match' } }),
    ])
    h.act(() =>
      el('hilos-table-filter-componentKey')
        .querySelector<HTMLButtonElement>('[data-id="hilos-dropdown-toggle"]')!
        .click(),
    )
    h.act(() => el('hilos-dropdown-option-1').click())
    expect(h.viewports.at(-1)).toEqual([
      HilosPages.STATUS_WORKS,
      'hilosStatusWorks',
      expect.objectContaining({
        filter: { search: 'no-match', componentKey: 'queue' },
      }),
    ])
    expect(h.facets).toContainEqual([
      HilosPages.STATUS_WORKS,
      'hilosStatusWorks',
      { componentKey: ['service', 'queue', 'database'] },
    ])
    expect(table().textContent).toContain('Database upgrade')
    for (const field of ['startsAt', 'endsAt', 'title']) {
      for (const direction of field === 'startsAt'
        ? ['desc', 'asc']
        : ['asc', 'desc']) {
        h.act(() =>
          table()
            .querySelector<HTMLButtonElement>(
              `[data-id="hilos-table-sort-${field}"]`,
            )!
            .click(),
        )
        expect(h.viewports.at(-1)).toEqual([
          HilosPages.STATUS_WORKS,
          'hilosStatusWorks',
          expect.objectContaining({ sort: [{ field, direction }] }),
        ])
      }
    }
    h.window([], undefined, { search: 'no-match' })
    h.detect()
    expect(h.fixture.nativeElement.textContent).toContain('Nothing found')
  })

  it('shows live values and proven mail handoffs while keeping remote membership behind Apply', () => {
    const h = worksPage()
    h.window([workRow(), workRow({ id: 8 })])
    h.update({
      title: 'Changed elsewhere',
      lastMailQueuedAt: announcement.startsAt,
    })
    h.delta({ kind: 'row_removed', rowKey: '8', reason: 'deleted' })
    h.detect()
    expect(el('hilos-status-work-title-7').textContent).toContain(
      'Changed elsewhere',
    )
    expect(el('hilos-status-work-mail-7').textContent?.trim()).not.toBe('—')
    expect(query('hilos-status-work-title-8')).not.toBeNull()
    expect(el('hilos-table-pending').textContent).toContain('1')
    h.act(() => el('hilos-table-apply').click())
    expect(query('hilos-status-work-title-8')).toBeNull()
    h.delta({ kind: 'row_removed', rowKey: '7', reason: 'deleted', own: true })
    h.detect()
    expect(query('hilos-status-work-title-7')).toBeNull()
    expect(h.viewports).toEqual([])
  })

  it('distinguishes unavailable from known empty and disposes the old input generation and viewport', () => {
    const h = worksPage()
    expect(query('hilos-status-works-unavailable')).not.toBeNull()
    expect(query('hilos-table-main-action')).toBeNull()
    h.window([])
    h.detect()
    expect(query('hilos-status-works-unavailable')).toBeNull()
    expect(h.fixture.nativeElement.textContent).toContain(
      'No maintenance is scheduled.',
    )
    expect(query('hilos-table-main-action')).not.toBeNull()
    const next = statusWorksHarness()
    try {
      h.fixture.componentRef.setInput('context', next.context)
      h.detect()
      expect(h.registered.size).toBe(0)
      expect(next.registered.size).toBe(1)
      h.window()
      h.detect()
      expect(query('hilos-status-works-unavailable')).not.toBeNull()
      next.window()
      h.detect()
      expect(el('hilos-status-work-title-7').textContent).toContain(
        'Database upgrade',
      )
      h.fixture.destroy()
      expect(next.registered.size).toBe(0)
    } finally {
      next.dispose()
    }
  })
})
