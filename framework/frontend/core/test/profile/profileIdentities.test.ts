import { describe, expect, it } from 'vitest'
import {
  createHilosProfileSignInMethods,
  HILOS_PROFILE_IDENTITIES_LIST,
} from '../../src/profile/profileIdentities.js'
import { ScopeManager } from '../../src/state/ScopeManager.js'
import { ingest } from '../../src/state/normalizer.js'

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
                    createdAt: '2026-10-01 12:00:00',
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
        addedAt: '2026-10-01 12:00:00',
        canUnlink: true,
      },
    ])

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
