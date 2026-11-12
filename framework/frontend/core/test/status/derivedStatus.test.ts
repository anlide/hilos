import { describe, expect, it } from 'vitest'
import { formatMoment } from '../../src/format/moment.js'
import {
  publicStatusBanner,
  publicStatusCurrentMaintenance,
  publicStatusDayStrips,
  publicStatusDayLabel,
  publicStatusHistory,
  publicStatusOutageViews,
  publicStatusOverall,
  publicStatusUpcomingWorks,
  type PublicStatusState,
} from '../../src/status/index.js'
import { calendarDays } from '../../src/timeZone/calendarPeriods.js'
import {
  heartbeat,
  legacyStatusFile,
  maintenance,
  NOW,
  outage,
  statusFile,
  work,
} from './fixtures.js'

describe('public current facts', () => {
  it.each([
    [[], 'unknown'],
    [['operational'], 'operational'],
    [['operational', 'unknown'], 'unknown'],
    [['checking', 'unknown'], 'checking'],
    [['partial_outage', 'checking'], 'partial_outage'],
    [['major_outage', 'unknown'], 'major_outage'],
    [['maintenance', 'major_outage'], 'maintenance'],
  ] as Array<[PublicStatusState[], PublicStatusState]>)(
    'derives %j as %s',
    (states, expected) => {
      const components = states.map((state, index) => ({
        componentKey: String(index),
        name: 'Component',
        description: null,
        state,
      }))
      expect(publicStatusOverall(components, null)).toBe(expected)
    },
  )

  it('recognizes an actual open maintenance period alongside unknown components', () => {
    expect(
      publicStatusOverall(
        [],
        statusFile({
          maintenancePeriods: [
            {
              id: 'm',
              workId: null,
              announcementKnown: true,
              title: 'Maintenance',
              componentKeys: ['site'],
              startedAt: NOW - 1000,
              lastObservedAt: NOW,
              endedAt: null,
              completionObservedAt: null,
            },
          ],
        }),
      ),
    ).toBe('maintenance')
  })
})

