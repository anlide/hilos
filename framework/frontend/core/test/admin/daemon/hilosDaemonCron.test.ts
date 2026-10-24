import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import {
  createHilosDaemonCronTable,
  daemonCronEmptyState,
  daemonCronHoldsIdleNotice,
  daemonCronIdleNotice,
  formatDaemonCronExecutor,
  formatDaemonCronLastRun,
  formatDaemonCronMoment,
  formatDaemonCronNextRun,
  readDaemonCronReported,
  resolveHilosDaemonCronRow,
  DAEMON_CRON_IDLE_NOT_LEADER,
  type HilosDaemonCronContext,
  type HilosDaemonCronRow,
} from '../../../src/admin/daemon/hilosDaemonCron.js'
import {
  type HilosConnection,
  type TableViewportDescriptor,
} from '../../../src/connection/HilosConnection.js'
import { HilosPages } from '../../../src/routing/hilosPages.js'
import { type ProjectSignal } from '../../../src/protocol/parseSignal.js'
import { applyServerTime } from '../../../src/session/serverClock.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'
import { type TableRow } from '../../../src/state/TableRowsStore.js'
import {
  bindTimeZoneState,
  hilosBrowserTimeZone,
  hilosTimeZone,
} from '../../../src/timeZone/timeZoneState.js'

function row(overrides: Partial<HilosDaemonCronRow> = {}): HilosDaemonCronRow {
  return {
    rowKey: '/cleanup',
    agentId: null,
    name: 'cleanup',
    expression: '0 3 * * *',
    lastRunAt: null,
    nextRunAt: null,
    idleReason: null,
    ...overrides,
  }
}

function rawRow(rowKey: string, slot: Record<string, unknown>): TableRow {
  return { rowKey, slots: { rule: slot } }
}

