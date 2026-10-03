// Covers the profile root's state (HIL-1169): the row icons and ids, the
// summary of each section from the page's answer and the project's lists, the
// summaries a project without those lists leaves out, and the verified address
// the Email row shows.
import { describe, expect, it } from 'vitest'
import { type ActionLifecycle } from '../../src/connection/actionLifecycle.js'
import { type HilosConnection } from '../../src/connection/HilosConnection.js'
import { describeHilosNotificationChannels } from '../../src/notifications/notificationPreferences.js'
import {
  createHilosProfileRootStore,
  hilosProfileSectionIcon,
  hilosProfileSectionId,
  type HilosProfileBinding,
} from '../../src/profile/profileRoot.js'
import { resolveHilosProfileSignInMethods } from '../../src/profile/profileSignInMethods.js'
import { HilosPages } from '../../src/routing/hilosPages.js'
import { ScopeManager } from '../../src/state/ScopeManager.js'
import { createSignal } from '../../src/state/signal.js'

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

/** The profile page's scopes with its answer in the page data. */
function answeredScopes(authenticators: readonly unknown[] = []): ScopeManager {
  const scopes = new ScopeManager()
  scopes.session.data.set('currentUser', { type: 'user', id: 1 })
  const page = scopes.openPage(HilosPages.PROFILE)
  page.data.set('notificationPreferences', {
    channels: [
      { channel: 'email', label: 'Email', allowed: true, hasAddress: true },
    ],
    mandatoryNote: false,
  })
  page.data.set('secondFactor', {
    authenticators,
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
  })
  return scopes
}

/** A root store over the scopes and the binding; its connection carries no frames. */
function rootStore(scopes: ScopeManager, binding: HilosProfileBinding) {
  return createHilosProfileRootStore(
    {
      connection: { on: () => () => {} } as unknown as HilosConnection,
      scopes,
      actions: {} as ActionLifecycle,
    },
    binding,
  )
}

/** A binding with the name only, as polls and tasks hand it. */
function nameOnly(): HilosProfileBinding {
  return {
    name: createSignal('Ann'),
    rename: null,
    signInMethods: null,
    sessionCount: null,
    deviceCount: null,
  }
}

describe('profile root rows', () => {
  it('names each framework section by its icon and a project section by a folder', () => {
    expect(hilosProfileSectionIcon(HilosPages.PROFILE_SIGN_IN)).toBe('bi-key')
    expect(hilosProfileSectionIcon(HilosPages.PROFILE_SECURITY)).toBe(
      'bi-shield-lock',
    )
    expect(hilosProfileSectionIcon(HilosPages.PROFILE_DATA)).toBe('bi-download')
    expect(hilosProfileSectionIcon('app_profile_pets')).toBe('bi-folder')
  })

  it('derives the row id from the page key', () => {
    expect(hilosProfileSectionId(HilosPages.PROFILE_SIGN_IN)).toBe(
      'profile-sign-in',
    )
    expect(hilosProfileSectionId(HilosPages.PROFILE_DATA)).toBe('profile-data')
  })
})

describe('profile root store', () => {
  it('summarizes the framework sections from the answer and leaves the project ones out without lists', () => {
    const store = rootStore(answeredScopes(), nameOnly())
    store.start()

    const summaries = store.summaries.get()
    expect(summaries[HilosPages.PROFILE_SECURITY]).toBe(
      'Two-step verification is off',
    )
    expect(summaries[HilosPages.PROFILE_NOTIFICATIONS]).toBe(
      describeHilosNotificationChannels([
        { channel: 'email', label: 'Email', allowed: true, hasAddress: true },
      ]),
    )
    expect(summaries[HilosPages.PROFILE_DATA]).toBeDefined()
    expect(summaries[HilosPages.PROFILE_AGREEMENTS]).toBeDefined()
    expect(summaries).not.toHaveProperty(HilosPages.PROFILE_SIGN_IN)
    expect(summaries).not.toHaveProperty(HilosPages.PROFILE_SESSIONS)
    expect(summaries).not.toHaveProperty(HilosPages.PROFILE_DEVICES)
    expect(store.verifiedEmail.get()).toBeNull()
    store.dispose()
  })

  it('knows whether a person is signed in', () => {
    const scopes = answeredScopes()
    const store = rootStore(scopes, nameOnly())
    expect(store.signedIn.get()).toBe(true)
    scopes.session.data.set('currentUser', undefined)
    expect(store.signedIn.get()).toBe(false)
  })

  it('says two-step verification is on once an authenticator is there', () => {
    const store = rootStore(
      answeredScopes([
        { id: 7, label: 'Phone', createdAt: 1, lastUsedAt: null },
      ]),
      nameOnly(),
    )
    store.start()

    expect(store.summaries.get()[HilosPages.PROFILE_SECURITY]).toBe(
      'Two-step verification is on',
    )
    store.dispose()
  })

  it("summarizes the project's lists live and reads the verified address from its ways in", () => {
    const methods = createSignal(
      resolveHilosProfileSignInMethods([password, key], []),
    )
    const sessions = createSignal(1)
    const devices = createSignal(2)
    const store = rootStore(answeredScopes(), {
      ...nameOnly(),
      signInMethods: methods,
      sessionCount: sessions,
      deviceCount: devices,
    })
    store.start()

    expect(store.summaries.get()[HilosPages.PROFILE_SIGN_IN]).toBe(
      'Password, 1 passkey',
    )
    expect(store.summaries.get()[HilosPages.PROFILE_SESSIONS]).toBe(
      '1 active sign-in',
    )
    expect(store.summaries.get()[HilosPages.PROFILE_DEVICES]).toBe(
      '2 subscribed to push',
    )
    expect(store.verifiedEmail.get()).toBe('a@example.test')

    sessions.set(3)
    methods.set(resolveHilosProfileSignInMethods([key], []))
    expect(store.summaries.get()[HilosPages.PROFILE_SESSIONS]).toBe(
      '3 active sign-ins',
    )
    expect(store.summaries.get()[HilosPages.PROFILE_SIGN_IN]).toBe('1 passkey')
    expect(store.verifiedEmail.get()).toBeNull()
    store.dispose()
  })

  it('warns a passkey-only account live and stays quiet without a method list', () => {
    expect(rootStore(answeredScopes(), nameOnly()).passkeyOnly.get()).toBe(
      false,
    )
    const methods = createSignal(resolveHilosProfileSignInMethods([key], []))
    const store = rootStore(answeredScopes(), {
      ...nameOnly(),
      signInMethods: methods,
    })
    expect(store.passkeyOnly.get()).toBe(true)
    methods.set(resolveHilosProfileSignInMethods([password, key], []))
    expect(store.passkeyOnly.get()).toBe(false)
    methods.set([])
    expect(store.passkeyOnly.get()).toBe(false)
  })
})
