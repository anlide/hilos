import { describe, expect, it } from 'vitest'
import {
  formatCountdown,
  formatDurationInWords,
  formatDurationShort,
} from '../../src/format/duration.js'

describe('formatDurationInWords', () => {
  it('reads a span in the largest unit it is whole in', () => {
    expect(formatDurationInWords(2592000)).toBe('30 days')
    expect(formatDurationInWords(86400)).toBe('1 day')
    expect(formatDurationInWords(129600)).toBe('36 hours')
    expect(formatDurationInWords(43200)).toBe('12 hours')
    expect(formatDurationInWords(3600)).toBe('1 hour')
    expect(formatDurationInWords(120)).toBe('2 minutes')
  })

  it('falls back to seconds when no larger unit is whole', () => {
    expect(formatDurationInWords(90)).toBe('90 seconds')
    expect(formatDurationInWords(1)).toBe('1 second')
    expect(formatDurationInWords(0)).toBe('0 seconds')
  })
})

describe('formatDurationShort', () => {
  it('prints a span under a minute in seconds alone', () => {
    expect(formatDurationShort(0)).toBe('0s')
    expect(formatDurationShort(7)).toBe('7s')
    expect(formatDurationShort(41)).toBe('41s')
  })

  it('pads the seconds after a leading minute to two digits', () => {
    expect(formatDurationShort(125)).toBe('2m 05s')
    expect(formatDurationShort(185)).toBe('3m 05s')
    expect(formatDurationShort(178)).toBe('2m 58s')
  })

  it('leads with hours from an hour on', () => {
    expect(formatDurationShort(3725)).toBe('1h 02m 05s')
    expect(formatDurationShort(7200)).toBe('2h 00m 00s')
  })
})

describe('formatCountdown', () => {
  it('reads what is left as m:ss, rounding seconds up', () => {
    expect(formatCountdown(65000, 0)).toBe('1:05')
    expect(formatCountdown(500, 0)).toBe('0:01')
    expect(formatCountdown(600000, 0)).toBe('10:00')
  })

  it('says nothing when no moment is armed', () => {
    expect(formatCountdown(null, 0)).toBeNull()
  })

  it('says nothing once the moment has passed', () => {
    expect(formatCountdown(1000, 1000)).toBeNull()
  })
})
