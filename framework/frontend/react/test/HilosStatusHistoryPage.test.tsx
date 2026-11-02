// The Status History tab in React: the real page, the real core catalog state
// and table, and the real HilosViewportTable with its search, component and
// outcome filters, period and Apply strip; only the wire is fake. Scenarios
// mirror framework/frontend/vue/src/admin/status/HilosStatusHistoryPage.test.ts,
// plus StrictMode, leaving the page, remount and a changed context. The shared
// section head is proven by its own test.
import { StrictMode } from 'react'
import { act, fireEvent, render } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import { formatMoment, HilosPages } from '@hilos/core/base'
import type { HilosStatusHistoryRow } from '@hilos/core/admin/status'

import { HilosStatusHistoryPage } from '../src/admin/status/HilosStatusHistoryPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'
import { readerTimeZone } from './readerTimeZone.js'
import {
  completedIncident,
  completedMaintenance,
  historyData,
  historyRow,
  statusHistoryHarness,
} from './statusFixtures.js'

const cleanups: (() => void)[] = []
afterEach(() => {
  for (const cleanup of cleanups.splice(0).reverse()) act(cleanup)
})

/** The format every moment of a row is drawn in. */
const MOMENT_FORMAT: Intl.DateTimeFormatOptions = {
  year: 'numeric',
  month: 'short',
  day: 'numeric',
  hour: '2-digit',
  minute: '2-digit',
  second: '2-digit',
  timeZoneName: 'short',
}

function find(
  id: string,
  root: ParentNode = document.body,
): HTMLElement | null {
  return root.querySelector<HTMLElement>(`[data-id="${id}"]`)
}

function get(id: string, root: ParentNode = document.body): HTMLElement {
  const element = find(id, root)
  if (element === null) throw new Error(`Missing [data-id="${id}"]`)
  return element
}

function table(): HTMLTableElement {
  const element = document.querySelector('table')
  if (element === null) throw new Error('Missing table')
  return element
}

/** The header words of every column the table draws, the framework's included. */
function columnNames(): string[] {
  return [...table().querySelectorAll('thead th')].map(
    (cell) => cell.textContent?.trim() ?? '',
  )
}

/**
 * The cell of a row under the column its header names.
 *
 * @param row The body row to read.
 * @param column The header words of the column.
 */
function cellUnder(row: HTMLTableRowElement, column: string): HTMLElement {
  const index = columnNames().indexOf(column)
  if (index === -1) throw new Error(`Missing column ${column}`)
  return row.cells[index]!
}

/**
 * Match a text that says exactly these sentences in this order, however the
 * nodes that carry them are spaced.
 *
 * @param parts The sentences, each one whole.
 */
function sentences(...parts: string[]): RegExp {
  const words = parts.map((sentence) =>
    sentence.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'),
  )
  return new RegExp(`^${words.join('\\s*')}$`)
}

/**
 * A completed incident with some fields changed.
 *
 * @param patch The fields that differ from the default incident.
 */
function fact(patch: Partial<HilosStatusHistoryRow>) {
  return historyRow({ ...completedIncident, ...patch } as HilosStatusHistoryRow)
}

/**
 * A completed maintenance with some fields changed.
 *
 * @param patch The fields that differ from the default maintenance.
 */
function maintenanceFact(patch: Partial<HilosStatusHistoryRow>) {
  return historyRow({
    ...completedMaintenance,
    ...patch,
  } as HilosStatusHistoryRow)
}

/**
 * Mount the real page under the harness router on the History page.
 *
 * @param viewer Whether the signed-in person views the admin view mode.
 * @param strict Whether to mount under StrictMode.
 */
function page(viewer = false, strict = false) {
  const h = statusHistoryHarness(viewer)
  cleanups.push(h.dispose)
  const tree = (
    <HilosRouterContext.Provider value={h.router}>
      <HilosStatusHistoryPage context={h.context} />
    </HilosRouterContext.Provider>
  )
  const view = render(strict ? <StrictMode>{tree}</StrictMode> : tree)
  let mounted = true
  const unmount = () => {
    if (!mounted) return
    mounted = false
    view.unmount()
  }
  cleanups.push(unmount)
  return { ...h, view, unmount }
}

