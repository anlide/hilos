import { describe, expect, it } from 'vitest'
import { formatMoment } from '../../src/format/moment.js'
import {
  publicStatusDayStrips,
  publicStatusHistory,
  publicStatusOutageViews,
  publicStatusOverall,
  type PublicStatusState,
} from '../../src/status/index.js'
import { calendarDays } from '../../src/timeZone/calendarPeriods.js'
import { NOW, outage, statusFile } from './fixtures.js'

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
              title: 'Maintenance',
              componentKeys: ['site'],
              startedAt: NOW - 1000,
              endedAt: null,
            },
          ],
        }),
      ),
    ).toBe('maintenance')
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
          title: 'Maintenance',
          componentKeys: ['site'],
          startedAt: NOW - 4000,
          endedAt: NOW - 1000,
        },
        {
          id: 'still-open',
          workId: null,
          title: 'Maintenance',
          componentKeys: ['site'],
          startedAt: NOW - 1000,
          endedAt: null,
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
    expect(publicStatusHistory(statusFile(), days)).toEqual([])
    expect(publicStatusHistory(null, days)).toEqual([])
  })
})

describe('published calendar day facts', () => {
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
          title: 'Maintenance',
          componentKeys: ['site'],
          startedAt: days[0]!.startTs,
          endedAt: null,
        },
      ],
    })
    const strips = publicStatusDayStrips(
      status,
      days,
      Date.parse('2026-01-03T12:00:00Z'),
    )
    expect(strips.map((strip) => strip.componentKey)).toEqual([
      'second',
      'site',
    ])
    expect(strips[0]?.days.map((day) => day.state)).toEqual([
      'operational',
      'operational',
      'operational',
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
          title: 'Maintenance',
          componentKeys: ['site'],
          startedAt: NOW - 1000,
          endedAt: null,
        },
      ],
    })
    const strips = publicStatusDayStrips(status, days, NOW)
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
        publicStatusDayStrips(status, days, Date.parse(now))[0]?.days.map(
          (day) => day.state,
        ),
      ).toEqual(['operational', 'major_outage'])
      const next = calendarDays(Date.parse(end), 'Europe/Warsaw', 2)
      expect(
        publicStatusDayStrips(status, next, Date.parse(end))[0]?.days.map(
          (day) => day.state,
        ),
      ).toEqual(['major_outage', 'operational'])
    },
  )
})
