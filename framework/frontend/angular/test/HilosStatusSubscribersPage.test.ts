import { By } from '@angular/platform-browser'
import { HIDDEN_VALUE, HilosPages, formatMoment } from '@hilos/core/base'
import { describe, expect, it } from 'vitest'

import { HilosAdminPage } from '../src/HilosAdminPage.js'
import { HilosStatusSubscribersPage } from '../src/admin/status/HilosStatusSubscribersPage.js'
import {
  el,
  mountStatusSubscribers,
  statusSubscribersHarness,
  subscriberRow,
} from './helpers/hilosStatus.js'

function page(viewer = false) {
  return mountStatusSubscribers(HilosStatusSubscribersPage, viewer)
}

const table = () => document.querySelector('table')!

describe('HilosStatusSubscribersPage over the real viewport', () => {
  it('passes its section identity and renders the answer rows and reader-zone moments', () => {
    const h = page()
    const adminPage = h.fixture.debugElement.query(By.directive(HilosAdminPage))
      .componentInstance as HilosAdminPage
    expect([
      adminPage.page(),
      adminPage.headKind(),
      adminPage.tabsLabel(),
    ]).toEqual([HilosPages.STATUS_SUBSCRIBERS, 'section', 'Status pages'])
    expect(h.registered.size).toBe(1)
    expect(h.sent).toEqual([])
    expect(h.viewports).toEqual([])
    h.window([subscriberRow(), subscriberRow({ id: 8, confirmed: true })])
    h.counts({ confirmedCount: 1, pendingCount: 1 })
    h.detect()
    expect(table().textContent).toContain('reader@example.net')
    expect(table().textContent).toContain('Awaiting confirmation')
    expect(table().textContent).toContain('Yes')
    const before = table().textContent
    h.chooseZone('Asia/Tokyo')
    expect(table().textContent).toContain(formatMoment(1_800_000_000_000)!)
    expect(table().textContent).not.toBe(before)
  })

  it('keeps unknown counts distinct from zero and applies count updates beside pending rows', () => {
    const h = page()
    const counts = () => [
      el('hilos-status-subscribers-confirmed').textContent?.trim(),
      el('hilos-status-subscribers-pending').textContent?.trim(),
    ]
    expect(counts()).toEqual(['—', '—'])
    expect(el('hilos-status-subscribers-unavailable').textContent).toContain(
      'Subscriber data is unavailable.',
    )
    h.window([subscriberRow(), subscriberRow({ id: 8 })])
    h.counts({ confirmedCount: 0, pendingCount: 0 })
    h.detect()
    expect(counts()).toEqual(['0', '0'])
    expect(
      document.querySelector(
        '[data-id="hilos-status-subscribers-unavailable"]',
      ),
    ).toBeNull()

    h.delta({ kind: 'row_removed', rowKey: '8', reason: 'deleted' })
    h.delta({
      kind: 'row_updated',
      rowKey: '7',
      row: subscriberRow({ confirmed: true }),
    })
    h.counts({ confirmedCount: 1, pendingCount: 0 })
    h.detect()
    expect(counts()).toEqual(['1', '0'])
    expect(el('hilos-status-subscriber-confirmed-7').textContent).toContain(
      'Yes',
    )
    expect(el('hilos-table-pending').textContent).toContain('1')
    expect(
      document.querySelector('[data-id="hilos-status-subscriber-email-8"]'),
    ).not.toBeNull()
    expect(h.viewports).toEqual([])

    h.act(() => el('hilos-table-apply').click())
    expect(
      document.querySelector('[data-id="hilos-status-subscriber-email-8"]'),
    ).toBeNull()
    h.counts(null)
    h.detect()
    expect(counts()).toEqual(['—', '—'])
  })

  it('sends search and confirmed filters through the table without filtering shown rows locally', () => {
    const h = page()
    h.window([subscriberRow()])
    h.detect()
    const search = el<HTMLInputElement>('hilos-table-search')
    expect(search.getAttribute('aria-label')).toBe('Search by email…')
    h.act(() => {
      search.value = 'no-match'
      search.dispatchEvent(new Event('input', { bubbles: true }))
    })
    expect(h.viewports.at(-1)).toEqual([
      HilosPages.STATUS_SUBSCRIBERS,
      'hilosStatusSubscribers',
      expect.objectContaining({ filter: { search: 'no-match' } }),
    ])
    expect(table().textContent).toContain('reader@example.net')

    h.act(() =>
      el('hilos-table-filter-confirmed')
        .querySelector<HTMLButtonElement>('[data-id="hilos-dropdown-toggle"]')!
        .click(),
    )
    h.act(() => el('hilos-dropdown-option-0').click())
    expect(h.viewports.at(-1)).toEqual([
      HilosPages.STATUS_SUBSCRIBERS,
      'hilosStatusSubscribers',
      expect.objectContaining({
        filter: { search: 'no-match', confirmed: true },
      }),
    ])
    expect(h.facets).toContainEqual([
      HilosPages.STATUS_SUBSCRIBERS,
      'hilosStatusSubscribers',
      { confirmed: [true, false] },
    ])
    h.window([], { search: 'no-match', confirmed: true })
    h.detect()
    expect(h.fixture.nativeElement.textContent).toContain('Nothing found')
  })

  it('keeps remote membership for Apply and applies the reader’s own removal immediately', () => {
    const h = page()
    h.window([subscriberRow(), subscriberRow({ id: 8 })])
    h.delta({ kind: 'row_removed', rowKey: '7', reason: 'deleted' })
    h.delta({ kind: 'row_removed', rowKey: '8', reason: 'deleted', own: true })
    h.detect()
    expect(
      document.querySelector('[data-id="hilos-status-subscriber-email-7"]'),
    ).not.toBeNull()
    expect(
      document.querySelector('[data-id="hilos-status-subscriber-email-8"]'),
    ).toBeNull()
    expect(el('hilos-table-pending').textContent).toContain('1')
  })

  it('shows hidden personal fields and disables viewer search, email sort and mutation', () => {
    const h = page(true)
    h.window([subscriberRow({ email: HIDDEN_VALUE })])
    h.counts({ confirmedCount: 0, pendingCount: 1 })
    h.detect()
    expect(h.fixture.nativeElement.textContent).not.toContain(
      'reader@example.net',
    )
    expect(h.fixture.nativeElement.textContent).not.toContain('[object Object]')
    expect(el('hilos-hidden').textContent).toContain('Hidden')
    expect(document.querySelector('[data-id="hilos-table-search"]')).toBeNull()
    expect(
      document.querySelector('[data-id="hilos-table-sort-email"]'),
    ).toBeNull()
    expect(
      document.querySelector('[data-id="hilos-table-sort-confirmed"]'),
    ).not.toBeNull()
    const remove = el<HTMLButtonElement>('hilos-status-subscriber-remove-7')
    expect(remove.disabled).toBe(true)
    h.act(() => remove.click())
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    expect(h.sent).toEqual([])
  })

  it('disposes its old context generation and viewport when context changes', () => {
    const h = page()
    h.window([subscriberRow()])
    h.counts({ confirmedCount: 2, pendingCount: 3 })
    h.detect()
    const second = statusSubscribersHarness()
    try {
      h.fixture.componentRef.setInput('context', second.context)
      h.detect()
      expect(h.registered.size).toBe(0)
      expect(second.registered.size).toBe(1)
      h.counts({ confirmedCount: 100, pendingCount: 100 })
      second.counts({ confirmedCount: 4, pendingCount: 5 })
      h.detect()
      expect(el('hilos-status-subscribers-confirmed').textContent).toContain(
        '4',
      )
      expect(el('hilos-status-subscribers-pending').textContent).toContain('5')
      h.fixture.destroy()
      expect(second.registered.size).toBe(0)
    } finally {
      second.dispose()
    }
  })
})