describe('HilosStatusHistoryPage with real normalized rows and viewport', () => {
  it('renders four read-only columns, saved text and reader-zone seconds', () => {
    const zone = readerTimeZone()
    cleanups.push(zone.release)
    act(() => zone.choose('Asia/Tokyo'))
    const h = page(true)
    expect(h.sent).toEqual([])
    expect(h.viewports).toEqual([])
    expect(h.registered.size).toBe(1)
    expect(find('hilos-status-history-table')).toBeNull()
    h.window()
    expect(
      h.view.getAllByRole('navigation', { name: 'Status pages' }),
    ).toHaveLength(1)
    expect(h.view.getByRole('table', { name: 'Status' })).toBe(table())
    expect(columnNames()).toEqual(
      expect.arrayContaining(['Start', 'Duration', 'What', 'Outcome']),
    )
    expect(
      table().querySelector('th[aria-sort="descending"]')?.textContent,
    ).toContain('Start')
    expect(
      table().querySelectorAll('[data-id^="hilos-table-sort-"]'),
    ).toHaveLength(1)
    expect(
      get('hilos-status-history-title-incident:7', table()).textContent,
    ).toBe('<b>Saved service</b>')
    expect(table().textContent).toContain('Major outage')
    expect(table().textContent).toContain('1 h 1 min 1 s')
    expect(table().textContent).toContain('Unknown')
    expect(table().textContent).toContain('The exact end time is unknown.')
    expect(table().textContent).toContain('Completion observed at')
    expect(table().textContent).toContain('Maintenance: announcement unknown')
    expect(table().textContent).toContain('Published at')
    expect(table().textContent).toContain('resolved automatically')
    expect(
      table().querySelector('b, script, input[type="checkbox"]'),
    ).toBeNull()
    expect(find('hilos-table-main-action')).toBeNull()
    expect(find('hilos-status-history-unavailable')).toBeNull()
    expect(table().textContent).toContain(
      formatMoment(completedIncident.startedAt, { format: MOMENT_FORMAT })!,
    )
    const before = table().textContent
    act(() => zone.choose('America/New_York'))
    expect(table().textContent).toContain(
      formatMoment(completedIncident.startedAt, { format: MOMENT_FORMAT })!,
    )
    expect(table().textContent).not.toBe(before)
    expect(h.sent).toEqual([])
  })

  it('distinguishes retained outcomes and announcement knowledge without deriving lost facts', () => {
    const h = page()
    h.window([
      fact({
        id: 'incident:8',
        sourceId: 8,
        outcome: 'recovered_before_publication',
        publishedAt: null,
      }),
      fact({
        id: 'incident:9',
        sourceId: 9,
        outcome: 'canceled_publication',
        publishedAt: null,
      }),
      maintenanceFact({ announcementKnown: true }),
      maintenanceFact({
        id: 'maintenance:8',
        sourceId: 8,
        announcementKnown: true,
        workPublicId: 'saved',
        endedAt: completedMaintenance.startedAt + 1000,
      }),
    ])
    expect(table().textContent).toContain(
      'Not published: recovered before publication',
    )
    expect(table().textContent).toContain(
      'Not published: publication canceled manually',
    )
    expect(table().textContent).toContain('Unannounced maintenance')
    expect(
      get('hilos-status-history-title-maintenance:8', table()).textContent,
    ).toBe('Maintenance «<script>Upgrade</script>»')
    expect(table().textContent).toContain('Completed')
    expect(table().textContent).toContain('1 s')
    expect(table().querySelector('script')).toBeNull()
  })

  it('shows an Unknown duration beside the observed completion, never a zero', () => {
    const h = page()
    h.window([historyRow(completedMaintenance)])
    const row = table().tBodies[0]!.rows[0]!
    expect(cellUnder(row, 'Duration').textContent).toBe('Unknown')
    expect(cellUnder(row, 'Outcome').textContent).toMatch(
      sentences(
        `Completion observed at ${formatMoment(
          completedMaintenance.completionObservedAt!,
          { format: MOMENT_FORMAT },
        )}`,
        'The exact end time is unknown.',
      ),
    )
  })

  it('sends ordinary filters, facets and Start sort while keeping held rows and independent catalog updates', () => {
    const zone = readerTimeZone()
    cleanups.push(zone.release)
    act(() => zone.choose('Europe/Warsaw'))
    const h = page()
    h.window()
    const search = get('hilos-table-search')
    expect(search.getAttribute('aria-label')).toBe(
      'Search by component or maintenance title…',
    )
    fireEvent.change(search, { target: { value: 'missing' } })
    fireEvent.click(
      get('hilos-dropdown-toggle', get('hilos-table-filter-componentKey')),
    )
    fireEvent.click(get('hilos-dropdown-option-0'))
    expect(h.viewports.at(-1)).toEqual([
      HilosPages.STATUS_HISTORY,
      'hilosStatusHistory',
      expect.objectContaining({
        filter: { search: 'missing', componentKey: 'removed' },
      }),
    ])
    fireEvent.click(get('hilos-table-filter-from'))
    const beforePeriod = h.viewports.length
    fireEvent.change(get('hilos-table-filter-from-from'), {
      target: { value: '2031-03-09' },
    })
    expect(h.viewports).toHaveLength(beforePeriod + 1)
    fireEvent.change(get('hilos-table-filter-from-to'), {
      target: { value: '2031-03-09' },
    })
    expect(h.viewports.at(-1)).toEqual([
      HilosPages.STATUS_HISTORY,
      'hilosStatusHistory',
      expect.objectContaining({
        filter: {
          search: 'missing',
          componentKey: 'removed',
          from: '2031-03-09',
          to: '2031-03-09',
          timeZone: 'Europe/Warsaw',
        },
      }),
    ])
    fireEvent.click(get('hilos-table-sort-startedAt', table()))
    expect(h.viewports.at(-1)).toEqual([
      HilosPages.STATUS_HISTORY,
      'hilosStatusHistory',
      expect.objectContaining({
        sort: [{ field: 'startedAt', direction: 'asc' }],
      }),
    ])
    expect(table().textContent).toContain('Saved service')
    h.delta({
      kind: 'row_removed',
      rowKey: completedMaintenance.id,
      reason: 'deleted',
    })
    h.delta({
      kind: 'row_updated',
      rowKey: completedIncident.id,
      row: historyRow({ ...completedIncident, title: 'Saved live name' }),
    })
    h.data({ components: [{ componentKey: 'new', name: 'New facet' }] })
    expect(table().textContent).toContain('Saved live name')
    expect(table().textContent).toContain('Maintenance: announcement unknown')
    expect(get('hilos-table-pending').textContent).toBe('1')
    fireEvent.click(get('hilos-table-apply'))
    expect(table().textContent).not.toContain(
      'Maintenance: announcement unknown',
    )
    expect(h.sent).toEqual([])
  })

  it('keeps unavailable, known empty and filtered empty distinct', () => {
    const h = page()
    h.window([], null)
    expect(get('hilos-status-history-unavailable').textContent).toBe(
      'History data is unavailable.',
    )
    expect(find('hilos-status-history-table')).toBeNull()
    h.window([])
    expect(document.body.textContent).toContain('No completed events yet.')
    expect(find('hilos-status-history-unavailable')).toBeNull()
    fireEvent.change(get('hilos-table-search'), {
      target: { value: 'missing' },
    })
    h.window([], historyData, { search: 'missing' })
    expect(document.body.textContent).toContain('Nothing found')
    h.data({ components: 'malformed' })
    expect(find('hilos-status-history-table')).toBeNull()
    expect(get('hilos-status-history-unavailable')).not.toBeNull()
  })
})

