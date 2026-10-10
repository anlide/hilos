import { describe, expect, it } from 'vitest'

import {
  daemonNodeDiagramPath,
  formatDaemonNodeSilentSince,
  formatDaemonNodeState,
  readHilosDaemonNodeHeading,
  type HilosDaemonNodeHeading,
} from '../../../src/admin/daemon/hilosDaemonNode.js'
import { formatDaemonCronMoment } from '../../../src/admin/daemon/hilosDaemonCron.js'
import { HilosPages } from '../../../src/routing/hilosPages.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'

describe('daemon node heading', () => {
  it('returns null when the page has no node data or an invalid shape', () => {
    const scopes = new ScopeManager()
    const page = scopes.openPage(HilosPages.DAEMON_WORKERS)
    const heading = readHilosDaemonNodeHeading(scopes)

    expect(heading.get()).toBeNull()
    page.data.set('node', {
      clustered: true,
      state: 'leader',
      silentSince: null,
      extra: true,
    })
    expect(heading.get()).toBeNull()
    page.data.set('node', {
      clustered: true,
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
      state: 'leader',
      silentSince: null,
    })
    expect(heading.get()).toEqual({
      clustered: true,
      state: 'leader',
      silentSince: null,
    })

    page.data.set('node', {
      clustered: true,
      state: 'silent',
      silentSince: 1_700_000_000,
    })
    expect(heading.get()).toEqual({
      clustered: true,
      state: 'silent',
      silentSince: 1_700_000_000,
    })
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
      state: 'silent',
      silentSince: 1_700_000_000,
    }

    expect(formatDaemonNodeSilentSince(heading, nowMs)).toBe(
      formatDaemonCronMoment(1_700_000_000, nowMs),
    )
    expect(
      formatDaemonNodeSilentSince({ ...heading, state: 'leader' }, nowMs),
    ).toBeNull()
    expect(
      formatDaemonNodeSilentSince({ ...heading, silentSince: null }, nowMs),
    ).toBeNull()
    expect(formatDaemonNodeSilentSince(null, nowMs)).toBeNull()
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
