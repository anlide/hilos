/**
 * Applies Bootstrap 5 color mode (data-bs-theme) from LocalStorageService preference.
 * "auto" follows prefers-color-scheme; light/dark are fixed.
 * Subscribes to localStorageService for same-tab and cross-tab updates.
 */
import { localStorageService } from '@/services/LocalStorageService'
import type { ThemePreference } from '@/services/LocalStorageService'

let mediaQuery: MediaQueryList | null = null
let onMediaChange: (() => void) | null = null

function detachMediaListener(): void {
  if (mediaQuery && onMediaChange) {
    mediaQuery.removeEventListener('change', onMediaChange)
  }
  mediaQuery = null
  onMediaChange = null
}

function effectiveBsTheme(preference: ThemePreference): 'light' | 'dark' {
  if (preference === 'light') {
    return 'light'
  }
  if (preference === 'dark') {
    return 'dark'
  }
  return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
}

function apply(preference: ThemePreference): void {
  if (typeof document === 'undefined') {
    return
  }
  document.documentElement.setAttribute('data-bs-theme', effectiveBsTheme(preference))
}

function wireAutoListener(): void {
  detachMediaListener()
  const preference = localStorageService.getThemePreference()
  if (preference !== 'auto') {
    return
  }
  mediaQuery = window.matchMedia('(prefers-color-scheme: dark)')
  onMediaChange = () => {
    apply('auto')
  }
  mediaQuery.addEventListener('change', onMediaChange)
}

function syncFromStorage(): void {
  const preference = localStorageService.getThemePreference()
  apply(preference)
  wireAutoListener()
}

/**
 * Call once on client after localStorageService.init().
 */
export function initBootstrapTheme(): void {
  if (typeof document === 'undefined') {
    return
  }
  syncFromStorage()
  localStorageService.onChange(() => {
    syncFromStorage()
  })
}
