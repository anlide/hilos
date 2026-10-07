import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  eraseBrowserValues,
  HILOS_BROWSER_VALUES,
} from '../../src/browser/browserValues.js'
import { type HilosConnection, type ProjectSignal } from '../../src/index.js'
import { bindSessionScope } from '../../src/session/sessionScope.js'
import { ScopeManager } from '../../src/state/ScopeManager.js'
import {
  THEME_PICK_STORAGE_KEY,
  THEME_SETTINGS_STORAGE_KEY,
} from '../../src/theme/themeBrowser.js'
import {
  bindThemeState,
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

let storage: Storage
let storageEvents: EventTarget
let mediaListeners: Set<(event: MediaQueryListEvent) => void>
let mediaMatches = false
let attributes: Map<string, string>
let release: (() => void) | null = null

function start() {
  const connection = fakeConnection()
  const scopes = new ScopeManager()
  bindSessionScope(connection as unknown as HilosConnection, scopes)
  release = bindThemeState(connection as unknown as HilosConnection, scopes)

  return connection
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
    expect(second.listenerCount()).toBe(2)
    expect(mediaListeners.size).toBe(1)
    expect(hilosThemePick.get()).toBeNull()
    release?.()
    expect(mediaListeners.size).toBe(0)
    expect(second.listenerCount()).toBe(1)
  })
})
