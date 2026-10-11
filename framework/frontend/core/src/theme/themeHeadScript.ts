import { type ThemeSettings } from '../session/sessionScope.js'
import { resolveThemeMode, type ThemePick } from './themeRule.js'
import {
  DEFAULT_THEME_SETTINGS,
  parseThemePick,
  parseThemeSettings,
  THEME_PICK_STORAGE_KEY,
  THEME_SETTINGS_STORAGE_KEY,
} from './themeStored.js'

export const THEME_HEAD_SCRIPT_ATTRIBUTE = 'data-hilos-theme-head'

/** Read the browser's remembered theme before the application starts. */
function wearThemeBeforeBoot(
  resolve: typeof resolveThemeMode,
  parsePick: (raw: string | null) => ThemePick,
  parseSettings: (raw: string | null, fallback: ThemeSettings) => ThemeSettings,
  pickKey: string,
  settingsKey: string,
  fallback: ThemeSettings,
): void {
  let pick: ThemePick = null
  let settings = fallback
  try {
    const storage = window.localStorage
    pick = parsePick(storage.getItem(pickKey))
    settings = parseSettings(storage.getItem(settingsKey), fallback)
  } catch {
    // Browser storage may be unavailable on this page.
  }

  let dark = false
  try {
    dark = window.matchMedia('(prefers-color-scheme: dark)').matches
  } catch {
    // Without matchMedia, system resolves to light.
  }
  document.documentElement.setAttribute(
    'data-bs-theme',
    resolve(pick, settings, dark),
  )
}

/** Build a classic inline script from the same functions the running app uses. */
export function themeHeadScript(): string {
  return `(${wearThemeBeforeBoot})(${resolveThemeMode},${parseThemePick},${parseThemeSettings},${JSON.stringify(THEME_PICK_STORAGE_KEY)},${JSON.stringify(THEME_SETTINGS_STORAGE_KEY)},${JSON.stringify(DEFAULT_THEME_SETTINGS)})`
}

/** Insert the theme script before the first head close, or refresh its contents. */
export function insertThemeHeadScript(html: string): string {
  const existing =
    /(<script\b(?=[^>]*\sdata-hilos-theme-head(?:\s|=|>))[^>]*>)[\s\S]*?(<\/script\s*>)/i
  const script = themeHeadScript()
  if (existing.test(html)) {
    return html.replace(
      existing,
      (_match, open: string, close: string) => open + script + close,
    )
  }

  const headClose = /<\/head\s*>/i
  if (!headClose.test(html)) {
    throw new Error('missing </head>')
  }

  return html.replace(
    headClose,
    (close) =>
      `<script ${THEME_HEAD_SCRIPT_ATTRIBUTE}>${script}</script>${close}`,
  )
}

/** Find an Angular-style stylesheet deferred until after the first paint. */
export function findDeferredStylesheet(html: string): string | null {
  const links = html.matchAll(/<link\b(?:[^'">]|"[^"]*"|'[^']*')*>/gi)
  for (const match of links) {
    const link = match[0]
    const attribute = (name: string): string | null => {
      const found = link.match(
        new RegExp(
          `(?:^|\\s)${name}\\s*=\\s*(?:"([^"]*)"|'([^']*)'|([^\\s"'=<>]+))`,
          'i',
        ),
      )

      return found ? (found[1] ?? found[2] ?? found[3]) : null
    }
    const rel = attribute('rel')
    if (
      rel?.toLowerCase().split(/\s+/).includes('stylesheet') &&
      attribute('media')?.toLowerCase() === 'print' &&
      /\sonload(?:\s|=|\/?>)/i.test(link)
    ) {
      return link
    }
  }

  return null
}
