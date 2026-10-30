import { afterEach, describe, expect, it, vi } from 'vitest'
import { pageIdentity } from '../../src/admin/identity/hilosPageIdentity.js'
import { bindPageInstanceName } from '../../src/admin/identity/hilosPageInstanceName.js'
import { type ActionLifecycle } from '../../src/connection/actionLifecycle.js'
import { type HilosConnection } from '../../src/connection/HilosConnection.js'
import { createHilosProfileHeadStore } from '../../src/profile/profileHead.js'
import { type HilosProfileSignInIdentitySource } from '../../src/profile/profileSignInMethods.js'
import {
  SIGNAL_SECOND_FACTOR_STATE,
  type HilosSecondFactorState,
} from '../../src/profile/secondFactor.js'
import { type ProjectSignal } from '../../src/protocol/parseSignal.js'
import { HilosPages } from '../../src/routing/hilosPages.js'
import {
  bindSessionScope,
  SIGNAL_SECOND_FACTOR_POLICY,
} from '../../src/session/sessionScope.js'
import { ingest, type ScopePayload } from '../../src/state/normalizer.js'
import { ScopeManager, type Scope } from '../../src/state/ScopeManager.js'
import {
  createSignal,
  subscribeSignal,
  type ReadonlySignal,
} from '../../src/state/signal.js'

const cleanups: Array<() => void> = []
afterEach(() => {
  for (const cleanup of cleanups.splice(0).reverse()) cleanup()
})

const security: HilosSecondFactorState = {
  enabled: true,
  authenticators: [],
  backupCodesLeft: 0,
  backupCodesTotal: 0,
  required: false,
  resetWait: {
    days: 8,
    pendingDays: null,
    pendingFrom: null,
    defaultDays: 8,
    minDays: 1,
    maxDays: 30,
  },
  reset: null,
}
const policy = {
  enabled: true,
  required: 'none',
  trustDays: 30,
  backupCodes: 10,
  resetWaitDefaultDays: 8,
  resetWaitMinDays: 1,
  resetWaitMaxDays: 30,
}
const passkey = {
  id: 2,
  type: 'passkey',
  provider: null,
  identifier: 'opaque',
  verified: true,
}
const password = {
  id: 1,
  type: 'password',
  provider: null,
  identifier: 'ann@example.test',
  verified: true,
}
const tabs = [
  { page: HilosPages.PROFILE, label: 'Overview' },
  { page: HilosPages.PROFILE_SIGN_IN, label: 'Ways in' },
  { page: HilosPages.PROFILE_SECURITY, label: 'Security' },
  { page: HilosPages.PROFILE_AGREEMENTS, label: 'Agreements' },
  { page: HilosPages.PROFILE_AGREEMENTS_HISTORY, label: 'Revision history' },
  { page: 'project_profile_example', label: 'Project example' },
]

/** A complete first answer, with catalog captions distinct from the person's name. */
function answer(section: HilosSecondFactorState = security): ScopePayload {
  return {
    data: {
      pageLabel: 'Current section',
      pageLead: 'Section lead',
      pageHeadOwner: {
        page: HilosPages.PROFILE,
        label: 'Profile',
        lead: 'Your account',
        icon: 'bi-person',
      },
      pageBreadcrumb: [{ page: HilosPages.PROFILE, label: 'Profile' }],
      pageTabs: tabs,
      pageChildren: [],
      profilePhoto: true,
      secondFactor: section,
    },
  }
}

/** The real normalizer writes the browser list and its entity references. */
function waysIn(
  page: Scope,
  identities: readonly HilosProfileSignInIdentitySource[],
): void {
  ingest(page, {
    lists: {
      profileIdentities: {
        items: [{ itemKey: 7, slots: { identities, passkeyCredentials: [] } }],
      },
    },
  })
}

/** One page owner with a project-signal emitter and the real session binder. */
function setup(name?: ReadonlySignal<string>) {
  const listeners = new Set<(signal: ProjectSignal) => void>()
  const connection = {
    on(event: string, listener: (payload: never) => void): () => void {
      if (event !== 'projectSignal') return () => {}
      const typed = listener as (signal: ProjectSignal) => void
      listeners.add(typed)
      return () => {
        listeners.delete(typed)
      }
    },
    emit(type: string, data: unknown): void {
      for (const listener of [...listeners]) {
        listener({ kind: 'project', type, data } as ProjectSignal)
      }
    },
    listenerCount: () => listeners.size,
  }
  const scopes = new ScopeManager()
  const wire = connection as unknown as HilosConnection
  bindSessionScope(wire, scopes)
  const store = createHilosProfileHeadStore(
    { connection: wire, scopes, actions: {} as ActionLifecycle },
    name,
  )
  cleanups.push(() => store.dispose())
  connection.emit('handshake_response', {
    entities: { currentUser: { id: 7, name: 'Ann', photo: '/ann.jpg' } },
  })
  return { scopes, connection, store }
}

