import { describe, expect, it } from 'vitest'
import {
  HILOS_TABLE_LIVE_ORDER,
  type HilosTableLiveKind,
  hilosTableLive,
} from '../../src/table/tableLive.js'

describe('hilosTableLive', () => {
  it('ranks pending changes, new rows above, a frozen source, then work running', () => {
    expect(HILOS_TABLE_LIVE_ORDER).toEqual([
      'pending',
      'announce',
      'stale',
      'progress',
    ])
  })

  it('has nothing to say when no message is live', () => {
    expect(
      hilosTableLive({
        pending: false,
        announce: false,
        stale: false,
        progress: false,
      }),
    ).toEqual({ top: null, rest: [] })
  })

  // Every one of the sixteen combinations, spelled out rather than derived from the
  // order constant: a test computing its expectation the way the function does would
  // agree with any order at all.
  const cases: readonly [
    string,
    HilosTableLiveKind | null,
    readonly HilosTableLiveKind[],
  ][] = [
    ['', null, []],
    ['p', 'pending', []],
    ['a', 'announce', []],
    ['s', 'stale', []],
    ['g', 'progress', []],
    ['pa', 'pending', ['announce']],
    ['ps', 'pending', ['stale']],
    ['pg', 'pending', ['progress']],
    ['as', 'announce', ['stale']],
    ['ag', 'announce', ['progress']],
    ['sg', 'stale', ['progress']],
    ['pas', 'pending', ['announce', 'stale']],
    ['pag', 'pending', ['announce', 'progress']],
    ['psg', 'pending', ['stale', 'progress']],
    ['asg', 'announce', ['stale', 'progress']],
    ['pasg', 'pending', ['announce', 'stale', 'progress']],
  ]

  it.each(cases)(
    'live "%s" puts %s on the line and the rest after it',
    (flags, top, rest) => {
      const live = hilosTableLive({
        pending: flags.includes('p'),
        announce: flags.includes('a'),
        stale: flags.includes('s'),
        progress: flags.includes('g'),
      })

      expect(live.top).toBe(top)
      expect(live.rest).toEqual(rest)
    },
  )
})
