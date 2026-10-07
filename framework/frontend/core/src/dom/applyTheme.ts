import { type ThemeMode } from '../theme/themeRule.js'

/**
 * Apply the one resolved Bootstrap color mode to the page, when a DOM exists.
 *
 * @param mode The resolved light or dark mode.
 */
export function applyTheme(mode: ThemeMode): void {
  if (typeof globalThis.document === 'undefined') {
    return
  }

  globalThis.document.documentElement.setAttribute('data-bs-theme', mode)
}