describe('public maintenance announcements and facts', () => {
  it('keeps future and begun unused windows until the exact end without starting maintenance', () => {
    const announcement = work()
    const status = statusFile({ scheduledWorks: [announcement] })
    for (const now of [NOW, announcement.startsAt, announcement.endsAt - 1]) {
      expect(publicStatusUpcomingWorks(status, now)).toMatchObject([
        {
          ...announcement,
          startsAtLabel: formatMoment(announcement.startsAt),
          endsAtLabel: formatMoment(announcement.endsAt),
          componentNames: ['Site'],
        },
      ])
      expect(publicStatusCurrentMaintenance(status, true)).toEqual([])
      expect(publicStatusOverall(status.components, status)).toBe('operational')
      expect(publicStatusHistory(status, calendarDays(now, 'UTC', 90))).toEqual(
        [],
      )
    }
    expect(publicStatusUpcomingWorks(status, announcement.endsAt)).toEqual([])
    expect(publicStatusUpcomingWorks(null, NOW)).toEqual([])
    expect(publicStatusCurrentMaintenance(null, true)).toEqual([])
  })

  it('sorts by schedule, keeping canonical source ties and opaque ids without mutating the file', () => {
    const status = statusFile({
      scheduledWorks: [
        work({ id: 'latest', startsAt: NOW + 2000 }),
        work({ id: '10' }),
        work({ id: '2' }),
        work({ id: 'earliest', startsAt: NOW - 1000 }),
      ],
    })
    expect(
      publicStatusUpcomingWorks(status, NOW).map((entry) => entry.id),
    ).toEqual(['earliest', '10', '2', 'latest'])
    expect(status.scheduledWorks.map((entry) => entry.id)).toEqual([
      'latest',
      '10',
      '2',
      'earliest',
    ])
  })

  it('never returns a consumed public id to upcoming, including a completed observed-only period', () => {
    const announcement = work()
    const active = maintenance({
      workId: announcement.id,
      title: announcement.title,
    })
    for (const period of [active, { ...active, completionObservedAt: NOW }]) {
      const status = statusFile({
        scheduledWorks: [
          announcement,
          work({ id: 'other', title: announcement.title }),
        ],
        maintenancePeriods: [period],
      })
      expect(
        publicStatusUpcomingWorks(status, NOW).map((entry) => entry.id),
      ).toEqual(['other'])
    }
    const status = statusFile({
      scheduledWorks: [announcement],
      maintenancePeriods: [active],
    })
    expect(publicStatusUpcomingWorks(status, announcement.endsAt + 1)).toEqual(
      [],
    )
    expect(publicStatusCurrentMaintenance(status, true)).toMatchObject([
      { id: active.id, announcement: 'announced', plannedWork: announcement },
    ])
    expect(
      publicStatusHistory(
        status,
        calendarDays(announcement.endsAt + 1, 'UTC', 90),
      ),
    ).toEqual([])
  })

  it.each([
    [true, 'opaque work', 'announced'],
    [true, null, 'unannounced'],
    [false, null, 'unknown'],
  ] as const)(
    'classifies only proven announcement knowledge (%s, %s)',
    (announcementKnown, workId, announcement) => {
      const period = maintenance({
        announcementKnown,
        workId,
        title: '<b>Saved public title</b>',
      })
      const status = statusFile({ maintenancePeriods: [period] })
      const [current] = publicStatusCurrentMaintenance(status, true)
      expect(current).toMatchObject({
        ...period,
        announcement,
        confirmedCurrent: true,
        plannedWork: null,
        durationSeconds: null,
        durationLabel: null,
        endedAtLabel: null,
        completionObservedAtLabel: null,
      })
      expect(publicStatusCurrentMaintenance(status, false)).toMatchObject([
        { id: period.id, announcement, confirmedCurrent: false },
      ])
    },
  )

  it('uses only exact public ids for a plan and retains saved period words without a matching work', () => {
    const period = maintenance({
      workId: '02',
      title: 'Saved title',
      componentKeys: ['site', 'removed'],
    })
    const unrelated = work({ id: '2', title: period.title })
    const status = statusFile({
      maintenancePeriods: [period],
      scheduledWorks: [unrelated],
    })
    expect(publicStatusCurrentMaintenance(status, true)).toMatchObject([
      {
        title: 'Saved title',
        announcement: 'announced',
        componentNames: ['Site', 'removed'],
        plannedWork: null,
      },
    ])
    status.scheduledWorks.push(
      work({
        id: '02',
        title: 'Retained announcement',
        description: '<b>Plain words</b>',
        componentKeys: ['removed'],
      }),
    )
    expect(publicStatusCurrentMaintenance(status, true)).toMatchObject([
      {
        title: 'Saved title',
        plannedWork: {
          id: '02',
          description: '<b>Plain words</b>',
          componentNames: ['removed'],
        },
      },
    ])
  })

  it('names announced maintenance in the banner while preserving stale and health priorities', () => {
    const status = statusFile({
      maintenancePeriods: [
        maintenance({ workId: 'saved', title: 'Database upgrade' }),
      ],
    })
    const current = publicStatusCurrentMaintenance(status, true)
    expect(publicStatusBanner('maintenance', null, current).title).toBe(
      'Maintenance in progress: Database upgrade',
    )
    expect(publicStatusBanner('maintenance', NOW, current)).toMatchObject({
      kind: 'not_responding',
      since: NOW,
    })
    for (const state of [
      'major_outage',
      'partial_outage',
      'checking',
      'unknown',
      'operational',
    ] as const) {
      expect(publicStatusBanner(state, null, current)).toEqual(
        publicStatusBanner(state, null),
      )
    }
    expect(
      publicStatusBanner(
        'maintenance',
        null,
        publicStatusCurrentMaintenance(status, false),
      ).title,
    ).toBe('Maintenance in progress')
    for (const announcementKnown of [false, true]) {
      const unknownOrUnannounced = publicStatusCurrentMaintenance(
        statusFile({
          maintenancePeriods: [maintenance({ announcementKnown })],
        }),
        true,
      )
      expect(
        publicStatusBanner('maintenance', null, unknownOrUnannounced).title,
      ).toBe('Maintenance in progress')
    }
  })
})

