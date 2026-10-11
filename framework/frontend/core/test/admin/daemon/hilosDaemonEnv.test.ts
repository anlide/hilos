import { describe, expect, it } from 'vitest'

import {
  createHilosDaemonEnvKeysTable,
  createHilosDaemonEnvOrphansTable,
  daemonEnvSourceBadge,
  daemonEnvTrouble,
  formatDaemonEnvDeclaredBy,
  formatDaemonEnvDrift,
  formatDaemonEnvRequired,
  formatDaemonEnvSimilarTo,
  formatDaemonEnvValue,
  resolveHilosDaemonEnvKeyRow,
  resolveHilosDaemonEnvOrphanRow,
  type HilosDaemonEnvContext,
  type HilosDaemonEnvKeyRow,
} from '../../../src/admin/daemon/hilosDaemonEnv.js'
import {
  type HilosConnection,
  type TableViewportDescriptor,
} from '../../../src/connection/HilosConnection.js'
import { HilosPages } from '../../../src/routing/hilosPages.js'
import { HIDDEN_VALUE } from '../../../src/state/hiddenValue.js'
import { type ScopeManager } from '../../../src/state/ScopeManager.js'
import { type TableRow } from '../../../src/state/TableRowsStore.js'

function rawRow(
  rowKey: string,
  slotName: string,
  slot: Record<string, unknown>,
): TableRow {
  return { rowKey, slots: { [slotName]: slot } }
}

function keyRow(
  overrides: Partial<HilosDaemonEnvKeyRow> = {},
): HilosDaemonEnvKeyRow {
  return {
    rowKey: 'APP_ENV',
    key: 'APP_ENV',
    type: 'string',
    required: true,
    declaredBy: 'framework',
    sensitive: false,
    perNode: false,
    source: 'process',
    value: { kind: 'text', text: 'production' },
    disk: null,
    ...overrides,
  }
}

