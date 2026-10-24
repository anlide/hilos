import { describe, expect, it, vi } from 'vitest'

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
  it('requires role, preserves unknown role and reads only the reported role', () => {
    const scopes = new ScopeManager()
    const page = scopes.openPage(HilosPages.DAEMON_WORKERS)
    const heading = readHilosDaemonNodeHeading(scopes)
    const oldShape = { clustered: true, state: 'leader', silentSince: null }
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
      role: null,
      state: 'leader',
      silentSince: null,
      extra: true,
    })
    expect(heading.get()).toBeNull()
    page.data.set('node', {
      clustered: true,
      role: null,
      state: 'silent',
      silentSince: 42.5,
    })
    expect(heading.get()).toBeNull()
  })

  it('reads the complete heading and follows page-data updates', () => {
    const scopes = new ScopeManager()
    const page = scopes.openPage(HilosPages.DAEMON_WORKERS)
    const heading = readHilosDaemonNodeHeading(scopes)

    page.data.set('node', {
      clustered: true,
      role: null,
      state: 'leader',
      silentSince: null,
    })
    expect(heading.get()).toEqual({
      clustered: true,
      role: null,
      state: 'leader',
      silentSince: null,
    })

    page.data.set('node', {
      clustered: true,
      role: null,
      state: 'silent',
      silentSince: 1_700_000_000_000,
    })
    expect(heading.get()).toEqual({
      clustered: true,
      role: null,
      state: 'silent',
      silentSince: 1_700_000_000_000,
    })
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
      role: null,
      state: 'silent',
      silentSince: 42000,
    }
    const standby = {
      clustered: true,
      role: null,
      state: 'standby',
      silentSince: null,
    }

    expect(daemonProcessesEmptyState(null, true)).toBe('none')
    expect(daemonProcessesEmptyState(silent, true)).toBe('none')
    expect(daemonProcessesEmptyState(silent, false)).toBe('silent')
    expect(daemonProcessesEmptyState(null, false)).toBe('waiting')
    expect(daemonProcessesEmptyState(standby, false)).toBe('waiting')
  })
})

describe('daemon node labels and diagram path', () => {
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
      role: null,
      state: 'silent',
      silentSince: 1_700_000_000_000,
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
        state: 'silent',
        silentSince: Date.parse('2025-12-31T21:59:00Z'),
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