function stubTable(nodeId = 'n1'): {
  table: ReturnType<typeof createHilosDaemonCronTable>
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
  const context: HilosDaemonCronContext = {
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

  return { table: createHilosDaemonCronTable(context, nodeId), sent }
}

describe('daemon cron row', () => {
  it('reads both owner kinds, nullable moments, and identity from the fragment', () => {
    expect(
      resolveHilosDaemonCronRow(
        rawRow('/cleanup', {
          agentId: null,
          name: 'cleanup',
          expression: '0 3 * * *',
          lastRunAt: null,
          nextRunAt: 1_800_000_000_000,
          idleReason: null,
          rowKey: 'wrong-slot-key',
        }),
      ),
    ).toEqual(row({ nextRunAt: 1_800_000_000_000 }))

    expect(
      resolveHilosDaemonCronRow(
        rawRow('log/rotate', {
          agentId: 'log',
          name: 'rotate',
          expression: '0 * * * *',
          lastRunAt: 1_800_000_000_000,
          nextRunAt: null,
          idleReason: null,
        }),
      ),
    ).toEqual(
      row({
        rowKey: 'log/rotate',
        agentId: 'log',
        name: 'rotate',
        expression: '0 * * * *',
        lastRunAt: 1_800_000_000_000,
      }),
    )
  })
})

describe('daemon cron viewport', () => {
  it('requests the route node after the cold page window and no order', () => {
    const { table, sent } = stubTable('standalone')
    expect(table.controller.descriptor()).toBeNull()

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

    expect(sent.at(-1)?.page).toBe(HilosPages.DAEMON_CRON)
    expect(sent.at(-1)?.tableKey).toBe('hilosDaemonCron')
    expect(sent.at(-1)?.descriptor.filter).toEqual({ node: 'standalone' })
    expect(sent.at(-1)?.descriptor.sort).toBeNull()
  })

  it('declares the five unsorted columns and each moment dependency', () => {
    const frame = stubTable().table.controller.frame
    expect(frame.declaration?.title).toBeUndefined()
    expect(frame.declaration?.search).toBeUndefined()
    expect(frame.declaration?.filters).toBeUndefined()
    expect(frame.declaration?.empty).toBeUndefined()
    expect(frame.declaration?.mainAction).toBeUndefined()
    expect(frame.declaration?.bulkActions).toBeUndefined()

    const columns = frame.columns.get()
    expect(columns.map((column) => column.key)).toEqual([
      'name',
      'agentId',
      'expression',
      'lastRunAt',
      'nextRunAt',
    ])
    expect(columns.map((column) => column.label)).toEqual([
      'Rule',
      'Run by',
      'Expression',
      'Last run',
      'Next run',
    ])
    expect(columns[0]?.card).toBe('title')
    expect(columns[1]?.card).toBe('badge')
    expect(columns[3]?.reads).toEqual(['idleReason'])
    expect(columns[4]?.reads).toEqual(['idleReason'])
    expect(columns.every((column) => column.sortable !== true)).toBe(true)
  })
})

describe('daemon cron empty state', () => {
  it('reads the current page report flag and follows its changes', () => {
    const scopes = new ScopeManager()
    const page = scopes.openPage(HilosPages.DAEMON_CRON)
    const reported = readDaemonCronReported(scopes)

    expect(reported.get()).toBe(false)
    page.data.set('cronReported', true)
    expect(reported.get()).toBe(true)
    page.data.set('cronReported', false)
    expect(reported.get()).toBe(false)
    page.data.set('cronReported', 'true')
    expect(reported.get()).toBe(false)
  })

  it('keeps a reported empty schedule distinct from an absent report', () => {
    const silent = {
      clustered: true,
      role: null,
      state: 'silent',
      silentSince: 42000,
    }

    expect(daemonCronEmptyState(null, true)).toBe('none')
    expect(daemonCronEmptyState(silent, true)).toBe('none')
  })

  it('explains an absent cron report on a silent node', () => {
    const silent = {
      clustered: true,
      role: null,
      state: 'silent',
      silentSince: 42000,
    }

    expect(daemonCronEmptyState(silent, false)).toBe('silent')
  })

  it('waits for an absent cron report on any other node', () => {
    const standby = {
      clustered: true,
      role: null,
      state: 'standby',
      silentSince: null,
    }

    expect(daemonCronEmptyState(null, false)).toBe('waiting')
    expect(daemonCronEmptyState(standby, false)).toBe('waiting')
  })
})

describe('daemon cron idle notice', () => {
  const standby = {
    clustered: true,
    role: null,
    state: 'standby',
    silentSince: null,
  }
  const idle = row({ idleReason: DAEMON_CRON_IDLE_NOT_LEADER })

  it('ignores agent rules even when they carry an idle reason', () => {
    const agent = row({
      agentId: 'log',
      idleReason: DAEMON_CRON_IDLE_NOT_LEADER,
    })

    expect(daemonCronIdleNotice([agent], standby)).toBe('none')
    expect(daemonCronIdleNotice([agent, row()], standby)).toBe('none')
    expect(daemonCronIdleNotice([], standby)).toBe('none')
  })

  it('has no notice for running daemon rules on a leader or standalone node', () => {
    expect(daemonCronIdleNotice([row()], { ...standby, state: 'leader' })).toBe(
      'none',
    )
    expect(
      daemonCronIdleNotice([row()], {
        clustered: false,
        role: null,
        state: null,
        silentSince: null,
      }),
    ).toBe('none')
  })

  it('adds the leadership prospect only on a standby node', () => {
    expect(daemonCronIdleNotice([idle], standby)).toBe('idle_until_leader')
    expect(daemonCronIdleNotice([idle], { ...standby, state: 'data' })).toBe(
      'idle',
    )
    expect(daemonCronIdleNotice([idle], { ...standby, state: 'silent' })).toBe(
      'idle',
    )
    expect(daemonCronIdleNotice([idle], { ...standby, state: null })).toBe(
      'idle',
    )
    expect(daemonCronIdleNotice([idle], null)).toBe('idle')
  })

  it('holds room in a cluster with daemon rules before and during idling', () => {
    expect(
      daemonCronHoldsIdleNotice([row()], { ...standby, state: 'leader' }),
    ).toBe(true)
    expect(daemonCronHoldsIdleNotice([idle], standby)).toBe(true)
  })

  it('holds no room on a standalone node or without daemon rules', () => {
    const standalone = {
      clustered: false,
      role: null,
      state: null,
      silentSince: null,
    }

    expect(daemonCronHoldsIdleNotice([row()], standalone)).toBe(false)
    expect(daemonCronHoldsIdleNotice([idle], standalone)).toBe(false)
    expect(daemonCronHoldsIdleNotice([row({ agentId: 'log' })], standby)).toBe(
      false,
    )
    expect(daemonCronHoldsIdleNotice([], standby)).toBe(false)
    expect(daemonCronHoldsIdleNotice([row()], null)).toBe(false)
  })
})

describe('daemon cron cell text', () => {
  const now = Date.parse('2025-12-31T23:30:00Z')
  const resolved = new Intl.DateTimeFormat().resolvedOptions()
  let release: () => void
  let choose: (zone: string) => void

  beforeEach(() => {
    vi.spyOn(Intl.DateTimeFormat.prototype, 'resolvedOptions').mockReturnValue({
      ...resolved,
      timeZone: 'America/Los_Angeles',
    })
    const listeners = new Set<(signal: ProjectSignal) => void>()
    const connection = {
      projectTimeZone: 'Asia/Tokyo',
      on(event: string, listener: (payload: never) => void): () => void {
        const receive = listener as (signal: ProjectSignal) => void
        if (event === 'projectSignal') listeners.add(receive)
        return () => listeners.delete(receive)
      },
    }
    release = bindTimeZoneState(connection as unknown as HilosConnection)
    choose = (timeZone) => {
      for (const listener of listeners) {
        listener({
          type: 'handshake_response',
          data: { entities: { currentUser: { id: 1, timeZone } } },
        } as ProjectSignal)
      }
    }
    choose('Europe/Kyiv')
    applyServerTime(Date.now())
  })

  afterEach(() => {
    release()
    vi.restoreAllMocks()
    vi.unstubAllGlobals()
    applyServerTime(Date.now())
  })

  it('names daemon and agent executors by installation mode', () => {
    expect(formatDaemonCronExecutor(row(), true)).toBe('Daemon (leader)')
    expect(formatDaemonCronExecutor(row(), false)).toBe('Daemon')
    expect(formatDaemonCronExecutor(row({ agentId: 'log:1' }), true)).toBe(
      'log:1',
    )
  })

  it('keeps a follower mark on daemon rows and nullable runs distinct', () => {
    const idle = row({ idleReason: DAEMON_CRON_IDLE_NOT_LEADER })
    expect(formatDaemonCronLastRun(idle, now)).toBe('Not on this node')
    expect(formatDaemonCronNextRun(idle, now)).toBe('—')
    expect(formatDaemonCronLastRun(row(), now)).toBe('Not yet')
    expect(formatDaemonCronNextRun(row(), now)).toBe('Never')
    const agent = row({ agentId: 'log', lastRunAt: now, nextRunAt: now })
    expect(formatDaemonCronLastRun(agent, now)).toBe('today, 01:30 AM')
    expect(formatDaemonCronNextRun(agent, now)).toBe('today, 01:30 AM')
  })

  it('uses the own zone rather than browser or project at a year boundary', () => {
    expect(hilosBrowserTimeZone.get()).toBe('America/Los_Angeles')
    expect(hilosTimeZone.get()).toBe('Europe/Kyiv')
    expect(
      formatDaemonCronMoment(Date.parse('2025-12-31T21:30:00Z'), now),
    ).toBe('yesterday, 11:30 PM')
    expect(
      formatDaemonCronMoment(Date.parse('2026-01-01T22:15:00Z'), now),
    ).toBe('tomorrow, 12:15 AM')
    expect(
      formatDaemonCronMoment(Date.parse('2026-01-05T22:15:00Z'), now),
    ).toBe('Jan 6, 2026, 12:15 AM')
    choose('America/Los_Angeles')
    expect(formatDaemonCronMoment(now, now)).toBe('today, 03:30 PM')
  })

  it.each([
    ['2026-03-28T22:30:00Z', '2026-03-29T21:30:00Z', 'tomorrow, 12:30 AM'],
    ['2026-03-29T21:30:00Z', '2026-03-28T22:30:00Z', 'yesterday, 12:30 AM'],
    ['2026-10-24T21:30:00Z', '2026-10-25T22:30:00Z', 'tomorrow, 12:30 AM'],
    ['2026-10-25T22:30:00Z', '2026-10-24T21:30:00Z', 'yesterday, 12:30 AM'],
  ])('compares calendar days over DST: %s to %s', (reference, at, expected) => {
    expect(formatDaemonCronMoment(Date.parse(at), Date.parse(reference))).toBe(
      expected,
    )
  })

  it.each([-300_000, 300_000])(
    'corrects only the calendar reference for a %i ms browser offset',
    (offset) => {
      const serverNow = Date.parse(
        offset > 0 ? '2025-12-31T22:02:00Z' : '2025-12-31T21:58:00Z',
      )
      const browserNow = serverNow - offset
      vi.spyOn(Date, 'now').mockReturnValue(browserNow)
      applyServerTime(serverNow)
      const at = Date.parse('2025-12-31T21:59:00Z')
      expect(formatDaemonCronMoment(at, browserNow)).toBe(
        offset > 0 ? 'yesterday, 11:59 PM' : 'today, 11:59 PM',
      )
      expect(formatDaemonCronMoment(serverNow, browserNow)).toBe(
        offset > 0 ? 'today, 12:02 AM' : 'today, 11:58 PM',
      )
    },
  )

  it('treats even a small number as milliseconds without guessing seconds', () => {
    choose('UTC')
    expect(
      formatDaemonCronMoment(1000, Date.parse('1970-01-01T12:00:00Z')),
    ).toBe('today, 12:00 AM')
  })

  it.each([NaN, Infinity, -Infinity, 8.64e15 + 1])(
    'keeps an invalid non-null moment %s distinct from a missing run',
    (at) => {
      expect(formatDaemonCronMoment(at, now)).toBe('—')
      expect(formatDaemonCronLastRun(row({ lastRunAt: at }), now)).toBe('—')
      expect(formatDaemonCronNextRun(row({ nextRunAt: at }), now)).toBe('—')
    },
  )

  it('returns a dash for an invalid reference or an unavailable formatter', () => {
    expect(formatDaemonCronMoment(now, NaN)).toBe('—')
    vi.stubGlobal('Intl', { ...Intl, DateTimeFormat: undefined })
    expect(formatDaemonCronMoment(now, now)).toBe('—')
    vi.stubGlobal('Intl', undefined)
    expect(formatDaemonCronMoment(now, now)).toBe('—')
    expect(formatDaemonCronLastRun(row({ lastRunAt: now }), now)).toBe('—')
    expect(formatDaemonCronNextRun(row({ nextRunAt: now }), now)).toBe('—')
    expect(formatDaemonCronLastRun(row(), now)).toBe('Not yet')
    expect(formatDaemonCronNextRun(row(), now)).toBe('Never')
  })
})
