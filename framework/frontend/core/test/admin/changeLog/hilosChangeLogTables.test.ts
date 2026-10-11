import { describe, expect, it } from 'vitest'

import { type HilosChangeLogContext } from '../../../src/admin/changeLog/hilosChangeLog.js'
import {
  CHANGE_LOG_TABLES_UNTRACKED_NOTE,
  createHilosChangeLogTablesTable,
  formatChangeLogColumnCounts,
  formatChangeLogTableChanges,
  formatChangeLogTableLastChange,
  formatChangeLogTableOwner,
  resolveHilosChangeLogTablesRow,
} from '../../../src/admin/changeLog/hilosChangeLogTables.js'
import {
  type HilosConnection,
  type TableViewportDescriptor,
} from '../../../src/connection/HilosConnection.js'
import { HilosPages } from '../../../src/routing/hilosPages.js'
import { type ScopeManager } from '../../../src/state/ScopeManager.js'
import { type TableRow } from '../../../src/state/TableRowsStore.js'

function rawRow(rowKey: string, slot: Record<string, unknown>): TableRow {
  return { rowKey, slots: { table: slot } }
}

function stubTable(): {
  table: ReturnType<typeof createHilosChangeLogTablesTable>
  sent: Array<{
    page: string
    tableKey: string
    descriptor: TableViewportDescriptor
  }>
  rendered: Array<{ page: string; tableKey: string; fields: readonly string[] }>
} {
  const sent: Array<{
    page: string
    tableKey: string
    descriptor: TableViewportDescriptor
  }> = []
  const rendered: Array<{
    page: string
    tableKey: string
    fields: readonly string[]
  }> = []
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
      sendTableRendered(
        page: string,
        tableKey: string,
        fields: readonly string[],
      ): boolean {
        rendered.push({ page, tableKey, fields })
        return true
      },
    } as unknown as HilosConnection,
    scopes: {} as ScopeManager,
  }
  return { table: createHilosChangeLogTablesTable(context), sent, rendered }
}

describe('change-log tables row', () => {
  it('resolves the table slot and takes its key from the fragment', () => {
    const row = resolveHilosChangeLogTablesRow(
      rawRow('bot', {
        rowKey: 'wrong-slot-key',
        name: 'bot',
        owner: 'project',
        journaled: true,
        columns: { value: 2, personal: 3, binary: 1, secret: 4, noise: 5 },
        changesLast24h: 7,
        lastChangeAt: '2026-10-09T10:11:12.123Z',
        changesSort: 7,
        lastChangeSort: '2026-10-09T10:11:12.123Z',
      }),
    )
    expect(row).toEqual({
      rowKey: 'bot',
      name: 'bot',
      owner: 'project',
      journaled: true,
      columns: { value: 2, personal: 3, binary: 1, secret: 4, noise: 5 },
      changesLast24h: 7,
      lastChangeAt: '2026-10-09T10:11:12.123Z',
    })
  })

  it('keeps null activity for an untracked framework table', () => {
    expect(
      resolveHilosChangeLogTablesRow(
        rawRow('hilos_session', {
          name: 'hilos_session',
          owner: 'framework',
          journaled: false,
          columns: null,
          changesLast24h: null,
          lastChangeAt: null,
        }),
      ),
    ).toEqual({
      rowKey: 'hilos_session',
      name: 'hilos_session',
      owner: 'framework',
      journaled: false,
      columns: null,
      changesLast24h: null,
      lastChangeAt: null,
    })
  })

  it('keeps zero activity for a tracked table without a write', () => {
    const row = resolveHilosChangeLogTablesRow(
      rawRow('hilos_setting', {
        name: 'hilos_setting',
        owner: 'framework',
        journaled: true,
        columns: { value: 1, personal: 0, binary: 0, secret: 0, noise: 0 },
        changesLast24h: 0,
        lastChangeAt: null,
      }),
    )
    expect(row.journaled).toBe(true)
    expect(row.changesLast24h).toBe(0)
    expect(row.lastChangeAt).toBeNull()
  })

  it.each([null, [], 'not counts', 2, {}])(
    'returns null for malformed column counts %j',
    (columns) => {
      expect(
        resolveHilosChangeLogTablesRow(rawRow('bot', { columns })).columns,
      ).toBeNull()
    },
  )

  it.each(['value', 'personal', 'binary', 'secret', 'noise'])(
    'requires a number for the %s mode',
    (field) => {
      const columns: Record<string, unknown> = {
        value: 1,
        personal: 2,
        binary: 3,
        secret: 4,
        noise: 5,
      }
      columns[field] = '1'
      expect(
        resolveHilosChangeLogTablesRow(rawRow('bot', { columns })).columns,
      ).toBeNull()
      delete columns[field]
      expect(
        resolveHilosChangeLogTablesRow(rawRow('bot', { columns })).columns,
      ).toBeNull()
    },
  )

  it('defaults unknown owners and malformed fields safely', () => {
    expect(
      resolveHilosChangeLogTablesRow(
        rawRow('bot', {
          name: 1,
          owner: 'unknown',
          journaled: 'true',
          changesLast24h: '3',
          lastChangeAt: 3,
        }),
      ),
    ).toEqual({
      rowKey: 'bot',
      name: '',
      owner: 'framework',
      journaled: false,
      columns: null,
      changesLast24h: null,
      lastChangeAt: null,
    })
    expect(
      resolveHilosChangeLogTablesRow({ rowKey: 'bot', slots: {} }),
    ).toEqual(resolveHilosChangeLogTablesRow(rawRow('bot', {})))
  })
})

