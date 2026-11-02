import { By } from '@angular/platform-browser'
import { TestBed } from '@angular/core/testing'
import {
  bindPageScope,
  bindTimeZoneState,
  formatMoment,
  HilosPages,
  type TableRow,
} from '@hilos/core/base'
import { type HilosStatusHistoryRow } from '@hilos/core/admin/status'
import { afterEach, describe, expect, it } from 'vitest'

import { HilosAdminPage } from '../src/HilosAdminPage.js'
import { HilosStatusHistoryPage } from '../src/admin/status/HilosStatusHistoryPage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'
import { el, query, statusHarness } from './helpers/hilosStatus.js'

const cleanups: (() => void)[] = []
afterEach(() => {
  for (const cleanup of cleanups.splice(0).reverse()) cleanup()
})

const incident: HilosStatusHistoryRow = {
  id: 'incident:7',
  kind: 'incident',
  sourceId: 7,
  startedAt: Date.UTC(2031, 0, 2, 3, 4, 5),
  endedAt: Date.UTC(2031, 0, 2, 4, 5, 6),
  completionObservedAt: null,
  publishedAt: Date.UTC(2031, 0, 2, 3, 4, 15),
  outcome: 'resolved',
  title: '<b>Saved service</b>',
  componentKeys: ['removed'],
  severity: 'major_outage',
  announcementKnown: null,
  workPublicId: null,
}

const maintenance: HilosStatusHistoryRow = {
  id: 'maintenance:7',
  kind: 'maintenance',
  sourceId: 7,
  startedAt: incident.startedAt,
  endedAt: null,
  completionObservedAt: incident.startedAt + 90000,
  publishedAt: null,
  outcome: 'maintenance_completed',
  title: '<script>Upgrade</script>',
  componentKeys: ['removed'],
  severity: null,
  announcementKnown: false,
  workPublicId: null,
}

const catalog = {
  components: [{ componentKey: 'removed', name: 'Removed component' }],
}

const row = (fact: HilosStatusHistoryRow = incident): TableRow => ({
  rowKey: fact.id,
  slots: { history: fact },
})

function prepareHistoryHarness(viewer = false) {
  const h = statusHarness(HilosPages.STATUS_HISTORY)
  cleanups.push(h.dispose)
  if (viewer) h.viewer()
  cleanups.push(bindTimeZoneState(h.context.connection))
  const pages = bindPageScope(h.context.connection, h.context.scopes)
  pages.subscribe(HilosPages.STATUS_HISTORY)
  return h
}

function historyPage(viewer = false) {
  const h = prepareHistoryHarness(viewer)
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: h.router }],
  })
  const fixture = TestBed.createComponent(HilosStatusHistoryPage)
  fixture.componentRef.setInput('context', h.context)
  fixture.detectChanges()
  let destroyed = false
  const destroy = () => {
    if (destroyed) return
    destroyed = true
    fixture.destroy()
  }
  cleanups.push(destroy)

  const answer = (payload: Record<string, unknown>) =>
    h.emit('projectSignal', {
      type: 'page_response',
      data: { page: HilosPages.STATUS_HISTORY, payload },
    })
  const delta = (data: Record<string, unknown>) =>
    h.emit('tableViewportDelta', {
      data: {
        page: HilosPages.STATUS_HISTORY,
        tableKey: 'hilosStatusHistory',
        ...data,
      },
    })
  const detect = () => fixture.detectChanges()
  return {
    ...h,
    fixture,
    detect,
    destroy,
    act(run: () => void) {
      run()
      detect()
    },
    chooseZone(timeZone: string) {
      h.emit('projectSignal', {
        type: 'handshake_response',
        data: { entities: { currentUser: { id: 1, timeZone } } },
      })
      detect()
    },
    window(
      rows: readonly TableRow[] = [row(), row(maintenance)],
      data: unknown = catalog,
      filter: Record<string, unknown> = {},
    ) {
      answer({
        data: { statusHistory: data },
        windows: {
          hilosStatusHistory: {
            rows,
            totalCount: rows.length,
            totalExact: true,
            firstAnchor: null,
            lastAnchor: null,
            limit: 25,
            sort: [{ field: 'startedAt', direction: 'desc' }],
            filter,
          },
        },
      })
    },
    delta,
  }
}

const table = () => document.querySelector('table')!

