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
  harness,
  mountColumns,
  router,
  settle,
  answerIdentity,
  releases,
} from '../../../test/helpers/changeLogTable.js'

const PAGE = HilosPages.CHANGE_LOG_TABLE_COLUMNS
const TABLE = 'hilosChangeLogTableColumns'
const rows = BOT.columns.map((column) => ({
  rowKey: column.name,
  slots: { column },
}))

describe('HilosChangeLogTableColumnsPage', () => {
  it('contains a cold page-window refusal and recovers from the server page answer', async () => {
    const h = harness()
    const { wrapper } = await mountColumns(h)
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

  it('draws schema windows, modes, footer and standard cards named by the shared h1', async () => {
    const h = harness()
    const { wrapper } = await mountColumns(h)
    h.pushMetadataWindow(PAGE, TABLE, rows)
    await settle()
    const columns = wrapper.get('[data-id="hilos-change-log-table-columns"]')
    expect(wrapper.findAll('h1')).toHaveLength(1)
    expect(wrapper.get('h1').text()).toBe('bot')
    expect(columns.find('h2').exists()).toBe(false)
    expect(columns.findAll('[data-id="hilos-viewport-table"]')).toHaveLength(1)
    expect(columns.find('[data-id="hilos-table-title"]').exists()).toBe(false)
    const table = columns.get('table')
    expect(table.attributes('aria-labelledby')).toBe(
      wrapper.get('h1').attributes('id'),
    )
    expect(table.findAll('th').map((th) => th.text())).toEqual([
      'Column',
      'Stored as',
      'In the journal',
      'Will move Will leaveRow state and controls',
    ])
    expect(
      table.findAll('tbody tr').map((tr) =>
        tr
          .findAll('td')
          .slice(0, 3)
          .map((td) => td.text().replace(/\s+/g, ' ')),
      ),
    ).toEqual([
      ['id', '—', 'Record key'],
      ['name', 'String', 'With value'],
      ['description', 'Long text', 'With value'],
      ['email', '—', 'Personal — only a fact'],
      ['seen_at', '—', 'Noisy — not written'],
    ])
    expect(
      table
        .findAll('code')
        .every((code) => code.classes().includes('text-break')),
    ).toBe(true)
    expect(
      table
        .findAll('td .badge.text-wrap i')
        .every((icon) => icon.attributes('aria-hidden') === 'true'),
    ).toBe(true)
    const cards = columns.get('[data-id="hilos-table-cards"] [role="list"]')
    expect(cards.attributes('role')).toBe('list')
    expect(cards.attributes('aria-labelledby')).toBe(
      wrapper.get('h1').attributes('id'),
    )
    expect(cards.findAll('[role="listitem"]')).toHaveLength(5)
    expect(
      cards.get('[data-id="hilos-table-card-description"]').text(),
    ).toContain('Long text')
    expect(cards.get('[data-id="hilos-table-card-email"]').text()).toContain(
      'Personal — only a fact',
    )
    expect(columns.get('[data-id="hilos-table-count"]').text()).toBe(
      '1 – 5 of 5',
    )
    expect(columns.text()).toContain(CHANGE_LOG_TABLE_COPY.noisyNote)
    expect(columns.find('input').exists()).toBe(false)
    expect(columns.find('[data-id="hilos-table-order"]').exists()).toBe(false)
    expect(table.find('th button').exists()).toBe(false)
  })

  it('requests its page/table/preset after the first window and renders only the matching reply', async () => {
    const h = harness()
    const { wrapper } = await mountColumns(h)
    expect(h.connection.registerTableWindow).toHaveBeenCalledWith(
      TABLE,
      expect.anything(),
    )
    expect(
      wrapper.find('table tbody tr[data-id^="hilos-table-row-"]').exists(),
    ).toBe(false)
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
    expect(wrapper.find('[data-id="hilos-table-row-id"]').exists()).toBe(false)
    h.pushMetadataWindow(HilosPages.CHANGE_LOG_TABLE_TRIGGERS, TABLE, rows)
    h.pushMetadataWindow(PAGE, 'hilosChangeLogTableTriggers', rows)
    await settle()
    expect(wrapper.find('[data-id="hilos-table-row-id"]').exists()).toBe(false)
    const descriptor = h.connection.sendTableViewport.mock.calls[0]![2]
    expect(descriptor.requestId).toEqual(expect.any(String))
    h.pushMetadataWindow(PAGE, TABLE, [rows[1]!], false, {
      requestId: descriptor.requestId,
      totalCount: 30,
      firstAnchor: { name: 'name' },
      lastAnchor: { name: 'name' },
    })
    await settle()
    expect(
      wrapper.get('table [data-id="hilos-table-row-name"]').text(),
    ).toContain('String')
    expect(wrapper.find('[data-id="hilos-table-row-seen_at"]').exists()).toBe(
      false,
    )
    // The note describes the full schema, including noise outside this window.
    expect(wrapper.text()).toContain(CHANGE_LOG_TABLE_COPY.noisyNote)
    expect(wrapper.get('[data-id="hilos-table-count"]').text()).toBe(
      '1 – 1 of 30',
    )
    await wrapper.get('[data-id="hilos-table-next"]').trigger('click')
    expect(h.connection.sendTableViewport).toHaveBeenLastCalledWith(
      PAGE,
      TABLE,
      expect.objectContaining({
        filter: { table: 'bot' },
        anchor: { name: 'name' },
        anchorDirection: 'after',
      }),
    )
    h.pushMetadataWindow(PAGE, TABLE, [rows[2]!], false, {
      totalCount: 30,
      rowsBefore: 25,
    })
    await settle()
    expect(wrapper.get('[data-id="hilos-table-count"]').text()).toBe(
      '26 – 26 of 30',
    )
    expect(wrapper.find('[data-id="hilos-table-row-name"]').exists()).toBe(
      false,
    )
  })

  it('reads nullable, secret and binary modes from the column slot and keeps the envelope key', async () => {
    const h = harness({
      ...BOT,
      columns: BOT.columns.filter(({ mode }) => mode !== 'noise'),
    })
    const { wrapper } = await mountColumns(h)
    h.pushMetadataWindow(PAGE, TABLE, [
      {
        rowKey: 'envelope',
        slots: {
          column: {
            name: 'untracked',
            sqlType: 'int',
            mode: null,
            storage: null,
            noiseReason: null,
          },
        },
      },
      {
        rowKey: 'token',
        slots: {
          column: {
            name: 'token',
            sqlType: 'varchar',
            mode: 'secret',
            storage: 'ignored',
            noiseReason: null,
          },
        },
      },
      {
        rowKey: 'picture',
        slots: {
          column: {
            name: 'picture',
            sqlType: 'blob',
            mode: 'binary',
            storage: 'fact',
            noiseReason: null,
          },
        },
      },
    ])
    await settle()
    expect(
      wrapper
        .get('table [data-id="hilos-table-row-envelope"]')
        .findAll('td')
        .slice(0, 3)
        .map((td) => td.text()),
    ).toEqual(['untracked', '—', '—'])
    expect(
      wrapper.get('table [data-id="hilos-table-row-token"]').text(),
    ).toContain('Secret — not written')
    expect(
      wrapper.get('table [data-id="hilos-table-row-picture"]').text(),
    ).toContain('Binary — only a fact')
    expect(wrapper.text()).not.toContain(CHANGE_LOG_TABLE_COPY.noisyNote)
    expect(wrapper.text()).not.toContain('seen_at')
  })

  it('rebuilds for route and same-address scope replacements and releases every listener on unmount', async () => {
    const h = harness()
    const { wrapper, r } = await mountColumns(h)
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
    expect(wrapper.text()).not.toContain('seen_at')
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
    expect(wrapper.get('h1').text()).toBe('other')
    const second = h.connection.registerTableWindow.mock.calls[1]?.[1]
    const sameAddress = h.context.scopes.openPage(PAGE)
    answerIdentity(sameAddress)
    sameAddress.data.set(CHANGE_LOG_TABLE_SECTION, { ...BOT, name: 'other' })
    await settle()
    expect(h.connection.unregisterTableWindow).toHaveBeenCalledWith(
      TABLE,
      second,
    )
    expect(h.connection.registerTableWindow).toHaveBeenCalledTimes(3)
    expect(wrapper.find('[data-id="hilos-table-row-name"]').exists()).toBe(
      false,
    )
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
    'does not mount a viewport for a missing or invalid declaration %s',
    async (section) => {
      const h = harness(section)
      const { wrapper } = await mountColumns(h)
      expect(
        wrapper.find('[data-id="hilos-change-log-table-columns"]').exists(),
      ).toBe(false)
      expect(h.connection.registerTableWindow).not.toHaveBeenCalled()
    },
  )

  it('keeps public metadata visible to an admin-view-mode viewer without mutation controls', async () => {
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
    const { wrapper } = await mountColumns(h, router({ tableId: 'bot' }))
    h.pushMetadataWindow(PAGE, TABLE, rows)
    await settle()
    expect(wrapper.get('h1').text()).toBe('bot')
    expect(
      wrapper.get('table [data-id="hilos-table-row-email"]').text(),
    ).toContain('Personal — only a fact')
    expect(wrapper.find('[data-id="hilos-table-main-action"]').exists()).toBe(
      false,
    )
    expect(h.source.sent).toEqual([])
  })
})
