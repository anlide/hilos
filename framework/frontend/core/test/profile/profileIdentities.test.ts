import { afterEach, describe, expect, it, vi } from 'vitest'
import { type HilosConnection } from '../../src/connection/HilosConnection.js'
import { type ProjectSignal } from '../../src/protocol/parseSignal.js'
import { hilosProfileSignInSubtitle } from '../../src/profile/profileSignInMethods.js'
import {
  bindTimeZoneState,
  hilosTimeZone,
} from '../../src/timeZone/timeZoneState.js'
import {
  createHilosProfileSignInMethods,
  HILOS_PROFILE_IDENTITIES_LIST,
} from '../../src/profile/profileIdentities.js'
import { ScopeManager } from '../../src/state/ScopeManager.js'
import { ingest } from '../../src/state/normalizer.js'
import { computedSignal, subscribeSignal } from '../../src/state/signal.js'

/** Feed account zone choices through the real time-zone binding. */
function zoneConnection() {
  const listeners = new Set<(signal: ProjectSignal) => void>()
  return {
    projectTimeZone: 'UTC',
    on(event: string, listener: (payload: never) => void): () => void {
      const projectListener = listener as (signal: ProjectSignal) => void
      if (event === 'projectSignal') listeners.add(projectListener)
      return () => {
        listeners.delete(projectListener)
      }
    },
    choose(timeZone: string): void {
      for (const listener of listeners) {
        listener({
          type: 'handshake_response',
          data: { entities: { currentUser: { id: 7, timeZone } } },
        } as ProjectSignal)
      }
    },
  }
}

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
      {
        key: '1',
        identifier: 'before@example.test',
        passwordChangedAt: null,
        providerName: null,
        canUnlink: true,
      },
      {
        key: '2',
        type: 'passkey',
        passwordChangedAt: null,
        providerName: null,
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

  it('projects metadata from snapshots and diffs and recomputes captions on zone changes alone', () => {
    const connection = zoneConnection()
    const release = bindTimeZoneState(connection as unknown as HilosConnection)
    connection.choose('Europe/Warsaw')
    const scopes = new ScopeManager()
    const page = scopes.openPage('hilos_profile_sign_in')
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
                    type: 'password',
                    provider: null,
                    identifier: 'account@example.test',
                    verified: true,
                    passwordChangedAt: 1767227400000,
                    providerName: null,
                  },
                  {
                    id: 2,
                    type: 'oauth',
                    provider: 'oauth:github',
                    identifier: 'github:subject-123',
                    verified: true,
                    passwordChangedAt: null,
                    providerName: 'Octocat',
                  },
                ],
              },
            },
          ],
        },
      },
    })

    const methodsSignal = createHilosProfileSignInMethods(scopes)
    const captions = computedSignal(() =>
      methodsSignal.get().map(hilosProfileSignInSubtitle),
    )
    const changes: string[][] = []
    const stop = subscribeSignal(captions, (value) => changes.push(value))
    try {
      const methods = methodsSignal.get()
      expect(methods).toMatchObject([
        { passwordChangedAt: 1767227400000, providerName: null },
        { passwordChangedAt: null, providerName: 'Octocat' },
      ])
      expect(captions.get()).toEqual([
        'Set · last changed Jan 1, 2026, 1:30:00 AM',
        'Octocat',
      ])

      connection.choose('America/New_York')
      expect(methodsSignal.get()).toBe(methods)
      expect(captions.get()).toEqual([
        'Set · last changed Dec 31, 2025, 7:30:00 PM',
        'Octocat',
      ])
      expect(changes).toEqual([
        ['Set · last changed Dec 31, 2025, 7:30:00 PM', 'Octocat'],
      ])

      ingest(page, {
        entities: {
          identities: {
            id: 1,
            passwordChangedAt: Date.parse('2026-02-01T00:30:00Z'),
          },
        },
      })
      expect(captions.get()[0]).toBe(
        'Set · last changed Jan 31, 2026, 7:30:00 PM',
      )
      expect(methodsSignal.get()[0]?.identifier).toBe('account@example.test')

      ingest(page, {
        entities: {
          identities: { id: 2, providerName: 'New provider name' },
        },
      })
      expect(captions.get()[1]).toBe('New provider name')
      expect(methodsSignal.get()[1]?.providerName).toBe('New provider name')

      ingest(page, {
        entities: {
          identities: [
            { id: 1, passwordChangedAt: null },
            { id: 2, providerName: null },
          ],
        },
      })
      expect(captions.get()).toEqual(['Set', 'Linked'])
      expect(methodsSignal.get()).toMatchObject([
        { passwordChangedAt: null, providerName: null },
        { passwordChangedAt: null, providerName: null },
      ])
    } finally {
      stop()
      release()
    }
  })

  it.each([
    { passwordChangedAt: null, providerName: null },
    { passwordChangedAt: '1767227400000', providerName: 123 },
  ])('reads nullable metadata with typed fallbacks from %j', (metadata) => {
    const scopes = new ScopeManager()
    ingest(scopes.openPage('hilos_profile_sign_in'), {
      lists: {
        [HILOS_PROFILE_IDENTITIES_LIST]: {
          items: [
            {
              itemKey: 7,
              slots: {
                identities: [
                  {
                    id: 1,
                    type: 'password',
                    identifier: 'account@example.test',
                    verified: true,
                    ...metadata,
                  },
                ],
              },
            },
          ],
        },
      },
    })
    const [method] = createHilosProfileSignInMethods(scopes).get()
    expect(method).toMatchObject({
      passwordChangedAt: null,
      providerName: null,
    })
    expect(hilosProfileSignInSubtitle(method)).toBe('Set')
  })
})
