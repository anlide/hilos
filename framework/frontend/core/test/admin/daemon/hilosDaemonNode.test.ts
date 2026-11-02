import { describe, expect, it, vi } from 'vitest'

import {
  daemonNodeClockSchema,
  formatHilosNodeLabel,
  formatDaemonNodeClock,
  formatDaemonNodeClockWarning,
  type HilosDaemonNodeClock,
} from '../../../src/admin/daemon/index.js'
import {
  daemonNodeDiagramPath,
  daemonProcessesEmptyState,
  formatDaemonNodeSilentSince,
  formatDaemonNodeState,
  readDaemonProcessesReported,
  readHilosDaemonNodeHeading,
  type HilosDaemonNodeHeading,
} from '../../../src/admin/daemon/hilosDaemonNode.js'
import { formatDaemonCronMoment } from '../../../src/admin/daemon/hilosDaemonCron.js'
import { type HilosConnection } from '../../../src/connection/HilosConnection.js'
import { type ProjectSignal } from '../../../src/protocol/parseSignal.js'
import { HilosPages } from '../../../src/routing/hilosPages.js'
import { applyServerTime } from '../../../src/session/serverClock.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'
import { bindTimeZoneState } from '../../../src/timeZone/timeZoneState.js'

describe('daemon node heading', () => {
  it('requires a nullable name and follows renames in the current answer', () => {
    const scopes = new ScopeManager()
    const page = scopes.openPage(HilosPages.DAEMON_WORKERS)
    const heading = readHilosDaemonNodeHeading(scopes)
    const oldShape = {
      clustered: true,
      state: 'leader',
      silentSince: null,
      role: 'master',
      clock: null,
    }
    page.data.set('node', oldShape)
    expect(heading.get()).toBeNull()
    for (const name of [null, 'Primary', 'Renamed']) {
      page.data.set('node', { ...oldShape, name })
      expect(heading.get()).toEqual({ ...oldShape, name })
    }
    for (const name of [undefined, 1, true, {}]) {
      page.data.set('node', { ...oldShape, name })
      expect(heading.get()).toBeNull()
    }
  })

  it('requires role, preserves unknown role and reads only the reported role', () => {
    const scopes = new ScopeManager()
    const page = scopes.openPage(HilosPages.DAEMON_WORKERS)
    const heading = readHilosDaemonNodeHeading(scopes)
    const oldShape = {
      clustered: true,
      name: null,
      state: 'leader',
      silentSince: null,
      clock: null,
    }
    page.data.set('node', oldShape)
    expect(heading.get()).toBeNull()
    for (const role of [null, 'master', 'slave']) {
      page.data.set('node', { ...oldShape, role })
      expect(heading.get()).toEqual({ ...oldShape, role })
    }
    page.data.set('node', { ...oldShape, role: 'leader' })
    expect(heading.get()).toBeNull()
    scopes.dropPage()
    expect(heading.get()).toBeNull()
  })

  it('returns null when the page has no node data or an invalid shape', () => {
    const scopes = new ScopeManager()
    const page = scopes.openPage(HilosPages.DAEMON_WORKERS)
    const heading = readHilosDaemonNodeHeading(scopes)

    expect(heading.get()).toBeNull()
    page.data.set('node', {
      clustered: true,
      name: null,
      role: null,
      state: 'leader',
      silentSince: null,
      clock: null,
      extra: true,
    })
    expect(heading.get()).toBeNull()
    page.data.set('node', {
      clustered: true,
      name: null,
      role: null,
      state: 'silent',
      silentSince: 42.5,
      clock: null,
    })
    expect(heading.get()).toBeNull()
  })

  it('reads the complete heading and follows page-data updates', () => {
    const scopes = new ScopeManager()
    const page = scopes.openPage(HilosPages.DAEMON_WORKERS)
    const heading = readHilosDaemonNodeHeading(scopes)

    page.data.set('node', {
      clustered: true,
      name: null,
      role: null,
      state: 'leader',
      silentSince: null,
      clock: null,
    })
    expect(heading.get()).toEqual({
      clustered: true,
      name: null,
      role: null,
      state: 'leader',
      silentSince: null,
      clock: null,
    })

    page.data.set('node', {
      clustered: true,
      name: null,
      role: null,
      state: 'silent',
      silentSince: 1_700_000_000_000,
      clock: null,
    })
    expect(heading.get()).toEqual({
      clustered: true,
      name: null,
      role: null,
      state: 'silent',
      silentSince: 1_700_000_000_000,
      clock: null,
    })
  })

  it('requires clock and follows the complete server projection', () => {
    const scopes = new ScopeManager()
    const page = scopes.openPage(HilosPages.DAEMON_WORKERS)
    const heading = readHilosDaemonNodeHeading(scopes)
    const base = {
      clustered: true,
      name: null,
      state: 'standby',
      silentSince: null,
      role: 'master',
    }
    page.data.set('node', base)
    expect(heading.get()).toBeNull()
    for (const clock of [
      null,
      {
        reference: true,
        offsetMs: null,
        measuredAt: null,
        thresholdMs: 300,
        beyond: false,
      },
      {
        reference: false,
        offsetMs: null,
        measuredAt: null,
        thresholdMs: null,
        beyond: false,
      },
      {
        reference: false,
        offsetMs: -301,
        measuredAt: 1_790_000_000_123,
        thresholdMs: 300,
        beyond: true,
      },
    ]) {
      page.data.set('node', { ...base, clock })
      expect(heading.get()?.clock).toEqual(clock)
    }
    page.data.set('node', { ...base, clock: {} })
    expect(heading.get()).toBeNull()
  })
})

