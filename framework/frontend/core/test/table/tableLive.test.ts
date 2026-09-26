import { describe, expect, it } from 'vitest'
import {
  HILOS_TABLE_LIVE_ORDER,
  type HilosTableLiveKind,
  hilosTableLive,
} from '../../src/table/tableLive.js'

describe('hilosTableLive', () => {
  it('ranks report, bulk run, pending changes, new rows, a frozen table, a frozen source, then work running', () => {
    expect(HILOS_TABLE_LIVE_ORDER).toEqual([
      'report',
      'bulk',
      'pending',
      'announce',
      'frozen',
      'stale',
      'progress',
    ])
  })

  it('has nothing to say when no message is live', () => {
    expect(
      hilosTableLive({
        report: false,
        bulk: false,
        pending: false,
        announce: false,
        frozen: false,
        stale: false,
        progress: false,
      }),
    ).toEqual({ top: null, rest: [] })
  })

  it('ranks report above bulk', () => {
    expect(
      hilosTableLive({
        report: true,
        bulk: true,
        pending: false,
        announce: false,
        frozen: false,
        stale: false,
        progress: false,
      }),
    ).toEqual({ top: 'report', rest: ['bulk'] })
  })

  it('ranks bulk above pending', () => {
    expect(
      hilosTableLive({
        report: false,
        bulk: true,
        pending: true,
        announce: false,
        frozen: false,
        stale: false,
        progress: false,
      }),
    ).toEqual({ top: 'bulk', rest: ['pending'] })
  })

  const cases: readonly [
    string,
    HilosTableLiveKind | null,
    readonly HilosTableLiveKind[],
  ][] = [
    ['', null, []],
    ['r', 'report', []],
    ['b', 'bulk', []],
    ['p', 'pending', []],
    ['a', 'announce', []],
    ['s', 'stale', []],
    ['g', 'progress', []],
    ['rb', 'report', ['bulk']],
    ['rp', 'report', ['pending']],
    ['bp', 'bulk', ['pending']],
    ['pa', 'pending', ['announce']],
    ['ps', 'pending', ['stale']],
    ['pg', 'pending', ['progress']],
    ['as', 'announce', ['stale']],
    ['ag', 'announce', ['progress']],
    ['sg', 'stale', ['progress']],
    ['f', 'frozen', []],
    ['pf', 'pending', ['frozen']],
    ['af', 'announce', ['frozen']],
    ['fg', 'frozen', ['progress']],
    ['fs', 'frozen', []],
    ['fsg', 'frozen', ['progress']],
    ['pfs', 'pending', ['frozen']],
    ['rbp', 'report', ['bulk', 'pending']],
    ['pas', 'pending', ['announce', 'stale']],
    ['pag', 'pending', ['announce', 'progress']],
    ['psg', 'pending', ['stale', 'progress']],
    ['asg', 'announce', ['stale', 'progress']],
    ['rbpasg', 'report', ['bulk', 'pending', 'announce', 'stale', 'progress']],
    [
      'rbpafsg',
      'report',
      ['bulk', 'pending', 'announce', 'frozen', 'progress'],
    ],
  ]

  // A frozen table takes a stale source out of the room entirely — not only off the line
  // but from the icons after it: the stale phrase ends "The other columns are live".
  it.each(cases)(
    'live "%s" puts %s on the line and the rest after it',
    (flags, top, rest) => {
      const live = hilosTableLive({
        report: flags.includes('r'),
        bulk: flags.includes('b'),
        pending: flags.includes('p'),
        announce: flags.includes('a'),
        frozen: flags.includes('f'),
        stale: flags.includes('s'),
        progress: flags.includes('g'),
      })

      expect(live.top).toBe(top)
      expect(live.rest).toEqual(rest)
    },
  )
})