describe('published outage archive', () => {
  it('sorts detection descending, duration from detection, timeline descending with stable ties and plain text', () => {
    const first = outage({
      id: 'b',
      detectedAt: NOW - 10000,
      publishedAt: NOW - 5000,
      resolvedAt: NOW - 1000,
      updates: [
        { at: NOW - 5000, source: 'automatic', text: 'First' },
        { at: NOW - 2000, source: 'team', text: '<b>Investigating</b>' },
        { at: NOW - 2000, source: 'team', text: 'Tie follows publisher order' },
      ],
    })
    const views = publicStatusOutageViews(
      [
        first,
        outage({ id: 'older', detectedAt: NOW - 20000 }),
        { ...first, id: 'a' },
      ],
      NOW,
    )
    expect(views.map((item) => item.id)).toEqual(['a', 'b', 'older'])
    expect(views[0]?.durationSeconds).toBe(9)
    expect(views[0]?.durationLabel).toBe('9s')
    expect(views[0]?.detectedAtLabel).toBe(formatMoment(first.detectedAt))
    expect(views[0]?.resolvedAtLabel).toBe(formatMoment(first.resolvedAt!))
    expect(views[0]?.updates.map((update) => update.text)).toEqual([
      '<b>Investigating</b>',
      'Tie follows publisher order',
      'First',
    ])
    expect(first.updates[0]?.text).toBe('First')
    expect(publicStatusOutageViews([], NOW)).toEqual([])
  })

  it('archives only completed published outages and actual maintenance overlapping the window', () => {
    const days = calendarDays(NOW, 'UTC', 90)
    const start = days[0]!.startTs
    const status = statusFile({
      candidates: [{ componentKey: 'site', detectedAt: NOW }],
      outages: [
        outage(),
        outage({
          id: 'ended-at-boundary',
          detectedAt: start - 1000,
          resolvedAt: start,
        }),
        outage({
          id: 'overlaps-start',
          detectedAt: start - 1000,
          resolvedAt: start + 1000,
        }),
        outage({
          id: 'latest',
          detectedAt: NOW - 2000,
          resolvedAt: NOW - 1000,
        }),
      ],
      maintenancePeriods: [
        {
          id: 'actual',
          workId: null,
          announcementKnown: true,
          title: 'Maintenance',
          componentKeys: ['site'],
          startedAt: NOW - 4000,
          lastObservedAt: NOW - 4000,
          endedAt: NOW - 1000,
          completionObservedAt: NOW - 1000,
        },
        {
          id: 'still-open',
          workId: null,
          announcementKnown: true,
          title: 'Maintenance',
          componentKeys: ['site'],
          startedAt: NOW - 1000,
          lastObservedAt: NOW,
          endedAt: null,
          completionObservedAt: null,
        },
      ],
    })
    const history = publicStatusHistory(status, days)
    expect(history.map((entry) => [entry.kind, entry.id])).toEqual([
      ['outage', 'latest'],
      ['maintenance', 'actual'],
      ['outage', 'overlaps-start'],
    ])
    expect(history[1]?.durationSeconds).toBe(3)
    expect(history[1]).toMatchObject({
      announcement: 'unannounced',
      endedAtLabel: formatMoment(NOW - 1000),
      durationLabel: '3s',
    })
    expect(publicStatusHistory(statusFile(), days)).toEqual([])
    expect(publicStatusHistory(null, days)).toEqual([])
  })
})

