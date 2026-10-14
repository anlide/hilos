import { describe, expect, it } from 'vitest'
import {
  ANALYTICS_BROWSER_SESSION_COPY,
  formatAnalyticsLastSignedIn,
  hilosAnalyticsBrowserSession,
  hilosAnalyticsBrowserSessionSchema,
} from '../../../src/admin/analytics/hilosAnalyticsBrowserSession.js'
import { HilosPages } from '../../../src/routing/hilosPages.js'
import { HIDDEN_VALUE } from '../../../src/state/hiddenValue.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'

const HEADER = {
  id: 57,
  browser: 'Firefox 143 / Linux',
  lastSignedInUserId: 7,
  lastSignedInUserLabel: 'Ada',
}

describe('analytics browser-session header', () => {
  it('reads the four header fields and preserves the whole hidden mark', () => {
    expect(hilosAnalyticsBrowserSessionSchema.parse(HEADER)).toEqual(HEADER)
    expect(hilosAnalyticsBrowserSessionSchema.parse({ _hidden: true })).toBe(
      HIDDEN_VALUE,
    )
  })

  it('follows updates, hidden answers, scope replacement and page departure', () => {
    const scopes = new ScopeManager()
    const header = hilosAnalyticsBrowserSession(scopes)
    expect(header.get()).toBeUndefined()
    const page = scopes.openPage(HilosPages.ANALYTICS_BROWSER_SESSION)
    expect(header.get()).toBeUndefined()
    page.data.set('browserSession', HEADER)
    expect(header.get()).toEqual(HEADER)
    page.data.set('browserSession', {
      ...HEADER,
      lastSignedInUserLabel: 'Grace',
    })
    expect(header.get()).toEqual({ ...HEADER, lastSignedInUserLabel: 'Grace' })
    page.data.set('browserSession', { _hidden: true })
    expect(header.get()).toBe(HIDDEN_VALUE)
    const next = scopes.openPage(HilosPages.ANALYTICS_BROWSER_SESSION)
    expect(header.get()).toBeUndefined()
    next.data.set('browserSession', { ...HEADER, id: 58 })
    expect(header.get()).toEqual({ ...HEADER, id: 58 })
    scopes.dropPage()
    expect(header.get()).toBeUndefined()
  })

  it.each([
    undefined,
    null,
    {},
    [],
    { ...HEADER, id: 0 },
    { ...HEADER, id: 1.5 },
    { ...HEADER, browser: 1 },
    { ...HEADER, lastSignedInUserId: 0 },
    { ...HEADER, lastSignedInUserId: '7' },
    { ...HEADER, lastSignedInUserLabel: { _hidden: true } },
    { id: 57, browser: null },
  ])('does not read absent or malformed data as a guest: %j', (value) => {
    const scopes = new ScopeManager()
    scopes
      .openPage(HilosPages.ANALYTICS_BROWSER_SESSION)
      .data.set('browserSession', value)
    expect(hilosAnalyticsBrowserSession(scopes).get()).toBeUndefined()
  })

  it('names a guest only from an explicitly received null account', () => {
    const guest = {
      ...HEADER,
      browser: null,
      lastSignedInUserId: null,
      lastSignedInUserLabel: null,
    }
    expect(hilosAnalyticsBrowserSessionSchema.parse(guest)).toEqual(guest)
    expect(formatAnalyticsLastSignedIn(guest)).toBe(
      'No one has signed in in this browser session',
    )
    expect(ANALYTICS_BROWSER_SESSION_COPY.lastSignedIn).toBe('Last signed in')
  })

  it('shows the current or deleted label without inventing or repeating a name', () => {
    expect(formatAnalyticsLastSignedIn(HEADER)).toBe('Ada (#7)')
    expect(
      formatAnalyticsLastSignedIn({
        ...HEADER,
        lastSignedInUserLabel: 'Deleted user #7',
      }),
    ).toBe('Deleted user #7')
    expect(
      formatAnalyticsLastSignedIn({ ...HEADER, lastSignedInUserLabel: null }),
    ).toBe('— (#7)')
  })
})
