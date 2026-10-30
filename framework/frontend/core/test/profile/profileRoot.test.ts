// Covers the profile root's state (HIL-1169): the row icons and ids, the
// shared head and removal of summary sources, the
// verified address the Email row shows, the Theme row line (HIL-1434), and the
// Security row once two-step verification is turned off for everyone
// (HIL-1303).
import { describe, expect, it, vi } from 'vitest'
import { ActionLifecycle } from '../../src/connection/actionLifecycle.js'
import { type HilosConnection } from '../../src/connection/HilosConnection.js'
import { HILOS_PROFILE_IDENTITIES_LIST } from '../../src/profile/profileIdentities.js'
import {
  type HandshakeSignal,
  type ProjectSignal,
} from '../../src/protocol/parseSignal.js'
import {
  createHilosProfileRootStore,
  HILOS_PROFILE_ROOT_COPY,
  hilosProfileSectionIcon,
  hilosProfileSectionId,
  type HilosProfileBinding,
} from '../../src/profile/profileRoot.js'
import { type HilosProfileSignInIdentitySource } from '../../src/profile/profileSignInMethods.js'
import { HilosPages } from '../../src/routing/hilosPages.js'
import {
  bindSessionScope,
  SIGNAL_SECOND_FACTOR_POLICY,
} from '../../src/session/sessionScope.js'
import { ScopeManager } from '../../src/state/ScopeManager.js'
import { ingest } from '../../src/state/normalizer.js'
import { createSignal, subscribeSignal } from '../../src/state/signal.js'
import {
  bindThemeState,
  setHilosThemePick,
} from '../../src/theme/themeState.js'
import { bindTimeZoneState } from '../../src/timeZone/timeZoneState.js'

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

