import { afterEach, describe, expect, it, vi } from 'vitest'

import { browserRefusesCookies } from '../../src/auth/cookiesRefused.js'

describe('browserRefusesCookies', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('reads as accepted where there is no navigator at all', () => {
    vi.stubGlobal('navigator', undefined)

    expect(browserRefusesCookies()).toBe(false)
  })

  it('reads as accepted where the navigator does not say (Node)', () => {
    vi.stubGlobal('navigator', {})

    expect(browserRefusesCookies()).toBe(false)
  })

  it('reads as accepted where the browser accepts cookies', () => {
    vi.stubGlobal('navigator', { cookieEnabled: true })

    expect(browserRefusesCookies()).toBe(false)
  })

  it('reads as refused only where the browser says it refuses them', () => {
    vi.stubGlobal('navigator', { cookieEnabled: false })

    expect(browserRefusesCookies()).toBe(true)
  })
})
