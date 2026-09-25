import { describe, expect, it } from 'vitest'

import {
  createHilosProfileSessionActions,
  endableProfileSessionCount,
  resolveHilosProfileSessions,
  type ActionHandle,
  type ActionLifecycle,
} from '../../src/index.js'

describe('profile sessions', () => {
  it('groups live tabs, marks the subscriber, and orders the current session first', () => {
    const sessions = resolveHilosProfileSessions(
      [
        {
          session: {
            id: 4,
            deviceName: 'Other browser',
            createdAt: '2026-09-20 10:00:00',
            lastSeenAt: '2026-09-25 11:00:00',
            expiresAt: '2026-10-25 11:00:00',
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
            createdAt: '2026-09-19 10:00:00',
            lastSeenAt: '2026-09-24 10:00:00',
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
    expect(sessions[0]).toMatchObject({ current: true, impersonated: true })
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
          createdAt: '2026-09-20 10:00:00',
          lastSeenAt: '2026-09-25 11:00:00',
          expiresAt: null,
          impersonatorUserId: null,
        },
        connections: [{ acceptKey: 'ak-7', sessionId: 7, connectedAt: 40 }],
      },
    ])

    expect(sessions[0]?.current).toBe(false)
    expect(sessions[0]?.tabs[0]?.current).toBe(false)
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