function fakeConnection(projectTimeZone?: string) {
  const handshakeListeners = new Set<(signal: HandshakeSignal) => void>()
  const projectListeners = new Set<(signal: ProjectSignal) => void>()

  return {
    projectTimeZone,
    on(event: string, listener: (payload: never) => void): () => void {
      const listeners =
        event === 'handshake'
          ? (handshakeListeners as Set<(payload: never) => void>)
          : event === 'projectSignal'
            ? (projectListeners as Set<(payload: never) => void>)
            : new Set<(payload: never) => void>()
      listeners.add(listener)

      return () => {
        listeners.delete(listener)
      }
    },
    welcome(zone?: string): void {
      this.projectTimeZone = zone
      const signal = {
        type: 'handshake',
        data: { build: 'test' },
      } as unknown as HandshakeSignal
      for (const listener of handshakeListeners) {
        listener(signal)
      }
    },
    emit(type: string, data: unknown): void {
      const signal = { type, data } as ProjectSignal
      for (const listener of projectListeners) {
        listener(signal)
      }
    },
    listenerCount: () => handshakeListeners.size + projectListeners.size,
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

/**
 * The profile page's scopes with its answer in the page data.
 *
 * @param authenticators The person's apps in the second-factor section.
 * @param section Members of that section to set otherwise.
 */
function answeredScopes(
  authenticators: readonly unknown[] = [],
  section: Record<string, unknown> = {},
): ScopeManager {
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
    enabled: true,
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
    ...section,
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
  it('shares one head and never reads removed summaries or compatibility counts', () => {
    const scopes = answeredScopes()
    const page = scopes.page()!
    page.data.set('pageLabel', 'Profile')
    page.data.set('pageHeadOwner', {
      page: HilosPages.PROFILE,
      label: 'Profile',
      lead: '',
    })
    page.data.set('pageTabs', [{ page: HilosPages.PROFILE, label: 'Overview' }])
    const name = createSignal('Ann')
    const readCount = vi.fn(() => 2)
    const connection = fakeConnection()
    const store = createHilosProfileRootStore(
      {
        scopes,
        connection: connection as unknown as HilosConnection,
        actions: {} as ActionLifecycle,
      },
      {
        name,
        rename: null,
        sessionCount: { get: readCount },
        deviceCount: { get: readCount },
      },
    )
    const empty = store.summaries.get()
    store.start()
    store.start()
    expect(connection.listenerCount()).toBe(1)
    expect(store.head.identity.get()?.pageHeadOwner?.label).toBe('Ann')
    expect(store.photo).toBe(store.head.photo)
    expect(store.photoEnabled).toBe(store.head.photoEnabled)
    expect(store.verifiedEmail).toBe(store.head.verifiedEmail)
    expect(store.passkeyOnly).toBe(store.head.passkeyOnly)
    expect(store.hiddenSections).toBe(store.head.hiddenSections)
    expect(empty).toEqual({})
    page.data.set('notificationPreferences', { channels: [] })
    page.data.set('legalAgreements', { documents: [] })
    page.data.set('dataExport', { status: 'ready' })
    expect(store.summaries.get()).toBe(empty)
    expect(readCount).not.toHaveBeenCalled()
    name.set('Beth')
    expect(store.head.identity.get()?.pageHeadOwner?.label).toBe('Beth')
    store.dispose()
    expect(connection.listenerCount()).toBe(0)
    expect(store.head.identity.get()?.pageHeadOwner?.label).toBe('Profile')
    store.start()
    expect(connection.listenerCount()).toBe(1)
    expect(store.head.identity.get()?.pageHeadOwner?.label).toBe('Beth')
    store.dispose()
  })

  it('knows whether a person is signed in', () => {
    const scopes = answeredScopes()
    const store = rootStore(scopes, nameOnly())
    expect(store.signedIn.get()).toBe(true)
    scopes.session.data.set('currentUser', undefined)
    expect(store.signedIn.get()).toBe(false)
  })

  it('keeps Security available once an authenticator is there', () => {
    const store = rootStore(
      answeredScopes([
        { id: 7, label: 'Phone', createdAt: 1, lastUsedAt: null },
      ]),
      nameOnly(),
    )
    store.start()
    expect(store.hiddenSections.get()).toEqual([])
    store.dispose()
  })

  it('keeps Security for a person with an app when the administrator turns it off', () => {
    const store = rootStore(
      answeredScopes(
        [{ id: 7, label: 'Phone', createdAt: 1, lastUsedAt: null }],
        { enabled: false },
      ),
      nameOnly(),
    )
    store.start()

    expect(store.hiddenSections.get()).toStrictEqual([])
    store.dispose()
  })

  it('keeps the row while a removal stands, and hides it with no app and no removal', () => {
    const removing = rootStore(
      answeredScopes([], {
        enabled: false,
        reset: { requestedAt: 1, effectiveAt: 2 },
      }),
      nameOnly(),
    )
    removing.start()
    expect(removing.hiddenSections.get()).toStrictEqual([])
    removing.dispose()

    const nothing = rootStore(
      answeredScopes([], { enabled: false }),
      nameOnly(),
    )
    nothing.start()
    expect(nothing.hiddenSections.get()).toStrictEqual([
      HilosPages.PROFILE_SECURITY,
    ])
    nothing.dispose()
  })

  it('hides no row while two-step verification is on or not answered yet', () => {
    const scopes = new ScopeManager()
    scopes.openPage(HilosPages.PROFILE)
    const unanswered = rootStore(scopes, nameOnly())
    unanswered.start()
    expect(unanswered.hiddenSections.get()).toStrictEqual([])
    unanswered.dispose()

    const on = rootStore(answeredScopes(), nameOnly())
    on.start()
    expect(on.hiddenSections.get()).toStrictEqual([])
    on.dispose()
  })

  it('follows the policy frame off and back on with no reload', () => {
    const connection = fakeConnection()
    const scopes = answeredScopes()
    bindSessionScope(connection as unknown as HilosConnection, scopes)
    const store = rootStore(scopes, nameOnly())
    store.start()
    const policy = {
      enabled: false,
      required: 'none',
      trustDays: 30,
      backupCodes: 10,
      resetWaitDefaultDays: 8,
      resetWaitMinDays: 1,
      resetWaitMaxDays: 30,
    }

    connection.emit(SIGNAL_SECOND_FACTOR_POLICY, policy)
    expect(store.hiddenSections.get()).toStrictEqual([
      HilosPages.PROFILE_SECURITY,
    ])

    connection.emit(SIGNAL_SECOND_FACTOR_POLICY, { ...policy, enabled: true })
    expect(store.hiddenSections.get()).toStrictEqual([])
    store.dispose()
  })

  it('follows the common identity list while legacy counts have no projection', () => {
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

    expect(store.verifiedEmail.get()).toBe('a@example.test')
    expect(store.hiddenSections.get()).toEqual([])
    expect(store.summaries.get()).toEqual({})

    sessions.set(3)
    setWaysIn(scopes, [key])
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
    const actions = new ActionLifecycle({
      sendAction: () => true,
      on: () => () => {},
    })
    const releaseTheme = bindThemeState(
      connection as unknown as HilosConnection,
      scopes,
      actions,
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

  it('exposes time zone and automatic copy words', () => {
    expect(HILOS_PROFILE_ROOT_COPY.timeZone).toBe('Time zone')
    expect(HILOS_PROFILE_ROOT_COPY.automatic).toBe('automatic')
  })

  it('follows own time zone, browser, and automatic fallback live', () => {
    const resolved = new Intl.DateTimeFormat().resolvedOptions()
    function reportZone(timeZone: unknown): void {
      vi.spyOn(
        Intl.DateTimeFormat.prototype,
        'resolvedOptions',
      ).mockReturnValue({
        ...resolved,
        timeZone: timeZone as string,
      })
    }

    reportZone('Europe/Warsaw')
    const windowEvents = new EventTarget()
    const page = Object.assign(new EventTarget(), {
      visibilityState: 'visible' as DocumentVisibilityState,
    })
    vi.stubGlobal(
      'addEventListener',
      windowEvents.addEventListener.bind(windowEvents),
    )
    vi.stubGlobal(
      'removeEventListener',
      windowEvents.removeEventListener.bind(windowEvents),
    )
    vi.stubGlobal('document', page)

    const connection = fakeConnection()
    const releaseTimeZone = bindTimeZoneState(
      connection as unknown as HilosConnection,
    )

    try {
      const store = rootStore(answeredScopes(), nameOnly())
      const seen: string[] = []
      const unsubscribe = subscribeSignal(store.timeZone, (value) => {
        seen.push(value)
      })

      try {
        // Automatic browser (Europe/Warsaw)
        expect(store.timeZone.get()).toBe('Europe/Warsaw · automatic')

        // own Asia/Tokyo
        connection.emit('handshake_response', {
          entities: {
            currentUser: {
              type: 'user',
              id: 1,
              timeZone: 'Asia/Tokyo',
            },
          },
        })
        expect(store.timeZone.get()).toBe('Asia/Tokyo')

        // live own change
        connection.emit('handshake_response', {
          entities: {
            currentUser: {
              type: 'user',
              id: 1,
              timeZone: 'Europe/Paris',
            },
          },
        })
        expect(store.timeZone.get()).toBe('Europe/Paris')

        // reset null
        connection.emit('handshake_response', {
          entities: {
            currentUser: {
              type: 'user',
              id: 1,
              timeZone: null,
            },
          },
        })
        expect(store.timeZone.get()).toBe('Europe/Warsaw · automatic')

        // own == browser -> null (name is the same, but automatic mark appears)
        connection.emit('handshake_response', {
          entities: {
            currentUser: {
              type: 'user',
              id: 1,
              timeZone: 'Europe/Warsaw',
            },
          },
        })
        expect(store.timeZone.get()).toBe('Europe/Warsaw')

        connection.emit('handshake_response', {
          entities: {
            currentUser: {
              type: 'user',
              id: 1,
              timeZone: null,
            },
          },
        })
        expect(store.timeZone.get()).toBe('Europe/Warsaw · automatic')

        // browser refresh via focus / visible when Automatic
        reportZone('America/Chicago')
        windowEvents.dispatchEvent(new Event('focus'))
        expect(store.timeZone.get()).toBe('America/Chicago · automatic')

        reportZone('Australia/Sydney')
        page.visibilityState = 'visible'
        page.dispatchEvent(new Event('visibilitychange'))
        expect(store.timeZone.get()).toBe('Australia/Sydney · automatic')

        // absence of browser -> project -> UTC
        reportZone(null)
        connection.welcome('America/New_York')
        expect(store.timeZone.get()).toBe('America/New_York · automatic')

        connection.welcome(undefined)
        expect(store.timeZone.get()).toBe('UTC · automatic')

        // Verify computed subscriptions captured every transition
        expect(seen).toEqual([
          'Asia/Tokyo',
          'Europe/Paris',
          'Europe/Warsaw · automatic',
          'Europe/Warsaw',
          'Europe/Warsaw · automatic',
          'America/Chicago · automatic',
          'Australia/Sydney · automatic',
          'America/New_York · automatic',
          'UTC · automatic',
        ])
      } finally {
        unsubscribe()
      }
    } finally {
      releaseTimeZone()
      vi.restoreAllMocks()
      vi.unstubAllGlobals()
    }
  })
})
