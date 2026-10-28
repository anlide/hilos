import { describe, expect, it, vi } from 'vitest'

import { type HilosChangeLogContext } from '../../../src/admin/changeLog/hilosChangeLog.js'
import {
  createHilosChangeLogTableColumnsTable,
  resolveHilosChangeLogTableColumnsRow,
} from '../../../src/admin/changeLog/hilosChangeLogTableColumns.js'
import {
  changeLogColumnModeCard,
  formatChangeLogColumnStorage,
} from '../../../src/admin/changeLog/hilosChangeLogTable.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'
import { type TableRow } from '../../../src/state/TableRowsStore.js'

const PAGE = 'hilos_change_log_table_columns'
const TABLE = 'hilosChangeLogTableColumns'
const slot = {
  name: 'name',
  sqlType: 'varchar',
  mode: 'value',
  storage: 'inline',
  noiseReason: null,
}

function rawRow(
  rowKey = 'first',
  values: Record<string, unknown> = slot,
): TableRow {
  return { rowKey, slots: { column: values } }
}

/** A real scope and observable table subscriptions, shared by successive mounts. */
function harness(tableName = 'bot') {
  const scopes = new ScopeManager()
  scopes.openPage(PAGE)
  const listeners = new Map<string, Set<(signal: unknown) => void>>()
  const registrations = new Map<string, unknown>()
  const connection = {
    registerTableWindow: vi.fn((key: string, source: unknown) => {
      registrations.set(key, source)
    }),
    unregisterTableWindow: vi.fn((key: string, source: unknown) => {
      if (registrations.get(key) === source) registrations.delete(key)
    }),
    tableWindowDescriptors: () => ({}),
    sendTableViewport: vi.fn(() => true),
    sendTableRendered: vi.fn(() => true),
    on(event: string, listener: (signal: unknown) => void) {
      const set = listeners.get(event) ?? new Set<(signal: unknown) => void>()
      listeners.set(event, set)
      set.add(listener)
      return () => set.delete(listener)
    },
  }
  const context = {
    connection: connection as unknown as HilosChangeLogContext['connection'],
    scopes,
  }
  const create = (table: string) =>
    createHilosChangeLogTableColumnsTable(context, { table })
  const table = create(tableName)
  function emit(event: string, signal: unknown): void {
    for (const listener of listeners.get(event) ?? []) listener(signal)
  }
  function windowRows(rows: TableRow[], page = PAGE, tableKey = TABLE): void {
    emit('tableWindow', {
      data: {
        page,
        tableKey,
        rows,
        totalCount: rows.length,
        totalExact: true,
        firstAnchor: null,
        lastAnchor: null,
        limit: 25,
        rowsBefore: 0,
      },
    })
  }
  function firstWindow(page = PAGE, tableKey = TABLE): void {
    emit('projectSignal', {
      type: 'page_response',
      data: {
        page,
        payload: {
          windows: {
            [tableKey]: {
              rows: [rawRow()],
              totalCount: 1,
              totalExact: true,
              firstAnchor: null,
              lastAnchor: null,
              limit: 25,
              rowsBefore: 0,
            },
          },
        },
      },
    })
  }
  return {
    table,
    scopes,
    connection,
    listeners,
    registrations,
    create,
    windowRows,
    firstWindow,
  }
}

