import { describe, expect, it } from 'vitest'

import {
  createHilosChangeLogHistoryTable,
  resolveHilosChangeLogHistoryRow,
} from '../../../src/admin/changeLog/hilosChangeLogHistory.js'
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
  return { rowKey, slots: { history: slot } }
}

function stubTable(record?: readonly (number | string)[]): {
  table: ReturnType<typeof createHilosChangeLogHistoryTable>
  sent: Array<{
    page: string
    tableKey: string
    descriptor: TableViewportDescriptor
  }>
  fields: string[]
} {
  const sent: Array<{
    page: string
    tableKey: string
    descriptor: TableViewportDescriptor
  }> = []
  const fields = ['name']
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
    table: createHilosChangeLogHistoryTable(context, {
      table: 'bot',
      record,
      fields: () => fields,
    }),
    sent,
    fields,
  }
}

describe('change-log history row', () => {
  it('resolves field changes, the record key and hidden names', () => {
    const row = resolveHilosChangeLogHistoryRow(
      rawRow('9', {
        rowKey: 99,
        createdAt: '2026-10-09T10:11:12.123Z',
        recordKey: [57, 'en'],
        mutation: 'update',
        changes: [
          {
            field: 'name',
            kind: 'inline',
            oldPresent: true,
            newPresent: true,
            oldValue: 'before',
            newValue: 'after',
            bodyOmitted: false,
          },
          {
            field: 'body',
            kind: 'long',
            oldPresent: true,
            newPresent: true,
            oldValue: null,
            newValue: null,
            bodyOmitted: true,
          },
        ],
        receiptId: 7,
        actorId: 3,
        actorLabel: { _hidden: true },
        actorDeleted: false,
        subjectId: null,
        subjectLabel: { _hidden: true },
        subjectDeleted: false,
        channel: 'web',
      }),
    )
    expect(row.rowKey).toBe(9)
    expect(row.recordKey).toEqual([57, 'en'])
    expect(row.changes).toHaveLength(2)
    expect(row.changes[1]?.bodyOmitted).toBe(true)
    expect(row.actorLabel).toBe(HIDDEN_VALUE)
    expect(row.subjectLabel).toBe(HIDDEN_VALUE)
  })

  it('returns no changes when the slot list is malformed', () => {
    expect(
      resolveHilosChangeLogHistoryRow(rawRow('3', { changes: 1 })).changes,
    ).toEqual([])
  })
})

describe('change-log history viewport', () => {
  it('declares sortable time, history filters and the empty state', () => {
    const { table, fields } = stubTable()
    const frame = table.controller.frame
    expect(frame.declaration?.title).toBe('History')
    expect(frame.declaration?.search?.placeholder).toBe('Who…')
    expect(frame.declaration?.empty?.title).toBe(
      'No changes recorded for this table yet',
    )
    const columns = frame.columns.get()
    expect(columns.map(({ key }) => key)).toEqual([
      'createdAt',
      'recordKey',
      'mutation',
      'changes',
      'actorLabel',
      'actions',
    ])
    expect(columns[0]?.sortable).toBe(true)
    expect(columns[0]?.card).toBe('title')
    expect(columns.slice(1).every(({ sortable }) => sortable !== true)).toBe(
      true,
    )
    expect(columns[4]?.reads).toEqual([
      'actorId',
      'actorDeleted',
      'subjectId',
      'subjectLabel',
      'subjectDeleted',
      'channel',
      'receiptId',
    ])
    expect(columns[5]?.reads).toEqual(['receiptId'])

    const filters = frame.declaration?.filters
    const resolved = typeof filters === 'function' ? filters() : filters
    expect(
      resolved?.map((filter) => ('key' in filter ? filter.key : '')),
    ).toEqual(['field', 'period'])
    const fieldFilter = resolved?.[0]
    if (fieldFilter?.kind === 'select') {
      expect(fieldFilter.options()).toEqual([{ value: 'name', label: 'name' }])
      fields.push('type')
      expect(fieldFilter.options()).toEqual([
        { value: 'name', label: 'name' },
        { value: 'type', label: 'type' },
      ])
    }
    const period = resolved?.[1]
    if (period?.kind === 'select') {
      expect(period.anyLabel).toBe('All time')
      expect(period.options().map(({ value }) => value)).toEqual([
        'day',
        'week',
        'month',
      ])
    }
  })

  it('keeps the table and record preset in the initial viewport and on reset', () => {
    const { table, sent } = stubTable([57])
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
    expect(sent.at(-1)?.page).toBe(HilosPages.CHANGE_LOG_TABLE)
    expect(sent.at(-1)?.tableKey).toBe('hilosChangeLogHistory')
    expect(sent.at(-1)?.descriptor.filter).toEqual({
      table: 'bot',
      record: [57],
    })
    table.controller.setFilter('period', 'week')
    table.controller.resetFilters()
    expect(sent.at(-1)?.descriptor.filter).toEqual({
      table: 'bot',
      record: [57],
    })
    const withoutRecord = stubTable()
    withoutRecord.table.controller.ingestSubscriptionWindow(
      [],
      0,
      true,
      null,
      null,
      25,
      undefined,
      [],
    )
    expect(withoutRecord.sent.at(-1)?.descriptor.filter).toEqual({
      table: 'bot',
    })
  })
})
