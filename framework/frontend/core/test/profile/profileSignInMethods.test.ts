import { afterEach, describe, expect, it, vi } from 'vitest'
import { hilosTimeZone } from '../../src/timeZone/timeZoneState.js'
import { applyServerTime } from '../../src/session/serverClock.js'
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

afterEach(() => {
  vi.restoreAllMocks()
  vi.unstubAllGlobals()
  applyServerTime(Date.now())
})

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
    { verified: true, subtitle: '+1 555 123 4567 · verified' },
    { verified: false, subtitle: '+1 555 123 4567' },
  ])(
    'formats a phone subtitle with verified=$verified',
    ({ verified, subtitle }) => {
      const [method] = resolveHilosProfileSignInMethods(
        [{ ...password, type: 'sms', identifier: '+15551234567', verified }],
        [],
      )
      expect(method.verified).toBe(verified)
      expect(method.identifier).toBe('+15551234567')
      expect(hilosProfileSignInSubtitle(method)).toBe(subtitle)
    },
  )
  it.each(['magic_link', 'custom'])(
    'keeps the identifier subtitle for a verified %s method',
    (type) => {
      const [method] = resolveHilosProfileSignInMethods(
        [{ ...password, type }],
        [],
      )
      expect(hilosProfileSignInSubtitle(method)).toBe(password.identifier)
    },
  )
  it('normalizes metadata from old callers to required nullable fields', () => {
    const [method] = resolveHilosProfileSignInMethods([password], [])
    expect(method.passwordChangedAt).toBeNull()
    expect(method.providerName).toBeNull()
    expect(hilosProfileSignInSubtitle(method)).toBe('Set')
    expect(hilosProfilePasswordState([method])).toEqual({
      hasPassword: true,
      verifiedEmail: password.identifier,
    })
  })
  it('shows the password change moment without exposing or replacing the email', () => {
    vi.spyOn(hilosTimeZone, 'get').mockReturnValue('Europe/Warsaw')
    applyServerTime(Date.now() + 300000)
    const [method] = resolveHilosProfileSignInMethods(
      [{ ...password, passwordChangedAt: 1767227400000 }],
      [],
    )
    expect(method.passwordChangedAt).toBe(1767227400000)
    expect(hilosProfileSignInSubtitle(method)).toBe(
      'Set · last changed Jan 1, 2026, 1:30:00 AM',
    )
    expect(hilosProfilePasswordState([method]).verifiedEmail).toBe(
      password.identifier,
    )
  })
  it.each([null, NaN, Infinity, -Infinity, 8640000000000001])(
    'uses Set for an absent or invalid password moment %s',
    (passwordChangedAt) => {
      const [method] = resolveHilosProfileSignInMethods(
        [{ ...password, passwordChangedAt }],
        [],
      )
      expect(hilosProfileSignInSubtitle(method)).toBe('Set')
    },
  )
  it('uses Set when the moment formatter is unavailable or returns empty text', () => {
    const [method] = resolveHilosProfileSignInMethods(
      [{ ...password, passwordChangedAt: 1767227400000 }],
      [],
    )
    const formatter = new Intl.DateTimeFormat()
    vi.spyOn(Intl, 'DateTimeFormat').mockImplementation(function () {
      return { ...formatter, format: () => '' } as Intl.DateTimeFormat
    })
    expect(hilosProfileSignInSubtitle(method)).toBe('Set')
    vi.stubGlobal('Intl', undefined)
    expect(hilosProfileSignInSubtitle(method)).toBe('Set')
  })
  it.each([
    ['Octocat', 'Octocat'],
    ['  Octocat  ', 'Octocat'],
    ['', 'Linked'],
    [' \t\n', 'Linked'],
    [null, 'Linked'],
    [undefined, 'Linked'],
  ])(
    'uses provider name %s or the linked fallback',
    (providerName, expected) => {
      const [method] = resolveHilosProfileSignInMethods(
        [
          {
            ...password,
            type: 'oauth',
            provider: 'oauth:github',
            identifier: 'github:subject-123',
            providerName,
          },
        ],
        [],
      )
      expect(method.providerName).toBe(providerName ?? null)
      expect(hilosProfileSignInSubtitle(method)).toBe(expected)
      expect(hilosProfileSignInTitle(method, offered)).toBe('GitHub')
      expect(describeHilosProfileSignInMethods([method], offered)).toBe(
        'GitHub',
      )
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