describe('daemon node clock contract', () => {
  const measured: HilosDaemonNodeClock = {
    reference: false,
    offsetMs: 301,
    measuredAt: 1_790_000_000_123,
    thresholdMs: 300,
    beyond: true,
  }

  it('requires every field and rejects additional clock fields', () => {
    expect(daemonNodeClockSchema.parse(measured)).toEqual(measured)
    for (const key of Object.keys(measured)) {
      const missing: Record<string, unknown> = { ...measured }
      delete missing[key]
      expect(daemonNodeClockSchema.safeParse(missing).success).toBe(false)
    }
    for (const clock of [
      null,
      undefined,
      [],
      'clock',
      { ...measured, rttMs: 5 },
    ]) {
      expect(daemonNodeClockSchema.safeParse(clock).success).toBe(false)
    }
  })

  it('keeps nullable signed integers and a positive nullable threshold strict', () => {
    for (const key of ['offsetMs', 'measuredAt', 'thresholdMs']) {
      expect(
        daemonNodeClockSchema.safeParse({ ...measured, [key]: null }).success,
      ).toBe(true)
      for (const value of [
        undefined,
        '1',
        true,
        false,
        1.5,
        NaN,
        Infinity,
        -Infinity,
      ]) {
        expect(
          daemonNodeClockSchema.safeParse({ ...measured, [key]: value })
            .success,
        ).toBe(false)
      }
    }
    for (const key of ['offsetMs', 'measuredAt']) {
      for (const value of [-300, 0, 300]) {
        expect(
          daemonNodeClockSchema.safeParse({ ...measured, [key]: value })
            .success,
        ).toBe(true)
      }
    }
    for (const value of [-1, 0]) {
      expect(
        daemonNodeClockSchema.safeParse({ ...measured, thresholdMs: value })
          .success,
      ).toBe(false)
    }
    for (const key of ['reference', 'beyond']) {
      for (const value of [undefined, null, 0, 1, 'true']) {
        expect(
          daemonNodeClockSchema.safeParse({ ...measured, [key]: value })
            .success,
        ).toBe(false)
      }
    }
  })

  it('distinguishes absent, reference and not yet measured clocks', () => {
    const nowMs = 1_790_000_100_000
    expect(formatDaemonNodeClock(null, nowMs)).toBeNull()
    expect(formatDaemonNodeClock({ ...measured, reference: true }, nowMs)).toBe(
      'Clock: reference',
    )
    expect(
      formatDaemonNodeClock(
        { ...measured, offsetMs: null, measuredAt: null },
        nowMs,
      ),
    ).toBe('Clock: not measured yet')
  })

  it.each([
    [301, '+301'],
    [-301, '-301'],
    [0, '+0'],
  ])(
    'formats offset %s with an explicit sign and the existing moment formatter',
    (offsetMs, signed) => {
      const nowMs = 1_790_000_100_000
      expect(formatDaemonNodeClock({ ...measured, offsetMs }, nowMs)).toBe(
        `Clock: ${signed} ms to the leader, measured ${formatDaemonCronMoment(measured.measuredAt!, nowMs)}`,
      )
    },
  )

  it('keeps a missing measurement moment unknown', () => {
    expect(formatDaemonNodeClock({ ...measured, measuredAt: null }, 1000)).toBe(
      'Clock: +301 ms to the leader, measured —',
    )
  })

  it('warns only on the server verdict and formats the absolute offset', () => {
    expect(formatDaemonNodeClockWarning(null)).toBeNull()
    expect(
      formatDaemonNodeClockWarning({
        ...measured,
        offsetMs: 900,
        beyond: false,
      }),
    ).toBeNull()
    expect(
      formatDaemonNodeClockWarning({
        ...measured,
        offsetMs: null,
        measuredAt: null,
        beyond: false,
      }),
    ).toBeNull()
    for (const offsetMs of [-301, 301]) {
      expect(formatDaemonNodeClockWarning({ ...measured, offsetMs })).toBe(
        'Clock 301 ms off the leader (threshold 300 ms)',
      )
    }
    expect(formatDaemonNodeClockWarning({ ...measured, offsetMs: 1 })).toBe(
      'Clock 1 ms off the leader (threshold 300 ms)',
    )
  })
})

