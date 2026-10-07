import { browserStorage } from '../browser/browserStorage.js'
import { browserValue } from '../browser/browserValue.js'
import {
  themeSettingsSchema,
  type ThemeSettings,
} from '../session/sessionScope.js'
import { type ThemePick } from './themeRule.js'

/** The guest's chosen theme position, absent when none was chosen. */
export const THEME_PICK_STORAGE_KEY = 'hilos.theme.pick'

/** The last complete installation settings snapshot for the next page load. */
export const THEME_SETTINGS_STORAGE_KEY = 'hilos.theme.settings'

/** The guest's theme position, declared for the /privacy sweep. */
export const THEME_PICK_BROWSER_VALUE = browserValue({
  store: 'local',
  key: THEME_PICK_STORAGE_KEY,
  label: 'Your theme choice in this browser',
})

/** The settings snapshot, declared for the /privacy sweep. */
export const THEME_SETTINGS_BROWSER_VALUE = browserValue({
  store: 'local',
  key: THEME_SETTINGS_STORAGE_KEY,
  label: 'Remembered theme settings for this site',
})

/** Catalog defaults before a valid settings snapshot is available. */
export const DEFAULT_THEME_SETTINGS: ThemeSettings = Object.freeze({
  switchingEnabled: true,
  defaultTheme: 'system',
})

/** Read the guest's choice; an unknown or inaccessible value is no choice. */
export function readThemePick(): ThemePick {
  const storage = browserStorage('localStorage')
  try {
    const value = storage?.getItem(THEME_PICK_STORAGE_KEY)

    return value === 'light' || value === 'dark' || value === 'system'
      ? value
      : null
  } catch {
    return null
  }
}

/**
 * Remember the guest's choice; null removes the key. A refused store is harmless.
 *
 * @param pick The new position, or no choice.
 */
export function writeThemePick(pick: ThemePick): void {
  const storage = browserStorage('localStorage')
  try {
    if (pick === null) {
      storage?.removeItem(THEME_PICK_STORAGE_KEY)
    } else {
      storage?.setItem(THEME_PICK_STORAGE_KEY, pick)
    }
  } catch {
    // The current tab still holds the choice in memory.
  }
}

/** Read the last complete snapshot, or the catalog defaults. */
export function readThemeSettings(): ThemeSettings {
  const storage = browserStorage('localStorage')
  try {
    const raw = storage?.getItem(THEME_SETTINGS_STORAGE_KEY)
    if (raw === null || raw === undefined) {
      return DEFAULT_THEME_SETTINGS
    }
    const parsed = themeSettingsSchema.safeParse(JSON.parse(raw))

    return parsed.success
      ? {
          switchingEnabled: parsed.data.switchingEnabled,
          defaultTheme: parsed.data.defaultTheme,
        }
      : DEFAULT_THEME_SETTINGS
  } catch {
    return DEFAULT_THEME_SETTINGS
  }
}

/**
 * Remember both settings atomically for the next page load.
 *
 * @param settings The last validated installation snapshot.
 */
export function writeThemeSettings(settings: ThemeSettings): void {
  const storage = browserStorage('localStorage')
  try {
    storage?.setItem(
      THEME_SETTINGS_STORAGE_KEY,
      JSON.stringify({
        switchingEnabled: settings.switchingEnabled,
        defaultTheme: settings.defaultTheme,
      }),
    )
  } catch {
    // The active binding still holds the server's snapshot in memory.
  }
}