describe('published calendar day facts', () => {
  it('does not paint candidate-only Checking as published maintenance or outage history', () => {
    const status = statusFile({
      candidates: [{ componentKey: 'site', detectedAt: NOW - 1000 }],
    })
    const days = calendarDays(NOW, 'UTC', 90)
    expect(
      publicStatusDayStrips(status, days, NOW, heartbeat())[0]?.days.every(
        (day) => day.state === 'operational',
      ),
    ).toBe(true)
    expect(publicStatusHistory(status, days)).toEqual([])
  })

  it('uses detection, half-open resolved boundaries and worst published severity per component', () => {
    const days = calendarDays(Date.parse('2026-01-03T12:00:00Z'), 'UTC', 3)
    const status = statusFile({
      components: [
        {
          componentKey: 'second',
          name: 'Second',
          description: null,
          state: 'checking',
        },
        {
          componentKey: 'site',
          name: 'Site',
          description: null,
          state: 'maintenance',
        },
      ],
      candidates: [
        {
          componentKey: 'second',
          detectedAt: Date.parse('2026-01-01T00:00:00Z'),
        },
      ],
      outages: [
        outage({
          severity: 'partial_outage',
          detectedAt: Date.parse('2026-01-01T23:00:00Z'),
          publishedAt: Date.parse('2026-01-02T01:00:00Z'),
          resolvedAt: Date.parse('2026-01-02T00:00:00Z'),
        }),
        outage({
          id: 'major',
          detectedAt: Date.parse('2026-01-02T23:30:00Z'),
          resolvedAt: Date.parse('2026-01-03T00:00:00Z'),
        }),
        outage({
          id: 'open',
          severity: 'partial_outage',
          detectedAt: Date.parse('2026-01-03T10:00:00Z'),
        }),
      ],
      scheduledWorks: [
        {
          id: 'not-actual',
          title: 'Future',
          description: null,
          componentKeys: ['second'],
          startsAt: days[0]!.startTs,
          endsAt: days[2]!.endTs,
        },
      ],
      maintenancePeriods: [
        {
          id: 'actual',
          workId: null,
          announcementKnown: true,
          title: 'Maintenance',
          componentKeys: ['site'],
          startedAt: days[0]!.startTs,
          lastObservedAt: Date.parse('2026-01-03T12:00:00Z'),
          endedAt: null,
          completionObservedAt: null,
        },
      ],
    })
    const strips = publicStatusDayStrips(
      status,
      days,
      Date.parse('2026-01-03T12:00:00Z'),
      heartbeat({ aliveAt: Date.parse('2026-01-03T12:00:00Z') }),
    )
    expect(strips.map((strip) => strip.componentKey)).toEqual([
      'second',
      'site',
    ])
    expect(strips[0]?.days.map((day) => day.state)).toEqual([
      'maintenance',
      'maintenance',
      'maintenance',
    ])
    expect(strips[1]?.days.map((day) => day.state)).toEqual([
      'partial_outage',
      'major_outage',
      'partial_outage',
    ])
    expect(status.outages[0]?.detectedAt).toBe(
      Date.parse('2026-01-01T23:00:00Z'),
    )
  })

  it('retains maintenance alone, ignores zero-length/beyond-now intervals, and gives narrow30 as suffix90', () => {
    const days = calendarDays(NOW, 'Europe/Warsaw', 90)
    const status = statusFile({
      outages: [
        outage({ detectedAt: NOW + 1 }),
        outage({ id: 'zero', detectedAt: NOW, resolvedAt: NOW }),
      ],
      maintenancePeriods: [
        {
          id: 'm',
          workId: null,
          announcementKnown: true,
          title: 'Maintenance',
          componentKeys: ['site'],
          startedAt: NOW - 1000,
          lastObservedAt: NOW,
          endedAt: null,
          completionObservedAt: null,
        },
      ],
    })
    const strips = publicStatusDayStrips(status, days, NOW, heartbeat())
    expect(strips[0]?.days).toHaveLength(90)
    expect(strips[0]?.days.at(-1)?.state).toBe('maintenance')
    expect(strips[0]?.narrowDays).toHaveLength(30)
    expect(strips[0]?.narrowDays).toEqual(strips[0]?.days.slice(-30))
  })

  it.each([
    ['2026-03-29T12:00:00Z', '2026-03-28T23:00:00Z', '2026-03-29T22:00:00Z'],
    ['2026-10-25T12:00:00Z', '2026-10-24T22:00:00Z', '2026-10-25T23:00:00Z'],
  ])(
    'colors the entire Warsaw DST day at %s, stopping exactly at the next midnight',
    (now, start, end) => {
      const days = calendarDays(Date.parse(now), 'Europe/Warsaw', 2)
      const status = statusFile({
        outages: [
          outage({
            detectedAt: Date.parse(start),
            resolvedAt: Date.parse(end),
          }),
        ],
      })
      expect(
        publicStatusDayStrips(
          status,
          days,
          Date.parse(now),
          heartbeat({ aliveAt: Date.parse(now) }),
        )[0]?.days.map((day) => day.state),
      ).toEqual(['operational', 'major_outage'])
      const next = calendarDays(Date.parse(end), 'Europe/Warsaw', 2)
      expect(
        publicStatusDayStrips(
          status,
          next,
          Date.parse(end),
          heartbeat({ aliveAt: Date.parse(end) }),
        )[0]?.days.map((day) => day.state),
      ).toEqual(['major_outage', 'operational'])
    },
  )
})