describe('profile head store', () => {
  it.each([
    HilosPages.PROFILE,
    HilosPages.PROFILE_SIGN_IN,
    HilosPages.PROFILE_NOTIFICATIONS,
    HilosPages.PROFILE_SESSIONS,
    HilosPages.PROFILE_DEVICES,
    HilosPages.PROFILE_SECURITY,
    HilosPages.PROFILE_AGREEMENTS,
    HilosPages.PROFILE_AGREEMENTS_HISTORY,
    HilosPages.PROFILE_DATA,
    'project_profile_example',
  ])('reads a direct %s answer without visiting the root', (key) => {
    const { scopes, store } = setup()
    const page = scopes.openPage(key)
    store.start()
    expect(store.identity.get()).toBeUndefined()
    expect(store.passkeyOnly.get()).toBe(false)
    expect(store.photoEnabled.get()).toBe(false)
    ingest(page, answer({ ...security, enabled: false }))
    waysIn(page, [password, passkey])

    expect(store.identity.get()).toEqual({
      label: 'Current section',
      lead: 'Section lead',
      pageHeadOwner: {
        page: HilosPages.PROFILE,
        label: 'Ann',
        lead: 'Your account',
        icon: 'bi-person',
      },
      breadcrumb: [{ page: HilosPages.PROFILE, label: 'Ann' }],
      pageTabs: [tabs[0], tabs[1], tabs[3], tabs[4], tabs[5]],
      children: [],
    })
    expect(store.name.get()).toBe('Ann')
    expect(store.photo.get()).toBe('/ann.jpg')
    expect(store.photoEnabled.get()).toBe(true)
    expect(store.verifiedEmail.get()).toBe('ann@example.test')
    expect(scopes.page()?.key).toBe(key)
  })

  it('follows session policy and full state frames without navigating off Security', () => {
    const { scopes, store, connection } = setup()
    const page = scopes.openPage(HilosPages.PROFILE_SECURITY)
    ingest(page, answer())
    store.start()
    const seen: Array<readonly string[]> = []
    cleanups.push(
      subscribeSignal(store.hiddenSections, (value) => seen.push(value)),
    )
    expect(store.identity.get()?.pageTabs).toEqual(tabs)

    connection.emit(SIGNAL_SECOND_FACTOR_POLICY, { ...policy, enabled: false })
    expect(store.hiddenSections.get()).toEqual([HilosPages.PROFILE_SECURITY])
    expect(store.identity.get()?.pageTabs).not.toContainEqual(tabs[2])
    connection.emit(SIGNAL_SECOND_FACTOR_STATE, {
      ...security,
      enabled: false,
      authenticators: [{ id: 3, label: 'Phone', createdAt: 1, lastUsedAt: 2 }],
      backupCodesLeft: 9,
      backupCodesTotal: 10,
    })
    expect(store.identity.get()?.pageTabs).toEqual(tabs)
    connection.emit(SIGNAL_SECOND_FACTOR_STATE, {
      ...security,
      enabled: false,
      reset: { requestedAt: 1, effectiveAt: 2 },
    })
    expect(store.hiddenSections.get()).toEqual([])
    connection.emit(SIGNAL_SECOND_FACTOR_STATE, { ...security, enabled: false })
    expect(store.hiddenSections.get()).toEqual([HilosPages.PROFILE_SECURITY])
    connection.emit(SIGNAL_SECOND_FACTOR_POLICY, policy)
    expect(store.identity.get()?.pageTabs).toEqual(tabs)
    expect(scopes.page()?.key).toBe(HilosPages.PROFILE_SECURITY)
    expect(seen).toEqual([
      [HilosPages.PROFILE_SECURITY],
      [],
      [HilosPages.PROFILE_SECURITY],
      [],
    ])

    // A malformed partial frame cannot invent an empty account state.
    connection.emit(SIGNAL_SECOND_FACTOR_STATE, { enabled: false })
    expect(store.hiddenSections.get()).toEqual([])
  })

  it('keeps the warning and verified address in step with the normalized ways in', () => {
    const { scopes, store } = setup()
    const page = scopes.openPage(HilosPages.PROFILE_AGREEMENTS_HISTORY)
    ingest(page, answer())
    store.start()
    expect(store.passkeyOnly.get()).toBe(false)
    expect(store.verifiedEmail.get()).toBeNull()
    waysIn(page, [passkey])
    expect(store.passkeyOnly.get()).toBe(true)
    waysIn(page, [passkey, { ...password, verified: false }])
    expect(store.passkeyOnly.get()).toBe(false)
    expect(store.verifiedEmail.get()).toBeNull()

    ingest(page, { entities: { identities: { id: 1, verified: true } } })
    expect(store.verifiedEmail.get()).toBe('ann@example.test')
    ingest(page, {
      entities: { identities: { id: 1, identifier: 'new@example.test' } },
    })
    expect(store.verifiedEmail.get()).toBe('new@example.test')
    waysIn(page, [passkey])
    expect(store.verifiedEmail.get()).toBeNull()
    expect(store.passkeyOnly.get()).toBe(true)
    waysIn(page, [passkey, { ...password, type: 'oauth', provider: 'example' }])
    expect(store.passkeyOnly.get()).toBe(false)
    expect(store.verifiedEmail.get()).toBeNull()
    waysIn(page, [passkey])
    expect(store.passkeyOnly.get()).toBe(true)
    waysIn(page, [])
    expect(store.passkeyOnly.get()).toBe(false)
  })

  it('binds a supplied live name for the shared head and title without renaming tabs', () => {
    const name = createSignal('Project name')
    const { scopes, store, connection } = setup(name)
    const page = scopes.openPage(HilosPages.PROFILE)
    ingest(page, answer())
    const sharedIdentity = pageIdentity(scopes)
    store.start()
    expect(store.name).toBe(name)
    expect(sharedIdentity.get()?.pageHeadOwner?.label).toBe('Project name')
    name.set('Renamed')
    expect(store.identity.get()?.pageHeadOwner?.label).toBe('Renamed')
    expect(sharedIdentity.get()?.breadcrumb).toEqual([
      { page: HilosPages.PROFILE, label: 'Renamed' },
    ])
    expect(store.identity.get()?.pageTabs).toEqual(tabs)
    connection.emit('handshake_response', {
      entities: {
        currentUser: { id: 7, name: 'Session name', photo: '/new.jpg' },
      },
    })
    expect(store.name.get()).toBe('Renamed')
    expect(store.photo.get()).toBe('/new.jpg')
    ingest(page, { data: { profilePhoto: false } })
    expect(store.photoEnabled.get()).toBe(false)
    name.set('')
    expect(store.identity.get()?.pageHeadOwner?.label).toBe('')
    store.dispose()
    expect(sharedIdentity.get()?.pageHeadOwner?.label).toBe('Profile')
  })

  it('reclaims its live name and caller cleanup without restarting second-factor listeners', () => {
    const name = createSignal('Project name')
    const { scopes, store, connection } = setup(name)
    ingest(scopes.openPage(HilosPages.PROFILE), answer())
    const listen = vi.spyOn(connection, 'on')
    store.start()
    connection.emit(SIGNAL_SECOND_FACTOR_STATE, { ...security, enabled: false })
    const stopOther = bindPageInstanceName(
      scopes,
      HilosPages.PROFILE,
      createSignal('Other producer'),
    )
    cleanups.push(stopOther)
    store.start()
    expect(pageIdentity(scopes).get()?.pageHeadOwner?.label).toBe(
      'Other producer',
    )

    store.claimNameBinding()
    store.claimNameBinding()
    stopOther()
    expect(pageIdentity(scopes).get()?.pageHeadOwner?.label).toBe(
      'Project name',
    )
    expect(store.hiddenSections.get()).toEqual([HilosPages.PROFILE_SECURITY])
    expect(listen).toHaveBeenCalledOnce()
    expect(connection.listenerCount()).toBe(2)
    name.set('Renamed')
    expect(store.identity.get()?.pageHeadOwner?.label).toBe('Renamed')

    store.dispose()
    expect(connection.listenerCount()).toBe(1)
    expect(pageIdentity(scopes).get()?.pageHeadOwner?.label).toBe('Profile')
    name.set('After disposal')
    expect(pageIdentity(scopes).get()?.pageHeadOwner?.label).toBe('Profile')
  })

  it('does not activate a name claim before start or after disposal', () => {
    const { scopes, store, connection } = setup()
    ingest(scopes.openPage(HilosPages.PROFILE), answer())
    const listen = vi.spyOn(connection, 'on')
    store.claimNameBinding()
    expect(pageIdentity(scopes).get()?.pageHeadOwner?.label).toBe('Profile')
    expect(listen).not.toHaveBeenCalled()
    expect(connection.listenerCount()).toBe(1)

    store.start()
    expect(pageIdentity(scopes).get()?.pageHeadOwner?.label).toBe('Ann')
    store.dispose()
    listen.mockClear()
    const stopOther = bindPageInstanceName(
      scopes,
      HilosPages.PROFILE,
      createSignal('Other producer'),
    )
    cleanups.push(stopOther)
    store.claimNameBinding()
    expect(pageIdentity(scopes).get()?.pageHeadOwner?.label).toBe(
      'Other producer',
    )
    stopOther()
    expect(pageIdentity(scopes).get()?.pageHeadOwner?.label).toBe('Profile')
    expect(listen).not.toHaveBeenCalled()
    expect(connection.listenerCount()).toBe(1)
  })

  it('drops the previous head and ways in during navigation and ignores the old scope', () => {
    const { scopes, store, connection } = setup()
    const first = scopes.openPage(HilosPages.PROFILE_SIGN_IN)
    ingest(first, answer())
    waysIn(first, [passkey])
    store.start()
    expect(store.passkeyOnly.get()).toBe(true)
    scopes.dropPage()
    expect(store.identity.get()).toBeUndefined()
    expect(store.verifiedEmail.get()).toBeNull()
    expect(store.passkeyOnly.get()).toBe(false)
    expect(store.photoEnabled.get()).toBe(false)

    const next = scopes.openPage(HilosPages.PROFILE_DATA)
    expect(store.identity.get()).toBeUndefined()
    ingest(first, { data: { pageLabel: 'Late old answer' } })
    expect(store.identity.get()).toBeUndefined()
    ingest(next, answer({ ...security, enabled: false }))
    expect(store.identity.get()?.label).toBe('Current section')
    expect(store.hiddenSections.get()).toEqual([HilosPages.PROFILE_SECURITY])
    connection.emit('handshake_response', {
      entities: { currentUser: { id: 7, name: 'Renamed', photo: null } },
    })
    expect(store.identity.get()?.pageHeadOwner?.label).toBe('Renamed')
    expect(store.photo.get()).toBeNull()
    ingest(first, answer())
    expect(store.hiddenSections.get()).toEqual([HilosPages.PROFILE_SECURITY])
  })

  it('releases its listeners and name binding and starts again for another person', () => {
    const { scopes, store, connection } = setup()
    const page = scopes.openPage(HilosPages.PROFILE_SESSIONS)
    ingest(page, answer({ ...security, enabled: false }))
    waysIn(page, [password])
    const sharedIdentity = pageIdentity(scopes)
    expect(connection.listenerCount()).toBe(1)
    store.start()
    store.start()
    expect(connection.listenerCount()).toBe(2)
    expect(store.hiddenSections.get()).toEqual([HilosPages.PROFILE_SECURITY])
    store.dispose()
    store.dispose()
    expect(connection.listenerCount()).toBe(1)
    expect(sharedIdentity.get()?.pageHeadOwner?.label).toBe('Profile')
    store.start()
    expect(connection.listenerCount()).toBe(2)
    expect(sharedIdentity.get()?.pageHeadOwner?.label).toBe('Ann')
    expect(store.hiddenSections.get()).toEqual([HilosPages.PROFILE_SECURITY])
    store.dispose()
    ingest(page, answer())
    connection.emit(SIGNAL_SECOND_FACTOR_STATE, { ...security, enabled: false })
    expect(store.hiddenSections.get()).toEqual([])

    scopes.dropPage()
    connection.emit('handshake_response', { entities: { currentUser: null } })
    expect(store.identity.get()).toBeUndefined()
    expect(store.name.get()).toBe('')
    expect(store.photo.get()).toBeNull()
    connection.emit('handshake_response', {
      entities: { currentUser: { id: 8, name: 'Ben', photo: '/ben.jpg' } },
    })
    const next = scopes.openPage(HilosPages.PROFILE_NOTIFICATIONS)
    store.start()
    expect(connection.listenerCount()).toBe(2)
    expect(store.identity.get()).toBeUndefined()
    expect(store.verifiedEmail.get()).toBeNull()
    ingest(next, answer())
    expect(store.identity.get()?.pageHeadOwner?.label).toBe('Ben')
    expect(sharedIdentity.get()?.pageHeadOwner?.label).toBe('Ben')
    expect(store.photo.get()).toBe('/ben.jpg')
    connection.emit(SIGNAL_SECOND_FACTOR_STATE, { ...security, enabled: false })
    expect(store.hiddenSections.get()).toEqual([HilosPages.PROFILE_SECURITY])
    store.dispose()
    expect(connection.listenerCount()).toBe(1)
    expect(sharedIdentity.get()?.pageHeadOwner?.label).toBe('Profile')
  })

  it('keeps independent applications and their account names isolated', () => {
    const first = setup()
    const second = setup()
    ingest(first.scopes.openPage(HilosPages.PROFILE), answer())
    ingest(second.scopes.openPage(HilosPages.PROFILE), answer())
    first.store.start()
    second.store.start()
    second.connection.emit('handshake_response', {
      entities: { currentUser: { id: 8, name: 'Ben', photo: null } },
    })
    expect(first.store.identity.get()?.pageHeadOwner?.label).toBe('Ann')
    expect(second.store.identity.get()?.pageHeadOwner?.label).toBe('Ben')
    first.store.dispose()
    expect(second.store.identity.get()?.pageHeadOwner?.label).toBe('Ben')
  })
})
