import { act, cleanup, fireEvent, screen, within } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import { CHANGE_LOG_TABLE_COPY as copy } from '@hilos/core/admin/change-log'
import {
  HilosPages,
  bindAdminAccess,
  bindSessionScope,
  hilosAdminAccess,
} from '@hilos/core/base'

import { HilosChangeLogTableColumnsPage } from '../src/admin/changeLog/HilosChangeLogTableColumnsPage.js'
import {
  BOT,
  answerTable,
  mountMetadataPage,
  tableHarness,
  tableRouter,
  updateTable,
} from './helpers/changeLogTable.js'

const PAGE = HilosPages.CHANGE_LOG_TABLE_COLUMNS
const TABLE = 'hilosChangeLogTableColumns'
const ROWS = BOT.columns.map((column) => ({
  rowKey: column.name,
  slots: { column },
}))
const releases: (() => void)[] = []

afterEach(() => {
  cleanup()
  for (const release of releases.splice(0)) release()
})

function mountColumns(h = tableHarness(BOT, PAGE), r = tableRouter(h)) {
  return mountMetadataPage(HilosChangeLogTableColumnsPage, h, r)
}

/** Read the three schema cells, leaving the framework's row controls to it. */
function cells(): string[][] {
  return Array.from(
    screen.getByRole('table', { name: 'bot' }).querySelectorAll('tbody tr'),
    (row) =>
      Array.from(row.querySelectorAll('td'))
        .slice(0, 3)
        .map((cell) => cell.textContent!.trim()),
  )
}

