import { DOMWrapper, mount } from '@vue/test-utils'
import { markRaw, nextTick } from 'vue'
import { afterEach, describe, expect, it } from 'vitest'
import {
  bindPageScope,
  createSignal,
  formatMoment,
  HilosPages,
  type TableRow,
} from '@hilos/core/base'
import { type HilosStatusHistoryRow } from '@hilos/core/admin/status'

import HilosStatusHistoryPage from './HilosStatusHistoryPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'
import { readerTimeZone } from '../../../test/helpers/readerTimeZone.js'
import { statusHarness } from '../../../test/statusFixtures.js'

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
const catalog = { components: [{ componentKey: 'removed', name: 'removed' }] }
const row = (fact: HilosStatusHistoryRow = incident): TableRow => ({
  rowKey: fact.id,
  slots: { history: fact },
})

async function page(viewer = false) {
  const h = statusHarness(HilosPages.STATUS_HISTORY)
  cleanups.push(h.dispose)
  if (viewer) h.viewer()
  const pages = bindPageScope(h.context.connection, h.context.scopes)
  pages.subscribe(HilosPages.STATUS_HISTORY)
  const router = {
    ...h.router,
    currentPath: createSignal('/hilos/status/history'),
    pageIdentity: createSignal({
      ...h.router.pageIdentity.get()!,
      pageTabs: [
        ...h.router.pageIdentity.get()!.pageTabs!,
        { page: HilosPages.STATUS_HISTORY, label: 'History' },
      ],
    }),
    resolvePath: (key: string) =>
      key === HilosPages.STATUS_HISTORY
        ? '/hilos/status/history'
        : h.router.resolvePath(key),
  }
  const wrapper = mount(HilosStatusHistoryPage, {
    props: { context: markRaw(h.context) },
    attachTo: document.body,
    global: { provide: { [hilosRouterKey as symbol]: router } },
  })
  cleanups.push(() => wrapper.unmount())
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
  await nextTick()
  return {
    ...h,
    wrapper,
    delta,
    window(
      rows = [row(), row(maintenance)],
      data: unknown = catalog,
      filter = {},
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
    data: (data: unknown) => answer({ data: { statusHistory: data } }),
  }
}

describe('HilosStatusHistoryPage', () => {
  it('renders one shared head, four readonly columns, saved text and reader-zone seconds', async () => {
    const zone = readerTimeZone()
    cleanups.push(zone.release)
    zone.choose('Asia/Tokyo')
    const h = await page(true)
    expect(h.sent).toEqual([])
    expect(h.viewports).toEqual([])
    expect(h.wrapper.find('table').exists()).toBe(false)
    h.window()
    await nextTick()
    expect(h.wrapper.findAll('h1').map((node) => node.text())).toEqual([
      'Status',
    ])
    expect(
      h.wrapper
        .get('nav[aria-label="Status pages"] [aria-current="page"]')
        .text(),
    ).toBe('History')
    const table = h.wrapper.get('table')
    expect(table.attributes('aria-labelledby')).toBe(
      h.wrapper.get('h1').attributes('id'),
    )
    expect(
      table
        .findAll('th')
        .slice(0, 4)
        .map((cell) => cell.text()),
    ).toEqual(['Start', 'Duration', 'What', 'Outcome'])
    expect(table.get('th[aria-sort="descending"]').text()).toBe('Start')
    expect(table.findAll('[data-id^="hilos-table-sort-"]')).toHaveLength(1)
    expect(table.text()).toContain('<b>Saved service</b>')
    expect(table.text()).toContain('Major outage')
    expect(table.text()).toContain('1 h 1 min 1 s')
    expect(table.text()).toContain('Unknown')
    expect(table.text()).toContain('The exact end time is unknown.')
    expect(table.text()).toContain('Completion observed at')
    expect(table.text()).toContain('Maintenance: announcement unknown')
    expect(table.text()).toContain('Published at')
    expect(table.text()).toContain('resolved automatically')
    expect(table.find('b, script, input[type="checkbox"]').exists()).toBe(false)
    expect(h.wrapper.find('[data-id="hilos-table-main-action"]').exists()).toBe(
      false,
    )
    const options = {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
      timeZoneName: 'short',
    } as const
    expect(table.text()).toContain(
      formatMoment(incident.startedAt, { format: options })!,
    )
    const before = table.text()
    zone.choose('America/New_York')
    await nextTick()
    expect(table.text()).toContain(
      formatMoment(incident.startedAt, { format: options })!,
    )
    expect(table.text()).not.toBe(before)
    expect(h.sent).toEqual([])
  })

  it('distinguishes retained outcomes and announcement knowledge without deriving lost facts', async () => {
    const h = await page()
    const fact = (patch: Partial<HilosStatusHistoryRow>) =>
      row({ ...incident, ...patch } as HilosStatusHistoryRow)
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
      row({ ...maintenance, announcementKnown: true }),
      row({
        ...maintenance,
        id: 'maintenance:8',
        sourceId: 8,
        announcementKnown: true,
        workPublicId: 'saved',
        endedAt: maintenance.startedAt + 1000,
      }),
    ])
    await nextTick()
    const table = h.wrapper.get('table')
    expect(table.text()).toContain(
      'Not published: recovered before publication',
    )
    expect(table.text()).toContain(
      'Not published: publication canceled manually',
    )
    expect(table.text()).toContain('Unannounced maintenance')
    expect(table.text()).toContain('Maintenance «<script>Upgrade</script>»')
    expect(table.text()).toContain('Completed')
    expect(table.text()).toContain('1 s')
    expect(table.find('script').exists()).toBe(false)
  })

  it('sends ordinary filters, facets and Start sort while keeping held rows and independent catalog updates', async () => {
    const zone = readerTimeZone()
    cleanups.push(zone.release)
    zone.choose('Europe/Warsaw')
    const h = await page()
    h.window()
    await nextTick()
    const search = h.wrapper.get('[data-id="hilos-table-search"]')
    expect(search.attributes('aria-label')).toBe(
      'Search by component or maintenance title…',
    )
    await search.setValue('missing')
    await h.wrapper
      .get(
        '[data-id="hilos-table-filter-componentKey"] [data-id="hilos-dropdown-toggle"]',
      )
      .trigger('click')
    await new DOMWrapper(document.body)
      .get('[data-id="hilos-dropdown-option-0"]')
      .trigger('click')
    expect(h.viewports.at(-1)).toEqual([
      HilosPages.STATUS_HISTORY,
      'hilosStatusHistory',
      expect.objectContaining({
        filter: { search: 'missing', componentKey: 'removed' },
      }),
    ])
    await h.wrapper.get('[data-id="hilos-table-filter-from"]').trigger('click')
    const beforePeriod = h.viewports.length
    await h.wrapper
      .get('[data-id="hilos-table-filter-from-from"]')
      .setValue('2031-03-09')
    expect(h.viewports).toHaveLength(beforePeriod + 1)
    await h.wrapper
      .get('[data-id="hilos-table-filter-from-to"]')
      .setValue('2031-03-09')
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
    await h.wrapper
      .get('[data-id="hilos-table-sort-startedAt"]')
      .trigger('click')
    expect(h.viewports.at(-1)).toEqual([
      HilosPages.STATUS_HISTORY,
      'hilosStatusHistory',
      expect.objectContaining({
        sort: [{ field: 'startedAt', direction: 'asc' }],
      }),
    ])
    expect(h.wrapper.get('table').text()).toContain('Saved service')
    h.delta({ kind: 'row_removed', rowKey: maintenance.id, reason: 'deleted' })
    h.delta({
      kind: 'row_updated',
      rowKey: incident.id,
      row: row({ ...incident, title: 'Saved live name' }),
    })
    h.data({ components: [{ componentKey: 'new', name: 'New facet' }] })
    await nextTick()
    expect(h.wrapper.get('table').text()).toContain('Saved live name')
    expect(h.wrapper.get('table').text()).toContain(
      'Maintenance: announcement unknown',
    )
    expect(h.wrapper.get('[data-id="hilos-table-pending"]').text()).toBe('1')
    await h.wrapper.get('[data-id="hilos-table-apply"]').trigger('click')
    expect(h.wrapper.get('table').text()).not.toContain(
      'Maintenance: announcement unknown',
    )
    expect(h.sent).toEqual([])
  })

  it('keeps unavailable, known empty and filtered empty distinct and unregisters on unmount', async () => {
    const h = await page()
    h.window([], null)
    await nextTick()
    expect(
      h.wrapper.get('[data-id="hilos-status-history-unavailable"]').text(),
    ).toBe('History data is unavailable.')
    expect(h.wrapper.find('table').exists()).toBe(false)
    h.window([])
    await nextTick()
    expect(h.wrapper.text()).toContain('No completed events yet.')
    await h.wrapper.get('[data-id="hilos-table-search"]').setValue('missing')
    h.window([], catalog, { search: 'missing' })
    await nextTick()
    expect(h.wrapper.text()).toContain('Nothing found')
    h.data({ components: 'malformed' })
    await nextTick()
    expect(h.wrapper.find('table').exists()).toBe(false)
    h.wrapper.unmount()
    expect(h.registered.size).toBe(0)
  })
})
