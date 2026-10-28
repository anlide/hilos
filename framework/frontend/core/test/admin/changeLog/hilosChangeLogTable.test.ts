import { describe, expect, it } from 'vitest'

import { type HilosChangeLogHistoryRow } from '../../../src/admin/changeLog/hilosChangeLogHistory.js'
import {
  CHANGE_LOG_RECORD_ROUTE_PARAM,
  CHANGE_LOG_TABLE_ROUTE_PARAM,
  CHANGE_LOG_TABLE_SECTION,
  changeLogColumnModeCard,
  changeLogTableColumnCounts,
  changeLogTableJournalSince,
  changeLogRecordRouteParam,
  formatChangeLogColumnStorage,
  formatChangeLogHistoryChanges,
  formatChangeLogTrigger,
  readChangeLogRecordRouteParam,
  readHilosChangeLogTable,
  type HilosChangeLogTableColumn,
  type HilosChangeLogTableDetail,
  type HilosChangeLogTableTrigger,
} from '../../../src/admin/changeLog/hilosChangeLogTable.js'
import { formatChangeLogColumnCounts } from '../../../src/admin/changeLog/hilosChangeLogTables.js'
import {
  HilosPages,
  HILOS_ROUTE_DECLARATIONS,
} from '../../../src/routing/hilosPages.js'
import { createPageRouter } from '../../../src/routing/PageRouter.js'

const nameColumn: HilosChangeLogTableColumn = {
  name: 'name',
  sqlType: 'varchar',
  mode: 'value',
  storage: 'inline',
  noiseReason: null,
}

const insertTrigger: HilosChangeLogTableTrigger = {
  name: 'hilos_cl_bot_after_insert',
  event: 'INSERT',
  table: 'bot',
  validFromMigration: 71,
}

const journaled: HilosChangeLogTableDetail = {
  name: 'bot',
  owner: 'project',
  journaled: true,
  changesLast24h: 0,
  lastChangeAt: '2026-10-15T09:10:11.123Z',
  columns: [
    {
      name: 'id',
      sqlType: 'int',
      mode: 'record_key',
      storage: null,
      noiseReason: null,
    },
    nameColumn,
    {
      name: 'last_activity',
      sqlType: 'datetime',
      mode: 'noise',
      storage: 'ignored',
      noiseReason: 'Automatic activity stamp',
    },
  ],
  triggers: [insertTrigger],
}

const untracked: HilosChangeLogTableDetail = {
  name: 'hilos_cache',
  owner: 'framework',
  journaled: false,
  changesLast24h: null,
  lastChangeAt: null,
  columns: [
    {
      name: 'payload',
      sqlType: 'longtext',
      mode: null,
      storage: null,
      noiseReason: null,
    },
  ],
  triggers: [],
}

function valueColumn(
  sqlType: string,
  storage: HilosChangeLogTableColumn['storage'] = 'inline',
): HilosChangeLogTableColumn {
  return { name: 'c', sqlType, mode: 'value', storage, noiseReason: null }
}

function historyRow(
  overrides: Partial<HilosChangeLogHistoryRow>,
): HilosChangeLogHistoryRow {
  return {
    rowKey: 1,
    createdAt: '2026-10-15T09:10:11.123Z',
    recordKey: [57],
    mutation: 'update',
    changes: [],
    receiptId: 7,
    actorId: null,
    actorLabel: null,
    actorDeleted: false,
    subjectId: null,
    subjectLabel: null,
    subjectDeleted: false,
    channel: null,
    ...overrides,
  }
}

