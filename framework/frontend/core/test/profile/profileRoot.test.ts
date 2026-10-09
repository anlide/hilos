// Covers the profile root's state (HIL-1169): the row icons and ids, the
// summary of each section from the page's answer and browser lists, the
// verified address the Email row shows, and the Theme row line (HIL-1434).
import { describe, expect, it, vi } from 'vitest'
import { type ActionLifecycle } from '../../src/connection/actionLifecycle.js'
import { type HilosConnection } from '../../src/connection/HilosConnection.js'
import { describeHilosNotificationChannels } from '../../src/notifications/notificationPreferences.js'
import { HILOS_PROFILE_IDENTITIES_LIST } from '../../src/profile/profileIdentities.js'
import { type ProjectSignal } from '../../src/protocol/parseSignal.js'
import {
  createHilosProfileRootStore,
  hilosProfileSectionIcon,
  hilosProfileSectionId,
  type HilosProfileBinding,
} from '../../src/profile/profileRoot.js'
import { type HilosProfileSignInIdentitySource } from '../../src/profile/profileSignInMethods.js'
import { HilosPages } from '../../src/routing/hilosPages.js'
import { bindSessionScope } from '../../src/session/sessionScope.js'
import { ScopeManager } from '../../src/state/ScopeManager.js'
import { ingest } from '../../src/state/normalizer.js'
import { createSignal } from '../../src/state/signal.js'
import {
  bindThemeState,
  setHilosThemePick,
} from '../../src/theme/themeState.js'

function fakeStorage(initial: Record<string, string> = {}): Storage {
  const entries = new Map<string, string>(Object.entries(initial))

  return {
    get length(): number {
      return entries.size
    },
    clear: () => entries.clear(),
    getItem: (key) => entries.get(key) ?? null,
    key: (index) => [...entries.keys()][index] ?? null,
    removeItem: (key) => {
      entries.delete(key)
    },
    setItem: (key, value) => {
      entries.set(key, value)
    },
  }
}

function fakeConnection() {
  const listeners = new Set<(signal: ProjectSignal) => void>()

  return {
    on(event: string, listener: (payload: never) => void): () => void {
      if (event === 'projectSignal') {
        listeners.add(listener as (signal: ProjectSignal) => void)
      }

      return () => listeners.delete(listener as (signal: ProjectSignal) => void)
    },
    emit(type: string, data: unknown): void {
      const signal = { type, data } as ProjectSignal
      for (const listener of listeners) {
        listener(signal)
      }
    },
    listenerCount: () => listeners.size,
  }
}

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

/** Put the framework's ways-in list in the current page and update it live. */
function setWaysIn(
  scopes: ScopeManager,
  identities: readonly HilosProfileSignInIdentitySource[],
): void {
  const page = scopes.page()
  if (!page) throw new Error('Profile page scope is missing')

  ingest(page, {
    lists: {
      [HILOS_PROFILE_IDENTITIES_LIST]: {
        items: [
          {
            itemKey: 1,
            slots: { identities, passkeyCredentials: [] },
          },
        ],
      },
    },
  })
}

/** A binding with the name only, as polls and tasks hand it. */
function nameOnly(): HilosProfileBinding {
  return {
    name: createSignal('Ann'),
    rename: null,
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
    expect(summaries[HilosPages.PROFILE_SIGN_IN]).toBe('No ways to sign in')
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

  it('summarizes the framework list and project lists live', () => {
    const scopes = answeredScopes()
    setWaysIn(scopes, [password, key])
    const sessions = createSignal(1)
    const devices = createSignal(2)
    const store = rootStore(scopes, {
      ...nameOnly(),
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
    setWaysIn(scopes, [key])
    expect(store.summaries.get()[HilosPages.PROFILE_SESSIONS]).toBe(
      '3 active sign-ins',
    )
    expect(store.summaries.get()[HilosPages.PROFILE_SIGN_IN]).toBe('1 passkey')
    expect(store.verifiedEmail.get()).toBeNull()
    store.dispose()
  })

  it('warns a passkey-only account live and stays quiet without a list', () => {
    expect(rootStore(answeredScopes(), nameOnly()).passkeyOnly.get()).toBe(
      false,
    )
    const scopes = answeredScopes()
    setWaysIn(scopes, [key])
    const store = rootStore(scopes, nameOnly())
    expect(store.passkeyOnly.get()).toBe(true)
    setWaysIn(scopes, [password, key])
    expect(store.passkeyOnly.get()).toBe(false)
    setWaysIn(scopes, [])
    expect(store.passkeyOnly.get()).toBe(false)
  })

  it('follows theme position, defaults, and switching live', () => {
    const storage = fakeStorage()
    vi.stubGlobal('localStorage', storage)
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    bindSessionScope(connection as unknown as HilosConnection, scopes)
    const releaseTheme = bindThemeState(
      connection as unknown as HilosConnection,
      scopes,
    )

    try {
      const store = rootStore(answeredScopes(), nameOnly())

      // «System · default» without choice
      setHilosThemePick(null)
      expect(store.theme.get()).toBe('System · default')

      // «Dark» with explicit dark
      setHilosThemePick('dark')
      expect(store.theme.get()).toBe('Dark')

      // «System» with explicit system and default system (no default mark)
      setHilosThemePick('system')
      expect(store.theme.get()).toBe('System')

      // «Dark · default» without choice when default dark
      connection.emit('hilos_theme_settings', {
        switchingEnabled: true,
        defaultTheme: 'dark',
      })
      setHilosThemePick(null)
      expect(store.theme.get()).toBe('Dark · default')

      // null when switchingEnabled=false with any choice
      connection.emit('hilos_theme_settings', {
        switchingEnabled: false,
        defaultTheme: 'dark',
      })
      expect(store.theme.get()).toBeNull()
      setHilosThemePick('light')
      expect(store.theme.get()).toBeNull()

      // Live change: pick, settings frame, off -> null -> on -> previous string
      connection.emit('hilos_theme_settings', {
        switchingEnabled: true,
        defaultTheme: 'system',
      })
      setHilosThemePick('light')
      expect(store.theme.get()).toBe('Light')

      connection.emit('hilos_theme_settings', {
        switchingEnabled: true,
        defaultTheme: 'dark',
      })
      expect(store.theme.get()).toBe('Light')

      connection.emit('hilos_theme_settings', {
        switchingEnabled: false,
        defaultTheme: 'dark',
      })
      expect(store.theme.get()).toBeNull()

      connection.emit('hilos_theme_settings', {
        switchingEnabled: true,
        defaultTheme: 'dark',
      })
      expect(store.theme.get()).toBe('Light')
    } finally {
      releaseTheme()
      setHilosThemePick(null)
      vi.unstubAllGlobals()
    }
  })
})
