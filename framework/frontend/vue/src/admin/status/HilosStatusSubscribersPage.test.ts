import { DOMWrapper, mount } from '@vue/test-utils'
import { markRaw, nextTick } from 'vue'
import { afterEach, describe, expect, it } from 'vitest'
import {
  HIDDEN_VALUE,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  formatMoment,
  ActionLifecycle,
  HilosConnection,
  HilosPages,
  bindPageScope,
  PAGE_SIGNAL_SCHEMAS,
} from '@hilos/core/base'

import HilosStatusSubscribersPage from './HilosStatusSubscribersPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'
import {
  statusSubscribersHarness,
  subscriberRow,
} from '../../../test/statusFixtures.js'
import { readerTimeZone } from '../../../test/helpers/readerTimeZone.js'
import { Socket } from '../../../../core/test/status/fixtures.js'

const cleanups: (() => void)[] = []
afterEach(() => {
  for (const cleanup of cleanups.splice(0).reverse()) cleanup()
})
async function page(viewer = false) {
  const h = statusSubscribersHarness(viewer)
  cleanups.push(h.dispose)
  const wrapper = mount(HilosStatusSubscribersPage, {
    props: { context: markRaw(h.context) },
    attachTo: document.body,
    global: { provide: { [hilosRouterKey as symbol]: h.router } },
  })
  cleanups.push(() => wrapper.unmount())
  await nextTick()
  return { ...h, wrapper }
}

