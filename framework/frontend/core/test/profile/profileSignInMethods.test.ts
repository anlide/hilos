import { describe, expect, it } from 'vitest'
import {
  describeHilosProfileSignInMethods,
  hilosProfileLinkableProviders,
  hilosProfilePasswordState,
  hilosProfileSignInSubtitle,
  hilosProfileSignInTitle,
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

describe('profile sign-in projection', () => {
  it('joins by identity id and disables removal only for the last method', () => {
    const [single] = resolveHilosProfileSignInMethods(
      [key],
      [{ identityId: 2, label: 'Laptop', createdAt: '2026-09-26 23:00:00' }],
    )
    expect(single.canUnlink).toBe(false)
    expect(hilosProfileSignInTitle(single, offered)).toBe('Laptop')
    expect(hilosProfileSignInSubtitle(single)).toBe(
      'Passkey · added 2026-09-26',
    )
    expect(
      resolveHilosProfileSignInMethods([password, key], []).every(
        (method) => method.canUnlink,
      ),
    ).toBe(true)
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
      { key: 'oauth:github', label: 'Continue with GitHub' },
    ])
    expect(describeHilosProfileSignInMethods([], offered)).toBe(
      'No ways to sign in',
    )
  })
})
