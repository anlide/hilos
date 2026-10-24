import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  createHilosStatusState,
  hilosStatusIncidentCard,
  statusOverviewSchema,
} from '../../../src/admin/status/index.js'
import { HilosPages } from '../../../src/routing/hilosPages.js'
import { applyServerTime } from '../../../src/session/serverClock.js'
import { HIDDEN_VALUE } from '../../../src/state/hiddenValue.js'
import { subscribeSignal } from '../../../src/state/signal.js'
import { bindTimeZoneState } from '../../../src/timeZone/timeZoneState.js'
import { harness, incident, overview } from './fixtures.js'

beforeEach(() => {
  vi.useFakeTimers()
  vi.setSystemTime(1_800_000_000_000)
  applyServerTime(Date.now())
})
afterEach(() => {
  applyServerTime(Date.now())
  vi.useRealTimers()
})

describe('Status wire schema', () => {
  it('preserves readonly nullable facts, multiple incidents, timeline order and hidden values', () => {
    const wire = overview({
      publishDelaySeconds: HIDDEN_VALUE,
      incidents: [
        incident({
          updates: [
            {
              id: 2,
              kind: 'team',
              severity: 'major_outage',
              text: HIDDEN_VALUE,
              at: 1_800_000_002_000,
            },
            {
              id: 1,
              kind: 'published',
              severity: 'partial_outage',
              text: '',
              at: 1_800_000_001_000,
            },
          ],
        }),
        incident({
          incidentId: 12,
          componentKey: 'mail',
          suppressedAt: 1_800_000_001_000,
        }),
      ],
    })
    const parsed = statusOverviewSchema.parse(wire)
    expect(parsed).toEqual(wire)
    expect(parsed.publishDelaySeconds).toBe(HIDDEN_VALUE)
    expect(parsed.incidents?.[0]?.updates[0]?.text).toBe(HIDDEN_VALUE)
    expect(Object.isFrozen(parsed)).toBe(true)
    expect(Object.isFrozen(parsed.incidents?.[0]?.updates)).toBe(true)
    expect(
      statusOverviewSchema.parse(
        overview({ incidents: null, publishDelaySeconds: null }),
      ).incidents,
    ).toBeNull()
    expect(
      statusOverviewSchema.parse(overview({ incidents: [] })).incidents,
    ).toEqual([])
  })

  it('requires every nullable key at every depth, and rejects extra keys', () => {
    for (const key of Object.keys(overview())) {
      const wire = { ...overview() } as Record<string, unknown>
      delete wire[key]
      expect(statusOverviewSchema.safeParse(wire).success, key).toBe(false)
    }
    for (const key of Object.keys(incident())) {
      const item = { ...incident() } as Record<string, unknown>
      delete item[key]
      expect(
        statusOverviewSchema.safeParse(overview({ incidents: [item as never] }))
          .success,
        key,
      ).toBe(false)
    }
    for (const maintenance of [
      { phase: 'verifying', operation: null },
      { phase: 'verifying', startedAt: null },
      { operation: null, startedAt: null },
      { phase: 'verifying', operation: null, startedAt: null, pass: 'secret' },
    ]) {
      expect(
        statusOverviewSchema.safeParse({ ...overview(), maintenance }).success,
      ).toBe(false)
    }
    for (const wire of [
      { ...overview(), extra: true },
      overview({
        components: {
          operationalCount: 0,
          totalCount: 1,
          extra: true,
        } as never,
      }),
      overview({ incidents: [{ ...incident(), extra: true } as never] }),
      overview({
        incidents: [
          incident({
            updates: [
              {
                id: 1,
                kind: 'team',
                severity: 'major_outage',
                text: 'x',
                at: 1,
                extra: true,
              } as never,
            ],
          }),
        ],
      }),
    ])
      expect(statusOverviewSchema.safeParse(wire).success).toBe(false)
  })

  it.each([
    { healthKnown: 'true' },
    { components: { operationalCount: -1, totalCount: 1 } },
    { components: { operationalCount: 1, totalCount: 0 } },
    { components: { operationalCount: 2, totalCount: 1 } },
    { publishDelaySeconds: -1 },
    { publishDelaySeconds: { _hidden: true, text: 'leak' } },
    { incidents: [incident({ incidentId: 0 })] },
    { incidents: [incident({ severity: 'unknown' as never })] },
    { incidents: [incident({ publishedAt: 'yesterday' as never })] },
    { incidents: [incident({ detectedAt: 1.5 })] },
  ])('rejects malformed data %j', (patch) => {
    expect(
      statusOverviewSchema.safeParse({ ...overview(), ...patch }).success,
    ).toBe(false)
  })
})

