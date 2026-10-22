import { afterEach, describe, expect, it } from 'vitest'
import { resolveTimeZone } from '../../src/timeZone/timeZoneRule.js'

const intl = Object.getOwnPropertyDescriptor(globalThis, 'Intl')

afterEach(() => {
  if (intl !== undefined) {
    Object.defineProperty(globalThis, 'Intl', intl)
  }
})

describe('resolveTimeZone', () => {
  it.each([
    ['Asia/Tokyo', 'Europe/Warsaw', 'America/New_York', 'Asia/Tokyo'],
    [null, 'Europe/Warsaw', 'America/New_York', 'Europe/Warsaw'],
    [undefined, 'Europe/Warsaw', 'America/New_York', 'Europe/Warsaw'],
    [null, null, 'America/New_York', 'America/New_York'],
    [undefined, undefined, 'America/New_York', 'America/New_York'],
    [null, null, null, 'UTC'],
    [undefined, undefined, undefined, 'UTC'],
    ['Asia/Tokyo', null, null, 'Asia/Tokyo'],
    [null, 'Europe/Warsaw', null, 'Europe/Warsaw'],
  ])(
    'takes the first present step of own %s, browser %s, project %s',
    (own, browser, project, expected) => {
      expect(resolveTimeZone(own, browser, project)).toBe(expected)
    },
  )

  it.each([
    ['empty', ''],
    ['blank', '   '],
    ['leading whitespace', ' Europe/Kyiv'],
    ['trailing whitespace', 'Europe/Kyiv '],
    ['a tab', '\tUTC'],
    ['an offset with a colon', '+02:00'],
    ['an offset without a colon', '-0500'],
    ['an hour offset', '+02'],
    ['an offset with the minus sign', '−05:00'],
    ['an unknown name', 'Mars/Olympus'],
    ['the word for no choice', 'Automatic'],
    ['an offset after a zone word', 'GMT+2'],
  ])('skips %s on every step', (_label, unusable) => {
    expect(resolveTimeZone(unusable, 'Europe/Warsaw', 'Asia/Tokyo')).toBe(
      'Europe/Warsaw',
    )
    expect(resolveTimeZone(null, unusable, 'Asia/Tokyo')).toBe('Asia/Tokyo')
    expect(resolveTimeZone(null, null, unusable)).toBe('UTC')
    expect(resolveTimeZone(unusable, unusable, unusable)).toBe('UTC')
  })

  it('keeps an alias the engine recognizes under its own name', () => {
    expect(resolveTimeZone('Europe/Kiev', 'Europe/Warsaw', null)).toBe(
      'Europe/Kiev',
    )
    expect(resolveTimeZone(null, 'US/Eastern', null)).toBe('US/Eastern')
    expect(resolveTimeZone(null, null, 'Etc/GMT-3')).toBe('Etc/GMT-3')
  })

  it('reads UTC itself as a usable choice ahead of the browser', () => {
    expect(resolveTimeZone('UTC', 'Europe/Warsaw', 'Asia/Tokyo')).toBe('UTC')
  })

  it('resolves to UTC without throwing when Intl is unavailable', () => {
    Object.defineProperty(globalThis, 'Intl', {
      configurable: true,
      value: undefined,
    })

    expect(resolveTimeZone('Asia/Tokyo', 'Europe/Warsaw', 'Europe/Kyiv')).toBe(
      'UTC',
    )
  })
})