describe('daemon processes empty state', () => {
  it('reads the current page roster verdict for workers and agents', () => {
    for (const pageKey of [
      HilosPages.DAEMON_WORKERS,
      HilosPages.DAEMON_AGENTS,
    ]) {
      const scopes = new ScopeManager()
      const page = scopes.openPage(pageKey)
      const reported = readDaemonProcessesReported(scopes)

      expect(reported.get()).toBe(false)
      page.data.set('processesReported', true)
      expect(reported.get()).toBe(true)
      page.data.set('processesReported', false)
      expect(reported.get()).toBe(false)
    }
  })

  it('distinguishes a completed empty roster, silence, and waiting', () => {
    const silent = {
      clustered: true,
      name: null,
      role: null,
      state: 'silent',
      silentSince: 42000,
      clock: null,
    }
    const standby = {
      clustered: true,
      name: null,
      role: null,
      state: 'standby',
      silentSince: null,
      clock: null,
    }

    expect(daemonProcessesEmptyState(null, true)).toBe('none')
    expect(daemonProcessesEmptyState(silent, true)).toBe('none')
    expect(daemonProcessesEmptyState(silent, false)).toBe('silent')
    expect(daemonProcessesEmptyState(null, false)).toBe('waiting')
    expect(daemonProcessesEmptyState(standby, false)).toBe('waiting')
  })
})

describe('daemon node labels and diagram path', () => {
  it('keeps the stable id beside a known name and alone when the name is unknown', () => {
    expect(formatHilosNodeLabel('n1', 'Primary')).toBe('Primary (n1)')
    expect(formatHilosNodeLabel('n1', null)).toBe('n1')
    expect(formatHilosNodeLabel('node /?#', 'Data')).toBe('Data (node /?#)')
  })

  it('labels all known states and preserves an unfamiliar word', () => {
    expect(formatDaemonNodeState('leader')).toBe('Leader')
    expect(formatDaemonNodeState('standby')).toBe('Standby')
    expect(formatDaemonNodeState('data')).toBe('Data')
    expect(formatDaemonNodeState('silent')).toBe('Silent')
    expect(formatDaemonNodeState('future')).toBe('future')
  })

  it('formats the report time only for a silent node with a timestamp', () => {
    const nowMs = 1_700_000_100_000
    const heading: HilosDaemonNodeHeading = {
      clustered: true,
      name: null,
      role: null,
      state: 'silent',
      silentSince: 1_700_000_000_000,
      clock: null,
    }

    expect(formatDaemonNodeSilentSince(heading, nowMs)).toBe(
      formatDaemonCronMoment(1_700_000_000_000, nowMs),
    )
    expect(
      formatDaemonNodeSilentSince({ ...heading, state: 'leader' }, nowMs),
    ).toBeNull()
    expect(
      formatDaemonNodeSilentSince({ ...heading, silentSince: null }, nowMs),
    ).toBeNull()
    expect(formatDaemonNodeSilentSince(null, nowMs)).toBeNull()
  })

  it('reads silentSince as ms in the own zone with only the reference corrected', () => {
    const resolved = new Intl.DateTimeFormat().resolvedOptions()
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
    const release = bindTimeZoneState(connection as unknown as HilosConnection)
    try {
      for (const listener of listeners) {
        listener({
          type: 'handshake_response',
          data: {
            entities: { currentUser: { id: 1, timeZone: 'Europe/Kyiv' } },
          },
        } as ProjectSignal)
      }
      const heading: HilosDaemonNodeHeading = {
        clustered: true,
        name: null,
        state: 'silent',
        silentSince: Date.parse('2025-12-31T21:59:00Z'),
        clock: null,
        role: 'master',
      }
      const serverNow = Date.parse('2025-12-31T22:02:00Z')
      const browserNow = serverNow - 300_000
      vi.spyOn(Date, 'now').mockReturnValue(browserNow)
      applyServerTime(serverNow)
      expect(formatDaemonNodeSilentSince(heading, browserNow)).toBe(
        'yesterday, 11:59 PM',
      )
      expect(
        formatDaemonNodeClock(
          {
            reference: false,
            offsetMs: 400,
            measuredAt: heading.silentSince,
            thresholdMs: 300,
            beyond: true,
          },
          browserNow,
        ),
      ).toBe('Clock: +400 ms to the leader, measured yesterday, 11:59 PM')
      expect(
        formatDaemonNodeSilentSince(
          { ...heading, silentSince: NaN },
          browserNow,
        ),
      ).toBe('—')
      vi.stubGlobal('Intl', undefined)
      expect(formatDaemonNodeSilentSince(heading, browserNow)).toBe('—')
    } finally {
      release()
      vi.restoreAllMocks()
      vi.unstubAllGlobals()
      applyServerTime(Date.now())
    }
  })

  it('uses the router address, including an absent address', () => {
    expect(daemonNodeDiagramPath(() => undefined)).toBeUndefined()
    expect(
      daemonNodeDiagramPath((page, params) => {
        expect(page).toBe(HilosPages.DAEMON)
        expect(params).toEqual({})
        return '/hilos/daemon'
      }),
    ).toBe('/hilos/daemon')
  })
})