describe('HilosStatusHistoryPage lifecycle', () => {
  it('keeps one live generation through the StrictMode replay and releases it on unmount', () => {
    const h = page(false, true)
    expect(h.registered.size).toBe(1)
    h.window()
    h.delta({
      kind: 'row_updated',
      rowKey: completedIncident.id,
      row: historyRow({ ...completedIncident, title: 'Changed elsewhere' }),
    })
    expect(
      get('hilos-status-history-title-incident:7', table()).textContent,
    ).toBe('Changed elsewhere')
    fireEvent.change(get('hilos-table-search'), {
      target: { value: 'saved' },
    })
    expect(h.viewports).toHaveLength(1)
    h.data({ components: [{ componentKey: 'new', name: 'New facet' }] })
    expect(find('hilos-status-history-table')).not.toBeNull()
    h.unmount()
    expect(h.registered.size).toBe(0)
    expect(find('hilos-status-history-table')).toBeNull()
  })

  it('lets a late frame after unmount reach nothing, and a remount binds a fresh generation', () => {
    const h = page()
    h.window()
    h.unmount()
    h.window([])
    h.delta({
      kind: 'row_removed',
      rowKey: completedIncident.id,
      reason: 'deleted',
    })
    expect(h.registered.size).toBe(0)
    expect(find('hilos-status-history-table')).toBeNull()

    const again = render(
      <StrictMode>
        <HilosRouterContext.Provider value={h.router}>
          <HilosStatusHistoryPage context={h.context} />
        </HilosRouterContext.Provider>
      </StrictMode>,
    )
    cleanups.push(again.unmount)
    expect(h.registered.size).toBe(1)
    h.window()
    expect(table().querySelectorAll('tbody tr')).toHaveLength(2)
    expect(find('hilos-table-apply')).toBeNull()
  })

  it('stops reading and registering after leaving the page', () => {
    const h = page()
    h.window()
    expect(table().querySelectorAll('tbody tr')).toHaveLength(2)
    act(() => {
      h.context.scopes.openPage(HilosPages.STATUS_COMPONENTS)
    })
    expect(h.registered.size).toBe(0)
    h.window()
    expect(get('hilos-status-history-unavailable')).not.toBeNull()
    expect(find('hilos-status-history-table')).toBeNull()
  })

  it('replaces the generation when the context changes', () => {
    const h = page()
    h.window()
    const next = { ...h.context }
    act(() => {
      h.view.rerender(
        <HilosRouterContext.Provider value={h.router}>
          <HilosStatusHistoryPage context={next} />
        </HilosRouterContext.Provider>,
      )
    })
    expect(h.registered.size).toBe(1)
    h.window([historyRow(), historyRow(completedMaintenance)])
    expect(table().querySelectorAll('tbody tr')).toHaveLength(2)
    fireEvent.change(get('hilos-table-search'), {
      target: { value: 'saved' },
    })
    expect(h.viewports).toHaveLength(1)
  })
})
