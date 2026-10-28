import { afterEach, describe, expect, it, vi } from 'vitest'
import { hilosTimeZone } from '../../src/timeZone/timeZoneState.js'
import {
  describeHilosProfileSignInMethods,
  hilosProfileAddableWays,
  hilosProfileLinkableProviders,
  hilosProfilePasswordState,
  hilosProfileSignInSubtitle,
  hilosProfileSignInTitle,
  isHilosProfilePasskeyOnly,
  resolveHilosProfileSignInMethods,
} from '../../src/profile/profileSignInMethods.js'

const offered = [{ key: 'oauth:github', name: 'GitHub' }]
const password = {
  id: 1,
  type: 'password',
  provider: null,
  identifier: 'a@example.test',
  verified: true,
}
const key = {
  id: 2,
  type: 'passkey',
  provider: null,
  identifier: 'opaque',
  verified: true,
}

afterEach(() => vi.restoreAllMocks())

describe('profile sign-in projection', () => {
  it('joins by identity id and disables removal only for the last method', () => {
    const [single] = resolveHilosProfileSignInMethods(
      [key],
      [{ identityId: 2, label: 'Laptop', createdAt: 1790463600000 }],
    )
    expect(single.canUnlink).toBe(false)
    expect(hilosProfileSignInTitle(single, offered)).toBe('Laptop')
    vi.spyOn(hilosTimeZone, 'get').mockReturnValue('UTC')
    expect(hilosProfileSignInSubtitle(single)).toBe(
      'Passkey · added Sep 26, 2026',
    )
    expect(
      resolveHilosProfileSignInMethods([password, key], []).every(
        (method) => method.canUnlink,
      ),
    ).toBe(true)
  })
  it.each([
    { verified: true, subtitle: '+15551234567 · verified' },
    { verified: false, subtitle: '+15551234567' },
  ])(
    'formats a phone subtitle with verified=$verified',
    ({ verified, subtitle }) => {
      const [method] = resolveHilosProfileSignInMethods(
        [{ ...password, type: 'sms', identifier: '+15551234567', verified }],
        [],
      )
      expect(method.verified).toBe(verified)
      expect(hilosProfileSignInSubtitle(method)).toBe(subtitle)
    },
  )
  it.each(['password', 'magic_link', 'oauth'])(
    'keeps the identifier subtitle for a verified %s method',
    (type) => {
      const [method] = resolveHilosProfileSignInMethods(
        [{ ...password, type }],
        [],
      )
      expect(hilosProfileSignInSubtitle(method)).toBe(password.identifier)
    },
  )
  it('formats enrollment across midnight and falls back for an absent or invalid moment', () => {
    const [method] = resolveHilosProfileSignInMethods(
      [key],
      [{ identityId: 2, label: null, createdAt: 1767227400000 }],
    )
    const zone = vi.spyOn(hilosTimeZone, 'get').mockReturnValue('Europe/Warsaw')
    expect(hilosProfileSignInSubtitle(method)).toBe(
      'Passkey · added Jan 1, 2026',
    )
    zone.mockReturnValue('America/New_York')
    expect(hilosProfileSignInSubtitle(method)).toBe(
      'Passkey · added Dec 31, 2025',
    )
    expect(hilosProfileSignInSubtitle({ ...method, addedAt: null })).toBe(
      'Passkey',
    )
    expect(hilosProfileSignInSubtitle({ ...method, addedAt: NaN })).toBe(
      'Passkey',
    )
  })
  it('uses only a verified email-bearing identity for password addition', () => {
    const methods = resolveHilosProfileSignInMethods(
      [{ ...password, verified: false }, key],
      [],
    )
    expect(hilosProfilePasswordState(methods)).toEqual({
      hasPassword: true,
      verifiedEmail: null,
    })
    expect(
      hilosProfilePasswordState(
        resolveHilosProfileSignInMethods(
          [{ ...password, type: 'magic_link' }],
          [],
        ),
      ),
    ).toEqual({ hasPassword: false, verifiedEmail: password.identifier })
  })
  it('uses provider names, excludes linked providers and counts device keys together', () => {
    const methods = resolveHilosProfileSignInMethods(
      [
        password,
        {
          id: 3,
          type: 'sms',
          provider: null,
          identifier: '+15551234567',
          verified: true,
        },
        {
          id: 4,
          type: 'oauth',
          provider: 'oauth:github',
          identifier: 'octocat',
          verified: true,
        },
        key,
        { ...key, id: 5 },
      ],
      [],
    )
    expect(describeHilosProfileSignInMethods(methods, offered)).toBe(
      'Password, phone, GitHub, 2 passkeys',
    )
    expect(hilosProfileLinkableProviders(methods, offered)).toEqual([])
    expect(hilosProfileLinkableProviders([], offered)).toEqual([
      { key: 'oauth:github', label: 'Continue with GitHub', name: 'GitHub' },
    ])
    expect(describeHilosProfileSignInMethods([], offered)).toBe(
      'No ways to sign in',
    )
  })
  it('reads the account as passkey-only when every method is a device key, and never on an empty list', () => {
    expect(isHilosProfilePasskeyOnly([])).toBe(false)
    expect(
      isHilosProfilePasskeyOnly(
        resolveHilosProfileSignInMethods([key, { ...key, id: 5 }], []),
      ),
    ).toBe(true)
    expect(
      isHilosProfilePasskeyOnly(
        resolveHilosProfileSignInMethods(
          [
            key,
            {
              id: 3,
              type: 'sms',
              provider: null,
              identifier: '+15551234567',
              verified: true,
            },
          ],
          [],
        ),
      ),
    ).toBe(false)
  })
  it('lists the ways that can still be added, in the window order and only among the offered ones', () => {
    const all = [
      { key: 'passkey', name: null },
      { key: 'oauth:github', name: 'GitHub' },
      { key: 'sms', name: null },
      { key: 'password', name: null },
    ]
    const keysOnly = resolveHilosProfileSignInMethods([key], [])
    expect(hilosProfileAddableWays(keysOnly, all, true)).toEqual([
      { kind: 'password' },
      { kind: 'phone' },
      {
        kind: 'provider',
        key: 'oauth:github',
        label: 'Continue with GitHub',
        name: 'GitHub',
      },
      { kind: 'passkey' },
    ])
    expect(hilosProfileAddableWays(keysOnly, all, false)).not.toContainEqual({
      kind: 'passkey',
    })
    expect(
      hilosProfileAddableWays(keysOnly, [{ key: 'passkey', name: null }], true),
    ).toEqual([{ kind: 'passkey' }])
    expect(hilosProfileAddableWays(keysOnly, [], true)).toEqual([])
    expect(
      hilosProfileAddableWays(
        resolveHilosProfileSignInMethods(
          [
            password,
            {
              id: 4,
              type: 'oauth',
              provider: 'oauth:github',
              identifier: 'octocat',
              verified: true,
            },
          ],
          [],
        ),
        all,
        true,
      ),
    ).toEqual([{ kind: 'phone' }, { kind: 'passkey' }])
  })
})
