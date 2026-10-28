import { describe, expect, it } from 'vitest'
import { nextTick } from 'vue'
import { HilosPages } from '@hilos/core/base'
import { CHANGE_LOG_TABLE_COPY } from '@hilos/core/admin/change-log'
import {
  BOT,
  SESSION,
  harness,
  router,
  mountOverview,
} from '../../../test/helpers/changeLogTable.js'

describe('HilosChangeLogTablePage', () => {
  it('draws Overview without any history bindings', async () => {
    const { wrapper } = await mountOverview()
    expect(wrapper.findAll('h1')).toHaveLength(1)
    expect(wrapper.get('h1').text()).toBe('bot')
    expect(wrapper.get('h1').classes()).toContain('font-monospace')
    expect(wrapper.get('h1').classes()).toContain('fs-5')
    expect(
      wrapper.get('[data-id="hilos-change-log-table-head-badges"]').text(),
    ).toBe('Project Journaled')
    expect(
      wrapper.get('[data-id="hilos-change-log-table-head-description"]').text(),
    ).toBe('Journaled since migration 071')
    const tiles = ['history', 'columns', 'triggers'].map((part) =>
      wrapper.get(`[data-id="hilos-change-log-table-link-${part}"]`),
    )
    expect(tiles.map((tile) => tile.attributes('href'))).toEqual([
      '/hilos/change-log/tables/bot/history',
      '/hilos/change-log/tables/bot/columns',
      '/hilos/change-log/tables/bot/triggers',
    ])
    expect(tiles.map((tile) => tile.get('h2').text())).toEqual([
      'Changes in 24 hours',
      'Columns',
      'Triggers',
    ])
    expect(tiles.map((tile) => tile.get('.fs-4').text())).toEqual([
      '3',
      '5',
      '3',
    ])
    expect(tiles.map((tile) => tile.get('div.small').text())).toEqual([
      'Last change: —',
      'with value 2 · as fact 1 · noisy 1',
      'Service 3',
    ])
    expect(
      wrapper.find('[data-id="hilos-change-log-table-header"]').exists(),
    ).toBe(false)

    expect(
      document.querySelector('[data-id="hilos-viewport-table"]'),
    ).toBeNull()
    expect(
      document.querySelector('[data-id="hilos-change-log-table-columns"]'),
    ).toBeNull()
    expect(
      document.querySelector('[data-id="hilos-change-log-table-triggers"]'),
    ).toBeNull()
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
  })

  it('draws an untracked table with two tabs and only the triggers tile', async () => {
    const h = harness(SESSION)
    const { wrapper } = await mountOverview(
      h,
      router({ tableId: 'hilos_session' }),
    )
    expect(wrapper.get('h1').text()).toBe('hilos_session')
    expect(
      wrapper.get('[data-id="hilos-change-log-table-head-badges"]').text(),
    ).toBe('Framework')
    expect(wrapper.text()).toContain(CHANGE_LOG_TABLE_COPY.untracked)
    expect(
      wrapper
        .findAll('[data-id^="hilos-change-log-table-link-"]')
        .map((link) => link.attributes('href')),
    ).toEqual(['/hilos/change-log/tables/hilos_session/triggers'])
    expect(
      wrapper
        .findAll('[data-id^="hilos-page-tab-"]')
        .map((link) => link.text()),
    ).toEqual(['Overview', 'Triggers'])
    expect(h.connection.registerTableWindow).not.toHaveBeenCalled()
  })

  it('draws no entity content when the section is damaged', async () => {
    const h = harness({ ...BOT, owner: 'nobody' })
    const { wrapper } = await mountOverview(h)
    expect(
      wrapper.find('[data-id="hilos-change-log-table-header"]').exists(),
    ).toBe(false)
    expect(wrapper.find('[data-id="hilos-viewport-table"]').exists()).toBe(
      false,
    )
    expect(
      wrapper.find('[data-id="hilos-change-log-table-head-badges"]').exists(),
    ).toBe(false)
    expect(h.connection.registerTableWindow).not.toHaveBeenCalled()
  })
  it('keeps the total column count, combines facts and omits zero modes', async () => {
    const h = harness({
      ...BOT,
      changesLast24h: 0,
      columns: [
        BOT.columns[0],
        BOT.columns[3],
        { ...BOT.columns[3], name: 'photo', mode: 'binary' },
        { ...BOT.columns[3], name: 'token', mode: 'secret' },
      ],
    })
    const { wrapper } = await mountOverview(h)
    const columns = wrapper.get(
      '[data-id="hilos-change-log-table-link-columns"]',
    )
    expect(columns.get('.fs-4').text()).toBe('4')
    expect(columns.get('div.small').text()).toBe('as fact 2 · secret 1')
    expect(
      wrapper
        .get('[data-id="hilos-change-log-table-link-history"] .fs-4')
        .text(),
    ).toBe('0')
    expect(h.source.sent).toEqual([])
    expect(h.connection.registerTableWindow).not.toHaveBeenCalled()
    expect(h.connection.sendTableViewport).not.toHaveBeenCalled()
  })

  it('uses the eligible server tabs for tiles even when a route is built', async () => {
    const h = harness()
    const { wrapper } = await mountOverview(h)
    h.context.scopes.page()!.data.set('pageTabs', [
      { page: HilosPages.CHANGE_LOG_TABLE, label: 'Overview' },
      { page: HilosPages.CHANGE_LOG_TABLE_TRIGGERS, label: 'Triggers' },
    ])
    await nextTick()
    expect(
      wrapper
        .findAll('[data-id^="hilos-change-log-table-link-"]')
        .map((link) => link.attributes('data-id')),
    ).toEqual(['hilos-change-log-table-link-triggers'])
  })
})
