import { describe, expect, it } from 'vitest'
import {
  bindAdminAccess,
  bindSessionScope,
  hilosAdminAccess,
  HilosPages,
  resolvePageTitle,
} from '@hilos/core/base'
import HilosChangeLogTablePage from './HilosChangeLogTablePage.vue'
import HilosChangeLogTableColumnsPage from './HilosChangeLogTableColumnsPage.vue'
import HilosChangeLogTableTriggersPage from './HilosChangeLogTableTriggersPage.vue'
import HilosChangeLogTableHistoryPage from './HilosChangeLogTableHistoryPage.vue'
import { nextTick } from 'vue'
import { CHANGE_LOG_TABLE_SECTION } from '@hilos/core/admin/change-log'
import {
  BOT,
  harness,
  router,
  mountPage,
  mountOverview,
  releases,
  answerIdentity,
} from '../../../test/helpers/changeLogTable.js'

describe('HilosChangeLogTableShell', () => {
  it.each([
    ['57', '/hilos/change-log/tables/bot/history/57'],
    [
      'part,with%2Ccomma',
      '/hilos/change-log/tables/bot/history/part%2Cwith%252Ccomma',
    ],
  ])(
    'retains record %s only in the History tab address',
    async (record, historyHref) => {
      const { wrapper } = await mountPage(
        harness(),
        router({ tableId: 'bot', record }),
        false,
      )
      expect(
        wrapper
          .get('[data-id="hilos-page-tabs"]')
          .findAll('a')
          .map((link) => link.attributes('href')),
      ).toEqual([
        '/hilos/change-log/tables/bot',
        '/hilos/change-log/tables/bot/columns',
        '/hilos/change-log/tables/bot/triggers',
        historyHref,
      ])
      expect(
        wrapper
          .get('[data-id="hilos-change-log-record-filter-clear"]')
          .attributes('href'),
      ).toBe('/hilos/change-log/tables/bot/history')
    },
  )

  it.each([
    HilosChangeLogTablePage,
    HilosChangeLogTableColumnsPage,
    HilosChangeLogTableTriggersPage,
    HilosChangeLogTableHistoryPage,
  ])(
    'draws the same entity head, native links and live identity on %s',
    async (component) => {
      const h = harness()
      const { wrapper, r } = await mountPage(
        h,
        router({ tableId: 'bot' }),
        false,
        component,
      )
      expect(wrapper.findAll('h1')).toHaveLength(1)
      expect(wrapper.get('h1').text()).toBe('bot')
      expect(wrapper.get('h1').attributes('id')).toBeTruthy()
      const identity = r.value.pageIdentity.get()
      expect(identity?.breadcrumb.map(({ label }) => label)).toEqual([
        'Hilos',
        'Change Log',
        'Tracked tables',
        'bot',
      ])
      expect(
        resolvePageTitle(
          r.value.currentRoute.get().page,
          {},
          'Test app',
          identity?.label,
          true,
          identity?.pageHeadOwner?.label,
        ),
      ).toBe('bot · Test app')
      const tabs = wrapper.get('[data-id="hilos-page-tabs"]')
      expect(tabs.attributes('aria-label')).toBe('Table pages')
      expect(tabs.findAll('a').map((a) => a.text())).toEqual([
        'Overview',
        'Columns',
        'Triggers',
        'History',
      ])
      expect(tabs.findAll('[aria-current="page"]')).toHaveLength(1)
      expect(tabs.get('[aria-current="page"]').attributes('data-id')).toBe(
        `hilos-page-tab-${r.value.currentRoute.get().page}`,
      )
      expect(tabs.get('ul').classes()).toContain('overflow-x-auto')
      expect(tabs.get('ul').classes()).toContain('flex-nowrap')
      expect(wrapper.find('[role="tablist"]').exists()).toBe(false)
      expect(wrapper.find('[data-id="hilos-admin-children"]').exists()).toBe(
        false,
      )
      expect(wrapper.find('[data-id="hilos-admin-title"]').exists()).toBe(false)
      expect(
        wrapper.find('[data-id="hilos-change-log-table-header"]').exists(),
      ).toBe(false)
      expect(
        wrapper
          .get('[data-id="hilos-change-log-table-head-icon"]')
          .attributes('aria-hidden'),
      ).toBe('true')
      expect(h.source.sent).toEqual([])
      if (component === HilosChangeLogTableHistoryPage) {
        expect(wrapper.get('table').attributes('aria-labelledby')).toBe(
          wrapper.get('h1').attributes('id'),
        )
      }
    },
  )

  it('discards the name and block on a new table address, scope or unrelated page and disposes the name binding', async () => {
    const h = harness()
    const { wrapper, r } = await mountOverview(h)
    r.route.set({
      page: HilosPages.CHANGE_LOG_TABLE,
      params: { tableId: 'other' },
      admin: true,
    })
    await nextTick()
    expect(wrapper.text()).not.toContain('bot')
    expect(
      wrapper.find('[data-id="hilos-change-log-table-head-badges"]').exists(),
    ).toBe(false)
    r.route.set({
      page: HilosPages.CHANGE_LOG_TABLE,
      params: { tableId: 'other' },
      admin: true,
    })
    const scope = h.context.scopes.openPage(HilosPages.CHANGE_LOG_TABLE)
    await nextTick()
    expect(
      wrapper
        .get('[data-id="hilos-page-head-skeleton"]')
        .attributes('aria-hidden'),
    ).toBe('true')
    expect(wrapper.find('h1').exists()).toBe(false)
    expect(wrapper.find('[data-id="hilos-page-tabs"]').exists()).toBe(false)
    scope.data.set(CHANGE_LOG_TABLE_SECTION, {
      ...BOT,
      name: 'other',
      triggers: [
        { ...BOT.triggers[0], validFromMigration: 92 },
        { ...BOT.triggers[1], validFromMigration: 7 },
      ],
    })
    answerIdentity(scope)
    await nextTick()
    expect(wrapper.get('h1').text()).toBe('other')
    expect(
      wrapper.get('[data-id="hilos-change-log-table-head-description"]').text(),
    ).toBe('Journaled since migration 007')
    expect(r.value.pageIdentity.get()?.pageHeadOwner?.label).toBe('other')
    wrapper.unmount()
    expect(r.value.pageIdentity.get()?.pageHeadOwner?.label).toBe(
      'Tracked table',
    )
    const unrelated = h.context.scopes.openPage(HilosPages.CHANGE_LOG)
    unrelated.data.set(CHANGE_LOG_TABLE_SECTION, BOT)
    expect(r.value.pageIdentity.get()).toBeUndefined()
  })

  it('does not invent migration data or a link to an unbuilt page', async () => {
    const h = harness({ ...BOT, triggers: [] })
    const { wrapper } = await mountOverview(
      h,
      router({ tableId: 'bot' }, false),
    )
    expect(
      wrapper.get('[data-id="hilos-change-log-table-head-description"]').text(),
    ).toBe('')
    expect(
      wrapper.findAll('[data-id^="hilos-change-log-table-link-"]'),
    ).toHaveLength(0)
    expect(h.connection.registerTableWindow).not.toHaveBeenCalled()
  })

  it.each([
    ['full', 'Overview'],
    ['view', 'Overview'],
    ['full', 'Columns'],
    ['view', 'Columns'],
    ['full', 'Triggers'],
    ['view', 'Triggers'],
  ] as const)(
    'keeps %s metadata visible on %s without history bindings',
    async (access, part) => {
      const h = harness()
      bindSessionScope(h.context.connection, h.context.scopes)
      releases.push(bindAdminAccess(h.context.scopes))
      h.emit('projectSignal', {
        type: 'handshake_response',
        data: {
          entities: {
            currentUser: { id: 9, name: 'Ada', admin: access === 'full' },
          },
          data: { adminViewMode: true },
        },
      })
      expect(hilosAdminAccess.get()).toBe(access)
      const component =
        part === 'Overview'
          ? HilosChangeLogTablePage
          : part === 'Columns'
            ? HilosChangeLogTableColumnsPage
            : HilosChangeLogTableTriggersPage
      const { wrapper } = await mountPage(
        h,
        router({ tableId: 'bot' }),
        false,
        component,
      )
      if (part === 'Columns') {
        h.pushMetadataWindow(
          HilosPages.CHANGE_LOG_TABLE_COLUMNS,
          'hilosChangeLogTableColumns',
          BOT.columns.map((column) => ({
            rowKey: column.name,
            slots: { column },
          })),
        )
      } else if (part === 'Triggers') {
        h.pushMetadataWindow(
          HilosPages.CHANGE_LOG_TABLE_TRIGGERS,
          'hilosChangeLogTableTriggers',
          BOT.triggers.map((trigger) => ({
            rowKey: trigger.name,
            slots: {
              trigger: { ...trigger, owner: 'service', writesTo: 'journal' },
            },
          })),
        )
      }
      await nextTick()
      if (part === 'Overview')
        expect(
          wrapper.get('[data-id="hilos-page-head-title"]').text(),
        ).toContain('bot')
      else {
        const section = wrapper.get(
          `[data-id="hilos-change-log-table-${part.toLowerCase()}"]`,
        )
        expect(section.findAll('tbody tr')).toHaveLength(
          part === 'Columns' ? 5 : 3,
        )
        expect(section.text()).toContain(
          part === 'Columns'
            ? 'Personal — only a fact'
            : 'hilos_cl_bot_after_insert',
        )
      }
      expect(
        document.querySelectorAll('[data-id="hilos-viewport-table"]'),
      ).toHaveLength(part === 'Overview' ? 0 : 1)
      expect(document.querySelector('[data-id="modal"]')).toBeNull()
      if (part === 'Overview') {
        expect(h.connection.registerTableWindow).not.toHaveBeenCalled()
      } else {
        expect(
          h.connection.registerTableWindow,
        ).toHaveBeenCalledExactlyOnceWith(
          part === 'Columns'
            ? 'hilosChangeLogTableColumns'
            : 'hilosChangeLogTableTriggers',
          expect.anything(),
        )
      }
      expect(h.source.sent).toEqual([])
    },
  )
})
