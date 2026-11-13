import type { Expect } from '@playwright/test'
import { describe, expect, it } from 'vitest'
import {
  analyticsPeriodHolds,
  analyticsPersonSeenTs,
  waitAnalyticsBrowserSession,
} from '../analytics.js'

const ROTATE = 'test:analytics:rotate'
const PROBE = 'test:analytics:browser-session'
const USER_ID = 7

describe('analyticsPeriodHolds', () => {
  // Asia/Kathmandu boundaries captured in the hour-boundary regression.
  const context = {
    hourStartTs: 1794525300000,
    dayStartTs: 1794507300000,
    weekStartTs: 1794161700000,
  }

  it.each([
    [context.hourStartTs - 1, [true, false, true, true]],
    [context.hourStartTs, [true, true, true, true]],
    [context.dayStartTs - 1, [true, false, false, true]],
    [context.dayStartTs, [true, false, true, true]],
    [context.weekStartTs - 1, [true, false, false, false]],
    [context.weekStartTs, [true, false, false, true]],
    [null, [true, false, false, false]],
  ] as const)('holds the inclusive periods for seenTs=%s', (seenTs, held) => {
    expect(
      (['any', 'this-hour', 'today', 'this-week'] as const).map((period) =>
        analyticsPeriodHolds(period, seenTs, context),
      ),
    ).toEqual(held)
  })
})

describe('analyticsPersonSeenTs', () => {
  it('reads the served moment of its own row', () => {
    const settled = {
      rows: [
        { rowKey: 8, slots: { person: { lastSeenTs: 1794525300001 } } },
        { rowKey: USER_ID, slots: { person: { lastSeenTs: 1794525299999 } } },
      ],
    }
    expect(analyticsPersonSeenTs(settled, USER_ID)).toBe(1794525299999)
  })

  it('refuses a missing subject row', () => {
    expect(() => analyticsPersonSeenTs({ rows: [] }, USER_ID)).toThrow(
      'Analytics person 7 is missing from the window',
    )
  })

  it.each([
    { _hidden: true },
    0,
    -1,
    1.5,
    Number.MAX_SAFE_INTEGER + 1,
    '123',
    undefined,
  ])('refuses an unavailable or invalid served moment: %s', (lastSeenTs) => {
    const settled = {
      rows: [{ rowKey: USER_ID, slots: { person: { lastSeenTs } } }],
    }
    expect(() => analyticsPersonSeenTs(settled, USER_ID)).toThrow(
      'Invalid analytics person 7 lastSeenTs',
    )
  })
})

type Send = (
  command: string,
  payload: Record<string, unknown>,
) => Promise<Record<string, unknown>>

/** The spec's expect, with a poll that repeats its read until it matches. */
function checks(rounds = 10) {
  const poll = (read: () => Promise<unknown>) => ({
    async toBe(wanted: unknown) {
      for (let round = 0; round < rounds; round += 1) {
        if ((await read()) === wanted) return
      }
      throw new Error(`poll did not reach ${String(wanted)}`)
    },
  })
  return Object.assign((value: unknown) => expect(value), {
    poll,
  }) as unknown as Expect
}

/** A node's command channel that writes each request into the shared log. */
function channel(
  name: string,
  log: string[],
  answer: (
    command: string,
  ) => Promise<Record<string, unknown> | undefined> | undefined = () =>
    undefined,
) {
  const requests: { command: string; payload: Record<string, unknown> }[] = []
  const send: Send = async (command, payload) => {
    requests.push({ command, payload })
    log.push(`${name}:${command === ROTATE ? 'rotate' : 'probe'}`)
    return (
      (await answer(command)) ??
      (command === ROTATE
        ? { rotated: true }
        : { browserSessionId: 5, actionNames: ['hilos_login'] })
    )
  }
  return { send, requests }
}

/** A probe that finds no session until `empty` probes have gone by. */
function lateSession(empty: number, actions: readonly string[] = []) {
  let probes = 0
  return async (command: string) => {
    if (command === ROTATE) return { rotated: true }
    probes += 1
    return probes > empty
      ? { browserSessionId: 5, actionNames: [...actions] }
      : { browserSessionId: null, actionNames: [] }
  }
}

describe('waitAnalyticsBrowserSession', () => {
  it('rotates every named journal in parallel before each probe', async () => {
    const log: string[] = []
    let release!: () => void
    const held = new Promise<void>((resolve) => {
      release = resolve
    })
    const probe = channel('probe', log, lateSession(2))
    let firstRotation = true
    const slow = channel('slow', log, async (command) => {
      if (command === ROTATE && firstRotation) {
        firstRotation = false
        await held
      }
      return undefined
    })
    const other = channel('other', log)

    const waiting = waitAnalyticsBrowserSession(
      checks(),
      probe.send,
      USER_ID,
      undefined,
      [slow.send, other.send, probe.send],
    )
    await new Promise((resolve) => setTimeout(resolve, 0))
    // The slow node's rotation is still open, yet the others were sent already.
    expect(log).toEqual(['slow:rotate', 'other:rotate', 'probe:rotate'])
    release()
    await waiting

    const round = ['slow:rotate', 'other:rotate', 'probe:rotate', 'probe:probe']
    expect(log).toEqual([...round, ...round, ...round, 'probe:probe'])
    expect(slow.requests).toEqual([
      { command: ROTATE, payload: {} },
      { command: ROTATE, payload: {} },
      { command: ROTATE, payload: {} },
    ])
  })

  it('rotates the probe channel when no journals are named', async () => {
    const log: string[] = []
    const probe = channel('probe', log, lateSession(1))

    const session = await waitAnalyticsBrowserSession(
      checks(),
      probe.send,
      USER_ID,
    )

    expect(session).toEqual({ browserSessionId: 5, actionNames: [] })
    expect(probe.requests).toEqual([
      { command: ROTATE, payload: {} },
      { command: PROBE, payload: { userId: USER_ID } },
      { command: ROTATE, payload: {} },
      { command: PROBE, payload: { userId: USER_ID } },
      { command: PROBE, payload: { userId: USER_ID } },
    ])
  })

  it('keeps rotating until the expected action has arrived', async () => {
    const log: string[] = []
    let probes = 0
    const probe = channel('probe', log, async (command) => {
      if (command === ROTATE) return { rotated: true }
      probes += 1
      return {
        browserSessionId: 5,
        actionNames: probes > 2 ? ['hilos_login'] : [],
      }
    })

    const session = await waitAnalyticsBrowserSession(
      checks(),
      probe.send,
      USER_ID,
      'hilos_login',
    )

    expect(session.actionNames).toEqual(['hilos_login'])
    expect(log.filter((line) => line === 'probe:rotate')).toHaveLength(3)
  })

  it('fails the wait with the server text when a rotation answers an error', async () => {
    const log: string[] = []
    const probe = channel('probe', log)
    const broken = channel('broken', log, async () => {
      throw new Error('Analytics journal is not writable on this node')
    })

    await expect(
      waitAnalyticsBrowserSession(checks(), probe.send, USER_ID, undefined, [
        broken.send,
        probe.send,
      ]),
    ).rejects.toThrow('Analytics journal is not writable on this node')

    expect(log).not.toContain('probe:probe')
  })
})
