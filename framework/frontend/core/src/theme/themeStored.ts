import type { ThemeSettings } from '../session/sessionScope.js'
import type { ThemePick } from './themeRule.js'

/** The guest's chosen theme position, absent when none was chosen. */
export const THEME_PICK_STORAGE_KEY = 'hilos.theme.pick'

/** The last complete installation settings snapshot for the next page load. */
export const THEME_SETTINGS_STORAGE_KEY = 'hilos.theme.settings'

/** Catalog defaults before a valid settings snapshot is available. */
export const DEFAULT_THEME_SETTINGS: ThemeSettings = Object.freeze({
  switchingEnabled: true,
  defaultTheme: 'system',
})

// Both parsers are self-contained: their function source runs in the head script
// before modules load, so neither may refer to another value in this module.

/** Parse a stored position; an unknown value means no choice. */
export function parseThemePick(raw: string | null): ThemePick {
  return raw === 'light' || raw === 'dark' || raw === 'system' ? raw : null
}

/** Parse a complete stored snapshot, falling back on invalid input. */
export function parseThemeSettings(
  raw: string | null,
  fallback: ThemeSettings,
): ThemeSettings {
  if (raw === null) return fallback

  try {
    const value: unknown = JSON.parse(raw)
    if (typeof value !== 'object' || value === null) {
      return fallback
    }
    const settings = value as Record<string, unknown>
    if (
      typeof settings['switchingEnabled'] !== 'boolean' ||
      (settings['defaultTheme'] !== 'light' &&
        settings['defaultTheme'] !== 'dark' &&
        settings['defaultTheme'] !== 'system')
    ) {
      return fallback
    }

    return {
      switchingEnabled: settings['switchingEnabled'],
      defaultTheme: settings['defaultTheme'],
    }
  } catch {
    return fallback
  }
}
