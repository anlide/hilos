import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  eraseBrowserValues,
  HILOS_BROWSER_VALUES,
} from '../../src/browser/browserValues.js'
import {
  ActionError,
  type ActionLifecycle,
  type ActionResult,
} from '../../src/connection/actionLifecycle.js'
import {
  type ConnectionState,
  type HilosConnection,
  type ProjectSignal,
} from '../../src/index.js'
import { bindSessionScope } from '../../src/session/sessionScope.js'
import { ScopeManager } from '../../src/state/ScopeManager.js'
import { createSignal } from '../../src/state/signal.js'
import { hilosToasts } from '../../src/state/toasts.js'
import {
  THEME_PICK_STORAGE_KEY,
  THEME_SETTINGS_STORAGE_KEY,
} from '../../src/theme/themeBrowser.js'
import {
  bindThemeState,
  hilosThemeChoice,
  hilosThemeMode,
  hilosThemePick,
  setHilosThemePick,
} from '../../src/theme/themeState.js'

function fakeStorage(): Storage {
  const entries = new Map<string, string>()

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
  const stateListeners = new Set<(state: ConnectionState) => void>()

  return {
    on(event: string, listener: (payload: never) => void): () => void {
      if (event === 'projectSignal') {
        listeners.add(listener as (signal: ProjectSignal) => void)
      }
      if (event === 'state') {
        stateListeners.add(listener as (state: ConnectionState) => void)
      }

      return () => {
        listeners.delete(listener as (signal: ProjectSignal) => void)
        stateListeners.delete(listener as (state: ConnectionState) => void)
      }
    },
    emit(type: string, data: unknown): void {
      const signal = { type, data } as ProjectSignal
      for (const listener of listeners) {
        listener(signal)
      }
    },
    emitState(state: ConnectionState): void {
      for (const listener of stateListeners) {
        listener(state)
      }
    },
    listenerCount: () => listeners.size,
    stateListenerCount: () => stateListeners.size,
  }
}

function fakeActions(done: Promise<ActionResult> = Promise.resolve({})) {
  return {
    dispatch: vi.fn(() => ({
      requestId: 'adopt',
      loading: createSignal(false),
      done,
    })),
  }
}

let storage: Storage
let storageEvents: EventTarget
let mediaListeners: Set<(event: MediaQueryListEvent) => void>
let mediaMatches = false
let attributes: Map<string, string>
let release: (() => void) | null = null

function start(actions = fakeActions(), bind = bindThemeState) {
  const connection = fakeConnection()
  const scopes = new ScopeManager()
  bindSessionScope(connection as unknown as HilosConnection, scopes)
  release = bind(
    connection as unknown as HilosConnection,
    scopes,
    actions as unknown as ActionLifecycle,
  )

  return { ...connection, actions }
}

function storageChange(key: string | null): void {
  const event = new Event('storage')
  Object.defineProperty(event, 'key', { value: key })
  storageEvents.dispatchEvent(event)
}

function systemChange(matches: boolean): void {
  mediaMatches = matches
  for (const listener of mediaListeners) {
    listener({ matches } as MediaQueryListEvent)
  }
}

beforeEach(() => {
  hilosToasts.clear()
  storage = fakeStorage()
  storageEvents = new EventTarget()
  mediaListeners = new Set()
  mediaMatches = false
  attributes = new Map()
  vi.stubGlobal('localStorage', storage)
  vi.stubGlobal(
    'addEventListener',
    storageEvents.addEventListener.bind(storageEvents),
  )
  vi.stubGlobal(
    'removeEventListener',
    storageEvents.removeEventListener.bind(storageEvents),
  )
  vi.stubGlobal('matchMedia', () => ({
    get matches() {
      return mediaMatches
    },
    addEventListener: (
      _type: string,
      listener: (event: MediaQueryListEvent) => void,
    ) => {
      mediaListeners.add(listener)
    },
    removeEventListener: (
      _type: string,
      listener: (event: MediaQueryListEvent) => void,
    ) => {
      mediaListeners.delete(listener)
    },
  }))
  vi.stubGlobal('document', {
    documentElement: {
      setAttribute: (key: string, value: string) => attributes.set(key, value),
    },
  })
})

afterEach(() => {
  release?.()
  release = null
  vi.unstubAllGlobals()
})

