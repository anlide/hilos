import { describe, expect, it } from 'vitest'
import { formatBytes } from '../../src/format/bytes.js'

describe('formatBytes', () => {
  it('formats zero bytes as 0 B', () => {
    expect(formatBytes(0)).toBe('0 B')
  })

  it('leaves figures under one kilobyte unrounded in bytes', () => {
    expect(formatBytes(512)).toBe('512 B')
    expect(formatBytes(1023)).toBe('1023 B')
  })

  it('climbs to kilobytes at 1024 bytes', () => {
    expect(formatBytes(1024)).toBe('1.0 KB')
  })

  it('scales to gigabytes with one decimal place', () => {
    expect(formatBytes(1536 * 1024 * 1024)).toBe('1.5 GB')
  })

  it('stops at the terabyte ceiling and prints thousands of terabytes', () => {
    expect(formatBytes(1024 ** 5)).toBe('1024.0 TB')
  })
})
