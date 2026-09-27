import { describe, expect, it } from 'vitest'
import { formatInitials } from '../../src/format/initials.js'

describe('formatInitials', () => {
  it.each([
    ['Alexander Baranov', 'AB'],
    ['Мария Ковалёва', 'МК'],
    ['anna maria de la cruz', 'AC'],
    ['(Bot) Assistant', 'BA'],
    ['e2e-1727-abc', 'E'],
    ['Alexander', 'A'],
    ['  Ivan   Petrov  ', 'IP'],
    ['', ''],
    [' \t\n ', ''],
    ['🤖', ''],
    ['— !!!', ''],
    ['Е\u0308лка', 'Ё'],
    ['42 Club', '4C'],
    ['🤖 (Anna) — Maria !!!', 'AM'],
    ['Anna\tMaria\nCruz', 'AC'],
    ['\u{10428}name Smith', '\u{10400}S'],
  ])('formats %j as %j', (name, expected) => {
    expect(formatInitials(name)).toBe(expected)
  })
})