function stubContext(): {
  context: HilosDaemonEnvContext
  sent: Array<{
    page: string
    tableKey: string
    descriptor: TableViewportDescriptor
  }>
} {
  const sent: Array<{
    page: string
    tableKey: string
    descriptor: TableViewportDescriptor
  }> = []
  const context: HilosDaemonEnvContext = {
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
  return { context, sent }
}

describe('daemon environment rows', () => {
  it('reads catalog fields, all value forms, a disk value and fragment identity', () => {
    expect(
      resolveHilosDaemonEnvKeyRow(
        rawRow('APP_ENV', 'key', {
          rowKey: 'wrong-slot-key',
          key: 'APP_ENV',
          type: 'boolean',
          required: true,
          declaredBy: 'project',
          sensitive: true,
          perNode: true,
          source: 'env',
          value: { kind: 'text', text: 'yes' },
          disk: { source: 'example', value: { kind: 'secret', length: 7 } },
          valueText: 'server-only',
        }),
      ),
    ).toEqual(
      keyRow({
        type: 'boolean',
        declaredBy: 'project',
        sensitive: true,
        perNode: true,
        source: 'env',
        value: { kind: 'text', text: 'yes' },
        disk: { source: 'example', value: { kind: 'secret', length: 7 } },
      }),
    )
    expect(
      resolveHilosDaemonEnvKeyRow(
        rawRow('NO_VALUE', 'key', {
          key: 'NO_VALUE',
          source: 'unrecognized',
          value: { kind: 'none' },
          disk: null,
        }),
      ),
    ).toMatchObject({
      rowKey: 'NO_VALUE',
      source: 'missing',
      value: { kind: 'none' },
      disk: null,
    })
  })

  it('preserves hidden marks and turns malformed values into none', () => {
    const hidden = resolveHilosDaemonEnvKeyRow(
      rawRow('SECRET', 'key', {
        value: { _hidden: true },
        disk: { source: 'unknown', value: { kind: 'text', text: 42 } },
      }),
    )
    expect(hidden.value).toBe(HIDDEN_VALUE)
    expect(hidden.disk).toEqual({ source: 'missing', value: { kind: 'none' } })
    expect(
      resolveHilosDaemonEnvKeyRow(
        rawRow('BROKEN', 'key', { value: { kind: 'secret', length: '7' } }),
      ).value,
    ).toEqual({ kind: 'none' })
  })

  it('reads orphan secrets and hidden values without using the slot rowKey', () => {
    expect(
      resolveHilosDaemonEnvOrphanRow(
        rawRow('APP_EN', 'orphan', {
          rowKey: 'wrong-slot-key',
          key: 'APP_EN',
          value: { kind: 'secret', length: 6 },
          similarTo: 'APP_ENV',
        }),
      ),
    ).toEqual({
      rowKey: 'APP_EN',
      key: 'APP_EN',
      value: { kind: 'secret', length: 6 },
      similarTo: 'APP_ENV',
    })
    const hidden = resolveHilosDaemonEnvOrphanRow(
      rawRow('UNKNOWN', 'orphan', {
        key: 'UNKNOWN',
        value: { _hidden: true },
        similarTo: null,
      }),
    )
    expect(hidden.value).toBe(HIDDEN_VALUE)
    expect(hidden.similarTo).toBeNull()
    expect(
      resolveHilosDaemonEnvOrphanRow(
        rawRow('BROKEN', 'orphan', { value: { kind: 'text', text: 42 } }),
      ).value,
    ).toEqual({ kind: 'none' })
  })
})

describe('daemon environment viewports', () => {
  it('requests each node-scoped table without a sort', () => {
    const { context, sent } = stubContext()
    const keys = createHilosDaemonEnvKeysTable(context, 'standalone')
    const orphans = createHilosDaemonEnvOrphansTable(context, 'node-2')
    expect(keys.controller.descriptor()).toBeNull()
    expect(orphans.controller.descriptor()).toBeNull()

    keys.controller.ingestSubscriptionWindow(
      [],
      0,
      true,
      null,
      null,
      25,
      undefined,
      [],
    )
    orphans.controller.ingestSubscriptionWindow(
      [],
      0,
      true,
      null,
      null,
      25,
      undefined,
      [],
    )

    expect(sent).toMatchObject([
      {
        page: HilosPages.DAEMON_ENV,
        tableKey: 'hilosDaemonEnvKeys',
        descriptor: { filter: { node: 'standalone' }, sort: null },
      },
      {
        page: HilosPages.DAEMON_ENV,
        tableKey: 'hilosDaemonEnvOrphans',
        descriptor: { filter: { node: 'node-2' }, sort: null },
      },
    ])
  })

  it('declares search, attention and five unsorted catalog columns with dependencies', () => {
    const frame = createHilosDaemonEnvKeysTable(stubContext().context, 'n1')
      .controller.frame
    expect(frame.declaration?.title).toBe('Catalog keys')
    expect(frame.searchPlaceholder.get()).toBe('Key or value')
    expect(frame.declaration?.filters).toEqual([
      {
        kind: 'toggle',
        key: 'attention',
        label: 'Needs attention only',
        on: true,
      },
    ])
    const columns = frame.columns.get()
    expect(columns.map(({ key, label }) => [key, label])).toEqual([
      ['key', 'Key'],
      ['type', 'Type'],
      ['value', 'Value and source'],
      ['required', 'Required'],
      ['declaredBy', 'Declared by'],
    ])
    expect(columns[0]?.card).toBe('title')
    expect(columns[0]?.reads).toEqual(['perNode', 'sensitive'])
    expect(columns[2]?.reads).toEqual(['source', 'sensitive', 'type', 'disk'])
    expect(columns.every((column) => column.sortable !== true)).toBe(true)
  })

  it('declares three unsorted orphan columns without search or filters', () => {
    const frame = createHilosDaemonEnvOrphansTable(stubContext().context, 'n1')
      .controller.frame
    expect(frame.declaration?.title).toBe('Orphans in .env')
    expect(frame.declaration?.search).toBeUndefined()
    expect(frame.declaration?.filters).toBeUndefined()
    expect(frame.columns.get().map(({ key, label }) => [key, label])).toEqual([
      ['key', 'Line in file'],
      ['value', 'Value'],
      ['similarTo', 'Looks like'],
    ])
    expect(frame.columns.get()[0]?.card).toBe('title')
    expect(
      frame.columns.get().every((column) => column.sortable !== true),
    ).toBe(true)
  })
})

describe('daemon environment cell text', () => {
  it('formats hidden, set and unset secrets, missing and empty values', () => {
    expect(formatDaemonEnvValue(HIDDEN_VALUE, 'string', true)).toEqual({
      kind: 'hidden',
    })
    expect(
      formatDaemonEnvValue({ kind: 'secret', length: 4 }, 'string', true),
    ).toEqual({
      kind: 'secret',
      text: 'Set · 4 chars',
    })
    expect(formatDaemonEnvValue({ kind: 'none' }, 'string', true)).toEqual({
      kind: 'secret',
      text: 'Not set',
    })
    expect(formatDaemonEnvValue({ kind: 'none' }, 'string', false)).toEqual({
      kind: 'none',
      text: '—',
    })
    expect(
      formatDaemonEnvValue({ kind: 'text', text: '' }, 'string', false),
    ).toEqual({
      kind: 'empty',
    })
  })

  it('recognizes boolean spellings and keeps an unrecognized one as text', () => {
    for (const text of ['1', ' TRUE ', 'Yes', 'on']) {
      expect(
        formatDaemonEnvValue({ kind: 'text', text }, 'boolean', false),
      ).toEqual({
        kind: 'boolean',
        on: true,
      })
    }
    for (const text of ['0', ' FALSE ', 'No', 'off']) {
      expect(
        formatDaemonEnvValue({ kind: 'text', text }, 'boolean', false),
      ).toEqual({
        kind: 'boolean',
        on: false,
      })
    }
    expect(
      formatDaemonEnvValue({ kind: 'text', text: 'maybe' }, 'boolean', false),
    ).toEqual({
      kind: 'text',
      text: 'maybe',
      numeric: false,
    })
  })

  it('marks integer and float text numeric without changing their spelling', () => {
    expect(
      formatDaemonEnvValue({ kind: 'text', text: '007' }, 'integer', false),
    ).toEqual({
      kind: 'text',
      text: '007',
      numeric: true,
    })
    expect(
      formatDaemonEnvValue({ kind: 'text', text: '1.20' }, 'float', false),
    ).toEqual({
      kind: 'text',
      text: '1.20',
      numeric: true,
    })
    expect(
      formatDaemonEnvValue({ kind: 'text', text: 'abc' }, 'string', false),
    ).toEqual({
      kind: 'text',
      text: 'abc',
      numeric: false,
    })
  })

  it('names all five source badges with their tones and hints', () => {
    expect(daemonEnvSourceBadge('process')).toEqual({
      label: 'Launch environment',
      tone: 'primary',
      hint: 'Set by whoever started the daemon: compose, systemd, a shell. .env does not override it.',
    })
    expect(daemonEnvSourceBadge('env')).toEqual({
      label: '.env',
      tone: 'success',
      hint: 'Set in the .env file next to the project.',
    })
    expect(daemonEnvSourceBadge('example')).toEqual({
      label: '.env.example',
      tone: 'warning',
      hint: 'Came from the sample file: a placeholder, not a setting.',
    })
    expect(daemonEnvSourceBadge('default')).toEqual({
      label: 'Catalog default',
      tone: 'secondary',
      hint: 'No value anywhere; the default declared in the catalog is read.',
    })
    expect(daemonEnvSourceBadge('missing')).toEqual({
      label: 'No value',
      tone: 'danger',
      hint: 'Not in the launch environment, not in the files, and no default.',
    })
  })

  it('formats disk drift and picks trouble in priority order', () => {
    expect(formatDaemonEnvDrift(keyRow())).toBeNull()
    expect(
      formatDaemonEnvDrift(
        keyRow({
          disk: { source: 'env', value: { kind: 'text', text: 'new' } },
        }),
      ),
    ).toBe('On disk: new · .env')
    expect(
      formatDaemonEnvDrift(
        keyRow({
          disk: { source: 'example', value: { kind: 'text', text: '' } },
        }),
      ),
    ).toBe('On disk: empty · .env.example')
    expect(
      formatDaemonEnvDrift(
        keyRow({
          disk: { source: 'env', value: { kind: 'secret', length: 8 } },
        }),
      ),
    ).toBe('On disk: set · 8 chars · .env')
    expect(
      formatDaemonEnvDrift(
        keyRow({
          disk: { source: 'missing', value: { kind: 'none' } },
        }),
      ),
    ).toBe('On disk: no value · No value')

    const disk = { source: 'env' as const, value: { kind: 'none' as const } }
    expect(daemonEnvTrouble(keyRow({ source: 'missing', disk }))).toBe(
      'missing',
    )
    expect(daemonEnvTrouble(keyRow({ source: 'example', disk }))).toBe(
      'example',
    )
    expect(daemonEnvTrouble(keyRow({ disk }))).toBe('drift')
    expect(daemonEnvTrouble(keyRow())).toBeNull()
    expect(
      daemonEnvTrouble(keyRow({ required: false, source: 'missing' })),
    ).toBeNull()
  })

  it('formats requirement, declarer and similarity', () => {
    expect(formatDaemonEnvRequired(keyRow())).toBe('Required')
    expect(formatDaemonEnvRequired(keyRow({ required: false }))).toBe(
      'Optional',
    )
    expect(formatDaemonEnvDeclaredBy(keyRow())).toBe('Framework')
    expect(formatDaemonEnvDeclaredBy(keyRow({ declaredBy: 'project' }))).toBe(
      'Project',
    )
    expect(formatDaemonEnvDeclaredBy(keyRow({ declaredBy: 'unknown' }))).toBe(
      'unknown',
    )
    expect(
      formatDaemonEnvSimilarTo({
        rowKey: 'APP_EN',
        key: 'APP_EN',
        value: { kind: 'none' },
        similarTo: 'APP_ENV',
      }),
    ).toBe('APP_ENV')
    expect(
      formatDaemonEnvSimilarTo({
        rowKey: 'OTHER',
        key: 'OTHER',
        value: HIDDEN_VALUE,
        similarTo: null,
      }),
    ).toBe('—')
  })
})