describe('HilosStatusSubscribersPage with the real viewport', () => {
  it('releases replayed rows on scope departure before unmount and ignores a late page answer', async () => {
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
    const wrapper = mount(HilosStatusSubscribersPage, {
      props: {
        context: markRaw({
          scopes: h.context.scopes,
          connection,
          actions: new ActionLifecycle(connection),
        }),
      },
      global: { provide: { [hilosRouterKey as symbol]: h.router } },
    })
    cleanups.push(() => wrapper.unmount())
    await nextTick()
    expect(wrapper.get('table').text()).toContain('reader@example.net')
    expect(connection.tableWindowDescriptors()).toHaveProperty(
      'hilosStatusSubscribers',
    )
    pages.subscribe(HilosPages.STATUS)
    await nextTick()
    expect(connection.tableWindowDescriptors()).toEqual({})
    expect(
      wrapper
        .find('table [data-id="hilos-status-subscriber-email-7"]')
        .exists(),
    ).toBe(false)
    expect(
      wrapper.get('[data-id="hilos-status-subscribers-pending"]').text(),
    ).toBe('—')
    socket.push('page_response', answer)
    await nextTick()
    expect(
      wrapper
        .find('table [data-id="hilos-status-subscriber-email-7"]')
        .exists(),
    ).toBe(false)
    expect(
      wrapper.get('[data-id="hilos-status-subscribers-pending"]').text(),
    ).toBe('—')
    wrapper.unmount()
    expect(connection.tableWindowDescriptors()).toEqual({})
  })

  it('renders one shared head, three addresses, four-field rows and reader-zone moments down to seconds', async () => {
    const zone = readerTimeZone()
    cleanups.push(zone.release)
    zone.choose('Asia/Tokyo')
    const h = await page()
    expect(h.sent).toEqual([])
    expect(h.viewports).toEqual([])
    expect(h.registered.size).toBe(1)
    h.window([subscriberRow(), subscriberRow({ id: 8, confirmed: true })])
    await nextTick()
    expect(h.wrapper.findAll('h1').map((h) => h.text())).toEqual(['Status'])
    expect(
      h.wrapper
        .get('nav[aria-label="Status pages"]')
        .findAll('a')
        .map((a) => [
          a.text(),
          a.attributes('href'),
          a.attributes('aria-current'),
        ]),
    ).toEqual([
      ['Overview', '/hilos/status', undefined],
      ['Components', '/hilos/status/components', undefined],
      ['Subscribers', '/hilos/status/subscribers', 'page'],
    ])
    const table = h.wrapper.get('table')
    expect(table.attributes('aria-labelledby')).toBe(
      h.wrapper.get('h1').attributes('id'),
    )
    expect(
      table.get('[data-id="hilos-status-subscriber-email-7"]').text(),
    ).toBe('reader@example.net')
    expect(
      table.get('[data-id="hilos-status-subscriber-confirmed-7"]').text(),
    ).toBe('Awaiting confirmation')
    expect(
      table.get('[data-id="hilos-status-subscriber-confirmed-8"]').text(),
    ).toBe('Yes')
    expect(table.text()).toContain(formatMoment(1_800_000_000_000)!)
    expect(
      table.get('[data-id="hilos-table-sort-subscribedAt"]').text(),
    ).toContain('Subscribed')
    expect(table.get('th[aria-sort="descending"]').text()).toContain(
      'Subscribed',
    )
    const before = table.text()
    zone.choose('America/New_York')
    await nextTick()
    expect(table.text()).toContain(formatMoment(1_800_000_000_000)!)
    expect(table.text()).not.toBe(before)
    const trash = table.get('[data-id="hilos-status-subscriber-remove-7"]')
    expect(trash.attributes('aria-label')).toBe('Remove a subscriber')
    await trash.trigger('click')
    expect(h.sent).toEqual([])
    expect(h.focuses.at(-1)).toEqual([
      'hilos_status_subscribers',
      'hilosStatusSubscribers',
      '7',
    ])
  })

  it('uses server search and boolean filters and offers six field orders without local filtering', async () => {
    const h = await page()
    h.window()
    await nextTick()
    const search = h.wrapper.get('[data-id="hilos-table-search"]')
    expect(search.attributes('aria-label')).toBe('Search by email…')
    await search.setValue('no-match')
    expect(h.viewports.at(-1)).toEqual([
      'hilos_status_subscribers',
      'hilosStatusSubscribers',
      expect.objectContaining({ filter: { search: 'no-match' } }),
    ])
    expect(h.wrapper.get('table').text()).toContain('reader@example.net')
    const filter = h.wrapper.get('[data-id="hilos-table-filter-confirmed"]')
    for (const [index, value] of [
      [0, true],
      [1, false],
    ] as const) {
      await filter.get('[data-id="hilos-dropdown-toggle"]').trigger('click')
      await new DOMWrapper(document.body)
        .get(`[data-id="hilos-dropdown-option-${index}"]`)
        .trigger('click')
      expect(h.viewports.at(-1)).toEqual([
        'hilos_status_subscribers',
        'hilosStatusSubscribers',
        expect.objectContaining({
          filter: { search: 'no-match', confirmed: value },
        }),
      ])
    }
    for (const field of ['email', 'subscribedAt', 'confirmed']) {
      const button = h.wrapper.get(
        `table [data-id="hilos-table-sort-${field}"]`,
      )
      for (const direction of ['asc', 'desc']) {
        await button.trigger('click')
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
    h.window([], { search: 'no-match' })
    await nextTick()
    expect(h.wrapper.text()).toContain('Nothing found')
    await h.wrapper.get('[data-id="hilos-table-filter-reset"]').trigger('click')
    h.window([])
    await nextTick()
    expect(h.wrapper.text()).toContain('No one has subscribed yet.')
  })

  it('keeps null distinct from authoritative zero counts and updates counts without consuming pending Apply', async () => {
    const h = await page()
    const confirmed = () =>
      h.wrapper.get('[data-id="hilos-status-subscribers-confirmed"]').text()
    const pending = () =>
      h.wrapper.get('[data-id="hilos-status-subscribers-pending"]').text()
    expect([confirmed(), pending()]).toEqual(['—', '—'])
    expect(
      h.wrapper.get('[data-id="hilos-status-subscribers-unavailable"]').text(),
    ).toBe('Subscriber data is unavailable.')
    h.window([subscriberRow(), subscriberRow({ id: 8 })])
    h.counts({ confirmedCount: 0, pendingCount: 0 })
    await nextTick()
    expect([confirmed(), pending()]).toEqual(['0', '0'])
    expect(
      h.wrapper
        .find('[data-id="hilos-status-subscribers-unavailable"]')
        .exists(),
    ).toBe(false)
    h.delta({ kind: 'row_removed', rowKey: '8', reason: 'deleted' })
    h.delta({
      kind: 'row_updated',
      rowKey: '7',
      row: subscriberRow({ confirmed: true }),
    })
    h.counts({ confirmedCount: 1, pendingCount: 0 })
    await nextTick()
    expect([confirmed(), pending()]).toEqual(['1', '0'])
    expect(
      h.wrapper
        .get('table [data-id="hilos-status-subscriber-confirmed-7"]')
        .text(),
    ).toBe('Yes')
    expect(h.wrapper.get('[data-id="hilos-table-pending"]').text()).toBe('1')
    expect(
      h.wrapper
        .find('table [data-id="hilos-status-subscriber-email-8"]')
        .exists(),
    ).toBe(true)
    expect(h.viewports).toEqual([])
    await h.wrapper.get('[data-id="hilos-table-apply"]').trigger('click')
    expect(
      h.wrapper
        .find('table [data-id="hilos-status-subscriber-email-8"]')
        .exists(),
    ).toBe(false)
    h.counts(null)
    await nextTick()
    expect([confirmed(), pending()]).toEqual(['—', '—'])
  })

  it('gates remote membership but applies a tagged own removal without a local delete', async () => {
    const h = await page()
    h.window([subscriberRow(), subscriberRow({ id: 8 })])
    h.delta({ kind: 'row_removed', rowKey: '7', reason: 'deleted' })
    h.delta({ kind: 'row_removed', rowKey: '8', reason: 'deleted', own: true })
    await nextTick()
    expect(
      h.wrapper
        .find('table [data-id="hilos-status-subscriber-email-8"]')
        .exists(),
    ).toBe(false)
    expect(
      h.wrapper
        .find('table [data-id="hilos-status-subscriber-email-7"]')
        .exists(),
    ).toBe(true)
    expect(h.wrapper.get('[data-id="hilos-table-pending"]').text()).toBe('1')
  })

  it('renders hidden email without personal controls and disables the viewer trash with the shared explanation', async () => {
    const h = await page(true)
    h.window([subscriberRow({ email: HIDDEN_VALUE })])
    h.counts({ confirmedCount: 0, pendingCount: 1 })
    await nextTick()
    expect(h.wrapper.html()).not.toContain('reader@example.net')
    expect(h.wrapper.html()).not.toContain('[object Object]')
    expect(h.wrapper.get('table [data-id="hilos-hidden"]').text()).toContain(
      'Hidden',
    )
    expect(h.wrapper.find('[data-id="hilos-table-search"]').exists()).toBe(
      false,
    )
    expect(
      h.wrapper.find('table [data-id="hilos-table-sort-email"]').exists(),
    ).toBe(false)
    expect(
      h.wrapper.find('table [data-id="hilos-table-sort-confirmed"]').exists(),
    ).toBe(true)
    const trash = h.wrapper.get(
      'table [data-id="hilos-status-subscriber-remove-7"]',
    )
    expect((trash.element as HTMLButtonElement).disabled).toBe(true)
    expect(trash.attributes('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )
    await trash.trigger('click')
    expect(
      new DOMWrapper(document.body).find('[data-id="modal"]').exists(),
    ).toBe(false)
    expect(h.sent).toEqual([])
  })

  it('disposes the table registration and page projections without reviving after scope change', async () => {
    const h = await page()
    h.window()
    h.counts({ confirmedCount: 2, pendingCount: 3 })
    h.context.scopes.openPage('another')
    await nextTick()
    expect(h.registered.size).toBe(0)
    h.counts({ confirmedCount: 100, pendingCount: 100 })
    await nextTick()
    expect(
      h.wrapper.get('[data-id="hilos-status-subscribers-confirmed"]').text(),
    ).toBe('—')
    h.wrapper.unmount()
    expect(h.registered.size).toBe(0)
  })
})