describe('HilosChangeLogTableColumnsPage', () => {
  it('draws schema windows, modes and cards named by the single shared heading', () => {
    const h = tableHarness(BOT, PAGE)
    const { container } = mountColumns(h)
    expect(container.textContent).not.toContain('seen_at')
    act(() => h.pushMetadataWindow(PAGE, TABLE, ROWS))
    const heading = screen.getByRole('heading', { level: 1, name: 'bot' })
    expect(screen.getAllByRole('heading', { level: 1 })).toHaveLength(1)
    expect(screen.queryByRole('heading', { level: 2 })).toBeNull()
    const table = screen.getByRole('table', { name: 'bot' })
    expect(table.getAttribute('aria-labelledby')).toBe(heading.id)
    expect(
      within(table)
        .getAllByRole('columnheader')
        .slice(0, 3)
        .map((cell) => cell.textContent),
    ).toEqual(['Column', 'Stored as', 'In the journal'])
    expect(cells()).toEqual([
      ['id', '—', 'Record key'],
      ['name', 'String', 'With value'],
      ['description', 'Long text', 'With value'],
      ['email', '—', 'Personal — only a fact'],
      ['seen_at', '—', 'Noisy — not written'],
    ])
    expect(
      Array.from(table.querySelectorAll('code'), (code) => code.textContent),
    ).toEqual(['id', 'name', 'description', 'email', 'seen_at'])
    const cards = screen.getByRole('list', { name: 'bot' })
    expect(cards.getAttribute('aria-labelledby')).toBe(heading.id)
    expect(within(cards).getAllByRole('listitem')).toHaveLength(5)
    expect(cards.textContent).toContain('Long text')
    expect(cards.textContent).toContain('Personal — only a fact')
    expect(screen.getByText('1 – 5 of 5')).toBeTruthy()
    expect(screen.getByText(copy.noisyNote)).toBeTruthy()
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
        .getByRole('link', { name: 'Columns' })
        .getAttribute('aria-current'),
    ).toBe('page')
    expect(h.source.sendAction).not.toHaveBeenCalled()
  })

  it('requests its preset after the first window and accepts only the matching page, table and request', () => {
    const h = tableHarness(BOT, PAGE)
    const { container } = mountColumns(h)
    act(() => h.pushMetadataWindow(PAGE, TABLE, ROWS, true))
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
      h.pushMetadataWindow(HilosPages.CHANGE_LOG_TABLE_TRIGGERS, TABLE, ROWS)
      h.pushMetadataWindow(PAGE, 'hilosChangeLogTableTriggers', ROWS)
    })
    expect(container.querySelector('[data-id="hilos-table-row-id"]')).toBeNull()
    const descriptor = h.connection.sendTableViewport.mock.calls[0]![2]
    expect(descriptor.requestId).toEqual(expect.any(String))
    act(() =>
      h.pushMetadataWindow(PAGE, TABLE, [ROWS[1]!], false, {
        requestId: descriptor.requestId,
        totalCount: 30,
        firstAnchor: { name: 'name' },
        lastAnchor: { name: 'name' },
      }),
    )
    expect(cells()).toEqual([['name', 'String', 'With value']])
    // Noise outside the window still belongs to the full schema's explanation.
    expect(screen.getByText(copy.noisyNote)).toBeTruthy()
    expect(screen.getByText('1 – 1 of 30')).toBeTruthy()
    fireEvent.click(container.querySelector('[data-id="hilos-table-next"]')!)
    expect(h.connection.sendTableViewport).toHaveBeenLastCalledWith(
      PAGE,
      TABLE,
      expect.objectContaining({
        filter: { table: 'bot' },
        anchor: { name: 'name' },
        anchorDirection: 'after',
      }),
    )
    const next = h.connection.sendTableViewport.mock.calls.at(-1)![2]
    act(() =>
      h.pushMetadataWindow(PAGE, TABLE, [ROWS[2]!], false, {
        requestId: next.requestId,
        totalCount: 30,
        rowsBefore: 25,
      }),
    )
    expect(cells()).toEqual([['description', 'Long text', 'With value']])
    expect(screen.getByText('26 – 26 of 30')).toBeTruthy()
  })

  it('contains a cold window refusal and recovers from a server page answer', () => {
    const h = tableHarness(BOT, PAGE)
    const { container } = mountColumns(h)
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
    expect(cells()).toHaveLength(5)
    expect(screen.getByText('1 – 5 of 5')).toBeTruthy()
    expect(h.connection.sendTableViewport).not.toHaveBeenCalled()
    expect(
      h.connection.registerTableWindow.mock.calls.at(-1)![1].descriptor(),
    ).toEqual(expect.objectContaining({ filter: { table: 'bot' }, limit: 25 }))
  })

  it('keeps nullable, secret and binary metadata while excluding values and noise absent from the schema', () => {
    const h = tableHarness(
      { ...BOT, columns: BOT.columns.filter(({ mode }) => mode !== 'noise') },
      PAGE,
    )
    const { container } = mountColumns(h)
    act(() =>
      h.pushMetadataWindow(PAGE, TABLE, [
        {
          rowKey: 'envelope',
          slots: {
            column: {
              ...BOT.columns[0],
              name: 'untracked',
              mode: null,
              value: 'private@example.test',
            },
          },
        },
        {
          rowKey: 'token',
          slots: {
            column: {
              ...BOT.columns[1],
              name: 'token',
              mode: 'secret',
              storage: 'ignored',
              value: 'private-token',
            },
          },
        },
        {
          rowKey: 'picture',
          slots: {
            column: {
              ...BOT.columns[3],
              name: 'picture',
              mode: 'binary',
              sqlType: 'blob',
              value: 'private-picture',
            },
          },
        },
      ]),
    )
    expect(cells()).toEqual([
      ['untracked', '—', '—'],
      ['token', '—', 'Secret — not written'],
      ['picture', '—', 'Binary — only a fact'],
    ])
    expect(
      container.querySelector('table [data-id="hilos-table-row-envelope"]'),
    ).not.toBeNull()
    for (const absent of [
      'private@example.test',
      'private-token',
      'private-picture',
      'seen_at',
      copy.noisyNote,
    ])
      expect(container.textContent).not.toContain(absent)
  })

  it('preserves full long metadata and renders HTML as text', () => {
    const h = tableHarness(BOT, PAGE)
    const { container } = mountColumns(h)
    const name = `column_${'long_'.repeat(90)}<img src=x onerror=alert(1)>`
    const sqlType = `type_${'detail_'.repeat(90)}<script>alert(1)</script>`
    act(() =>
      h.pushMetadataWindow(PAGE, TABLE, [
        {
          rowKey: 'long',
          slots: { column: { ...BOT.columns[1], name, sqlType } },
        },
      ]),
    )
    expect(cells()).toEqual([[name, sqlType, 'With value']])
    expect(container.querySelector('table code')?.textContent).toBe(name)
    expect(screen.getByRole('list', { name: 'bot' }).textContent).toContain(
      name,
    )
    expect(container.querySelector('img, script')).toBeNull()
  })

  it('shows an empty standard window without invented schema rows or a noisy note', () => {
    const h = tableHarness({ ...BOT, columns: [] }, PAGE)
    const { container } = mountColumns(h)
    act(() => h.pushMetadataWindow(PAGE, TABLE, []))
    expect(screen.getByRole('table', { name: 'bot' })).toBeTruthy()
    expect(
      container.querySelector(
        '[data-id="hilos-table-cards"] [role="listitem"]',
      ),
    ).toBeNull()
    expect(container.querySelector('[data-id^="hilos-table-row-"]')).toBeNull()
    expect(
      container.querySelectorAll('[data-id="hilos-table-empty"]'),
    ).toHaveLength(2)
    expect(container.querySelector('[data-id="hilos-table-count"]')).toBeNull()
    expect(screen.queryByText(copy.noisyNote)).toBeNull()
  })

  it('waits for the permitted first answer before opening a window', () => {
    const h = tableHarness(BOT, PAGE, false)
    const { container } = mountColumns(h)
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
      const { container } = mountColumns(h)
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
        mismatch === 'scope' ? HilosPages.CHANGE_LOG_TABLE_TRIGGERS : PAGE,
      )
      const r = tableRouter(h)
      r.route.set({
        page:
          mismatch === 'route' ? HilosPages.CHANGE_LOG_TABLE_TRIGGERS : PAGE,
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
      const { container } = mountColumns(h, r)
      expect(
        container.querySelector('[data-id="hilos-viewport-table"]'),
      ).toBeNull()
      expect(h.connection.registerTableWindow).not.toHaveBeenCalled()
    },
  )

  it('rebuilds on addresses, permitted names and replacement scopes and releases StrictMode mounts', () => {
    const h = tableHarness(BOT, PAGE)
    const { container, r, unmount } = mountColumns(h)
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
    expect(container.textContent).not.toContain('seen_at')
    expect(r.router.pageIdentity.get()?.pageHeadOwner?.label).not.toBe('bot')
    expect(h.connection.registerTableWindow).toHaveBeenCalledTimes(2)
    // Changing only the permitted name at this address opens a fresh window.
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
      container.querySelector('[data-id="hilos-table-row-name"]'),
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
    'closes the window when its %s leaves Columns',
    (change) => {
      const h = tableHarness(BOT, PAGE)
      const { container, r } = mountColumns(h)
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
      const { container } = mountColumns(h)
      act(() => h.pushMetadataWindow(PAGE, TABLE, ROWS))
      expect(cells()[3]).toEqual(['email', '—', 'Personal — only a fact'])
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
