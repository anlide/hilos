import { act, cleanup, screen, within } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import { CHANGE_LOG_TABLE_COPY as copy } from '@hilos/core/admin/change-log'
import {
  HilosPages,
  bindAdminAccess,
  bindSessionScope,
  hilosAdminAccess,
} from '@hilos/core/base'

import { HilosChangeLogTableTriggersPage } from '../src/admin/changeLog/HilosChangeLogTableTriggersPage.js'
import {
  BOT,
  answerTable,
  mountMetadataPage,
  tableHarness,
  tableRouter,
  updateTable,
} from './helpers/changeLogTable.js'

const PAGE = HilosPages.CHANGE_LOG_TABLE_TRIGGERS
const TABLE = 'hilosChangeLogTableTriggers'
const ROWS = BOT.triggers.map((trigger) => ({
  rowKey: trigger.name,
  slots: { trigger: { ...trigger, owner: 'service', writesTo: 'journal' } },
}))
const SESSION = {
  ...BOT,
  name: 'hilos_session',
  owner: 'framework',
  journaled: false,
  changesLast24h: null,
  columns: [{ ...BOT.columns[0], mode: null }],
  triggers: [],
}
const releases: (() => void)[] = []

afterEach(() => {
  cleanup()
  for (const release of releases.splice(0)) release()
})

function mountTriggers(h = tableHarness(BOT, PAGE), r = tableRouter(h)) {
  return mountMetadataPage(HilosChangeLogTableTriggersPage, h, r)
}

/** Read the five interface cells, without the framework's row controls. */
function cells(): string[][] {
  return Array.from(
    screen.getByRole('table', { name: 'bot' }).querySelectorAll('tbody tr'),
    (row) =>
      Array.from(row.querySelectorAll('td'))
        .slice(0, 5)
        .map((cell) => cell.textContent!.trim()),
  )
}

