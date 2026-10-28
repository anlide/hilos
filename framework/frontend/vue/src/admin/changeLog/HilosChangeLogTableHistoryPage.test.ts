import { describe, expect, it } from 'vitest'
import { nextTick } from 'vue'
import {
  HilosPages,
  bindAdminAccess,
  bindSessionScope,
  hilosAdminAccess,
} from '@hilos/core/base'
import {
  CHANGE_LOG_TABLE_COPY,
  HILOS_CHANGE_LOG_RECEIPT_READ,
  CHANGE_LOG_TABLE_SECTION,
} from '@hilos/core/admin/change-log'
import {
  HISTORY,
  historySlot,
  harness,
  router,
  mountPage,
  settle,
  element,
  releases,
} from '../../../test/helpers/changeLogTable.js'

describe('HilosChangeLogTableHistoryPage', () => {
  it('builds the history with the table preset and links unmasked records', async () => {
    const h = harness()
    const { wrapper, r } = await mountPage(h)
    expect(h.connection.registerTableWindow).toHaveBeenCalledWith(
      HISTORY,
      expect.anything(),
    )
    expect(wrapper.get('[data-id="hilos-table-subtitle"]').text()).toBe(
      'All records of this table',
    )
    expect(wrapper.find('[data-id="hilos-table-title"]').exists()).toBe(false)
    expect(
      wrapper.find('[data-id="hilos-change-log-record-filter"]').exists(),
    ).toBe(false)

    const row = wrapper.get('table [data-id="hilos-table-row-101"]')
    const link = row.get('[data-id="hilos-change-log-history-record-101"]')
    expect(link.text()).toBe('#57')
    expect(link.attributes('href')).toBe(
      '/hilos/change-log/tables/bot/history/57',
    )
    expect(r.value.resolvePath).toHaveBeenCalledWith(
      HilosPages.CHANGE_LOG_TABLE_HISTORY,
      { tableId: 'bot', record: '57' },
    )
    await link.trigger('click')
    expect(r.value.navigate).toHaveBeenCalledWith(
      '/hilos/change-log/tables/bot/history/57',
    )
    expect(row.text()).toContain('updated')
    expect(row.text()).toContain('description: before → after')
    expect(row.text()).toContain('Ada')
    expect(row.text()).toContain('Web')
    expect(
      row.get('[data-id="hilos-change-log-history-receipt-101"]').text(),
    ).toBe('Receipt')

    const masked = wrapper.get('table [data-id="hilos-table-row-102"]')
    expect(
      masked.find('[data-id="hilos-change-log-history-record-102"]').exists(),
    ).toBe(false)
    expect(masked.find('a').exists()).toBe(false)
    expect(masked.text()).toContain('#?')
    expect(masked.text()).toContain('deleted')
    expect(masked.text()).toContain('Row snapshot')
    expect(masked.text()).toContain('Unknown')
    expect(masked.text()).toContain(CHANGE_LOG_TABLE_COPY.pastApplication)
    expect(masked.text()).toContain(CHANGE_LOG_TABLE_COPY.noReceipt)
    expect(
      masked.find('[data-id="hilos-change-log-history-receipt-102"]').exists(),
    ).toBe(false)
  })

  it('narrows to the address record behind a plate whose × leaves the record', async () => {
    const h = harness()
    const { wrapper, r } = await mountPage(
      h,
      router({ tableId: 'bot', record: '57' }),
      false,
    )
    h.pushPageWindow()
    expect(h.connection.sendTableViewport).toHaveBeenCalledWith(
      HilosPages.CHANGE_LOG_TABLE_HISTORY,
      HISTORY,
      expect.objectContaining({
        filter: expect.objectContaining({ table: 'bot', record: ['57'] }),
      }),
    )
    const plate = wrapper.get('[data-id="hilos-change-log-record-filter"]')
    expect(plate.text()).toContain('Only record #57')
    expect(wrapper.text()).not.toContain('All records of this table')
    const clear = plate.get('[data-id="hilos-change-log-record-filter-clear"]')
    expect(clear.attributes('href')).toBe(
      '/hilos/change-log/tables/bot/history',
    )
    expect(clear.attributes('aria-label')).toBe('Show all records')
    expect(clear.get('i').attributes('aria-hidden')).toBe('true')
    await clear.trigger('click')
    expect(r.value.navigate).toHaveBeenCalledWith(
      '/hilos/change-log/tables/bot/history',
    )
  })

  it('rebuilds the history window when the address gains a record', async () => {
    const h = harness()
    const { wrapper, r } = await mountPage(h)
    expect(h.connection.registerTableWindow).toHaveBeenCalledTimes(1)
    r.go({ tableId: 'bot', record: '57' })
    await settle()
    expect(h.connection.unregisterTableWindow).toHaveBeenCalledWith(
      HISTORY,
      h.connection.registerTableWindow.mock.calls[0]?.[1],
    )
    expect(h.connection.registerTableWindow).toHaveBeenCalledTimes(2)
    h.pushPageWindow()
    expect(h.connection.sendTableViewport).toHaveBeenCalledTimes(1)
    expect(h.connection.sendTableViewport).toHaveBeenLastCalledWith(
      HilosPages.CHANGE_LOG_TABLE_HISTORY,
      HISTORY,
      expect.objectContaining({
        filter: expect.objectContaining({ table: 'bot', record: ['57'] }),
      }),
    )
    expect(
      wrapper.get('[data-id="hilos-change-log-record-filter"]').text(),
    ).toContain('Only record #57')
  })

  it('opens the row receipt through the Change Log reading action and closes it on an address change', async () => {
    const h = harness()
    const { r } = await mountPage(h)
    element(
      'hilos-change-log-history-receipt-101',
      document.querySelector('[data-id="hilos-viewport-table"] table')!,
    ).click()
    await settle()
    expect(h.source.sent[0]).toMatchObject({
      action: HILOS_CHANGE_LOG_RECEIPT_READ,
      data: { receiptId: 7, afterEntryId: null },
    })
    expect(element('modal').getAttribute('aria-label')).toBe('Receipt #7')
    r.go({ tableId: 'bot', record: '57' })
    await settle()
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
  })

  it.each(['full', 'view'] as const)(
    'shows the same table under %s access and hides people from a viewer',
    async (access) => {
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
      const { wrapper } = await mountPage(h, router({ tableId: 'bot' }), false)
      h.pushWindow([
        {
          rowKey: '101',
          slots: {
            history: historySlot(
              access === 'view' ? { actorLabel: { _hidden: true } } : {},
            ),
          },
        },
      ])
      await nextTick()
      const row = wrapper.get('table [data-id="hilos-table-row-101"]')
      if (access === 'view') {
        expect(row.text().replace(/\s+/g, ' ')).toContain('Hidden (#3)')
        expect(row.text()).not.toContain('Ada')
      } else {
        expect(row.text()).toContain('Ada')
      }
      expect(
        row.get('[data-id="hilos-change-log-history-receipt-101"]').text(),
      ).toBe('Receipt')
    },
  )

  it('releases the history window and every connection binding on unmount', async () => {
    const h = harness()
    const { wrapper } = await mountPage(h)
    expect([...h.listeners.values()].some((set) => set.size > 0)).toBe(true)
    wrapper.unmount()
    expect(h.connection.unregisterTableWindow).toHaveBeenCalledWith(
      HISTORY,
      h.connection.registerTableWindow.mock.calls[0]?.[1],
    )
    expect([...h.listeners.values()].every((set) => set.size === 0)).toBe(true)
  })
  it.each(['identity', 'scope'] as const)(
    'closes a pending receipt on a new %s before the new page can render it',
    async (change) => {
      const h = harness()
      const { wrapper } = await mountPage(h)
      await wrapper
        .get('table [data-id="hilos-change-log-history-receipt-101"]')
        .trigger('click')
      await settle()
      expect(element('modal').getAttribute('aria-label')).toBe('Receipt #7')
      if (change === 'identity') {
        h.context.scopes.page()!.data.set('pageLabel', 'Changed identity')
      } else {
        const section = h.context.scopes
          .pageDataSignal(CHANGE_LOG_TABLE_SECTION)
          .get()
        const scope = h.context.scopes.openPage(
          HilosPages.CHANGE_LOG_TABLE_HISTORY,
        )
        scope.data.set(CHANGE_LOG_TABLE_SECTION, section)
      }
      await settle()
      expect(document.querySelector('[data-id="modal"]')).toBeNull()
      h.source.success(0)
      await settle()
      expect(document.querySelector('[data-id="modal"]')).toBeNull()
    },
  )
})
