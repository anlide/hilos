import { afterEach, describe, expect, it, vi } from 'vitest'
import { HilosPages } from '../../src/routing/hilosPages.js'
import { applyServerTime } from '../../src/session/serverClock.js'
import { prepareHilosPageSubscribeParams } from '../../src/subscription/hilosPageSubscribeParams.js'
import { hilosTimeZone } from '../../src/timeZone/timeZoneState.js'

afterEach(() => {
  vi.restoreAllMocks()
  applyServerTime(Date.now())
})

describe('prepareHilosPageSubscribeParams', () => {
  it('keeps Nodes subscribe params empty without reading zone or clock', () => {
    const zone = vi.spyOn(hilosTimeZone, 'get')
    const now = vi.spyOn(Date, 'now')
    const params = {}

    expect(
      prepareHilosPageSubscribeParams(HilosPages.MAINTENANCE_NODES, params),
    ).toBe(params)
    expect(params).toEqual({})
    expect(zone).not.toHaveBeenCalled()
    expect(now).not.toHaveBeenCalled()
  })

  it('prepares string UTC milliseconds with the answered session clock and reader zone', () => {
    vi.spyOn(Date, 'now').mockReturnValue(Date.parse('2026-10-23T00:15:00Z'))
    applyServerTime(Date.parse('2026-10-24T01:15:00Z'))
    vi.spyOn(hilosTimeZone, 'get').mockReturnValue('America/New_York')
    const params = { keep: 'value', timeZone: 'stale', hourStartTs: 'stale' }

    expect(
      prepareHilosPageSubscribeParams(HilosPages.ANALYTICS, params),
    ).toEqual({
      keep: 'value',
      timeZone: 'America/New_York',
      weekStartTs: String(Date.parse('2026-10-19T04:00:00Z')),
      dayStartTs: String(Date.parse('2026-10-23T04:00:00Z')),
      hourStartTs: String(Date.parse('2026-10-24T01:00:00Z')),
    })
    expect(params).toEqual({
      keep: 'value',
      timeZone: 'stale',
      hourStartTs: 'stale',
    })
  })

  it('leaves every other page and its params untouched without reading zone or clock', () => {
    const zone = vi.spyOn(hilosTimeZone, 'get')
    const now = vi.spyOn(Date, 'now')
    const params = { id: '7', nested: { value: 1 } }
    for (const page of [
      'project_page',
      HilosPages.ANALYTICS_PERSON,
      HilosPages.ANALYTICS_BROWSER_SESSION,
    ]) {
      expect(prepareHilosPageSubscribeParams(page, params)).toBe(params)
    }
    expect(zone).not.toHaveBeenCalled()
    expect(now).not.toHaveBeenCalled()
  })
})