describe('HilosStatusHistoryPage over the real viewport', () => {
  it('passes the shared head and renders saved completed facts in the live reader zone', () => {
    const h = historyPage(true)
    const admin = h.fixture.debugElement.query(By.directive(HilosAdminPage))
      .componentInstance as HilosAdminPage
    expect([admin.page(), admin.headKind(), admin.tabsLabel()]).toEqual([
      HilosPages.STATUS_HISTORY,
      'section',
      'Status pages',
    ])
    expect(h.registered.size).toBe(1)
    expect(h.sent).toEqual([])
    expect(h.viewports).toEqual([])
    h.window([
      row(),
      row(maintenance),
      row({
        ...incident,
        id: 'incident:8',
        sourceId: 8,
        publishedAt: null,
        outcome: 'recovered_before_publication',
      }),
      row({
        ...incident,
        id: 'incident:9',
        sourceId: 9,
        publishedAt: null,
        outcome: 'canceled_publication',
      }),
      row({
        ...maintenance,
        id: 'maintenance:8',
        sourceId: 8,
        announcementKnown: true,
      }),
      row({
        ...maintenance,
        id: 'maintenance:9',
        sourceId: 9,
        endedAt: maintenance.startedAt + 1000,
        announcementKnown: true,
        workPublicId: 'saved-announcement',
      }),
    ])
    h.detect()

    const rendered = table()
    expect(rendered.textContent).toContain('<b>Saved service</b>')
    expect(rendered.textContent).toContain('Major outage')
    expect(rendered.textContent).toContain('1 h 1 min 1 s')
    expect(rendered.textContent).toContain('Unknown')
    expect(rendered.textContent).toContain('The exact end time is unknown.')
    expect(rendered.textContent).toContain('Completion observed at')
    expect(rendered.textContent).toContain('Published at')
    expect(rendered.textContent).toContain('resolved automatically')
    expect(rendered.textContent).toContain('recovered before publication')
    expect(rendered.textContent).toContain('publication canceled manually')
    expect(rendered.textContent).toContain('Unannounced maintenance')
    expect(rendered.textContent).toContain(
      'Maintenance «<script>Upgrade</script>»',
    )
    expect(rendered.textContent).toContain('Completed')
    expect(rendered.textContent).toContain('1 s')
    expect(rendered.querySelector('b, script')).toBeNull()
    expect(query('hilos-table-main-action')).toBeNull()

    const options = {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
      timeZoneName: 'short',
    } as const
    h.chooseZone('Asia/Tokyo')
    expect(rendered.textContent).toContain(
      formatMoment(incident.startedAt, { format: options })!,
    )
    const tokyo = rendered.textContent
    h.chooseZone('America/New_York')
    expect(rendered.textContent).not.toBe(tokyo)
    expect(h.viewports).toEqual([])
    expect(h.sent).toEqual([])
  })

  it('delegates history filters and Start order while visible values update before Apply', () => {
    const h = historyPage()
    h.window()
    h.detect()
    expect(
      el<HTMLInputElement>('hilos-table-search').getAttribute('aria-label'),
    ).toBe('Search by component or maintenance title…')
    h.act(() => {
      const search = el<HTMLInputElement>('hilos-table-search')
      search.value = 'saved'
      search.dispatchEvent(new Event('input', { bubbles: true }))
    })
    expect(h.viewports.at(-1)).toEqual([
      HilosPages.STATUS_HISTORY,
      'hilosStatusHistory',
      expect.objectContaining({ filter: { search: 'saved' } }),
    ])

    h.act(() =>
      el('hilos-table-filter-componentKey')
        .querySelector<HTMLButtonElement>('[data-id="hilos-dropdown-toggle"]')!
        .click(),
    )
    h.act(() =>
      el('hilos-table-filter-componentKey')
        .querySelector<HTMLButtonElement>(
          '[data-id="hilos-dropdown-option-0"]',
        )!
        .click(),
    )
    expect(h.viewports.at(-1)).toEqual([
      HilosPages.STATUS_HISTORY,
      'hilosStatusHistory',
      expect.objectContaining({
        filter: { search: 'saved', componentKey: 'removed' },
      }),
    ])
    h.act(() =>
      el('hilos-table-filter-outcome')
        .querySelector<HTMLButtonElement>('[data-id="hilos-dropdown-toggle"]')!
        .click(),
    )
    h.act(() =>
      el('hilos-table-filter-outcome')
        .querySelector<HTMLButtonElement>(
          '[data-id="hilos-dropdown-option-0"]',
        )!
        .click(),
    )
    expect(h.viewports.at(-1)).toEqual([
      HilosPages.STATUS_HISTORY,
      'hilosStatusHistory',
      expect.objectContaining({
        filter: expect.objectContaining({ outcome: 'resolved' }),
      }),
    ])

    h.chooseZone('Europe/Warsaw')
    h.act(() => el('hilos-table-filter-from').click())
    h.act(() => {
      const from = el<HTMLInputElement>('hilos-table-filter-from-from')
      from.value = '2031-03-09'
      from.dispatchEvent(new Event('input', { bubbles: true }))
    })
    h.act(() => {
      const to = el<HTMLInputElement>('hilos-table-filter-from-to')
      to.value = '2031-03-09'
      to.dispatchEvent(new Event('input', { bubbles: true }))
    })
    expect(h.viewports.at(-1)).toEqual([
      HilosPages.STATUS_HISTORY,
      'hilosStatusHistory',
      expect.objectContaining({
        filter: expect.objectContaining({
          from: '2031-03-09',
          to: '2031-03-09',
          timeZone: 'Europe/Warsaw',
        }),
      }),
    ])
    h.act(() =>
      table()
        .querySelector<HTMLButtonElement>(
          '[data-id="hilos-table-sort-startedAt"]',
        )!
        .click(),
    )
    expect(h.viewports.at(-1)).toEqual([
      HilosPages.STATUS_HISTORY,
      'hilosStatusHistory',
      expect.objectContaining({
        sort: [{ field: 'startedAt', direction: 'asc' }],
      }),
    ])

    h.delta({
      kind: 'row_updated',
      rowKey: incident.id,
      row: row({ ...incident, title: 'Saved live service' }),
    })
    h.delta({ kind: 'row_removed', rowKey: maintenance.id, reason: 'deleted' })
    h.detect()
    expect(el('hilos-status-history-title-incident:7').textContent).toContain(
      'Saved live service',
    )
    expect(table().textContent).toContain('Maintenance: announcement unknown')
    expect(el('hilos-table-pending').textContent).toContain('1')
    const requestsBeforeApply = h.viewports.length
    h.act(() => el('hilos-table-apply').click())
    expect(query('hilos-status-history-title-maintenance:7')).toBeNull()
    expect(h.viewports).toHaveLength(requestsBeforeApply)
    expect(h.sent).toEqual([])
  })

  it('keeps unavailable, known empty and filtered empty histories distinct', () => {
    const h = historyPage()
    h.window([], null)
    h.detect()
    expect(el('hilos-status-history-unavailable').textContent).toContain(
      'History data is unavailable.',
    )
    expect(query('hilos-status-history-table')).toBeNull()

    h.window([])
    h.detect()
    expect(h.fixture.nativeElement.textContent).toContain(
      'No completed events yet.',
    )
    h.act(() => {
      const search = el<HTMLInputElement>('hilos-table-search')
      search.value = 'missing'
      search.dispatchEvent(new Event('input', { bubbles: true }))
    })
    h.window([], catalog, { search: 'missing' })
    h.detect()
    expect(h.fixture.nativeElement.textContent).toContain('Nothing found')
    expect(query('hilos-table-main-action')).toBeNull()
    expect(h.sent).toEqual([])
  })

  it('disposes each viewport and page-data observer when the input context changes', () => {
    const h = historyPage()
    h.window()
    h.detect()
    const next = prepareHistoryHarness()
    h.fixture.componentRef.setInput('context', next.context)
    h.detect()
    expect(h.registered.size).toBe(0)
    expect(next.registered.size).toBe(1)
    expect(query('hilos-status-history-unavailable')).not.toBeNull()

    next.emit('projectSignal', {
      type: 'page_response',
      data: {
        page: HilosPages.STATUS_HISTORY,
        payload: {
          data: { statusHistory: catalog },
          windows: {
            hilosStatusHistory: {
              rows: [],
              totalCount: 0,
              totalExact: true,
              firstAnchor: null,
              lastAnchor: null,
              limit: 25,
              sort: [{ field: 'startedAt', direction: 'desc' }],
              filter: {},
            },
          },
        },
      },
    })
    h.detect()
    expect(h.fixture.nativeElement.textContent).toContain(
      'No completed events yet.',
    )
    h.destroy()
    expect(next.registered.size).toBe(0)
  })
})
