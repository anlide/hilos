import { nextTick } from 'vue'
import { afterEach, describe, expect, it } from 'vitest'
import { formatMoment, HILOS_VIEW_MODE_STRIP_TEXT_ID } from '@hilos/core/base'

import {
  announcement,
  body,
  worksData,
  workRow,
  worksPage,
  workSave,
} from '../../../test/statusWorksFixtures.js'
import { readerTimeZone } from '../../../test/helpers/readerTimeZone.js'

const cleanups: (() => void)[] = []
afterEach(() => {
  for (const cleanup of cleanups.splice(0).reverse()) cleanup()
})

describe('HilosStatusWorksPage with real normalized rows and viewport', () => {
  it('renders the complete catalog without mount requests, one shared heading, six columns and live reader-zone seconds', async () => {
    const zone = readerTimeZone()
    cleanups.push(zone.release)
    zone.choose('Asia/Tokyo')
    const h = await worksPage(cleanups)
    expect(h.sent).toEqual([])
    expect(h.viewports).toEqual([])
    expect(h.registered.size).toBe(1)
    h.window([
      workRow({
        title: '<b>Upgrade</b>',
        componentKeys: ['queue'],
        description: '<script>text</script>',
      }),
    ])
    await nextTick()
    expect(h.wrapper.findAll('h1').map((node) => node.text())).toEqual([
      'Status',
    ])
    expect(
      h.wrapper
        .get('nav[aria-label="Status pages"] [aria-current="page"]')
        .text(),
    ).toBe('Scheduled maintenance')
    const table = h.wrapper.get('table')
    expect(table.attributes('aria-labelledby')).toBe(
      h.wrapper.get('h1').attributes('id'),
    )
    expect(table.findAll('th').map((cell) => cell.text())).toEqual([
      'Starts',
      'Ends',
      'Title',
      'Components',
      'Last mail queued',
      '',
      'Will move Will leaveRow state and controls',
    ])
    expect(table.get('th[aria-sort="ascending"]').text()).toContain('Starts')
    expect(table.text()).toContain('Message queue')
    expect(table.text()).toContain('<b>Upgrade</b>')
    expect(table.find('script').exists()).toBe(false)
    expect(table.get('[data-id="hilos-status-work-mail-7"]').text()).toBe('—')
    expect(table.text()).toContain(
      formatMoment(announcement.startsAt, {
        format: {
          year: 'numeric',
          month: 'short',
          day: 'numeric',
          hour: '2-digit',
          minute: '2-digit',
          second: '2-digit',
          timeZoneName: 'short',
        },
      })!,
    )
    const before = table.text()
    zone.choose('America/New_York')
    await nextTick()
    expect(table.text()).not.toBe(before)
    expect(h.sent).toEqual([])
    expect(
      h.wrapper.find('[data-id="hilos-status-works-unavailable"]').exists(),
    ).toBe(false)
  })

  it('uses server search, component facets and six indexed orders without locally filtering the held rows', async () => {
    const h = await worksPage(cleanups)
    h.window()
    await nextTick()
    const search = h.wrapper.get('[data-id="hilos-table-search"]')
    expect(search.attributes('aria-label')).toBe(
      'Search by title or description…',
    )
    await search.setValue('no-match')
    expect(h.viewports.at(-1)).toEqual([
      'hilos_status_works',
      'hilosStatusWorks',
      expect.objectContaining({ filter: { search: 'no-match' } }),
    ])
    const filter = h.wrapper.get('[data-id="hilos-table-filter-componentKey"]')
    await filter.get('[data-id="hilos-dropdown-toggle"]').trigger('click')
    await body().get('[data-id="hilos-dropdown-option-1"]').trigger('click')
    expect(h.viewports.at(-1)).toEqual([
      'hilos_status_works',
      'hilosStatusWorks',
      expect.objectContaining({
        filter: { search: 'no-match', componentKey: 'queue' },
      }),
    ])
    expect(h.facets).toContainEqual([
      'hilos_status_works',
      'hilosStatusWorks',
      { componentKey: ['service', 'queue', 'database'] },
    ])
    expect(h.wrapper.get('table').text()).toContain('Database upgrade')
    for (const field of ['startsAt', 'endsAt', 'title']) {
      const button = h.wrapper.get(
        `table [data-id="hilos-table-sort-${field}"]`,
      )
      for (const direction of field === 'startsAt'
        ? ['desc', 'asc']
        : ['asc', 'desc']) {
        await button.trigger('click')
        expect(h.viewports.at(-1)).toEqual([
          'hilos_status_works',
          'hilosStatusWorks',
          expect.objectContaining({ sort: [{ field, direction }] }),
        ])
      }
    }
    h.window([], worksData, { search: 'no-match' })
    await nextTick()
    expect(h.wrapper.text()).toContain('Nothing found')
    await h.wrapper.get('[data-id="hilos-table-filter-reset"]').trigger('click')
    h.window([])
    await nextTick()
    expect(h.wrapper.text()).toContain('No maintenance is scheduled.')
    expect(
      h.wrapper.findAll('[data-id="hilos-table-empty-action"]'),
    ).toHaveLength(2)
  })

  it('applies row values live while holding membership for Apply, and never invents a mail queue mark', async () => {
    const h = await worksPage(cleanups)
    h.window([workRow(), workRow({ id: 8 })])
    h.delta({ kind: 'row_removed', rowKey: '8', reason: 'deleted' })
    h.update({
      title: 'Changed elsewhere',
      lastMailQueuedAt: 1_800_000_000_000,
    })
    h.data({ ...worksData!, upcomingCount: 0, nextWork: null })
    await nextTick()
    expect(
      h.wrapper.get('table [data-id="hilos-status-work-title-7"]').text(),
    ).toBe('Changed elsewhere')
    expect(
      h.wrapper.get('table [data-id="hilos-status-work-mail-7"]').text(),
    ).not.toBe('—')
    expect(
      h.wrapper.find('table [data-id="hilos-status-work-title-8"]').exists(),
    ).toBe(true)
    expect(h.wrapper.get('[data-id="hilos-table-pending"]').text()).toBe('1')
    await h.wrapper.get('[data-id="hilos-table-apply"]').trigger('click')
    expect(
      h.wrapper.find('table [data-id="hilos-status-work-title-8"]').exists(),
    ).toBe(false)
    expect(h.viewports).toEqual([])
    h.delta({ kind: 'row_removed', rowKey: '7', reason: 'deleted', own: true })
    await nextTick()
    expect(
      h.wrapper.find('table [data-id="hilos-status-work-title-7"]').exists(),
    ).toBe(false)
  })

  it('keeps unavailable distinct from known empty and serves readable forms to a viewer with mutations marked closed', async () => {
    const h = await worksPage(cleanups, true)
    h.window([], null)
    await nextTick()
    expect(
      h.wrapper.get('[data-id="hilos-status-works-unavailable"]').text(),
    ).toBe('Scheduled maintenance data is unavailable.')
    h.window()
    await nextTick()
    await h.open('edit')
    expect(workSave().attributes('disabled')).toBeDefined()
    expect(workSave().attributes('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )
    expect(
      body().get('[data-id="hilos-status-work-title"]').attributes('disabled'),
    ).toBeUndefined()
    await body().get('[data-id="hilos-status-work-close"]').trigger('click')
    expect(body().find('[data-id="modal"]').exists()).toBe(false)
    expect(h.sent).toEqual([])
  })
})