describe('observed maintenance boundaries', () => {
  it('retains by completion detection at the 90-day cutoff without painting an unknown gap', () => {
    const days = calendarDays(NOW, 'Europe/Warsaw', 90)
    const cutoff = days[0]!.startTs
    const status = statusFile({
      maintenancePeriods: [
        maintenance({
          startedAt: cutoff - 10000,
          lastObservedAt: cutoff - 1000,
          completionObservedAt: cutoff + 1,
          announcementKnown: false,
        }),
      ],
    })
    expect(publicStatusHistory(status, days)).toMatchObject([
      {
        announcement: 'unknown',
        endedAt: null,
        endedAtLabel: null,
        durationLabel: null,
      },
    ])
    expect(
      publicStatusDayStrips(status, days, NOW, heartbeat())[0]?.days.every(
        (day) => day.state === 'operational',
      ),
    ).toBe(true)
    status.maintenancePeriods[0]!.completionObservedAt = cutoff
    expect(publicStatusHistory(status, days)).toEqual([])
  })

  it('completes an observed-only period without coloring its unknown gap or inventing duration', () => {
    const days = calendarDays(NOW, 'UTC', 3)
    const period = {
      id: 'gap',
      workId: null,
      announcementKnown: false,
      title: 'Maintenance',
      componentKeys: ['site'],
      startedAt: days[0]!.startTs + 1000,
      lastObservedAt: days[0]!.startTs + 2000,
      endedAt: null,
      completionObservedAt: NOW,
    }
    const status = statusFile({ maintenancePeriods: [period] })
    expect(publicStatusOverall(status.components, status)).toBe('operational')
    expect(publicStatusHistory(status, days)).toMatchObject([
      {
        id: 'gap',
        kind: 'maintenance',
        endedAtLabel: null,
        completionObservedAtLabel: formatMoment(NOW),
        durationSeconds: null,
        durationLabel: null,
      },
    ])
    expect(
      publicStatusDayStrips(status, days, NOW, heartbeat())[0]?.days.map(
        (day) => day.state,
      ),
    ).toEqual(['maintenance', 'operational', 'operational'])
    const active = statusFile({
      maintenancePeriods: [{ ...period, completionObservedAt: null }],
    })
    expect(publicStatusOverall(active.components, active)).toBe('maintenance')
    expect(publicStatusHistory(active, days)).toEqual([])
    expect(
      publicStatusDayStrips(active, days, NOW, heartbeat())[0]?.days.map(
        (day) => day.state,
      ),
    ).toEqual(['maintenance', 'operational', 'operational'])
  })
})