describe('table columns viewport', () => {
  it('reads only the column slot and takes the key from the envelope', () => {
    const row = resolveHilosChangeLogTableColumnsRow({
      rowKey: 'envelope',
      slots: {
        column: { ...slot, rowKey: 'wrong', id: 99 },
        trigger: { name: 'wrong' },
      },
    })
    expect(row).toEqual({ rowKey: 'envelope', ...slot })
    expect(formatChangeLogColumnStorage(row)).toBe('String')
    expect(changeLogColumnModeCard(row.mode)?.key).toBe('value')
  })

  it.each([
    ['value', 'inline', null],
    ['value', 'long', null],
    ['personal', 'fact', null],
    ['binary', 'fact', null],
    ['secret', 'ignored', null],
    ['noise', 'ignored', 'Automatic timestamp'],
    ['record_key', null, null],
    [null, null, null],
  ])(
    'preserves mode %s, storage %s and reason %s',
    (mode, storage, noiseReason) => {
      expect(
        resolveHilosChangeLogTableColumnsRow(
          rawRow('c', { ...slot, mode, storage, noiseReason }),
        ),
      ).toEqual({ rowKey: 'c', ...slot, mode, storage, noiseReason })
    },
  )

  it.each([
    undefined,
    null,
    [],
    'bad',
    { mode: 'trigger', storage: 'blob', noiseReason: 7 },
  ])('uses nullable defaults for a malformed slot (%j)', (column) => {
    expect(
      resolveHilosChangeLogTableColumnsRow({
        rowKey: 'envelope',
        slots: { column },
      }),
    ).toEqual({
      rowKey: 'envelope',
      name: '',
      sqlType: '',
      mode: null,
      storage: null,
      noiseReason: null,
    })
  })

  it('offers only metadata columns, with no search, filter controls or order menu', () => {
    const { table } = harness()
    const frame = table.controller.frame
    expect(frame.columns.get().map(({ key }) => key)).toEqual([
      'name',
      'storage',
      'mode',
    ])
    expect(frame.columns.get().map(({ label }) => label)).toEqual([
      'Column',
      'Stored as',
      'In the journal',
    ])
    expect(frame.columns.get().some(({ sortable }) => sortable)).toBe(false)
    expect(frame.card.get().title?.key).toBe('name')
    expect(frame.columns.get()[0]?.card).toBe('title')
    expect(frame.searchPlaceholder.get()).toBeUndefined()
    expect(frame.filters.get()).toEqual([])
    expect(frame.activeFilterCount.get()).toBe(0)
    expect(frame.orders.get()).toEqual([])
    expect(frame.declaration.title).toBeUndefined()
    expect(table.controller.filter.get()).toEqual({ table: 'bot' })
  })

  it('binds only its page and table, sends the preset and dependencies, then releases every listener', () => {
    const { table, connection, listeners, firstWindow, windowRows } = harness()
    table.start()
    expect(connection.registerTableWindow).toHaveBeenCalledWith(
      TABLE,
      table.controller,
    )
    firstWindow('hilos_change_log_table')
    firstWindow(PAGE, 'otherTable')
    expect(connection.sendTableViewport).not.toHaveBeenCalled()
    firstWindow()
    expect(table.controller.rows.get()).toEqual([])
    expect(connection.sendTableViewport).toHaveBeenLastCalledWith(
      PAGE,
      TABLE,
      expect.objectContaining({
        filter: { table: 'bot' },
        sort: null,
        limit: 25,
        rendered: ['name', 'storage', 'mode', 'sqlType'],
      }),
    )
    windowRows([rawRow()], 'hilos_change_log_table')
    windowRows([rawRow()], PAGE, 'otherTable')
    expect(table.controller.rows.get()).toEqual([])
    windowRows([rawRow()])
    expect(table.controller.rows.get()[0]?.row).toEqual(
      resolveHilosChangeLogTableColumnsRow(rawRow()),
    )
    expect(table.controller.descriptor()?.filter).toEqual({ table: 'bot' })
    table.controller.show()
    table.controller.resetFilters()
    expect(connection.sendTableViewport).toHaveBeenLastCalledWith(
      PAGE,
      TABLE,
      expect.objectContaining({ filter: { table: 'bot' }, sort: null }),
    )
    table.dispose()
    table.dispose()
    expect(connection.unregisterTableWindow).toHaveBeenCalledExactlyOnceWith(
      TABLE,
      table.controller,
    )
    expect([...listeners.values()].every((set) => set.size === 0)).toBe(true)
    windowRows([rawRow('late')])
    expect(table.controller.rows.get()[0]?.row?.rowKey).toBe('first')
  })

  it('addresses the cold rendered report when there is no active preset', () => {
    const { table, connection, firstWindow } = harness('')
    table.start()
    firstWindow()
    expect(connection.sendTableRendered).toHaveBeenCalledExactlyOnceWith(
      PAGE,
      TABLE,
      ['name', 'storage', 'mode', 'sqlType'],
    )
    expect(connection.sendTableViewport).not.toHaveBeenCalled()
    table.dispose()
  })

  it('keeps a new table and scope on a fresh controller after disposing the old mount', () => {
    const {
      table,
      scopes,
      connection,
      registrations,
      create,
      firstWindow,
      windowRows,
      listeners,
    } = harness()
    table.start()
    windowRows([rawRow('old')])
    scopes.openPage('other_page')
    windowRows([rawRow('wrong_scope')])
    expect(table.controller.rows.get()[0]?.row?.rowKey).toBe('old')
    table.dispose()
    scopes.openPage(PAGE)
    const next = create('hilos_user')
    expect(next.controller).not.toBe(table.controller)
    expect(next.controller.rows.get()).toEqual([])
    next.start()
    table.dispose()
    expect(registrations.get(TABLE)).toBe(next.controller)
    firstWindow()
    expect(connection.sendTableViewport).toHaveBeenLastCalledWith(
      PAGE,
      TABLE,
      expect.objectContaining({ filter: { table: 'hilos_user' } }),
    )
    windowRows([rawRow('new')])
    expect(next.controller.rows.get()[0]?.row?.rowKey).toBe('new')
    expect(table.controller.rows.get()[0]?.row?.rowKey).toBe('old')
    next.dispose()
    expect(registrations.size).toBe(0)
    expect([...listeners.values()].every((set) => set.size === 0)).toBe(true)
  })
})