describe('theme binding', () => {
  const picks = ['light', 'dark', 'system', null] as const
  const cases = picks.flatMap((account) =>
    picks.map((browser) => ({ account, browser })),
  )

  describe.each(['sign-in', 'resume', 'restate'])('%s', (entry) => {
    it.each(cases)(
      'account $account, browser $browser',
      ({ account, browser }) => {
        if (browser !== null) {
          storage.setItem(THEME_PICK_STORAGE_KEY, browser)
        }
        mediaMatches = true
        const connection = start()
        if (entry === 'sign-in') {
          connection.emit('handshake_response', {
            entities: { currentUser: null },
          })
        }
        const frame = {
          entities: { currentUser: { id: 1, themePick: account } },
          data: {
            themeSettings: { switchingEnabled: true, defaultTheme: 'light' },
          },
        }
        connection.emit('handshake_response', frame)
        if (entry === 'restate') {
          connection.emit('handshake_response', frame)
          connection.emit('handshake_response', frame)
        }

        const expected = account ?? browser
        expect(hilosThemePick.get()).toBe(expected)
        expect(hilosThemeMode.get()).toBe(
          expected === 'dark' || expected === 'system' ? 'dark' : 'light',
        )
        expect(storage.getItem(THEME_PICK_STORAGE_KEY)).toBe(expected)
        if (account === null && browser !== null) {
          expect(connection.actions.dispatch).toHaveBeenCalledExactlyOnceWith(
            'hilos_theme_adopt',
            { themePick: browser },
          )
        } else {
          expect(connection.actions.dispatch).not.toHaveBeenCalled()
        }
        connection.emit('hilos_theme_settings', {
          switchingEnabled: false,
          defaultTheme: 'light',
        })
        expect(hilosThemeMode.get()).toBe('light')
        expect(hilosThemePick.get()).toBe(expected)
        expect(storage.getItem(THEME_PICK_STORAGE_KEY)).toBe(expected)
        connection.emit('hilos_theme_settings', {
          switchingEnabled: true,
          defaultTheme: 'light',
        })
        expect(hilosThemeMode.get()).toBe(
          expected === 'dark' || expected === 'system' ? 'dark' : 'light',
        )
        expect(hilosThemePick.get()).toBe(expected)
      },
    )
  })

  it('follows the authoritative account, ignores signed-in storage and preserves it on sign-out', () => {
    storage.setItem(THEME_PICK_STORAGE_KEY, 'light')
    const connection = start()
    connection.emit('handshake_response', {
      entities: { currentUser: { id: 1, themePick: 'dark' } },
    })
    storage.setItem(THEME_PICK_STORAGE_KEY, 'light')
    storageChange(THEME_PICK_STORAGE_KEY)
    expect(hilosThemePick.get()).toBe('dark')
    storage.clear()
    storageChange(null)
    expect(hilosThemePick.get()).toBe('dark')
    expect(hilosThemeMode.get()).toBe('dark')

    connection.emit('handshake_response', {
      entities: { currentUser: { id: 1, themePick: 'system' } },
    })
    expect(hilosThemePick.get()).toBe('system')
    expect(storage.getItem(THEME_PICK_STORAGE_KEY)).toBe('system')
    systemChange(true)
    expect(hilosThemeMode.get()).toBe('dark')
    connection.emit('handshake_response', {
      entities: { currentUser: null },
    })
    expect(hilosThemePick.get()).toBe('system')
    expect(hilosThemeMode.get()).toBe('dark')
    expect(storage.getItem(THEME_PICK_STORAGE_KEY)).toBe('system')

    storage.setItem(THEME_PICK_STORAGE_KEY, 'light')
    storageChange(THEME_PICK_STORAGE_KEY)
    expect(hilosThemePick.get()).toBe('light')
    expect(connection.actions.dispatch).not.toHaveBeenCalled()
  })

  it('keeps an anonymous tab choice even when its browser copy has changed', () => {
    const connection = start()
    setHilosThemePick('dark')
    storage.setItem(THEME_PICK_STORAGE_KEY, 'light')
    connection.emit('handshake_response', {
      entities: { currentUser: null },
    })
    expect(hilosThemePick.get()).toBe('dark')
    expect(hilosThemeMode.get()).toBe('dark')
    expect(storage.getItem(THEME_PICK_STORAGE_KEY)).toBe('light')
    expect(connection.actions.dispatch).not.toHaveBeenCalled()
  })

  it('keeps memory and adopts its guest choice when storage refuses every operation', () => {
    vi.stubGlobal('localStorage', {
      getItem: () => {
        throw new Error('refused')
      },
      setItem: () => {
        throw new Error('refused')
      },
      removeItem: () => {
        throw new Error('refused')
      },
    })
    const connection = start()
    setHilosThemePick('dark')
    connection.emit('handshake_response', {
      entities: { currentUser: { id: 1, themePick: null } },
    })
    expect(hilosThemePick.get()).toBe('dark')
    expect(connection.actions.dispatch).toHaveBeenCalledExactlyOnceWith(
      'hilos_theme_adopt',
      { themePick: 'dark' },
    )
    connection.emit('handshake_response', {
      entities: { currentUser: { id: 1, themePick: 'light' } },
    })
    expect(hilosThemePick.get()).toBe('light')
    expect(hilosThemeMode.get()).toBe('light')
    connection.emit('handshake_response', {
      entities: { currentUser: null },
    })
    expect(hilosThemePick.get()).toBe('light')
    expect(hilosThemeMode.get()).toBe('light')
  })

  it.each([
    {},
    { entities: {} },
    { entities: { currentUser: { id: 1 } } },
    ...['invalid', '', false, 0, {}, []].map((themePick) => ({
      entities: { currentUser: { id: 1, themePick } },
    })),
  ])(
    'preserves a known choice on a missing or malformed answer: %j',
    (frame) => {
      storage.setItem(THEME_PICK_STORAGE_KEY, 'light')
      const connection = start()
      connection.emit('handshake_response', {
        entities: { currentUser: { id: 1, themePick: 'dark' } },
      })
      connection.emit('handshake_response', frame)
      expect(storage.getItem(THEME_PICK_STORAGE_KEY)).toBe('dark')
      storage.setItem(THEME_PICK_STORAGE_KEY, 'system')
      storageChange(THEME_PICK_STORAGE_KEY)
      expect(hilosThemePick.get()).toBe('dark')
      expect(hilosThemeMode.get()).toBe('dark')
      expect(connection.actions.dispatch).not.toHaveBeenCalled()
    },
  )

  it('deduplicates adoption by person and choice until a new socket opens', () => {
    storage.setItem(THEME_PICK_STORAGE_KEY, 'dark')
    const connection = start()
    const frame = (id: string) => ({
      entities: { currentUser: { id, themePick: null } },
    })
    connection.emit('handshake_response', frame('ada'))
    connection.emit('handshake_response', frame('bob'))
    connection.emit('handshake_response', frame('ada'))
    expect(connection.actions.dispatch).toHaveBeenCalledTimes(2)

    connection.emit('handshake_response', {
      entities: { currentUser: null },
    })
    setHilosThemePick('light')
    connection.emit('handshake_response', frame('ada'))
    connection.emit('handshake_response', frame('ada'))
    expect(connection.actions.dispatch).toHaveBeenCalledTimes(3)
    expect(connection.actions.dispatch).toHaveBeenLastCalledWith(
      'hilos_theme_adopt',
      { themePick: 'light' },
    )
  })

  it.each(['fail', 'timeout', 'disconnected'] as const)(
    'handles %s silently, never loops on restate, and retries on a new connection',
    async (outcome) => {
      storage.setItem(THEME_PICK_STORAGE_KEY, 'dark')
      const done = Promise.reject(
        new ActionError('hilos_theme_adopt', outcome, 'Refused'),
      )
      const catchDone = vi.spyOn(done, 'catch')
      const connection = start(fakeActions(done))
      const frame = {
        entities: { currentUser: { id: 1, themePick: null } },
      }
      connection.emit('handshake_response', frame)
      await Promise.resolve()
      expect(catchDone).toHaveBeenCalledOnce()
      connection.emit('handshake_response', frame)
      expect(connection.actions.dispatch).toHaveBeenCalledOnce()
      expect(hilosThemePick.get()).toBe('dark')
      expect(hilosThemeMode.get()).toBe('dark')
      expect(hilosToasts.toasts.get()).toEqual([])

      connection.emitState('reconnecting')
      connection.emitState('connected')
      connection.emit('handshake_response', frame)
      connection.emit('handshake_response', frame)
      expect(connection.actions.dispatch).toHaveBeenCalledTimes(2)
      await Promise.resolve()

      const independent = start()
      independent.emit('handshake_response', frame)
      expect(independent.actions.dispatch).toHaveBeenCalledOnce()
      independent.emit('handshake_response', {
        entities: { currentUser: { id: 1, themePick: 'light' } },
      })
      expect(hilosThemePick.get()).toBe('light')
      expect(storage.getItem(THEME_PICK_STORAGE_KEY)).toBe('light')
      expect(hilosToasts.toasts.get()).toEqual([])
    },
  )

  it('delivers a new authoritative account choice to two independent tab contexts', async () => {
    storage.setItem(THEME_PICK_STORAGE_KEY, 'light')
    const first = start()
    const releaseFirst = release
    // A fresh module graph represents another tab's independent theme memory.
    vi.resetModules()
    const secondTheme = await import('../../src/theme/themeState.js')
    const second = start(fakeActions(), secondTheme.bindThemeState)
    try {
      const empty = {
        entities: { currentUser: { id: 1, themePick: null } },
      }
      first.emit('handshake_response', empty)
      second.emit('handshake_response', empty)
      expect(first.actions.dispatch).toHaveBeenCalledOnce()
      expect(second.actions.dispatch).toHaveBeenCalledOnce()
      const authoritative = {
        entities: { currentUser: { id: 1, themePick: 'dark' } },
      }
      first.emit('handshake_response', authoritative)
      expect(hilosThemePick.get()).toBe('dark')
      expect(secondTheme.hilosThemePick.get()).toBe('light')
      storageChange(THEME_PICK_STORAGE_KEY)
      expect(secondTheme.hilosThemePick.get()).toBe('light')
      second.emit('handshake_response', authoritative)
      expect(secondTheme.hilosThemePick.get()).toBe('dark')
      expect(hilosThemeMode.get()).toBe('dark')
      expect(secondTheme.hilosThemeMode.get()).toBe('dark')
      expect(storage.getItem(THEME_PICK_STORAGE_KEY)).toBe('dark')
      expect(first.actions.dispatch).toHaveBeenCalledOnce()
      expect(second.actions.dispatch).toHaveBeenCalledOnce()
    } finally {
      releaseFirst?.()
    }
  })

  it('loads cache, follows handshake, live frame and reconnect, and ignores bad snapshots', () => {
    storage.setItem(THEME_PICK_STORAGE_KEY, 'system')
    storage.setItem(
      THEME_SETTINGS_STORAGE_KEY,
      JSON.stringify({ switchingEnabled: true, defaultTheme: 'light' }),
    )
    mediaMatches = true
    const connection = start()
    expect(hilosThemePick.get()).toBe('system')
    expect(hilosThemeMode.get()).toBe('dark')
    expect(attributes.get('data-bs-theme')).toBe('dark')

    connection.emit('handshake_response', {
      data: {
        themeSettings: { switchingEnabled: false, defaultTheme: 'light' },
      },
    })
    expect(hilosThemeMode.get()).toBe('light')
    expect(
      JSON.parse(storage.getItem(THEME_SETTINGS_STORAGE_KEY) ?? ''),
    ).toEqual({
      switchingEnabled: false,
      defaultTheme: 'light',
    })

    connection.emit('hilos_theme_settings', {
      switchingEnabled: true,
      defaultTheme: 'system',
    })
    expect(hilosThemeMode.get()).toBe('dark')
    connection.emit('hilos_theme_settings', {
      switchingEnabled: false,
      defaultTheme: 'invalid',
    })
    connection.emit('handshake_response', { data: {} })
    expect(hilosThemeMode.get()).toBe('dark')
    expect(
      JSON.parse(storage.getItem(THEME_SETTINGS_STORAGE_KEY) ?? ''),
    ).toEqual({
      switchingEnabled: true,
      defaultTheme: 'system',
    })

    connection.emit('handshake_response', {
      data: {
        themeSettings: { switchingEnabled: false, defaultTheme: 'dark' },
      },
    })
    expect(hilosThemeMode.get()).toBe('dark')
  })

  it('responds to setter, system changes, other tabs and same-tab privacy erase', () => {
    const connection = start()
    expect(hilosThemeMode.get()).toBe('light')
    setHilosThemePick('dark')
    expect(storage.getItem(THEME_PICK_STORAGE_KEY)).toBe('dark')
    expect(attributes.get('data-bs-theme')).toBe('dark')

    connection.emit('hilos_theme_settings', {
      switchingEnabled: false,
      defaultTheme: 'light',
    })
    expect(hilosThemePick.get()).toBe('dark')
    expect(hilosThemeMode.get()).toBe('light')
    connection.emit('hilos_theme_settings', {
      switchingEnabled: true,
      defaultTheme: 'system',
    })
    expect(hilosThemeMode.get()).toBe('dark')

    setHilosThemePick('system')
    systemChange(true)
    expect(hilosThemeMode.get()).toBe('dark')
    systemChange(false)
    expect(hilosThemeMode.get()).toBe('light')
    storage.setItem(THEME_PICK_STORAGE_KEY, 'dark')
    storageChange(THEME_PICK_STORAGE_KEY)
    expect(hilosThemePick.get()).toBe('dark')
    storage.removeItem(THEME_PICK_STORAGE_KEY)
    storageChange(null)
    expect(hilosThemePick.get()).toBeNull()
    expect(hilosThemeMode.get()).toBe('light')

    setHilosThemePick('dark')
    eraseBrowserValues(HILOS_BROWSER_VALUES, { sessionCookieName: undefined })
    expect(hilosThemePick.get()).toBeNull()
    expect(hilosThemeMode.get()).toBe('light')
    expect(storage.getItem(THEME_SETTINGS_STORAGE_KEY)).toBeNull()
  })

  it('keeps the current tab live when storage refuses and releases prior listeners on rebind', () => {
    const first = start()
    expect(mediaListeners.size).toBe(1)
    const refused = {
      ...fakeStorage(),
      getItem: () => {
        throw new Error('refused')
      },
      setItem: () => {
        throw new Error('refused')
      },
      removeItem: () => {
        throw new Error('refused')
      },
    } as Storage
    vi.stubGlobal('localStorage', refused)
    setHilosThemePick('dark')
    expect(hilosThemePick.get()).toBe('dark')
    expect(hilosThemeMode.get()).toBe('dark')
    eraseBrowserValues(HILOS_BROWSER_VALUES, { sessionCookieName: undefined })
    expect(hilosThemePick.get()).toBe('dark')

    const second = start()
    expect(first.listenerCount()).toBe(1) // session scope remains; theme listener left
    expect(first.stateListenerCount()).toBe(0)
    expect(second.listenerCount()).toBe(2)
    expect(second.stateListenerCount()).toBe(1)
    expect(mediaListeners.size).toBe(1)
    expect(hilosThemePick.get()).toBeNull()
    release?.()
    expect(mediaListeners.size).toBe(0)
    expect(second.listenerCount()).toBe(1)
    expect(second.stateListenerCount()).toBe(0)
  })

  it('publishes the header choice from the settings the page wears', () => {
    storage.setItem(
      THEME_SETTINGS_STORAGE_KEY,
      JSON.stringify({ switchingEnabled: false, defaultTheme: 'dark' }),
    )
    const connection = start()
    expect(hilosThemeChoice.get()).toBeNull()

    connection.emit('handshake_response', {
      data: {
        themeSettings: { switchingEnabled: true, defaultTheme: 'dark' },
      },
    })
    expect(hilosThemeChoice.get()).toEqual({
      position: 'dark',
      isDefault: true,
    })

    setHilosThemePick('dark')
    expect(hilosThemeChoice.get()).toEqual({
      position: 'dark',
      isDefault: false,
    })

    connection.emit('hilos_theme_settings', {
      switchingEnabled: false,
      defaultTheme: 'light',
    })
    expect(hilosThemeChoice.get()).toBeNull()
    connection.emit('hilos_theme_settings', {
      switchingEnabled: true,
      defaultTheme: 'dark',
    })
    expect(hilosThemeChoice.get()).toEqual({
      position: 'dark',
      isDefault: false,
    })

    storage.setItem(THEME_PICK_STORAGE_KEY, 'system')
    storageChange(THEME_PICK_STORAGE_KEY)
    expect(hilosThemeChoice.get()).toEqual({
      position: 'system',
      isDefault: false,
    })
    systemChange(true)
    expect(hilosThemeChoice.get()).toEqual({
      position: 'system',
      isDefault: false,
    })

    connection.emit('hilos_theme_settings', {
      switchingEnabled: true,
      defaultTheme: 'nope',
    })
    expect(hilosThemeChoice.get()).toEqual({
      position: 'system',
      isDefault: false,
    })
  })
})