describe('change-log table section', () => {
  it('names its section and route params', () => {
    expect(CHANGE_LOG_TABLE_SECTION).toBe('changeLogTable')
    expect(CHANGE_LOG_TABLE_ROUTE_PARAM).toBe('tableId')
    expect(CHANGE_LOG_RECORD_ROUTE_PARAM).toBe('record')
  })

  it('reads a journaled and an untracked table as sent', () => {
    expect(readHilosChangeLogTable(journaled)).toEqual(journaled)
    expect(readHilosChangeLogTable(untracked)).toEqual(untracked)
  })

  it('reads a damaged section as null', () => {
    expect(readHilosChangeLogTable(null)).toBeNull()
    expect(readHilosChangeLogTable([])).toBeNull()
    expect(readHilosChangeLogTable({ ...journaled, name: '' })).toBeNull()
    expect(
      readHilosChangeLogTable({ ...journaled, owner: 'vendor' }),
    ).toBeNull()
    expect(
      readHilosChangeLogTable({ ...journaled, changesLast24h: 1.5 }),
    ).toBeNull()
    expect(
      readHilosChangeLogTable({
        ...journaled,
        columns: [{ ...nameColumn, mode: 'trigger' }],
      }),
    ).toBeNull()
    expect(
      readHilosChangeLogTable({
        ...journaled,
        columns: [{ ...nameColumn, storage: 'blob' }],
      }),
    ).toBeNull()
    expect(
      readHilosChangeLogTable({
        ...journaled,
        triggers: [{ ...insertTrigger, event: 'REPLACE' }],
      }),
    ).toBeNull()
    expect(
      readHilosChangeLogTable({ ...journaled, triggers: undefined }),
    ).toBeNull()
  })

  it('finds the earliest trigger migration independently of declaration order', () => {
    const detail = {
      ...journaled,
      triggers: [
        { ...insertTrigger, validFromMigration: 82 },
        { ...insertTrigger, event: 'UPDATE' as const, validFromMigration: 9 },
        { ...insertTrigger, event: 'DELETE' as const, validFromMigration: 71 },
      ],
    }
    expect(changeLogTableJournalSince(detail)).toBe(9)
    expect(
      detail.triggers.map(({ validFromMigration }) => validFromMigration),
    ).toEqual([82, 9, 71])
    expect(changeLogTableJournalSince(journaled)).toBe(71)
    expect(
      changeLogTableJournalSince({ ...journaled, triggers: [] }),
    ).toBeNull()
    expect(changeLogTableJournalSince(untracked)).toBeNull()
  })

  it('counts each journal mode and lets the formatter combine personal and binary as facts', () => {
    const modes: HilosChangeLogTableColumn['mode'][] = [
      'record_key',
      'value',
      'value',
      'personal',
      'binary',
      'binary',
      'noise',
      'secret',
      null,
    ]
    const detail = {
      ...journaled,
      columns: modes.map((mode, index) => ({
        ...nameColumn,
        name: `c${index}`,
        mode,
      })),
    }
    const before = structuredClone(detail)
    const counts = changeLogTableColumnCounts(detail)
    expect(counts).toEqual({
      value: 2,
      personal: 1,
      binary: 2,
      secret: 1,
      noise: 1,
    })
    expect(formatChangeLogColumnCounts(counts)).toBe(
      'with value 2 · as fact 3 · noisy 1 · secret 1',
    )
    expect(detail.columns).toHaveLength(9)
    expect(detail).toEqual(before)
  })

  it('returns zero counts for empty, untracked and record-key-only declarations', () => {
    for (const detail of [
      { ...journaled, columns: [] },
      untracked,
      { ...journaled, columns: [journaled.columns[0]!] },
    ]) {
      expect(changeLogTableColumnCounts(detail)).toEqual({
        value: 0,
        personal: 0,
        binary: 0,
        secret: 0,
        noise: 0,
      })
      expect(
        formatChangeLogColumnCounts(changeLogTableColumnCounts(detail)),
      ).toBe('—')
    }
  })
})

describe('change-log record route param', () => {
  const router = createPageRouter(HILOS_ROUTE_DECLARATIONS)

  function roundTrip(key: readonly (number | string)[]): {
    path: string | undefined
    record: string[] | null
  } {
    const path = router.path(HilosPages.CHANGE_LOG_TABLE_HISTORY, {
      tableId: 'bot',
      record: changeLogRecordRouteParam(key),
    })
    const match = router.match(path ?? '')
    expect(match.page).toBe(HilosPages.CHANGE_LOG_TABLE_HISTORY)
    expect(match.params.tableId).toBe('bot')
    return {
      path,
      record: readChangeLogRecordRouteParam(match.params.record),
    }
  }

  it('addresses a numeric record in the example form', () => {
    expect(roundTrip([57])).toEqual({
      path: '/hilos/change-log/tables/bot/history/57',
      record: ['57'],
    })
  })

  it('carries a string with a comma and a percent sign back whole', () => {
    expect(changeLogRecordRouteParam(['a,b%c'])).toBe('a%2Cb%25c')
    expect(roundTrip(['a,b%c']).record).toEqual(['a,b%c'])
  })

  it('joins the parts of a composite key with a comma', () => {
    expect(changeLogRecordRouteParam([57, 'en'])).toBe('57,en')
    expect(roundTrip([57, 'en,US']).record).toEqual(['57', 'en,US'])
  })

  it('opens the table without a record when the tail is absent', () => {
    const match = router.match('/hilos/change-log/tables/bot')
    expect(match).toEqual({
      page: HilosPages.CHANGE_LOG_TABLE,
      params: { tableId: 'bot' },
      admin: true,
    })
    expect(readChangeLogRecordRouteParam(match.params.record)).toBeNull()
    expect(readChangeLogRecordRouteParam('')).toBeNull()
  })

  it('keeps a part that cannot be decoded as it came', () => {
    expect(readChangeLogRecordRouteParam('%E0%A4%A,57')).toEqual([
      '%E0%A4%A',
      '57',
    ])
  })
})