describe('known day events beside publication silence', () => {
  it.each([
    'major_outage',
    'partial_outage',
    'maintenance',
    'operational',
  ] as const)(
    'retains only the worst known %s event outside silence for each component',
    (expected) => {
      const days = calendarDays(NOW, 'UTC', 1)
      const start = days[0]!.startTs
      const gap = {
        id: '0123456789abcdef0123456789abcdef',
        startedAt: start + 1000,
        endedAt: start + 4000,
      }
      const status = statusFile({
        components: [
          {
            componentKey: 'site',
            name: 'Site',
            description: null,
            state: 'operational',
          },
          {
            componentKey: 'other',
            name: 'Other',
            description: null,
            state: 'checking',
          },
        ],
        silencePeriods: [gap],
        outages: [
          outage({ detectedAt: start + 1000, resolvedAt: start + 4000 }),
          ...(expected === 'major_outage' || expected === 'partial_outage'
            ? [
                outage({
                  id: 'known',
                  severity: expected,
                  detectedAt: start + 4000,
                  resolvedAt: start + 5000,
                }),
              ]
            : []),
        ],
        maintenancePeriods:
          expected === 'operational'
            ? []
            : [
                maintenance({
                  startedAt: start,
                  lastObservedAt: start + 1000,
                  endedAt: start + 1000,
                  completionObservedAt: start + 1000,
                }),
              ],
      })
      const strips = publicStatusDayStrips(status, days, NOW, heartbeat())
      expect(strips[0]?.days[0]).toMatchObject({
        state: expected,
        hasNoData: true,
      })
      expect(strips[1]?.days[0]).toMatchObject({
        state: expected === 'operational' ? 'operational' : 'maintenance',
        hasNoData: true,
      })
      expect(publicStatusHistory(status, days)).toContainEqual(
        expect.objectContaining({ id: 'arbitrary id', kind: 'outage' }),
      )
    },
  )

  it('does not let an open outage prove anything within a current gap, preserving its duration', () => {
    const days = calendarDays(NOW, 'UTC', 3)
    const aliveAt = days[0]!.startTs + 1000
    const status = statusFile({ outages: [outage({ detectedAt: aliveAt })] })
    const original = structuredClone(status)
    expect(
      publicStatusDayStrips(
        status,
        days,
        NOW,
        heartbeat({ aliveAt }),
      )[0]?.days.map(({ state, hasNoData }) => ({ state, hasNoData })),
    ).toEqual([
      { state: 'operational', hasNoData: true },
      { state: 'operational', hasNoData: true },
      { state: 'operational', hasNoData: true },
    ])
    expect(
      publicStatusOutageViews(status.outages, NOW)[0]?.durationSeconds,
    ).toBe((NOW - aliveAt) / 1000)
    expect(publicStatusHistory(status, days)).toEqual([])
    expect(status).toEqual(original)
  })

  it('unites overlapping and touching gaps before subtracting, retaining a single known millisecond', () => {
    const days = calendarDays(NOW, 'UTC', 1)
    const start = days[0]!.startTs
    const status = statusFile({
      silencePeriods: [
        {
          id: 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
          startedAt: start + 2000,
          endedAt: start + 4000,
        },
        {
          id: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
          startedAt: start + 1000,
          endedAt: start + 3000,
        },
        {
          id: 'cccccccccccccccccccccccccccccccc',
          startedAt: start + 4000,
          endedAt: start + 5000,
        },
      ],
      outages: [outage({ detectedAt: start + 1000, resolvedAt: start + 5000 })],
    })
    expect(
      publicStatusDayStrips(status, days, NOW, heartbeat())[0]?.days[0],
    ).toMatchObject({ state: 'operational', hasNoData: true })
    status.outages[0]!.resolvedAt = start + 5001
    expect(
      publicStatusDayStrips(status, days, NOW, heartbeat())[0]?.days[0],
    ).toMatchObject({ state: 'major_outage', hasNoData: true })
    status.outages[0]!.detectedAt = start + 999
    status.outages[0]!.resolvedAt = start + 5000
    expect(
      publicStatusDayStrips(status, days, NOW, heartbeat())[0]?.days[0]?.state,
    ).toBe('major_outage')
  })

  it('starts current silence only after the exact threshold and clips future facts to elapsed time', () => {
    const days = calendarDays(NOW, 'UTC', 1)
    const status = statusFile({
      silencePeriods: [
        {
          id: '0123456789abcdef0123456789abcdef',
          startedAt: NOW,
          endedAt: NOW + 1000,
        },
      ],
      outages: [outage({ detectedAt: NOW, resolvedAt: NOW + 1000 })],
      maintenancePeriods: [
        maintenance({ startedAt: NOW, lastObservedAt: NOW + 1000 }),
      ],
    })
    const beat = heartbeat({ aliveAt: NOW - 120000 })
    expect(
      publicStatusDayStrips(status, days, NOW, beat)[0]?.days[0],
    ).toMatchObject({ state: 'operational', hasNoData: false })
    expect(
      publicStatusDayStrips(status, days, NOW + 1, beat)[0]?.days[0],
    ).toMatchObject({ state: 'operational', hasNoData: true })
    const fresh = heartbeat({ aliveAt: NOW + 1000 })
    expect(
      publicStatusDayStrips(status, days, NOW, fresh)[0]?.days[0],
    ).toMatchObject({ state: 'operational', hasNoData: false })
  })

  it.each([
    ['2026-03-28T23:00:00Z', '2026-03-29T22:00:00Z'],
    ['2026-10-24T22:00:00Z', '2026-10-25T23:00:00Z'],
  ])(
    'uses the real Warsaw DST day [%s, %s), excluding both neighboring days',
    (startText, endText) => {
      const start = Date.parse(startText)
      const end = Date.parse(endText)
      const now = end + 1
      const days = calendarDays(now, 'Europe/Warsaw', 3)
      expect(days[1]).toEqual({ startTs: start, endTs: end })
      const status = statusFile({
        silencePeriods: [
          {
            id: '0123456789abcdef0123456789abcdef',
            startedAt: start,
            endedAt: end,
          },
        ],
        outages: [outage({ detectedAt: start, resolvedAt: end })],
      })
      expect(
        publicStatusDayStrips(
          status,
          days,
          now,
          heartbeat({ aliveAt: now }),
        )[0]?.days.map(({ state, hasNoData }) => ({ state, hasNoData })),
      ).toEqual([
        { state: 'operational', hasNoData: false },
        { state: 'operational', hasNoData: true },
        { state: 'operational', hasNoData: false },
      ])
    },
  )

  it('assigns a cross-midnight gap to reader days and keeps narrow30 the same objects as suffix90', () => {
    const now = Date.parse('2026-10-26T08:00:01Z')
    const midnight = Date.parse('2026-10-26T07:00:00Z')
    const days = calendarDays(now, 'America/Los_Angeles', 90)
    const status = statusFile({
      silencePeriods: [
        {
          id: '0123456789abcdef0123456789abcdef',
          startedAt: midnight - 1,
          endedAt: midnight + 1,
        },
      ],
    })
    const strip = publicStatusDayStrips(
      status,
      days,
      now,
      heartbeat({ aliveAt: now }),
    )[0]!
    expect(strip.days.at(-1)?.startTs).toBe(midnight)
    expect(strip.days.slice(-3).map((day) => day.hasNoData)).toEqual([
      false,
      true,
      true,
    ])
    expect(strip.narrowDays).toHaveLength(30)
    for (let i = 0; i < 30; i++)
      expect(strip.narrowDays[i]).toBe(strip.days[i + 60])
  })

  it('does not turn Checking or a scheduled announcement into a known event marker', () => {
    const days = calendarDays(NOW, 'UTC', 1)
    const status = statusFile({
      components: [
        {
          componentKey: 'site',
          name: 'Site',
          description: null,
          state: 'checking',
        },
      ],
      candidates: [{ componentKey: 'site', detectedAt: NOW - 1000 }],
      scheduledWorks: [work({ startsAt: NOW - 1000 })],
      silencePeriods: [
        {
          id: '0123456789abcdef0123456789abcdef',
          startedAt: NOW - 2000,
          endedAt: NOW - 1000,
        },
      ],
    })
    const day = publicStatusDayStrips(status, days, NOW, heartbeat())[0]!
      .days[0]!
    expect(day).toMatchObject({ state: 'operational', hasNoData: true })
    expect(publicStatusDayLabel(day)).toBe(
      `${formatMoment(day.startTs, { kind: 'date' })} · No data`,
    )
  })

  it.each([1, 2] as const)(
    'treats legacy %s as no saved gaps while still computing current silence',
    (schemaVersion) => {
      const days = calendarDays(NOW, 'UTC', 1)
      const status = legacyStatusFile(schemaVersion)
      expect(
        publicStatusDayStrips(
          status,
          days,
          NOW,
          heartbeat({ schemaVersion }),
        )[0]?.days[0]?.hasNoData,
      ).toBe(false)
      expect(
        publicStatusDayStrips(
          status,
          days,
          NOW + 120001,
          heartbeat({ schemaVersion }),
        )[0]?.days[0],
      ).toMatchObject({ state: 'operational', hasNoData: true })
    },
  )

  it.each([
    ['operational', 'Operational', 'No data'],
    ['partial_outage', 'Partial outage', 'No data · Partial outage recorded'],
    ['major_outage', 'Major outage', 'No data · Major outage recorded'],
    ['maintenance', 'Maintenance', 'No data · Maintenance recorded'],
  ] as const)(
    'labels the date and both facts for %s without Operational beside No data',
    (state, normal, missing) => {
      const day = {
        ...calendarDays(NOW, 'UTC', 1)[0]!,
        state,
        hasNoData: false,
      }
      const date = formatMoment(day.startTs, { kind: 'date' })
      expect(publicStatusDayLabel(day)).toBe(`${date} · ${normal}`)
      expect(publicStatusDayLabel({ ...day, hasNoData: true })).toBe(
        `${date} · ${missing}`,
      )
    },
  )
})