describe('change-log table labels', () => {
  it('labels the owner and the untracked declaration', () => {
    expect(formatChangeLogTableOwner('framework')).toBe('Framework')
    expect(formatChangeLogTableOwner('project')).toBe('Project')
    expect(CHANGE_LOG_TABLES_UNTRACKED_NOTE).toBe(
      'No journal label — not written',
    )
  })

  it('keeps mode order and combines personal and binary columns as facts', () => {
    expect(
      formatChangeLogColumnCounts({
        value: 2,
        personal: 3,
        binary: 4,
        secret: 5,
        noise: 6,
      }),
    ).toBe('with value 2 · as fact 7 · noisy 6 · secret 5')
  })

  it('omits zero parts and counts binary-only columns as facts', () => {
    expect(
      formatChangeLogColumnCounts({
        value: 0,
        personal: 0,
        binary: 2,
        secret: 3,
        noise: 0,
      }),
    ).toBe('as fact 2 · secret 3')
    expect(
      formatChangeLogColumnCounts({
        value: 1,
        personal: 0,
        binary: 0,
        secret: 0,
        noise: 0,
      }),
    ).toBe('with value 1')
  })

  it('shows dashes for absent counts, all-zero modes and absent activity', () => {
    expect(formatChangeLogColumnCounts(null)).toBe('—')
    expect(
      formatChangeLogColumnCounts({
        value: 0,
        personal: 0,
        binary: 0,
        secret: 0,
        noise: 0,
      }),
    ).toBe('—')
    expect(formatChangeLogTableChanges(null)).toBe('—')
    expect(formatChangeLogTableChanges(0)).toBe('0')
    expect(formatChangeLogTableChanges(37)).toBe('37')
    expect(formatChangeLogTableLastChange(null)).toBe('—')
  })

  it('formats the last write in local date and time', () => {
    const at = '2026-10-09T10:11:12.123Z'
    expect(formatChangeLogTableLastChange(at)).toBe(
      new Date(at).toLocaleString(undefined, {
        dateStyle: 'medium',
        timeStyle: 'medium',
      }),
    )
  })
})

describe('change-log tables viewport', () => {
  it('declares the columns, reads, sort controls and journal filter', () => {
    const { table } = stubTable()
    const frame = table.controller.frame
    expect(frame.declaration?.title).toBe('Tables')
    expect(frame.declaration?.search?.placeholder).toBe('Table…')
    expect(frame.declaration?.empty).toBeUndefined()
    const columns = frame.columns.get()
    expect(columns.map(({ key }) => key)).toEqual([
      'name',
      'owner',
      'columns',
      'changesLast24h',
      'lastChangeAt',
      'actions',
    ])
    expect(columns.map(({ label }) => label)).toEqual([
      'Table',
      'Owner',
      'Journaled columns',
      'Last 24 hours',
      'Last change',
      '',
    ])
    expect(
      columns.filter(({ sortable }) => sortable).map(({ key }) => key),
    ).toEqual(['name', 'changesLast24h', 'lastChangeAt'])
    expect(columns[0]?.card).toBe('title')
    expect(columns[0]?.reads).toEqual(['journaled'])
    expect(columns[2]?.reads).toEqual(['journaled'])
    expect(columns[3]?.headerClass).toBe('text-end')
    expect(columns[3]?.cellClass).toBe('text-end')
    expect(columns[4]?.cellClass).toBe('text-nowrap')
    expect(columns[5]?.reads).toEqual(['journaled', 'name'])

    const filters = frame.declaration?.filters
    const resolved = typeof filters === 'function' ? filters() : filters
    expect(resolved).toHaveLength(1)
    const journal = resolved?.[0]
    expect(journal?.kind).toBe('select')
    if (journal?.kind === 'select') {
      expect(journal.key).toBe('journal')
      expect(journal.label).toBe('Journal label')
      expect(journal.anyLabel).toBe('All')
      expect(journal.options()).toEqual([
        { value: 'tracked', label: 'Tracked' },
        { value: 'untracked', label: 'Untracked' },
      ])
    }
  })

  it('takes its first window from the backend and addresses later requests to the tables page', () => {
    const { table, sent, rendered } = stubTable()
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
    expect(rendered).toEqual([
      {
        page: 'hilos_change_log_tables',
        tableKey: 'hilosChangeLogTables',
        fields: [
          'name',
          'journaled',
          'owner',
          'columns',
          'changesLast24h',
          'lastChangeAt',
        ],
      },
    ])
    table.controller.setFilter('journal', 'tracked')
    expect(sent.at(-1)?.page).toBe(HilosPages.CHANGE_LOG_TABLES)
    expect(sent.at(-1)?.tableKey).toBe('hilosChangeLogTables')
    expect(sent.at(-1)?.descriptor.filter).toEqual({ journal: 'tracked' })
    expect(sent.at(-1)?.descriptor.sort).toBeNull()
    expect(sent.at(-1)?.descriptor.limit).toBe(25)
    table.controller.setSort('changesLast24h')
    expect(sent.at(-1)?.descriptor.sort).toEqual([
      { field: 'changesLast24h', direction: 'asc' },
    ])
    table.controller.setSort('changesLast24h')
    expect(sent.at(-1)?.descriptor.sort).toEqual([
      { field: 'changesLast24h', direction: 'desc' },
    ])
    table.controller.resetFilters()
    expect(sent.at(-1)?.descriptor.filter).toEqual({})
  })
})
