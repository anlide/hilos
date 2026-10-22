import { afterEach, describe, expect, it, vi } from 'vitest'
import { readBrowserTimeZone } from '../../src/timeZone/timeZoneBrowser.js'

const intl = Object.getOwnPropertyDescriptor(globalThis, 'Intl')
const resolved = new Intl.DateTimeFormat().resolvedOptions()

function reportZone(timeZone: unknown): void {
  vi.spyOn(Intl.DateTimeFormat.prototype, 'resolvedOptions').mockReturnValue({
    ...resolved,
    timeZone: timeZone as string,
  })
}

afterEach(() => {
  vi.restoreAllMocks()
  if (intl !== undefined) {
    Object.defineProperty(globalThis, 'Intl', intl)
  }
})

describe('readBrowserTimeZone', () => {
  it('reads the zone the engine resolves for this browser', () => {
    reportZone('Europe/Warsaw')
    expect(readBrowserTimeZone()).toBe('Europe/Warsaw')
  })

  it('keeps an alias the engine resolves under its own name', () => {
    reportZone('Europe/Kiev')
    expect(readBrowserTimeZone()).toBe('Europe/Kiev')
  })

  it('asks again on every read', () => {
    reportZone('Europe/Warsaw')
    expect(readBrowserTimeZone()).toBe('Europe/Warsaw')
    reportZone('Asia/Tokyo')
    expect(readBrowserTimeZone()).toBe('Asia/Tokyo')
  })

  it.each([
    ['no name', undefined],
    ['an empty name', ''],
    ['a name of another type', 120],
    ['an offset', '+02:00'],
    ['a name with outer whitespace', ' Europe/Warsaw'],
    ['a name the engine does not know', 'Mars/Olympus'],
  ])('reads %s as no zone', (_label, timeZone) => {
    reportZone(timeZone)
    expect(readBrowserTimeZone()).toBeNull()
  })

  it('reads a throwing resolver as no zone', () => {
    vi.spyOn(
      Intl.DateTimeFormat.prototype,
      'resolvedOptions',
    ).mockImplementation(() => {
      throw new RangeError('refused')
    })
    expect(readBrowserTimeZone()).toBeNull()
  })

  it('reads a runtime without Intl as no zone', () => {
    Object.defineProperty(globalThis, 'Intl', {
      configurable: true,
      value: undefined,
    })
    expect(readBrowserTimeZone()).toBeNull()
  })

  it('reads a refused Intl getter as no zone', () => {
    Object.defineProperty(globalThis, 'Intl', {
      configurable: true,
      get: () => {
        throw new Error('refused getter')
      },
    })
    expect(readBrowserTimeZone()).toBeNull()
  })
})
