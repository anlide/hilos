// @vitest-environment happy-dom
import { afterEach, describe, expect, it, vi } from 'vitest'
import { isPlatformPasskeyAvailable } from '../../src/auth/passkey.js'

afterEach(() => vi.unstubAllGlobals())

describe('the platform passkey capability (HIL-1106)', () => {
  it.each([true, false])(
    'returns the browser answer %s without caching',
    async (available) => {
      const probe = vi
        .fn()
        .mockResolvedValueOnce(available)
        .mockResolvedValueOnce(!available)
      vi.stubGlobal('navigator', { credentials: {} })
      vi.stubGlobal('PublicKeyCredential', {
        isUserVerifyingPlatformAuthenticatorAvailable: probe,
      })
      expect(await isPlatformPasskeyAvailable()).toBe(available)
      expect(await isPlatformPasskeyAvailable()).toBe(!available)
      expect(probe).toHaveBeenCalledTimes(2)
    },
  )

  it('answers no without WebAuthn', async () => {
    vi.stubGlobal('PublicKeyCredential', undefined)
    expect(await isPlatformPasskeyAvailable()).toBe(false)
  })

  it('answers no when the browser probe fails', async () => {
    vi.stubGlobal('navigator', { credentials: {} })
    vi.stubGlobal('PublicKeyCredential', {
      isUserVerifyingPlatformAuthenticatorAvailable: () =>
        Promise.reject(new Error('unavailable')),
    })
    expect(await isPlatformPasskeyAvailable()).toBe(false)
  })
})
