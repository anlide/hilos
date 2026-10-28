import { describe, expect, it } from 'vitest'
import {
  CHANGE_LOG_TABLE_COPY,
  CHANGE_LOG_TABLE_SECTION,
} from '@hilos/core/admin/change-log'
import {
  HilosPages,
  bindAdminAccess,
  bindSessionScope,
  hilosAdminAccess,
} from '@hilos/core/base'
import {
  BOT,
  SESSION,
  harness,
  mountTriggers,
  router,
  settle,
  answerIdentity,
  releases,
} from '../../../test/helpers/changeLogTable.js'

const PAGE = HilosPages.CHANGE_LOG_TABLE_TRIGGERS
const TABLE = 'hilosChangeLogTableTriggers'
const rows = BOT.triggers.map((trigger) => ({
  rowKey: trigger.name,
  slots: { trigger: { ...trigger, owner: 'service', writesTo: 'journal' } },
}))

describe('HilosChangeLogTableTriggersPage', () => {
  it('contains a cold page-window refusal and recovers from the server page answer', async () => {
    const h = harness()
    const { wrapper } = await mountTriggers(h)
    h.emit('projectSignal', {
      type: 'page_response',
      data: {
        page: PAGE,
        payload: {
          data: { [CHANGE_LOG_TABLE_SECTION]: BOT },
          refusedWindows: { [TABLE]: { errorCode: 'internal_error' } },
        },
      },
    })
    await settle()
    expect(wrapper.get('h1').text()).toBe('bot')
    expect(wrapper.get('[data-id="hilos-table-unavailable"]').text()).toContain(
      'List unavailable',
    )
    expect(wrapper.find('[data-id^="hilos-table-row-"]').exists()).toBe(false)
    expect(h.connection.sendTableViewport).not.toHaveBeenCalled()
    h.pushMetadataWindow(PAGE, TABLE, rows, true)
    await settle()
    expect(wrapper.find('[data-id="hilos-table-unavailable"]').exists()).toBe(
      false,
    )
    expect(wrapper.get('[data-id="hilos-table-count"]').text()).toBe(
      `1 – ${rows.length} of ${rows.length}`,
    )
    expect(h.connection.sendTableViewport).not.toHaveBeenCalled()
    expect(
      h.connection.registerTableWindow.mock.calls[0]?.[1].descriptor(),
    ).toEqual(expect.objectContaining({ filter: { table: 'bot' }, limit: 25 }))
  })

  it('draws service triggers in event order with the standard footer, cards and shared heading', async () => {
    const h = harness()
    const { wrapper } = await mountTriggers(h)
    h.pushMetadataWindow(PAGE, TABLE, rows)
    await settle()
    const triggers = wrapper.get('[data-id="hilos-change-log-table-triggers"]')
    expect(wrapper.findAll('h1')).toHaveLength(1)
    expect(wrapper.get('h1').text()).toBe('bot')
    expect(triggers.find('h2').exists()).toBe(false)
    expect(triggers.findAll('[data-id="hilos-viewport-table"]')).toHaveLength(1)
    expect(triggers.find('[data-id="hilos-table-title"]').exists()).toBe(false)
    const table = triggers.get('table')
    expect(table.attributes('aria-labelledby')).toBe(
      wrapper.get('h1').attributes('id'),
    )
    expect(table.findAll('th').map((th) => th.text())).toEqual([
      'Trigger',
      'Owner',
      'Fires',
      'Writes to',
      'In effect',
      'Will move Will leaveRow state and controls',
    ])
    expect(
      table.findAll('tbody tr').map((tr) =>
        tr
          .findAll('td')
          .slice(0, 5)
          .map((td) => td.text()),
      ),
    ).toEqual([
      [
        'hilos_cl_bot_after_insert',
        'Service',
        'AFTER INSERT on bot',
        'Journal',
        'from migration 071',
      ],
      [
        'hilos_cl_bot_after_update',
        'Service',
        'AFTER UPDATE on bot',
        'Journal',
        'from migration 071',
      ],
      [
        'hilos_cl_bot_after_delete',
        'Service',
        'AFTER DELETE on bot',
        'Journal',
        'from migration 071',
      ],
    ])
    expect(
      table
        .findAll('code')
        .every((code) => code.classes().includes('text-break')),
    ).toBe(true)
    const cards = triggers.get('[data-id="hilos-table-cards"] [role="list"]')
    expect(cards.attributes('role')).toBe('list')
    expect(cards.attributes('aria-labelledby')).toBe(
      wrapper.get('h1').attributes('id'),
    )
    expect(cards.findAll('[role="listitem"]')).toHaveLength(3)
    expect(
      cards
        .get('[data-id="hilos-table-card-hilos_cl_bot_after_update"]')
        .text(),
    ).toContain('AFTER UPDATE on bot')
    expect(triggers.get('[data-id="hilos-table-count"]').text()).toBe(
      '1 – 3 of 3',
    )
    const paragraphs = triggers.findAll('p')
    expect(paragraphs[0]!.text()).toBe(CHANGE_LOG_TABLE_COPY.triggersLead)
    expect(paragraphs.at(-1)!.text()).toBe(
      CHANGE_LOG_TABLE_COPY.triggersFootnote,
    )
    expect(triggers.element.firstElementChild?.tagName).toBe('P')
    expect(triggers.element.lastElementChild?.tagName).toBe('P')
    expect(triggers.find('input').exists()).toBe(false)
    expect(triggers.find('[data-id="hilos-table-order"]').exists()).toBe(false)
    expect(table.find('th button').exists()).toBe(false)
  })

  it('requests only its table preset and draws a trigger from the reply slot rather than the snapshot', async () => {
    const h = harness()
    const { wrapper } = await mountTriggers(h)
    expect(h.connection.registerTableWindow).toHaveBeenCalledWith(
      TABLE,
      expect.anything(),
    )
    h.pushMetadataWindow(PAGE, TABLE, rows, true)
    expect(h.connection.sendTableViewport).toHaveBeenCalledTimes(1)
    expect(h.connection.sendTableViewport).toHaveBeenLastCalledWith(
      PAGE,
      TABLE,
      expect.objectContaining({
        filter: { table: 'bot' },
        sort: null,
        limit: 25,
        anchor: null,
        anchorDirection: 'after',
      }),
    )
    await settle()
    expect(
      wrapper
        .find('[data-id="hilos-table-row-hilos_cl_bot_after_insert"]')
        .exists(),
    ).toBe(false)
    h.pushMetadataWindow(HilosPages.CHANGE_LOG_TABLE_COLUMNS, TABLE, rows)
    h.pushMetadataWindow(PAGE, 'hilosChangeLogTableColumns', rows)
    await settle()
    expect(
      wrapper
        .find('[data-id="hilos-table-row-hilos_cl_bot_after_insert"]')
        .exists(),
    ).toBe(false)
    const descriptor = h.connection.sendTableViewport.mock.calls[0]![2]
    expect(descriptor.requestId).toEqual(expect.any(String))
    h.pushMetadataWindow(
      PAGE,
      TABLE,
      [
        {
          rowKey: 'envelope',
          slots: {
            trigger: {
              name: 'server_trigger',
              owner: 'service',
              event: 'DELETE',
              table: 'server_table',
              writesTo: 'journal',
              validFromMigration: 109,
            },
          },
        },
      ],
      false,
      { requestId: descriptor.requestId },
    )
    await settle()
    expect(
      wrapper
        .get('table [data-id="hilos-table-row-envelope"]')
        .findAll('td')
        .slice(0, 5)
        .map((td) => td.text()),
    ).toEqual([
      'server_trigger',
      'Service',
      'AFTER DELETE on server_table',
      'Journal',
      'from migration 109',
    ])
    expect(wrapper.text()).not.toContain('hilos_cl_bot_after_insert')
    expect(wrapper.get('[data-id="hilos-table-count"]').text()).toBe(
      '1 – 1 of 1',
    )
  })

  it('uses an empty standard viewport on an untracked table without inventing service rows', async () => {
    const h = harness(SESSION)
    const { wrapper } = await mountTriggers(
      h,
      router({ tableId: 'hilos_session' }),
    )
    h.pushMetadataWindow(PAGE, TABLE, [], true)
    expect(h.connection.sendTableViewport).toHaveBeenLastCalledWith(
      PAGE,
      TABLE,
      expect.objectContaining({ filter: { table: 'hilos_session' } }),
    )
    h.pushMetadataWindow(PAGE, TABLE, [])
    await settle()
    expect(wrapper.get('h1').text()).toBe('hilos_session')
    expect(wrapper.findAll('[data-id="hilos-viewport-table"]')).toHaveLength(1)
    expect(
      wrapper.get('[data-id="hilos-table-cards"]').findAll('[role="listitem"]'),
    ).toHaveLength(0)
    expect(
      wrapper.find('table tbody tr[data-id^="hilos-table-row-"]').exists(),
    ).toBe(false)
    expect(wrapper.find('[data-id="hilos-table-count"]').exists()).toBe(false)
    expect(wrapper.findAll('[data-id="hilos-table-empty"]')).toHaveLength(2)
    expect(wrapper.text()).not.toContain('hilos_cl_')
    expect(wrapper.text()).toContain(CHANGE_LOG_TABLE_COPY.triggersLead)
    expect(wrapper.text()).toContain(CHANGE_LOG_TABLE_COPY.triggersFootnote)
    expect(
      wrapper
        .get('[data-id="hilos-page-tabs"]')
        .findAll('a')
        .map((link) => link.text()),
    ).toEqual(['Overview', 'Triggers'])
  })

  it('disposes stale addresses and same-address scopes and sends nothing after unmount', async () => {
    const h = harness()
    const { wrapper, r } = await mountTriggers(h)
    h.pushMetadataWindow(PAGE, TABLE, rows)
    await settle()
    const first = h.connection.registerTableWindow.mock.calls[0]?.[1]
    r.route.set({ page: PAGE, params: { tableId: 'other' }, admin: true })
    await settle()
    expect(h.connection.unregisterTableWindow).toHaveBeenCalledWith(
      TABLE,
      first,
    )
    expect(wrapper.find('[data-id="hilos-viewport-table"]').exists()).toBe(
      false,
    )
    expect(wrapper.text()).not.toContain('hilos_cl_bot_after_insert')
    expect(wrapper.get('h1').text()).not.toBe('bot')
    const replacement = h.context.scopes.openPage(PAGE)
    answerIdentity(replacement)
    replacement.data.set(CHANGE_LOG_TABLE_SECTION, { ...BOT, name: 'other' })
    await settle()
    expect(h.connection.registerTableWindow).toHaveBeenCalledTimes(2)
    h.pushMetadataWindow(PAGE, TABLE, [], true)
    expect(h.connection.sendTableViewport).toHaveBeenLastCalledWith(
      PAGE,
      TABLE,
      expect.objectContaining({ filter: { table: 'other' } }),
    )
    h.pushMetadataWindow(PAGE, TABLE, [rows[1]!])
    await settle()
    const second = h.connection.registerTableWindow.mock.calls[1]?.[1]
    const scope = h.context.scopes.openPage(PAGE)
    answerIdentity(scope)
    scope.data.set(CHANGE_LOG_TABLE_SECTION, { ...BOT, name: 'other' })
    await settle()
    expect(h.connection.unregisterTableWindow).toHaveBeenCalledWith(
      TABLE,
      second,
    )
    expect(h.connection.registerTableWindow).toHaveBeenCalledTimes(3)
    expect(
      wrapper
        .find('[data-id="hilos-table-row-hilos_cl_bot_after_update"]')
        .exists(),
    ).toBe(false)
    wrapper.unmount()
    expect(h.connection.unregisterTableWindow).toHaveBeenCalledTimes(3)
    const viewportSends = h.connection.sendTableViewport.mock.calls.length
    const renderedSends = h.connection.sendTableRendered.mock.calls.length
    h.pushMetadataWindow(PAGE, TABLE, rows, true)
    h.pushMetadataWindow(PAGE, TABLE, rows)
    h.context.scopes.openPage(PAGE)
    await settle()
    expect(h.connection.sendTableViewport).toHaveBeenCalledTimes(viewportSends)
    expect(h.connection.sendTableRendered).toHaveBeenCalledTimes(renderedSends)
    expect(
      [...h.listeners.values()].every((listeners) => listeners.size === 0),
    ).toBe(true)
  })

  it.each([null, { ...BOT, name: 'stale' }, { name: 'bot' }])(
    'waits for a valid declaration for its page %s',
    async (section) => {
      const h = harness(section)
      const { wrapper } = await mountTriggers(h)
      expect(
        wrapper.find('[data-id="hilos-change-log-table-triggers"]').exists(),
      ).toBe(false)
      expect(h.connection.registerTableWindow).not.toHaveBeenCalled()
    },
  )

  it('keeps service metadata available in admin view mode without mutations', async () => {
    const h = harness()
    bindSessionScope(h.context.connection, h.context.scopes)
    releases.push(bindAdminAccess(h.context.scopes))
    h.emit('projectSignal', {
      type: 'handshake_response',
      data: {
        entities: { currentUser: { id: 9, name: 'Ada', admin: false } },
        data: { adminViewMode: true },
      },
    })
    expect(hilosAdminAccess.get()).toBe('view')
    const { wrapper } = await mountTriggers(h)
    h.pushMetadataWindow(PAGE, TABLE, rows)
    await settle()
    expect(wrapper.get('h1').text()).toBe('bot')
    expect(
      wrapper
        .get('table [data-id="hilos-table-row-hilos_cl_bot_after_insert"]')
        .text(),
    ).toContain('Service')
    expect(wrapper.find('[data-id="hilos-table-main-action"]').exists()).toBe(
      false,
    )
    expect(h.source.sent).toEqual([])
  })
})
