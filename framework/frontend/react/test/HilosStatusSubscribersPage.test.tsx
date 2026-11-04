// The Status Subscribers tab in React: the real page, the real core table,
// counts and removal controller, the real HilosViewportTable with its search,
// filter, orders and Apply strip; only the wire is fake. Scenarios mirror
// framework/frontend/vue/src/admin/status/HilosStatusSubscribersPage.test.ts.
// The shared section head is proven by its own test.
import { act, fireEvent } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import {
  ActionLifecycle,
  bindPageScope,
  formatMoment,
  HIDDEN_VALUE,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  HilosConnection,
  HilosPages,
  PAGE_SIGNAL_SCHEMAS,
} from '@hilos/core/base'
import type { HilosStatusSubscribersContext } from '@hilos/core/admin/status'

import { HilosStatusSubscribersPage } from '../src/admin/status/HilosStatusSubscribersPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'
import { readerTimeZone } from './readerTimeZone.js'
import { statusSubscribersHarness, subscriberRow } from './statusFixtures.js'
import { Socket } from '../../core/test/status/fixtures.js'

const cleanups: (() => void)[] = []
afterEach(() => {
  for (const cleanup of cleanups.splice(0).reverse()) act(cleanup)
})

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

/** The confirmed and awaiting counts, as the page draws them. */
function counts(): [string | null, string | null] {
  return [
    get('hilos-status-subscribers-confirmed').textContent,
    get('hilos-status-subscribers-pending').textContent,
  ]
}

/**
 * Choose one option of the standard confirmation filter.
 *
 * @param index The option's position in the filter's list.
 */
function chooseConfirmed(index: number): void {
  fireEvent.click(
    get('hilos-dropdown-toggle', get('hilos-table-filter-confirmed')),
  )
  fireEvent.click(get(`hilos-dropdown-option-${index}`))
}

function page(viewer = false, strict = false) {
  const h = statusSubscribersHarness(viewer)
  cleanups.push(h.dispose)
  const view = h.mount(
    <HilosStatusSubscribersPage context={h.context} />,
    strict,
  )
  let mounted = true
  const unmount = () => {
    if (!mounted) return
    mounted = false
    view.unmount()
  }
  cleanups.push(unmount)
  return { ...h, view, unmount }
}

