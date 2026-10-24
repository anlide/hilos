import { describe, expect, it } from 'vitest'

import {
  daemonHttpEmptyState,
  daemonHttpSummarySchema,
  formatDaemonHttpAddress,
  formatDaemonHttpPeriod,
  readHilosDaemonHttpSummary,
  type HilosDaemonHttpSummary,
} from '../../../src/admin/daemon/hilosDaemonHttp.js'
import { HilosPages } from '../../../src/routing/hilosPages.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'

function summary(
  overrides: Partial<HilosDaemonHttpSummary> = {},
): HilosDaemonHttpSummary {
  return {
    host: '0.0.0.0',
    port: 8080,
    countingHours: 0,
    requests: 0,
    serverErrors: 0,
    slow: 0,
    ...overrides,
  }
}

describe('daemon HTTP summary', () => {
  it('accepts known zero counters and the port and period limits', () => {
    expect(daemonHttpSummarySchema.parse(summary())).toEqual(summary())
    expect(daemonHttpSummarySchema.parse(summary({ port: 0 }))).toEqual(
      summary({ port: 0 }),
    )
    expect(
      daemonHttpSummarySchema.parse(
        summary({ port: 65535, countingHours: 24 }),
      ),
    ).toEqual(summary({ port: 65535, countingHours: 24 }))
  })

  it.each([
    null,
    undefined,
    'http',
    [],
    {},
    { ...summary(), extra: true },
    { ...summary(), host: null },
    { ...summary(), port: -1 },
    { ...summary(), port: 65536 },
    { ...summary(), port: 8080.5 },
    { ...summary(), countingHours: -1 },
    { ...summary(), countingHours: 25 },
    { ...summary(), countingHours: 1.5 },
    { ...summary(), requests: -1 },
    { ...summary(), requests: 1.5 },
    { ...summary(), serverErrors: -1 },
    { ...summary(), serverErrors: 1.5 },
    { ...summary(), slow: -1 },
    { ...summary(), slow: 1.5 },
  ])('returns null for an absent or invalid report: %j', (value) => {
    const scopes = new ScopeManager()
    const page = scopes.openPage(HilosPages.DAEMON_HTTP_SERVER)
    page.data.set('http', value)
    expect(readHilosDaemonHttpSummary(scopes).get()).toBeNull()
  })

  it('follows whole summary replacements, clearing and a fresh page scope', () => {
    const scopes = new ScopeManager()
    const http = readHilosDaemonHttpSummary(scopes)
    expect(http.get()).toBeNull()
    const page = scopes.openPage(HilosPages.DAEMON_HTTP_SERVER)
    expect(http.get()).toBeNull()
    page.data.set('http', summary({ requests: 1284, countingHours: 23 }))
    expect(http.get()).toEqual(summary({ requests: 1284, countingHours: 23 }))
    page.data.set(
      'http',
      summary({ host: '::', requests: 5, countingHours: 0 }),
    )
    expect(http.get()).toEqual(
      summary({ host: '::', requests: 5, countingHours: 0 }),
    )
    page.data.set('http', null)
    expect(http.get()).toBeNull()
    page.data.set('http', summary())
    expect(http.get()).toEqual(summary())
    const replacement = scopes.openPage(HilosPages.DAEMON_HTTP_SERVER)
    expect(http.get()).toBeNull()
    replacement.data.set('http', summary({ port: 9090 }))
    expect(http.get()).toEqual(summary({ port: 9090 }))
    scopes.dropPage()
    expect(http.get()).toBeNull()
  })
})

describe('daemon HTTP empty state', () => {
  it('distinguishes waiting, silence without a report and known metrics', () => {
    const silent = {
      clustered: true,
      role: null,
      state: 'silent',
      silentSince: 42,
    }
    const leader = {
      clustered: true,
      role: null,
      state: 'leader',
      silentSince: null,
    }
    expect(daemonHttpEmptyState(null, null)).toBe('waiting')
    expect(daemonHttpEmptyState(leader, null)).toBe('waiting')
    expect(daemonHttpEmptyState(silent, null)).toBe('silent')
    expect(daemonHttpEmptyState(silent, summary())).toBe('none')
    expect(daemonHttpEmptyState(null, summary())).toBe('none')
    expect(daemonHttpEmptyState(leader, summary())).toBe('none')
  })
})

describe('daemon HTTP labels', () => {
  it.each([
    ['localhost', 'localhost:8080'],
    ['0.0.0.0', '0.0.0.0:8080'],
    ['127.0.0.1', '127.0.0.1:8080'],
    ['::', '[::]:8080'],
    ['2001:db8::1', '[2001:db8::1]:8080'],
    ['[::1]', '[::1]:8080'],
  ])('formats the listener %s as %s', (host, address) => {
    expect(formatDaemonHttpAddress(summary({ host }))).toBe(address)
  })

  it.each([
    [0, 'Since start, <1 h'],
    [1, 'Since start, 1 h'],
    [23, 'Since start, 23 h'],
    [24, 'Last 24 h'],
  ])('labels a %i hour report as %s', (countingHours, label) => {
    expect(formatDaemonHttpPeriod(summary({ countingHours }))).toBe(label)
  })
})
