import { describe, expect, it } from 'vitest'
import { formatPhoneNumber } from '../../src/format/phoneNumber.js'

describe('phone number captions', () => {
  it.each([
    ['+12133734253', '+1 213 373 4253'],
    ['+442079460018', '+44 20 7946 0018'],
    ['+33142345678', '+33 1 42 34 56 78'],
    ['+48123456789', '+48 12 345 67 89'],
  ])('formats %s in its country groups', (identifier, expected) => {
    expect(formatPhoneNumber(identifier)).toBe(expected)
  })

  it('formats a parsed number without requiring validity or a possible length', () => {
    expect(formatPhoneNumber('+15551234567')).toBe('+1 555 123 4567')
    expect(formatPhoneNumber('+1213')).toBe('+1 213')
  })

  it.each(['+999123456789', '0012345678901234567890', 'phone-12345', ''])(
    'keeps an unparseable identifier %s without losing digits',
    (identifier) => {
      expect(formatPhoneNumber(identifier)).toBe(identifier)
    },
  )
})
