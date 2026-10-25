import { afterEach, describe, expect, it, vi } from 'vitest'

import {
  createHilosProfileSessionActions,
  endableProfileSessionCount,
  formatProfileDateTime,
  resolveHilosProfileSessions,
  type ActionHandle,
  type ActionLifecycle,
} from '../../src/index.js'
import { applyServerTime } from '../../src/session/serverClock.js'
import { hilosTimeZone } from '../../src/timeZone/timeZoneState.js'

afterEach(() => {
  vi.restoreAllMocks()
  applyServerTime(Date.now())
})

describe('profile sessions', () => {
  it('groups live tabs, marks the subscriber, and orders the current session first', () => {
    const sessions = resolveHilosProfileSessions(
      [
        {
          session: {
            id: 4,
            deviceName: 'Other browser',
            browserTimeZone: 'US/Eastern',
            createdAt: 1789898400000,
            lastSeenAt: 1790334000000,
            expiresAt: 1792926000000,
            impersonatorUserId: null,
          },
          connections: [
            { acceptKey: 'ak-current', sessionId: 3, connectedAt: 20 },
            { acceptKey: 'ak-other', sessionId: 4, connectedAt: 30 },
          ],
        },
        {
          session: {
            id: 3,
            deviceName: null,
            createdAt: 1789812000000,
            lastSeenAt: 1790244000000,
            expiresAt: null,
            impersonatorUserId: 9,
          },
          connections: [
            { acceptKey: 'ak-current', sessionId: 3, connectedAt: 20 },
            { acceptKey: 'ak-other', sessionId: 4, connectedAt: 30 },
          ],
        },
      ],
      { acceptKey: 'ak-current', sessionId: 3 },
    )

    expect(sessions.map((session) => session.id)).toEqual([3, 4])
    expect(sessions[0]).toMatchObject({
      current: true,
      impersonated: true,
      browserTimeZone: null,
      expiresAt: null,
      createdAt: 1789812000000,
      lastSeenAt: 1790244000000,
    })
    expect(sessions[1]?.browserTimeZone).toBe('US/Eastern')
    expect(sessions[0]?.tabs).toEqual([
      { acceptKey: 'ak-current', connectedAt: 20, current: true },
    ])
    expect(sessions[1]?.tabs).toEqual([
      { acceptKey: 'ak-other', connectedAt: 30, current: false },
    ])
    expect(endableProfileSessionCount(sessions)).toBe(1)
  })

  it('marks nothing current when the mounting surface has no self connection', () => {
    const sessions = resolveHilosProfileSessions([
      {
        session: {
          id: 7,
          deviceName: 'Browser',
          createdAt: 1789898400000,
          lastSeenAt: 1790334000000,
          expiresAt: null,
          impersonatorUserId: null,
        },
        connections: [{ acceptKey: 'ak-7', sessionId: 7, connectedAt: 40 }],
      },
    ])

    expect(sessions[0]?.current).toBe(false)
    expect(sessions[0]?.tabs[0]?.current).toBe(false)
  })

  it('sorts numeric activity and tab moments descending without guessing units', () => {
    const sessions = resolveHilosProfileSessions(
      [2, 10, 9].map((lastSeenAt) => ({
        session: { id: lastSeenAt, createdAt: 0, lastSeenAt },
        connections: [
          {
            acceptKey: 'older',
            sessionId: lastSeenAt,
            connectedAt: 1710000000123,
          },
          {
            acceptKey: 'newer',
            sessionId: lastSeenAt,
            connectedAt: 1710000000456,
          },
        ],
      })),
    )
    expect(sessions.map((session) => session.id)).toEqual([10, 9, 2])
    expect(sessions[0]?.tabs.map((tab) => tab.connectedAt)).toEqual([
      1710000000456, 1710000000123,
    ])
    expect(
      resolveHilosProfileSessions([
        {
          session: {
            id: 1,
            createdAt: '1710000000000',
            lastSeenAt: '2026-01-01 00:00:00',
          },
          connections: [],
        },
      ])[0],
    ).toMatchObject({ createdAt: 0, lastSeenAt: 0, expiresAt: null })
  })

  it('formats one instant in the reader zone without correcting the clock', () => {
    const instant = 1767227400000
    const zone = vi.spyOn(hilosTimeZone, 'get')
    applyServerTime(Date.now() + 86_400_000)
    const options = {
      year: 'numeric',
      month: 'numeric',
      day: 'numeric',
      hour: 'numeric',
      minute: 'numeric',
      second: 'numeric',
    } as const
    zone.mockReturnValue('Europe/Warsaw')
    const warsaw = formatProfileDateTime(instant)
    expect(warsaw).toBe(
      new Date(instant).toLocaleString(undefined, {
        ...options,
        timeZone: 'Europe/Warsaw',
      }),
    )
    zone.mockReturnValue('America/New_York')
    const newYork = formatProfileDateTime(instant)
    expect(newYork).toBe(
      new Date(instant).toLocaleString(undefined, {
        ...options,
        timeZone: 'America/New_York',
      }),
    )
    expect(newYork).not.toBe(warsaw)
    expect(formatProfileDateTime('2026-01-01T00:30:00Z')).toBe(newYork)
    expect(formatProfileDateTime(null)).toBe('never')
    for (const value of ['2026-01-01 00:30:00', '1767227400000', 'bad', NaN]) {
      expect(formatProfileDateTime(value)).toBe(String(value))
    }
  })

  it('dispatches the two tracked session actions with their wire payloads', () => {
    const sent: Array<{ action: string; payload: unknown }> = []
    const handle = {} as ActionHandle
    const actions = {
      dispatch(action: string, payload: unknown): ActionHandle {
        sent.push({ action, payload })

        return handle
      },
    } as unknown as ActionLifecycle
    const profileActions = createHilosProfileSessionActions({ actions })

    expect(profileActions.endSession(42)).toBe(handle)
    expect(profileActions.endOtherSessions()).toBe(handle)
    expect(sent).toEqual([
      { action: 'hilos_session_end', payload: { sessionId: 42 } },
      { action: 'hilos_sessions_end_others', payload: {} },
    ])
  })
})
