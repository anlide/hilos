import { afterEach, describe, expect, it, vi } from 'vitest'
import { hilosProfileSignInSubtitle } from '../../src/profile/profileSignInMethods.js'
import { hilosTimeZone } from '../../src/timeZone/timeZoneState.js'
import {
  createHilosProfileSignInMethods,
  HILOS_PROFILE_IDENTITIES_LIST,
} from '../../src/profile/profileIdentities.js'
import { ScopeManager } from '../../src/state/ScopeManager.js'
import { ingest } from '../../src/state/normalizer.js'

afterEach(() => vi.restoreAllMocks())

describe('framework profile identities list', () => {
  it('is empty before a profile list arrives', () => {
    expect(createHilosProfileSignInMethods(new ScopeManager()).get()).toEqual(
      [],
    )
  })

  it('resolves identities and passkey sidecars from the normalized page scope', () => {
    const scopes = new ScopeManager()
    const page = scopes.openPage('hilos_profile')
    ingest(page, {
      lists: {
        [HILOS_PROFILE_IDENTITIES_LIST]: {
          items: [
            {
              itemKey: 7,
              slots: {
                identities: [
                  {
                    id: 1,
                    userId: 7,
                    type: 'password',
                    identifier: 'before@example.test',
                    provider: null,
                    verified: true,
                  },
                  {
                    id: 2,
                    userId: 7,
                    type: 'passkey',
                    identifier: 'opaque',
                    provider: null,
                    verified: true,
                  },
                ],
                passkeyCredentials: [
                  {
                    id: 4,
                    userId: 7,
                    identityId: 2,
                    label: 'Laptop',
                    createdAt: 1767227400000,
                  },
                ],
              },
            },
          ],
        },
      },
    })

    const methods = createHilosProfileSignInMethods(scopes)
    expect(methods.get()).toMatchObject([
      { key: '1', identifier: 'before@example.test', canUnlink: true },
      {
        key: '2',
        type: 'passkey',
        deviceName: 'Laptop',
        addedAt: 1767227400000,
        canUnlink: true,
      },
    ])

    const zone = vi.spyOn(hilosTimeZone, 'get').mockReturnValue('Europe/Warsaw')
    expect(hilosProfileSignInSubtitle(methods.get()[1]!)).toBe(
      'Passkey · added Jan 1, 2026',
    )
    zone.mockReturnValue('America/New_York')
    expect(hilosProfileSignInSubtitle(methods.get()[1]!)).toBe(
      'Passkey · added Dec 31, 2025',
    )

    ingest(page, {
      entities: {
        identities: {
          id: 1,
          identifier: 'after@example.test',
        },
      },
    })
    expect(methods.get()[0]?.identifier).toBe('after@example.test')
  })
})