describe('change-log column formatting', () => {
  it('names value columns by their SQL type', () => {
    const expected: ReadonlyArray<readonly [string, string]> = [
      ['tinyint', 'Yes / no'],
      ['smallint', 'Number'],
      ['mediumint', 'Number'],
      ['int', 'Number'],
      ['bigint', 'Number'],
      ['decimal', 'Number'],
      ['float', 'Number'],
      ['double', 'Number'],
      ['char', 'String'],
      ['varchar', 'String'],
      ['date', 'Date'],
      ['datetime', 'Date and time'],
      ['timestamp', 'Date and time'],
      ['time', 'Time'],
      ['year', 'Year'],
      ['enum', 'Choice'],
      ['set', 'Choice'],
      ['bit', 'bit'],
    ]
    for (const [sqlType, label] of expected) {
      expect(formatChangeLogColumnStorage(valueColumn(sqlType))).toBe(label)
    }
  })

  it('names long storage before the type and dashes non-value columns', () => {
    expect(formatChangeLogColumnStorage(valueColumn('text', 'long'))).toBe(
      'Long text',
    )
    expect(formatChangeLogColumnStorage(valueColumn('varchar', 'long'))).toBe(
      'Long text',
    )
    for (const column of [...journaled.columns, ...untracked.columns]) {
      if (column.mode !== 'value') {
        expect(formatChangeLogColumnStorage(column)).toBe('—')
      }
    }
    expect(
      formatChangeLogColumnStorage({
        ...valueColumn('varchar'),
        mode: 'personal',
        storage: 'fact',
      }),
    ).toBe('—')
  })

  it('finds the mode card by key, and none for an untracked table', () => {
    expect(changeLogColumnModeCard('personal')?.label).toBe(
      'Personal — only a fact',
    )
    expect(changeLogColumnModeCard('record_key')?.icon).toBe('bi-key')
    expect(changeLogColumnModeCard(null)).toBeNull()
  })
})

describe('change-log trigger formatting', () => {
  it('describes a service trigger by its interface', () => {
    expect(formatChangeLogTrigger(insertTrigger)).toEqual({
      fires: 'AFTER INSERT on bot',
      writesTo: 'Journal',
      validFrom: 'from migration 071',
    })
    expect(
      formatChangeLogTrigger({
        name: 'hilos_cl_bot_after_delete',
        event: 'DELETE',
        table: 'bot',
        validFromMigration: 1204,
      }),
    ).toEqual({
      fires: 'AFTER DELETE on bot',
      writesTo: 'Journal',
      validFrom: 'from migration 1204',
    })
  })
})

describe('change-log history changes cell', () => {
  it('draws one line per updated field', () => {
    const row = historyRow({
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
          field: 'note',
          kind: 'inline',
          oldPresent: true,
          newPresent: true,
          oldValue: null,
          newValue: '',
          bodyOmitted: false,
        },
        {
          field: 'email',
          kind: 'fact',
          oldPresent: false,
          newPresent: false,
          oldValue: null,
          newValue: null,
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
    })
    expect(formatChangeLogHistoryChanges(row)).toEqual([
      'name: before → after',
      'note: NULL → Empty string',
      'email: Personal — no value',
      'body: Value omitted',
    ])
  })

  it('names a creation as a fact', () => {
    expect(
      formatChangeLogHistoryChanges(historyRow({ mutation: 'create' })),
    ).toEqual(['Created as a fact'])
  })

  it('sends a deletion to its receipt, if there is one', () => {
    expect(
      formatChangeLogHistoryChanges(historyRow({ mutation: 'delete' })),
    ).toEqual(['Row snapshot — in the receipt'])
    expect(
      formatChangeLogHistoryChanges(
        historyRow({ mutation: 'delete', receiptId: null }),
      ),
    ).toEqual(['Row snapshot'])
  })
})
