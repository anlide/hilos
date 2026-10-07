import { afterEach, describe, expect, it } from 'vitest'
import {
  DEFAULT_THEME_SETTINGS,
  readThemePick,
  readThemeSettings,
  THEME_PICK_STORAGE_KEY,
  THEME_SETTINGS_STORAGE_KEY,
  writeThemePick,
  writeThemeSettings,
} from '../../src/theme/themeBrowser.js'

function fakeStorage(refuses = false): Storage {
  const entries = new Map<string, string>()

  return {
    get length(): number {
      return entries.size
    },
    clear: () => entries.clear(),
    getItem(key): string | null {
      if (refuses) throw new Error('refused')

      return entries.get(key) ?? null
    },
    key: (index) => [...entries.keys()][index] ?? null,
    removeItem(key): void {
      if (refuses) throw new Error('refused')
      entries.delete(key)
    },
    setItem(key, value): void {
      if (refuses) throw new Error('refused')
      entries.set(key, value)
    },
  }
}

function installStorage(storage: Storage): void {
  Object.defineProperty(globalThis, 'localStorage', {
    configurable: true,
    value: storage,
  })
}

afterEach(() => {
  Reflect.deleteProperty(globalThis, 'localStorage')
})

describe('theme browser values', () => {
  it('treats absent and malformed values as no pick and catalog settings', () => {
    const storage = fakeStorage()
    installStorage(storage)
    expect(readThemePick()).toBeNull()
    expect(readThemeSettings()).toEqual(DEFAULT_THEME_SETTINGS)

    storage.setItem(THEME_PICK_STORAGE_KEY, 'default')
    storage.setItem(THEME_SETTINGS_STORAGE_KEY, '{')
    expect(readThemePick()).toBeNull()
    expect(readThemeSettings()).toEqual(DEFAULT_THEME_SETTINGS)

    storage.setItem(
      THEME_SETTINGS_STORAGE_KEY,
      JSON.stringify({ defaultTheme: 'dark' }),
    )
    expect(readThemeSettings()).toEqual(DEFAULT_THEME_SETTINGS)
  })

  it('writes a pick as one string or removes it, and a full settings pair as JSON', () => {
    const storage = fakeStorage()
    installStorage(storage)
    writeThemePick('dark')
    writeThemeSettings({ switchingEnabled: false, defaultTheme: 'light' })
    expect(storage.getItem(THEME_PICK_STORAGE_KEY)).toBe('dark')
    expect(readThemePick()).toBe('dark')
    expect(
      JSON.parse(storage.getItem(THEME_SETTINGS_STORAGE_KEY) ?? ''),
    ).toEqual({
      switchingEnabled: false,
      defaultTheme: 'light',
    })
    expect(readThemeSettings()).toEqual({
      switchingEnabled: false,
      defaultTheme: 'light',
    })
    writeThemePick(null)
    expect(storage.getItem(THEME_PICK_STORAGE_KEY)).toBeNull()
  })

  it('survives missing, getter-refused and operation-refused storage', () => {
    expect(readThemePick()).toBeNull()
    expect(readThemeSettings()).toEqual(DEFAULT_THEME_SETTINGS)
    writeThemePick('dark')
    writeThemeSettings({ switchingEnabled: false, defaultTheme: 'dark' })

    Object.defineProperty(globalThis, 'localStorage', {
      configurable: true,
      get: () => {
        throw new Error('refused getter')
      },
    })
    expect(readThemePick()).toBeNull()
    expect(readThemeSettings()).toEqual(DEFAULT_THEME_SETTINGS)
    writeThemePick(null)
    writeThemeSettings({ switchingEnabled: false, defaultTheme: 'dark' })

    installStorage(fakeStorage(true))
    expect(readThemePick()).toBeNull()
    expect(readThemeSettings()).toEqual(DEFAULT_THEME_SETTINGS)
    writeThemePick('light')
    writeThemeSettings({ switchingEnabled: true, defaultTheme: 'system' })
  })
})