describe('Status state and presentation', () => {
  it('reads only the current page datum, clears malformed data and stops its timers', () => {
    const h = harness()
    const state = createHilosStatusState(h.context)
    expect(state.summary.get()).toBe('loading')
    h.setOverview(overview())
    state.start()
    state.start()
    expect(state.cards.get()).toHaveLength(1)
    expect(vi.getTimerCount()).toBe(1)
    expect(h.sent).toEqual([])
    const oldScope = h.scopes.page()!
    h.scopes.openPage(HilosPages.STATUS_COMPONENTS)
    expect(state.overview.get()).toBeNull()
    expect(vi.getTimerCount()).toBe(0)
    oldScope.data.set('status', overview())
    expect(state.overview.get()).toBeNull()
    h.scopes.openPage(HilosPages.STATUS)
    h.setOverview(overview())
    expect(state.cards.get()).toHaveLength(1)
    h.setOverview({ ...overview(), invalid: true })
    expect(state.overview.get()).toBeNull()
    h.setOverview(overview())
    state.dispose()
    state.dispose()
    state.start()
    expect(vi.getTimerCount()).toBe(0)
    h.setOverview(overview({ incidents: [] }))
    expect(state.overview.get()).toBeNull()
    h.releaseAccess()
  })

  it('keeps known cards and counts under unknown health, and prioritizes maintenance', () => {
    const h = harness()
    const state = createHilosStatusState(h.context)
    state.start()
    h.setOverview(
      overview({
        healthKnown: false,
        incidents: [
          incident(),
          incident({ incidentId: 12, componentKey: 'mail', suppressedAt: 1 }),
        ],
      }),
    )
    expect(state.summary.get()).toBe('unknown')
    expect(state.overview.get()?.components.operationalCount).toBe(0)
    expect(state.cards.get()).toHaveLength(2)
    expect(state.cards.get()[1]).toMatchObject({
      suppressed: true,
      countdown: null,
      canPublishNow: false,
      canCancelPublication: false,
      state: 'checking',
    })
    h.setOverview(
      overview({
        healthKnown: false,
        maintenance: { phase: 'verifying', operation: null, startedAt: null },
      }),
    )
    expect(state.summary.get()).toBe('maintenance')
    expect(state.cards.get()).toHaveLength(1)
    expect(state.maintenance.get()?.startedAtLabel).toBeNull()
    h.setOverview(overview({ incidents: null }))
    expect(state.summary.get()).toBe('unknown')
    h.setOverview(overview({ incidents: [] }))
    expect(state.summary.get()).toBe('checking')
    h.setOverview(
      overview({
        components: { operationalCount: 1, totalCount: 1 },
        incidents: [],
      }),
    )
    expect(state.summary.get()).toBe('operational')
    state.dispose()
    h.releaseAccess()
  })

  it('uses serverClock exactly once, and zero waits without altering the incident', () => {
    applyServerTime(Date.now() + 90_000)
    const h = harness()
    h.setOverview(
      overview({ incidents: [incident({ publishAt: Date.now() + 95_000 })] }),
    )
    const state = createHilosStatusState(h.context)
    state.start()
    expect(state.cards.get()[0]?.countdown).toBe('0:05')
    vi.advanceTimersByTime(5000)
    expect(state.cards.get()[0]).toMatchObject({
      countdown: null,
      waitingForPublication: true,
      canPublishNow: true,
      state: 'checking',
    })
    expect(state.overview.get()?.incidents?.[0]?.publishedAt).toBeNull()
    expect(h.sent).toEqual([])
    expect(
      hilosStatusIncidentCard(incident({ publishedAt: 0 }), Date.now())
        .canAddUpdate,
    ).toBe(true)
    h.setOverview(overview({ incidents: [incident({ suppressedAt: 0 })] }))
    expect(vi.getTimerCount()).toBe(0)
    state.dispose()
    h.releaseAccess()
  })

  it('rerenders absolute moments in cards, timeline and maintenance on zone changes without a new page frame', () => {
    const h = harness()
    const offZone = bindTimeZoneState(h.context.connection)
    const at = Date.UTC(2026, 9, 24, 12)
    h.setOverview(
      overview({
        incidents: [
          incident({
            detectedAt: at,
            updates: [
              {
                id: 1,
                kind: 'team',
                severity: 'major_outage',
                text: '<b>plain</b>',
                at,
              },
            ],
          }),
        ],
        maintenance: { phase: 'verifying', operation: 'backup', startedAt: at },
      }),
    )
    const state = createHilosStatusState(h.context)
    state.start()
    const before = state.cards.get()[0]?.detectedAtLabel
    let redraws = 0
    const off = subscribeSignal(state.cards, () => redraws++)
    h.emit('projectSignal', {
      type: 'handshake_response',
      data: { entities: { currentUser: { id: 1, timeZone: 'Asia/Tokyo' } } },
    })
    const card = state.cards.get()[0]!
    expect(card.detectedAtLabel).not.toBe(before)
    expect(card.detectedAtLabel).toBe(card.updates[0]?.atLabel)
    expect(card.detectedAtLabel).toBe(state.maintenance.get()?.startedAtLabel)
    expect(card.updates[0]?.text).toBe('<b>plain</b>')
    expect(redraws).toBe(1)
    off()
    state.dispose()
    offZone()
    h.releaseAccess()
  })
})