describe('HilosStatusSubscribersPage with the real viewport', () => {
  it('releases replayed rows on scope departure before unmount and ignores a late page answer', () => {
    const h = statusSubscribersHarness()
    cleanups.push(h.dispose)
    const socket = new Socket()
    const connection = new HilosConnection({
      url: 'ws://test/ws',
      webSocketFactory: () => socket,
      projectSchemas: PAGE_SIGNAL_SCHEMAS,
    })
    cleanups.push(() => connection.close())
    const pages = bindPageScope(connection, h.context.scopes)
    connection.connect()
    socket.emit('open')
    pages.releaseOnSession()
    pages.subscribe(HilosPages.STATUS_SUBSCRIBERS)
    const answer = {
      page: HilosPages.STATUS_SUBSCRIBERS,
      payload: {
        data: { statusSubscribers: { confirmedCount: 0, pendingCount: 1 } },
        windows: {
          hilosStatusSubscribers: {
            filter: {},
            rows: [subscriberRow()],
            totalCount: 1,
            totalExact: true,
            firstAnchor: null,
            lastAnchor: null,
            limit: 25,
            sort: [{ field: 'subscribedAt', direction: 'desc' }],
          },
        },
      },
    }
    socket.push('page_response', answer)
    const context: HilosStatusSubscribersContext = {
      scopes: h.context.scopes,
      connection,
      actions: new ActionLifecycle(connection),
    }
    const view = h.mount(<HilosStatusSubscribersPage context={context} />)
    cleanups.push(view.unmount)
    expect(table().textContent).toContain('reader@example.net')
    expect(connection.tableWindowDescriptors()).toHaveProperty(
      'hilosStatusSubscribers',
    )
    act(() => {
      pages.subscribe(HilosPages.STATUS)
    })
    expect(connection.tableWindowDescriptors()).toEqual({})
    expect(find('hilos-status-subscriber-email-7', table())).toBeNull()
    expect(get('hilos-status-subscribers-pending').textContent).toBe('—')
    act(() => {
      socket.push('page_response', answer)
    })
    expect(find('hilos-status-subscriber-email-7', table())).toBeNull()
    expect(get('hilos-status-subscribers-pending').textContent).toBe('—')
    view.unmount()
    expect(connection.tableWindowDescriptors()).toEqual({})
  })

  it('renders a named table of four-field rows with reader-zone moments and a trash that only focuses its row', () => {
    const zone = readerTimeZone()
    cleanups.push(zone.release)
    act(() => zone.choose('Asia/Tokyo'))
    const h = page()
    expect(h.sent).toEqual([])
    expect(h.viewports).toEqual([])
    expect(h.registered.size).toBe(1)
    h.window([subscriberRow(), subscriberRow({ id: 8, confirmed: true })])
    expect(get('hilos-status-subscribers-table')).not.toBeNull()
    expect(table().getAttribute('aria-labelledby')).toBe(
      h.view.container.querySelector('h1')!.id,
    )
    expect(get('hilos-status-subscriber-email-7', table()).textContent).toBe(
      'reader@example.net',
    )
    expect(
      get('hilos-status-subscriber-confirmed-7', table()).textContent,
    ).toBe('Awaiting confirmation')
    expect(
      get('hilos-status-subscriber-confirmed-8', table()).textContent,
    ).toBe('Yes')
    expect(table().textContent).toContain(formatMoment(1_800_000_000_000)!)
    expect(get('hilos-table-sort-subscribedAt', table()).textContent).toContain(
      'Subscribed',
    )
    expect(
      table().querySelector('th[aria-sort="descending"]')?.textContent,
    ).toContain('Subscribed')
    const before = table().textContent
    act(() => zone.choose('America/New_York'))
    expect(table().textContent).toContain(formatMoment(1_800_000_000_000)!)
    expect(table().textContent).not.toBe(before)
    const trash = get('hilos-status-subscriber-remove-7', table())
    expect(trash.getAttribute('aria-label')).toBe('Remove a subscriber')
    fireEvent.click(trash)
    expect(h.sent).toEqual([])
    expect(h.focuses.at(-1)).toEqual([
      'hilos_status_subscribers',
      'hilosStatusSubscribers',
      '7',
    ])
  })

  it('uses server search and boolean filters and offers six field orders without local filtering', () => {
    const h = page()
    h.window()
    const search = get('hilos-table-search') as HTMLInputElement
    expect(search.getAttribute('aria-label')).toBe('Search by email…')
    fireEvent.change(search, { target: { value: 'no-match' } })
    expect(h.viewports.at(-1)).toEqual([
      'hilos_status_subscribers',
      'hilosStatusSubscribers',
      expect.objectContaining({ filter: { search: 'no-match' } }),
    ])
    expect(table().textContent).toContain('reader@example.net')
    for (const [index, value] of [
      [0, true],
      [1, false],
    ] as const) {
      chooseConfirmed(index)
      expect(h.viewports.at(-1)).toEqual([
        'hilos_status_subscribers',
        'hilosStatusSubscribers',
        expect.objectContaining({
          filter: { search: 'no-match', confirmed: value },
        }),
      ])
    }
    for (const field of ['email', 'subscribedAt', 'confirmed']) {
      for (const direction of ['asc', 'desc']) {
        fireEvent.click(get(`hilos-table-sort-${field}`, table()))
        expect(h.viewports.at(-1)).toEqual([
          'hilos_status_subscribers',
          'hilosStatusSubscribers',
          expect.objectContaining({ sort: [{ field, direction }] }),
        ])
      }
    }
    expect(h.facets).toContainEqual([
      'hilos_status_subscribers',
      'hilosStatusSubscribers',
      { confirmed: [true, false] },
    ])
    h.window([], { search: 'no-match', confirmed: false })
    expect(document.body.textContent).toContain('Nothing found')
    fireEvent.click(get('hilos-table-filter-reset'))
    h.window([])
    expect(document.body.textContent).toContain('No one has subscribed yet.')
  })

  it('keeps null distinct from authoritative zero counts and updates counts without consuming pending Apply', () => {
    const h = page()
    expect(counts()).toEqual(['—', '—'])
    expect(get('hilos-status-subscribers-unavailable').textContent).toBe(
      'Subscriber data is unavailable.',
    )
    h.window([subscriberRow(), subscriberRow({ id: 8 })])
    h.counts({ confirmedCount: 0, pendingCount: 0 })
    expect(counts()).toEqual(['0', '0'])
    expect(find('hilos-status-subscribers-unavailable')).toBeNull()
    h.delta({ kind: 'row_removed', rowKey: '8', reason: 'deleted' })
    h.delta({
      kind: 'row_updated',
      rowKey: '7',
      row: subscriberRow({ confirmed: true }),
    })
    h.counts({ confirmedCount: 1, pendingCount: 0 })
    expect(counts()).toEqual(['1', '0'])
    expect(
      get('hilos-status-subscriber-confirmed-7', table()).textContent,
    ).toBe('Yes')
    expect(get('hilos-table-pending').textContent).toBe('1')
    expect(find('hilos-status-subscriber-email-8', table())).not.toBeNull()
    expect(h.viewports).toEqual([])
    fireEvent.click(get('hilos-table-apply'))
    expect(find('hilos-status-subscriber-email-8', table())).toBeNull()
    h.counts(null)
    expect(counts()).toEqual(['—', '—'])
  })

  it('gates remote membership but applies a tagged own removal without a local delete', () => {
    const h = page()
    h.window([subscriberRow(), subscriberRow({ id: 8 })])
    h.delta({ kind: 'row_removed', rowKey: '7', reason: 'deleted' })
    h.delta({ kind: 'row_removed', rowKey: '8', reason: 'deleted', own: true })
    expect(find('hilos-status-subscriber-email-8', table())).toBeNull()
    expect(find('hilos-status-subscriber-email-7', table())).not.toBeNull()
    expect(get('hilos-table-pending').textContent).toBe('1')
  })

  it('renders hidden email without personal controls and disables the viewer trash with the shared explanation', () => {
    const h = page(true)
    h.window([subscriberRow({ email: HIDDEN_VALUE })])
    h.counts({ confirmedCount: 0, pendingCount: 1 })
    expect(document.body.innerHTML).not.toContain('reader@example.net')
    expect(document.body.innerHTML).not.toContain('[object Object]')
    expect(get('hilos-hidden', table()).textContent).toContain('Hidden')
    expect(find('hilos-table-search')).toBeNull()
    expect(find('hilos-table-sort-email', table())).toBeNull()
    expect(find('hilos-table-sort-confirmed', table())).not.toBeNull()
    const trash = get(
      'hilos-status-subscriber-remove-7',
      table(),
    ) as HTMLButtonElement
    expect(trash.disabled).toBe(true)
    expect(trash.getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )
    fireEvent.click(trash)
    expect(find('modal')).toBeNull()
    expect(h.sent).toEqual([])
  })

  it('disposes the table registration and page projections without reviving after scope change', () => {
    const h = page()
    h.window()
    h.counts({ confirmedCount: 2, pendingCount: 3 })
    act(() => {
      h.context.scopes.openPage('another')
    })
    expect(h.registered.size).toBe(0)
    h.counts({ confirmedCount: 100, pendingCount: 100 })
    expect(get('hilos-status-subscribers-confirmed').textContent).toBe('—')
    h.unmount()
    expect(h.registered.size).toBe(0)
  })
})

