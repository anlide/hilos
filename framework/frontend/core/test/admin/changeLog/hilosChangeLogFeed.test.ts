import { describe, expect, it } from 'vitest'

import {
  createHilosChangeLogFeedTable,
  resolveHilosChangeLogFeedRow,
} from '../../../src/admin/changeLog/hilosChangeLogFeed.js'
import { type HilosChangeLogContext } from '../../../src/admin/changeLog/hilosChangeLog.js'
import {
  type HilosConnection,
  type TableViewportDescriptor,
} from '../../../src/connection/HilosConnection.js'
import { HilosPages } from '../../../src/routing/hilosPages.js'
import { HIDDEN_VALUE } from '../../../src/state/hiddenValue.js'
import { type ScopeManager } from '../../../src/state/ScopeManager.js'
import { type TableRow } from '../../../src/state/TableRowsStore.js'

function rawRow(rowKey: string, slot: Record<string, unknown>): TableRow {
  return { rowKey, slots: { feed: slot } }
}

function stubTable(): {
  table: ReturnType<typeof createHilosChangeLogFeedTable>
  sent: Array<{
    page: string
    tableKey: string
    descriptor: TableViewportDescriptor
  }>
  tables: string[]
} {
  const sent: Array<{
    page: string
    tableKey: string
    descriptor: TableViewportDescriptor
  }> = []
  const tables = ['bot']
  const context: HilosChangeLogContext = {
    connection: {
      registerTableWindow(): void {},
      unregisterTableWindow(): void {},
      tableWindowDescriptors: () => ({}),
      sendTableViewport(
        page: string,
        tableKey: string,
        descriptor: TableViewportDescriptor,
      ): boolean {
        sent.push({ page, tableKey, descriptor })
        return true
      },
      sendTableRendered(): boolean {
        return true
      },
    } as unknown as HilosConnection,
    scopes: {} as ScopeManager,
  }
  return {
    table: createHilosChangeLogFeedTable(context, { tables: () => tables }),
    sent,
    tables,
  }
}

describe('change-log feed row', () => {
  it('resolves a receipt slot and keeps hidden names', () => {
    const row = resolveHilosChangeLogFeedRow(
      rawRow('receipt:7', {
        rowKey: 'wrong-slot-key',
        kind: 'receipt',
        receiptId: 7,
        entryId: null,
        createdAt: '2026-10-09T10:11:12.123Z',
        actorId: 3,
        actorLabel: { _hidden: true },
        actorDeleted: false,
        subjectId: 4,
        subjectLabel: { _hidden: true },
        subjectDeleted: true,
        channel: 'web',
        action: 'bot.update',
        touched: [
          {
            table: 'bot',
            records: 1,
            recordKey: [3],
            mutation: 'update',
            changedFields: 2,
          },
        ],
      }),
    )
    expect(row.rowKey).toBe('receipt:7')
    expect(row.actorLabel).toBe(HIDDEN_VALUE)
    expect(row.subjectLabel).toBe(HIDDEN_VALUE)
    expect(row.touched).toEqual([
      {
        table: 'bot',
        records: 1,
        recordKey: [3],
        mutation: 'update',
        changedFields: 2,
      },
    ])
  })

  it('resolves a bare entry and ignores a malformed touched list', () => {
    const row = resolveHilosChangeLogFeedRow(
      rawRow('entry:8', {
        kind: 'entry',
        entryId: 8,
        touched: 'not a list',
      }),
    )
    expect(row.rowKey).toBe('entry:8')
    expect(row.receiptId).toBeNull()
    expect(row.entryId).toBe(8)
    expect(row.touched).toEqual([])
  })
})

describe('change-log feed viewport', () => {
  it('declares the agreed columns, reads and filters without a preset', () => {
    const { table, tables } = stubTable()
    const frame = table.controller.frame
    expect(frame.declaration?.title).toBe('Recent actions')
    expect(frame.declaration?.search?.placeholder).toBe('Who…')
    expect(frame.declaration?.empty).toBeUndefined()
    const columns = frame.columns.get()
    expect(columns.map(({ key }) => key)).toEqual([
      'createdAt',
      'actorLabel',
      'action',
      'touched',
      'actions',
    ])
    expect(columns[0]?.card).toBe('title')
    expect(columns[0]?.cellClass).toBe('text-nowrap')
    expect(columns[1]?.reads).toEqual([
      'actorId',
      'actorDeleted',
      'subjectId',
      'subjectLabel',
      'subjectDeleted',
      'channel',
      'kind',
    ])
    expect(columns[2]?.reads).toEqual(['kind'])
    expect(columns[4]?.reads).toEqual(['kind', 'receiptId', 'entryId'])
    expect(columns.every(({ sortable }) => sortable !== true)).toBe(true)

    const filters = frame.declaration?.filters
    const resolved = typeof filters === 'function' ? filters() : filters
    expect(
      resolved?.map((filter) => ('key' in filter ? filter.key : '')),
    ).toEqual(['channel', 'table', 'period'])
    const tableFilter = resolved?.[1]
    expect(tableFilter?.kind).toBe('select')
    if (tableFilter?.kind === 'select') {
      expect(tableFilter.options()).toEqual([{ value: 'bot', label: 'bot' }])
      tables.push('hilos_user')
      expect(tableFilter.options()).toEqual([
        { value: 'bot', label: 'bot' },
        { value: 'hilos_user', label: 'hilos_user' },
      ])
    }
    const period = resolved?.[2]
    if (period?.kind === 'select') {
      expect(period.anyLabel).toBe('All time')
      expect(period.options().map(({ value }) => value)).toEqual([
        'hour',
        'day',
        'week',
        'month',
      ])
    }
  })

  it('takes the first unfiltered window from the backend declaration', () => {
    const { table, sent } = stubTable()
    table.controller.ingestSubscriptionWindow(
      [],
      0,
      true,
      null,
      null,
      25,
      undefined,
      [],
    )
    expect(sent).toEqual([])
    table.controller.setFilter('channel', 'web')
    expect(sent.at(-1)?.page).toBe(HilosPages.CHANGE_LOG)
    expect(sent.at(-1)?.tableKey).toBe('hilosChangeLogFeed')
    expect(sent.at(-1)?.descriptor.filter).toEqual({ channel: 'web' })
    expect(sent.at(-1)?.descriptor.sort).toBeNull()
  })
})