describe('HilosChangeLogTableTriggersPage', () => {
  it('draws service triggers in event order with cards named by the single shared heading', () => {
    const h = tableHarness(BOT, PAGE)
    const { container } = mountTriggers(h)
    expect(container.textContent).not.toContain('hilos_cl_bot_after_insert')
    act(() => h.pushMetadataWindow(PAGE, TABLE, ROWS))
    const heading = screen.getByRole('heading', { level: 1, name: 'bot' })
    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1)
    expect(screen.queryByRole('heading', { level: 2 })).toBeNull()
    const table = screen.getByRole('table', { name: 'bot' })
    expect(table.getAttribute('aria-labelledby')).toBe(heading.id)
    expect(
      within(table)
        .getAllByRole('columnheader')
        .slice(0, 5)
        .map((cell) => cell.textContent),
    ).toEqual(['Trigger', 'Owner', 'Fires', 'Writes to', 'In effect'])
    expect(cells()).toEqual([
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
      Array.from(table.querySelectorAll('code'), (code) => code.textContent),
    ).toEqual([
      'hilos_cl_bot_after_insert',
      'hilos_cl_bot_after_update',
      'hilos_cl_bot_after_delete',
    ])
    const cards = screen.getByRole('list', { name: 'bot' })
    expect(cards.getAttribute('aria-labelledby')).toBe(heading.id)
    expect(within(cards).getAllByRole('listitem')).toHaveLength(3)
    expect(cards.textContent).toContain('AFTER UPDATE on bot')
    expect(screen.getByText('1 – 3 of 3')).toBeTruthy()
    const section = container.querySelector(
      '[data-id="hilos-change-log-table-triggers"]',
    )!
    expect(section.firstElementChild?.textContent).toBe(copy.triggersLead)
    expect(section.lastElementChild?.textContent).toBe(copy.triggersFootnote)
    expect(
      container.querySelectorAll('[data-id="hilos-viewport-table"]'),
    ).toHaveLength(1)
    expect(container.querySelector('[data-id="hilos-table-title"]')).toBeNull()
    expect(
      container.querySelector('[data-id="hilos-change-log-table-overview"]'),
    ).toBeNull()
    expect(container.querySelector('input')).toBeNull()
    expect(container.querySelector('[data-id="hilos-table-order"]')).toBeNull()
    expect(table.querySelector('th button')).toBeNull()
    for (const icon of container.querySelectorAll('i'))
      expect(icon.closest('[aria-hidden="true"]')).not.toBeNull()
    const tabs = screen.getByRole('navigation', { name: 'Table pages' })
    expect(tabs.querySelectorAll('[aria-current="page"]')).toHaveLength(1)
    expect(
      within(tabs)
        .getByRole('link', { name: 'Triggers' })
        .getAttribute('aria-current'),
    ).toBe('page')
    expect(h.source.sendAction).not.toHaveBeenCalled()
  })

  it('requests its preset and draws only the matching reply slot rather than snapshot triggers', () => {
    const h = tableHarness(BOT, PAGE)
    const { container } = mountTriggers(h)
    act(() => h.pushMetadataWindow(PAGE, TABLE, ROWS, true, { filter: {} }))
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
    act(() => {
      h.pushMetadataWindow(HilosPages.CHANGE_LOG_TABLE_COLUMNS, TABLE, ROWS)
      h.pushMetadataWindow(PAGE, 'hilosChangeLogTableColumns', ROWS)
    })
    expect(
      container.querySelector(
        '[data-id="hilos-table-row-hilos_cl_bot_after_insert"]',
      ),
    ).toBeNull()
    const descriptor = h.connection.sendTableViewport.mock.calls[0]![2]
    expect(descriptor.requestId).toEqual(expect.any(String))
    act(() =>
      h.pushMetadataWindow(
        PAGE,
        TABLE,
        [
          {
            rowKey: 'envelope',
            slots: {
              trigger: {
                ...ROWS[0]!.slots.trigger,
                name: 'server_trigger',
                event: 'DELETE',
                table: 'server_table',
                validFromMigration: 109,
              },
            },
          },
        ],
        false,
        { requestId: descriptor.requestId },
      ),
    )
    expect(cells()).toEqual([
      [
        'server_trigger',
        'Service',
        'AFTER DELETE on server_table',
        'Journal',
        'from migration 109',
      ],
    ])
    expect(
      container.querySelector('table [data-id="hilos-table-row-envelope"]'),
    ).not.toBeNull()
    expect(container.textContent).not.toContain('hilos_cl_bot_after_insert')
    expect(screen.getByText('1 – 1 of 1')).toBeTruthy()
  })

  it('contains a cold window refusal and recovers from a server page answer', () => {
    const h = tableHarness(BOT, PAGE)
    const { container } = mountTriggers(h)
    act(() =>
      h.emit('projectSignal', {
        type: 'page_response',
        data: {
          page: PAGE,
          payload: {
            refusedWindows: { [TABLE]: { errorCode: 'internal_error' } },
          },
        },
      }),
    )
    expect(screen.getByRole('heading', { level: 1, name: 'bot' })).toBeTruthy()
    expect(
      container.querySelector('[data-id="hilos-table-unavailable"]')
        ?.textContent,
    ).toContain('List unavailable')
    expect(container.querySelector('[data-id^="hilos-table-row-"]')).toBeNull()
    expect(h.connection.sendTableViewport).not.toHaveBeenCalled()
    act(() => h.pushMetadataWindow(PAGE, TABLE, ROWS, true))
    expect(
      container.querySelector('[data-id="hilos-table-unavailable"]'),
    ).toBeNull()
    expect(cells()).toHaveLength(3)
    expect(screen.getByText('1 – 3 of 3')).toBeTruthy()
    expect(h.connection.sendTableViewport).not.toHaveBeenCalled()
  })

  it('uses an empty standard viewport and only eligible tabs for an untracked table', () => {
    const h = tableHarness(SESSION, PAGE)
    const { container } = mountTriggers(
      h,
      tableRouter(h, { tableId: 'hilos_session' }),
    )
    act(() => h.pushMetadataWindow(PAGE, TABLE, [], true))
    expect(h.connection.sendTableViewport).toHaveBeenLastCalledWith(
      PAGE,
      TABLE,
      expect.objectContaining({ filter: { table: 'hilos_session' } }),
    )
    const descriptor = h.connection.sendTableViewport.mock.calls.at(-1)![2]
    act(() =>
      h.pushMetadataWindow(PAGE, TABLE, [], false, {
        requestId: descriptor.requestId,
      }),
    )
    expect(
      screen.getByRole('heading', { level: 1, name: 'hilos_session' }),
    ).toBeTruthy()
    expect(screen.getByRole('table', { name: 'hilos_session' })).toBeTruthy()
    expect(
      container.querySelector(
        '[data-id="hilos-table-cards"] [role="listitem"]',
      ),
    ).toBeNull()
    expect(container.querySelector('[data-id^="hilos-table-row-"]')).toBeNull()
    expect(container.querySelector('[data-id="hilos-table-count"]')).toBeNull()
    expect(
      container.querySelectorAll('[data-id="hilos-table-empty"]'),
    ).toHaveLength(2)
    expect(container.textContent).not.toContain('hilos_cl_')
    expect(screen.getByText(copy.untracked)).toBeTruthy()
    expect(screen.getByText(copy.triggersLead)).toBeTruthy()
    expect(screen.getByText(copy.triggersFootnote)).toBeTruthy()
    const tabs = screen.getByRole('navigation', { name: 'Table pages' })
    expect(
      within(tabs)
        .getAllByRole('link')
        .map((link) => [link.textContent, link.getAttribute('href')]),
    ).toEqual([
      ['Overview', '/hilos/change-log/tables/hilos_session'],
      ['Triggers', '/hilos/change-log/tables/hilos_session/triggers'],
    ])
    expect(tabs.querySelectorAll('[aria-current="page"]')).toHaveLength(1)
    expect(
      within(tabs)
        .getByRole('link', { name: 'Triggers' })
        .getAttribute('aria-current'),
    ).toBe('page')
  })

  it('preserves full long names and renders HTML literally without trigger bodies or personal values', () => {
    const h = tableHarness(BOT, PAGE)
    const { container } = mountTriggers(h)
    const name = `trigger_${'long_'.repeat(90)}<img src=x onerror=alert(1)>`
    const table = `table_${'long_'.repeat(90)}<script>alert(1)</script>`
    act(() =>
      h.pushMetadataWindow(PAGE, TABLE, [
        {
          rowKey: 'long',
          slots: {
            trigger: {
              ...ROWS[0]!.slots.trigger,
              name,
              table,
              body: 'private SQL body',
              actorLabel: 'Private person',
              email: 'private@example.test',
            },
          },
        },
      ]),
    )
    expect(cells()).toEqual([
      [
        name,
        'Service',
        `AFTER INSERT on ${table}`,
        'Journal',
        'from migration 071',
      ],
    ])
    expect(container.querySelector('table code')?.textContent).toBe(name)
    expect(screen.getByRole('list', { name: 'bot' }).textContent).toContain(
      name,
    )
    expect(container.querySelector('img, script')).toBeNull()
    for (const absent of [
      'private SQL body',
      'Private person',
      'private@example.test',
    ])
      expect(container.textContent).not.toContain(absent)
  })

  it('opens only after the permitted first answer', () => {
    const h = tableHarness(BOT, PAGE, false)
    const { container } = mountTriggers(h)
    expect(
      container
        .querySelector('[data-id="hilos-page-head-skeleton"]')
        ?.getAttribute('aria-hidden'),
    ).toBe('true')
    expect(screen.queryByRole('heading')).toBeNull()
    expect(screen.queryByRole('navigation')).toBeNull()
    expect(h.connection.registerTableWindow).not.toHaveBeenCalled()
    act(() => answerTable(h.scope))
    expect(screen.getByRole('heading', { level: 1, name: 'bot' })).toBeTruthy()
    expect(h.connection.registerTableWindow).toHaveBeenCalledTimes(1)
    expect(h.connection.registerTableWindow).toHaveBeenCalledWith(
      TABLE,
      expect.anything(),
    )
  })

  it.each([null, { ...BOT, name: 'stale' }, { name: 'bot' }])(
    'opens no resource for a missing, stale or invalid declaration %s',
    (section) => {
      const h = tableHarness(section, PAGE)
      const { container } = mountTriggers(h)
      expect(
        container.querySelector('[data-id="hilos-viewport-table"]'),
      ).toBeNull()
      expect(h.connection.registerTableWindow).not.toHaveBeenCalled()
    },
  )

  it.each(['route', 'scope', 'error'] as const)(
    'opens no window when the %s is for another page or refused',
    (mismatch) => {
      const h = tableHarness(
        BOT,
        mismatch === 'scope' ? HilosPages.CHANGE_LOG_TABLE_COLUMNS : PAGE,
      )
      const r = tableRouter(h)
      r.route.set({
        page: mismatch === 'route' ? HilosPages.CHANGE_LOG_TABLE_COLUMNS : PAGE,
        params: { tableId: 'bot' },
        admin: true,
      })
      if (mismatch === 'error')
        r.error.set({
          httpCode: 403,
          errorCode: 'forbidden',
          title: 'Forbidden',
          message: 'No access',
        })
      const { container } = mountTriggers(h, r)
      expect(
        container.querySelector('[data-id="hilos-viewport-table"]'),
      ).toBeNull()
      expect(h.connection.registerTableWindow).not.toHaveBeenCalled()
    },
  )

  it('rebuilds on addresses, permitted names and replacement scopes and releases StrictMode mounts', () => {
    const h = tableHarness(BOT, PAGE)
    const { container, r, unmount } = mountTriggers(h)
    expect(h.connection.registerTableWindow).toHaveBeenCalledTimes(2)
    expect(h.connection.unregisterTableWindow).toHaveBeenCalledWith(
      TABLE,
      h.connection.registerTableWindow.mock.calls[0]![1],
    )
    act(() => h.pushMetadataWindow(PAGE, TABLE, ROWS))
    const first = h.connection.registerTableWindow.mock.calls.at(-1)![1]
    act(() =>
      r.route.set({ page: PAGE, params: { tableId: 'other' }, admin: true }),
    )
    expect(h.connection.unregisterTableWindow).toHaveBeenLastCalledWith(
      TABLE,
      first,
    )
    expect(
      container.querySelector('[data-id="hilos-viewport-table"]'),
    ).toBeNull()
    expect(container.textContent).not.toContain('hilos_cl_bot_after_insert')
    expect(r.router.pageIdentity.get()?.pageHeadOwner?.label).not.toBe('bot')
    expect(h.connection.registerTableWindow).toHaveBeenCalledTimes(2)
    updateTable(h, { ...BOT, name: 'other' })
    expect(h.connection.registerTableWindow).toHaveBeenCalledTimes(3)
    act(() => h.pushMetadataWindow(PAGE, TABLE, [ROWS[1]!]))
    expect(
      screen.getByRole('heading', { level: 1, name: 'other' }),
    ).toBeTruthy()
    const second = h.connection.registerTableWindow.mock.calls.at(-1)![1]
    act(() =>
      answerTable(h.context.scopes.openPage(PAGE), { ...BOT, name: 'other' }),
    )
    expect(h.connection.unregisterTableWindow).toHaveBeenLastCalledWith(
      TABLE,
      second,
    )
    expect(h.connection.registerTableWindow).toHaveBeenCalledTimes(4)
    expect(
      container.querySelector(
        '[data-id="hilos-table-row-hilos_cl_bot_after_update"]',
      ),
    ).toBeNull()
    const pending = h.connection.registerTableWindow.mock.calls.at(-1)![1]
    act(() => h.context.scopes.openPage(PAGE))
    expect(h.connection.unregisterTableWindow).toHaveBeenLastCalledWith(
      TABLE,
      pending,
    )
    expect(h.connection.registerTableWindow).toHaveBeenCalledTimes(4)
    expect(
      container.querySelector('[data-id="hilos-viewport-table"]'),
    ).toBeNull()
    expect(screen.queryByRole('heading')).toBeNull()
    expect(
      container.querySelector('[data-id="hilos-page-head-skeleton"]'),
    ).not.toBeNull()
    expect(r.router.pageIdentity.get()?.pageHeadOwner?.label).not.toBe('other')
    act(() => answerTable(h.context.scopes.page()!, { ...BOT, name: 'other' }))
    expect(h.connection.registerTableWindow).toHaveBeenCalledTimes(5)
    const third = h.connection.registerTableWindow.mock.calls.at(-1)![1]
    act(() =>
      r.error.set({
        httpCode: 403,
        errorCode: 'forbidden',
        title: 'Forbidden',
        message: 'No access',
      }),
    )
    expect(h.connection.unregisterTableWindow).toHaveBeenLastCalledWith(
      TABLE,
      third,
    )
    expect(
      container.querySelector('[data-id="hilos-viewport-table"]'),
    ).toBeNull()
    act(() => r.error.set(null))
    expect(h.connection.registerTableWindow).toHaveBeenCalledTimes(6)
    unmount()
    expect(h.connection.unregisterTableWindow).toHaveBeenCalledTimes(6)
    expect(r.router.pageIdentity.get()?.pageHeadOwner?.label).toBe(
      'Tracked table',
    )
    const sends = h.connection.sendTableViewport.mock.calls.length
    const rendered = h.connection.sendTableRendered.mock.calls.length
    act(() => {
      h.pushMetadataWindow(PAGE, TABLE, ROWS, true)
      h.pushMetadataWindow(PAGE, TABLE, ROWS)
      h.context.scopes.openPage(PAGE)
    })
    expect(h.connection.sendTableViewport).toHaveBeenCalledTimes(sends)
    expect(h.connection.sendTableRendered).toHaveBeenCalledTimes(rendered)
    expect(
      [...h.listeners.values()].every((listeners) => listeners.size === 0),
    ).toBe(true)
  })

  it.each(['route', 'scope'] as const)(
    'closes the window when its %s leaves Triggers',
    (change) => {
      const h = tableHarness(BOT, PAGE)
      const { container, r } = mountTriggers(h)
      act(() => h.pushMetadataWindow(PAGE, TABLE, ROWS))
      const current = h.connection.registerTableWindow.mock.calls.at(-1)![1]
      act(() => {
        if (change === 'route')
          r.route.set({
            page: HilosPages.CHANGE_LOG_TABLE,
            params: { tableId: 'bot' },
            admin: true,
          })
        else answerTable(h.context.scopes.openPage(HilosPages.CHANGE_LOG_TABLE))
      })
      expect(h.connection.unregisterTableWindow).toHaveBeenLastCalledWith(
        TABLE,
        current,
      )
      expect(
        container.querySelector('[data-id="hilos-viewport-table"]'),
      ).toBeNull()
      expect(h.connection.registerTableWindow).toHaveBeenCalledTimes(2)
    },
  )

  it.each(['full', 'view'] as const)(
    'keeps public metadata visible under %s access without personal values or mutations',
    (access) => {
      const h = tableHarness(BOT, PAGE)
      bindSessionScope(h.context.connection, h.context.scopes)
      releases.push(bindAdminAccess(h.context.scopes))
      act(() =>
        h.emit('projectSignal', {
          type: 'handshake_response',
          data: {
            entities: {
              currentUser: { id: 9, name: 'Ada', admin: access === 'full' },
            },
            data: { adminViewMode: true },
          },
        }),
      )
      expect(hilosAdminAccess.get()).toBe(access)
      const { container } = mountTriggers(h)
      act(() => h.pushMetadataWindow(PAGE, TABLE, ROWS))
      expect(cells()).toHaveLength(3)
      expect(cells()[0]![1]).toBe('Service')
      expect(container.textContent).not.toContain('Ada')
      expect(
        container.querySelector('[data-id="hilos-table-main-action"]'),
      ).toBeNull()
      expect(container.querySelector('[data-id="modal"]')).toBeNull()
      expect(
        h.connection.registerTableWindow.mock.calls.every(
          ([key]) => key === TABLE,
        ),
      ).toBe(true)
      expect(h.source.sendAction).not.toHaveBeenCalled()
    },
  )
})