describe('HilosStatusSubscribersPage lifecycle', () => {
  it('keeps one live generation through the StrictMode replay and releases it on unmount', () => {
    const h = page(false, true)
    expect(h.registered.size).toBe(1)
    h.window([subscriberRow(), subscriberRow({ id: 8 })])
    h.counts({ confirmedCount: 1, pendingCount: 1 })
    expect(counts()).toEqual(['1', '1'])
    h.delta({
      kind: 'row_updated',
      rowKey: '7',
      row: subscriberRow({ confirmed: true }),
    })
    expect(
      get('hilos-status-subscriber-confirmed-7', table()).textContent,
    ).toBe('Yes')
    fireEvent.change(get('hilos-table-search'), {
      target: { value: 'reader' },
    })
    expect(h.viewports).toHaveLength(1)
    fireEvent.click(get('hilos-status-subscriber-remove-8', table()))
    expect(h.focuses).toHaveLength(1)
    expect(get('hilos-status-subscriber-remove-target').textContent).toBe(
      'reader@example.net · #8',
    )
    h.unmount()
    expect(h.registered.size).toBe(0)
    expect(find('hilos-status-subscribers-table')).toBeNull()
    expect(find('modal')).toBeNull()
  })

  it('lets a late frame after unmount reach nothing, and a remount binds a fresh generation', () => {
    const h = page()
    h.window()
    h.counts({ confirmedCount: 1, pendingCount: 0 })
    h.unmount()
    h.window([])
    h.counts({ confirmedCount: 9, pendingCount: 9 })
    h.delta({ kind: 'row_removed', rowKey: '7', reason: 'deleted' })
    expect(h.registered.size).toBe(0)
    expect(find('hilos-status-subscribers-table')).toBeNull()

    const again = h.mount(
      <HilosStatusSubscribersPage context={h.context} />,
      true,
    )
    cleanups.push(again.unmount)
    expect(h.registered.size).toBe(1)
    h.window([subscriberRow(), subscriberRow({ id: 8 })])
    h.counts({ confirmedCount: 2, pendingCount: 0 })
    expect(table().querySelectorAll('tbody tr')).toHaveLength(2)
    expect(counts()).toEqual(['2', '0'])
    expect(find('hilos-table-apply')).toBeNull()
  })

  it('replaces the generation when the context changes and leaves the old one unbound', () => {
    const h = page()
    h.window()
    const next = { ...h.context }
    act(() => {
      h.view.rerender(
        <HilosRouterContext.Provider value={h.router}>
          <HilosStatusSubscribersPage context={next} />
        </HilosRouterContext.Provider>,
      )
    })
    expect(h.registered.size).toBe(1)
    h.window([subscriberRow(), subscriberRow({ id: 8 })])
    h.counts({ confirmedCount: 0, pendingCount: 2 })
    expect(table().querySelectorAll('tbody tr')).toHaveLength(2)
    expect(counts()).toEqual(['0', '2'])
  })
})
