import { describe, expect, it } from 'vitest'

import {
  createHilosDaemonEnvKeysTable,
  createHilosDaemonEnvOrphansTable,
  DAEMON_ENV_ENVIRONMENT_DATA,
  DAEMON_ENV_LADDER,
  DAEMON_ENV_NODE_SILENT_DATA,
  daemonEnvNodeHeading,
  daemonEnvSourceBadge,
  daemonEnvSummary,
  daemonEnvTrouble,
  formatDaemonEnvDeclaredBy,
  formatDaemonEnvDrift,
  formatDaemonEnvDriftNotice,
  formatDaemonEnvRequired,
  formatDaemonEnvSimilarTo,
  formatDaemonEnvValue,
  readHilosDaemonEnvOrphansPage,
  readHilosDaemonEnvPage,
  resolveHilosDaemonEnvKeyRow,
  resolveHilosDaemonEnvOrphanRow,
  type HilosDaemonEnvContext,
  type HilosDaemonEnvKeyRow,
} from '../../../src/admin/daemon/hilosDaemonEnv.js'
import { DAEMON_NODE_STATE_SILENT } from '../../../src/admin/daemon/hilosDaemonNode.js'
import {
  type HilosConnection,
  type TableViewportDescriptor,
} from '../../../src/connection/HilosConnection.js'
import { HilosPages } from '../../../src/routing/hilosPages.js'
import { HIDDEN_VALUE } from '../../../src/state/hiddenValue.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'
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
        page: HilosPages.DAEMON_ENV_ORPHANS,
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
    expect(frame.declaration?.filteredEmpty).toBe('page')
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
    expect(frame.declaration?.title).toBeUndefined()
    expect(frame.declaration?.subtitle).toBeUndefined()
    expect(frame.declaration?.search).toBeUndefined()
    expect(frame.declaration?.filters).toBeUndefined()
    expect(frame.declaration?.mainAction).toBeUndefined()
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

  it('binds orphans to the orphans page, drops a late environment frame, and takes the first window', () => {
    const scopes = new ScopeManager()
    scopes.openPage(HilosPages.DAEMON_ENV_ORPHANS)
    const listeners = new Map<string, Set<(payload: never) => void>>()
    const registered: string[] = []
    const unregistered: string[] = []
    const rendered: string[] = []
    const sent: string[] = []
    const connection = {
      on(event: string, listener: (payload: never) => void) {
        const group = listeners.get(event) ?? new Set()
        group.add(listener)
        listeners.set(event, group)
        return () => {
          group.delete(listener)
        }
      },
      registerTableWindow(tableKey: string) {
        registered.push(tableKey)
      },
      unregisterTableWindow(tableKey: string) {
        unregistered.push(tableKey)
      },
      tableWindowDescriptors: () => ({}),
      sendTableViewport(page: string) {
        sent.push(page)
        return true
      },
      sendTableRendered(page: string) {
        rendered.push(page)
        return true
      },
    } as unknown as HilosConnection
    const orphans = createHilosDaemonEnvOrphansTable(
      { connection, scopes },
      'n1',
    )
    orphans.start()
    expect(registered).toEqual(['hilosDaemonEnvOrphans'])
    const frame = {
      data: {
        page: '',
        tableKey: 'hilosDaemonEnvOrphans',
        rows: [
          {
            rowKey: 'RAW',
            slots: {
              orphan: {
                key: 'RAW',
                value: { kind: 'secret', length: 4 },
                similarTo: null,
              },
            },
          },
        ],
        totalCount: 1,
        totalExact: true,
        limit: 25,
        sort: null,
        firstAnchor: null,
        lastAnchor: null,
        rowsBefore: 0,
      },
    }
    const emit = (page: string) => {
      frame.data.page = page
      for (const listener of listeners.get('projectSignal') ?? []) {
        listener({
          type: 'page_response',
          data: {
            page,
            payload: {
              windows: { hilosDaemonEnvOrphans: frame.data },
            },
          },
        } as never)
      }
      for (const listener of listeners.get('tableWindow') ?? []) {
        listener(frame as never)
      }
    }
    emit(HilosPages.DAEMON_ENV)
    expect(orphans.controller.rows.get()).toEqual([])
    emit(HilosPages.DAEMON_ENV_ORPHANS)
    expect(orphans.controller.rows.get()[0]?.rowKey).toBe('RAW')
    expect(sent.every((page) => page === HilosPages.DAEMON_ENV_ORPHANS)).toBe(
      true,
    )
    expect(
      rendered.every((page) => page === HilosPages.DAEMON_ENV_ORPHANS),
    ).toBe(true)
    orphans.dispose()
    expect(unregistered).toEqual(['hilosDaemonEnvOrphans'])
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

describe('readHilosDaemonEnvOrphansPage', () => {
  it('reads the heading and silence and ignores an environment catalog', () => {
    const scopes = new ScopeManager()
    const page = scopes.openPage(HilosPages.DAEMON_ENV_ORPHANS)
    const state = readHilosDaemonEnvOrphansPage(scopes)
    expect(state.get()).toEqual({ node: null, nodeSilent: false })
    expect(state.get()).not.toHaveProperty('environment')
    const node = {
      clustered: false,
      state: null,
      silentSince: null,
      role: null,
    }
    page.data.set('node', node)
    page.data.set(DAEMON_ENV_NODE_SILENT_DATA, true)
    page.data.set(DAEMON_ENV_ENVIRONMENT_DATA, {
      nodeId: 'n1',
      cluster: false,
      keys: [],
    })
    expect(state.get()).toEqual({ node, nodeSilent: true })
  })
})

describe('readHilosDaemonEnvPage', () => {
  it('reads live node response with parsed keys, rowKey = key, all value forms and hidden marks', () => {
    const scopes = new ScopeManager()
    const page = scopes.openPage(HilosPages.DAEMON_ENV)
    const stateSignal = readHilosDaemonEnvPage(scopes)

    expect(stateSignal.get()).toEqual({
      node: null,
      environment: null,
      nodeSilent: false,
    })

    page.data.set(DAEMON_ENV_ENVIRONMENT_DATA, {
      nodeId: 'node-alpha',
      cluster: true,
      readAt: 1_700_000_000,
      keys: [
        {
          key: 'APP_ENV',
          type: 'string',
          required: true,
          declaredBy: 'framework',
          sensitive: false,
          perNode: false,
          source: 'process',
          value: { kind: 'text', text: 'production' },
          disk: null,
        },
        {
          key: 'APP_SECRET',
          type: 'string',
          required: true,
          declaredBy: 'framework',
          sensitive: true,
          perNode: false,
          source: 'env',
          value: { kind: 'secret', length: 32 },
          disk: {
            source: 'example',
            value: { kind: 'secret', length: 16 },
          },
        },
        {
          key: 'UNSET_VAR',
          type: 'string',
          required: false,
          declaredBy: 'project',
          sensitive: false,
          perNode: true,
          source: 'missing',
          value: { kind: 'none' },
          disk: null,
        },
        {
          key: 'VIEWER_HIDDEN',
          type: 'string',
          required: true,
          declaredBy: 'framework',
          sensitive: false,
          perNode: false,
          source: 'default',
          value: { _hidden: true },
          disk: null,
        },
      ],
      orphans: [{ key: 'OLD_KEY', value: { kind: 'text', text: 'val' } }],
    })
    page.data.set(DAEMON_ENV_NODE_SILENT_DATA, false)

    const state = stateSignal.get()
    expect(state.nodeSilent).toBe(false)
    expect(state.environment).not.toBeNull()
    expect(state.environment?.nodeId).toBe('node-alpha')
    expect(state.environment?.cluster).toBe(true)
    expect(state.environment?.keys).toHaveLength(4)

    const keys = state.environment!.keys
    expect(keys[0]).toEqual({
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
    })
    expect(keys[1]).toEqual({
      rowKey: 'APP_SECRET',
      key: 'APP_SECRET',
      type: 'string',
      required: true,
      declaredBy: 'framework',
      sensitive: true,
      perNode: false,
      source: 'env',
      value: { kind: 'secret', length: 32 },
      disk: {
        source: 'example',
        value: { kind: 'secret', length: 16 },
      },
    })
    expect(keys[2]).toEqual({
      rowKey: 'UNSET_VAR',
      key: 'UNSET_VAR',
      type: 'string',
      required: false,
      declaredBy: 'project',
      sensitive: false,
      perNode: true,
      source: 'missing',
      value: { kind: 'none' },
      disk: null,
    })
    expect(keys[3]).toEqual({
      rowKey: 'VIEWER_HIDDEN',
      key: 'VIEWER_HIDDEN',
      type: 'string',
      required: true,
      declaredBy: 'framework',
      sensitive: false,
      perNode: false,
      source: 'default',
      value: HIDDEN_VALUE,
      disk: null,
    })
  })

  it('reads nodeSilent flag when node is silent', () => {
    const scopes = new ScopeManager()
    const page = scopes.openPage(HilosPages.DAEMON_ENV)
    const stateSignal = readHilosDaemonEnvPage(scopes)

    page.data.set(DAEMON_ENV_ENVIRONMENT_DATA, null)
    page.data.set(DAEMON_ENV_NODE_SILENT_DATA, true)

    expect(stateSignal.get()).toEqual({
      node: null,
      environment: null,
      nodeSilent: true,
    })
  })

  it('returns null environment when payload is invalid', () => {
    const scopes = new ScopeManager()
    const page = scopes.openPage(HilosPages.DAEMON_ENV)
    const stateSignal = readHilosDaemonEnvPage(scopes)

    page.data.set(DAEMON_ENV_ENVIRONMENT_DATA, 'not-an-object')
    expect(stateSignal.get().environment).toBeNull()

    page.data.set(DAEMON_ENV_ENVIRONMENT_DATA, { nodeId: 123 })
    expect(stateSignal.get().environment).toBeNull()

    page.data.set(DAEMON_ENV_ENVIRONMENT_DATA, {
      nodeId: 'n1',
      cluster: 'not-bool',
      keys: [],
    })
    expect(stateSignal.get().environment).toBeNull()
  })

  it('updates state reactively when page data changes entirely', () => {
    const scopes = new ScopeManager()
    const page = scopes.openPage(HilosPages.DAEMON_ENV)
    const stateSignal = readHilosDaemonEnvPage(scopes)

    page.data.set(DAEMON_ENV_ENVIRONMENT_DATA, {
      nodeId: 'node-1',
      cluster: true,
      keys: [],
    })
    page.data.set(DAEMON_ENV_NODE_SILENT_DATA, false)
    expect(stateSignal.get()).toEqual({
      node: null,
      environment: { nodeId: 'node-1', cluster: true, keys: [] },
      nodeSilent: false,
    })

    page.data.set(DAEMON_ENV_ENVIRONMENT_DATA, {
      nodeId: 'node-2',
      cluster: false,
      keys: [],
    })
    page.data.set(DAEMON_ENV_NODE_SILENT_DATA, false)
    expect(stateSignal.get()).toEqual({
      node: null,
      environment: { nodeId: 'node-2', cluster: false, keys: [] },
      nodeSilent: false,
    })
  })
})

describe('daemonEnvSummary', () => {
  it('evaluates conditions independently in catalog order and skips optional missing keys', () => {
    const k1 = keyRow({
      key: 'K1_REQ_MISSING',
      required: true,
      source: 'missing',
      disk: null,
    })
    const k2 = keyRow({
      key: 'K2_OPT_MISSING',
      required: false,
      source: 'missing',
      disk: null,
    })
    const k3 = keyRow({
      key: 'K3_EXAMPLE_DRIFT',
      required: false,
      source: 'example',
      disk: { source: 'env', value: { kind: 'text', text: 'val' } },
    })
    const k4 = keyRow({
      key: 'K4_NORMAL',
      required: true,
      source: 'process',
      disk: null,
    })
    const k5 = keyRow({
      key: 'K5_DRIFT_ONLY',
      required: true,
      source: 'env',
      disk: { source: 'example', value: { kind: 'none' } },
    })

    const summary = daemonEnvSummary([k1, k2, k3, k4, k5])
    expect(summary.keyCount).toBe(5)
    expect(summary.missingRequired).toEqual(['K1_REQ_MISSING'])
    expect(summary.fromExample).toEqual(['K3_EXAMPLE_DRIFT'])
    expect(summary.drifted).toEqual([k3, k5])
  })
})

describe('formatDaemonEnvDriftNotice', () => {
  it('returns null when disk is null', () => {
    expect(formatDaemonEnvDriftNotice(keyRow({ disk: null }))).toBeNull()
  })

  it('formats text, empty, secret, none and hidden values for live and disk', () => {
    expect(
      formatDaemonEnvDriftNotice(
        keyRow({
          key: 'TEXT_TO_EMPTY',
          value: { kind: 'text', text: 'live' },
          disk: { source: 'env', value: { kind: 'text', text: '' } },
        }),
      ),
    ).toEqual({
      key: 'TEXT_TO_EMPTY',
      process: 'live',
      disk: 'empty',
    })

    expect(
      formatDaemonEnvDriftNotice(
        keyRow({
          key: 'SECRET_TO_NONE',
          value: { kind: 'secret', length: 16 },
          disk: { source: 'example', value: { kind: 'none' } },
        }),
      ),
    ).toEqual({
      key: 'SECRET_TO_NONE',
      process: 'set · 16 chars',
      disk: 'no value',
    })

    expect(
      formatDaemonEnvDriftNotice(
        keyRow({
          key: 'HIDDEN_TO_SECRET',
          value: HIDDEN_VALUE,
          disk: { source: 'env', value: { kind: 'secret', length: 8 } },
        }),
      ),
    ).toEqual({
      key: 'HIDDEN_TO_SECRET',
      process: 'hidden',
      disk: 'set · 8 chars',
    })
  })
})

describe('daemonEnvNodeHeading', () => {
  it('uses the own node response for live and silent cluster and standalone pages', () => {
    for (const clustered of [false, true]) {
      for (const silent of [false, true]) {
        const node = {
          clustered,
          state: silent ? DAEMON_NODE_STATE_SILENT : null,
          silentSince: null,
          role: 'slave' as const,
        }
        expect(
          daemonEnvNodeHeading({
            node,
            environment: silent
              ? null
              : { nodeId: 'n1', cluster: clustered, keys: [] },
            nodeSilent: silent,
          }),
        ).toBe(node)
      }
    }
    expect(
      daemonEnvNodeHeading({
        node: null,
        environment: null,
        nodeSilent: false,
      }),
    ).toBeNull()
  })

  it('follows own node live updates and clears it on scope replacement and loss', () => {
    const scopes = new ScopeManager()
    const state = readHilosDaemonEnvPage(scopes)
    const page = scopes.openPage(HilosPages.DAEMON_ENV)
    const node = {
      clustered: false,
      state: null,
      silentSince: null,
      role: 'master',
    }
    page.data.set('node', node)
    expect(daemonEnvNodeHeading(state.get())).toEqual(node)
    page.data.set('node', { ...node, state: 'silent' })
    expect(daemonEnvNodeHeading(state.get())).toEqual({
      ...node,
      state: 'silent',
    })
    scopes.openPage(HilosPages.DAEMON_ENV)
    expect(daemonEnvNodeHeading(state.get())).toBeNull()
    page.data.set('node', node)
    expect(daemonEnvNodeHeading(state.get())).toBeNull()
    scopes.dropPage()
    expect(daemonEnvNodeHeading(state.get())).toBeNull()
  })
})

describe('DAEMON_ENV_LADDER', () => {
  it('contains five sources in index priority order', () => {
    expect(DAEMON_ENV_LADDER).toEqual([
      'process',
      'env',
      'example',
      'default',
      'missing',
    ])
  })
})
