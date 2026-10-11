import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  DEFAULT_THEME_SETTINGS,
  readThemePick,
  readThemeSettings,
  THEME_PICK_STORAGE_KEY,
  THEME_SETTINGS_STORAGE_KEY,
} from '../../src/theme/themeBrowser.js'
import {
  findDeferredStylesheet,
  insertThemeHeadScript,
  themeHeadScript,
} from '../../src/theme/themeHeadScript.js'
import { resolveThemeMode } from '../../src/theme/themeRule.js'

afterEach(() => vi.unstubAllGlobals())

function installBrowser(
  pick: string | null,
  settings: string | null,
  dark: boolean,
): { attributes: Map<string, string>; storage: Storage } {
  const entries = new Map<string, string>()
  if (pick !== null) entries.set(THEME_PICK_STORAGE_KEY, pick)
  if (settings !== null) entries.set(THEME_SETTINGS_STORAGE_KEY, settings)
  const storage = {
    getItem: (key: string) => entries.get(key) ?? null,
  } as Storage
  const attributes = new Map<string, string>()
  vi.stubGlobal('localStorage', storage)
  vi.stubGlobal('window', {
    localStorage: storage,
    matchMedia: () => ({ matches: dark }),
  })
  vi.stubGlobal('document', {
    documentElement: {
      setAttribute: (key: string, value: string) => attributes.set(key, value),
    },
  })

  return { attributes, storage }
}

function runHead(): void {
  // No module bindings enter this function's global scope.
  new Function(themeHeadScript())()
}

describe('pre-boot theme script', () => {
  it('matches the running browser readers and rule across picks and settings', () => {
    for (const pick of [null, 'light', 'dark', 'system', 'unknown']) {
      for (const settings of [
        null,
        '{',
        ...[true, false].flatMap((switchingEnabled) =>
          (['light', 'dark', 'system'] as const).map((defaultTheme) =>
            JSON.stringify({ switchingEnabled, defaultTheme }),
          ),
        ),
      ]) {
        for (const dark of [true, false]) {
          const { attributes } = installBrowser(pick, settings, dark)
          runHead()
          expect(attributes.get('data-bs-theme')).toBe(
            resolveThemeMode(readThemePick(), readThemeSettings(), dark),
          )
        }
      }
    }
  })

  it('uses defaults when storage refuses and light when matchMedia is absent', () => {
    const { attributes } = installBrowser('dark', null, true)
    vi.stubGlobal('window', {
      get localStorage() {
        throw new Error('refused')
      },
    })
    runHead()
    expect(attributes.get('data-bs-theme')).toBe(
      resolveThemeMode(null, DEFAULT_THEME_SETTINGS, false),
    )

    vi.stubGlobal('window', {
      localStorage: {
        getItem: () => {
          throw new Error('refused')
        },
      },
      matchMedia: () => ({ matches: true }),
    })
    runHead()
    expect(attributes.get('data-bs-theme')).toBe('dark')
  })
})

describe('HTML insertion', () => {
  it('inserts immediately before the first head close and refreshes one tag', () => {
    const input = '<html><head><title>One</title></head><body></body></html>'
    const inserted = insertThemeHeadScript(input)
    expect(inserted).toContain(
      `<title>One</title><script data-hilos-theme-head>${themeHeadScript()}</script></head>`,
    )
    expect(insertThemeHeadScript(inserted)).toBe(inserted)
    const stale = inserted.replace(themeHeadScript(), 'old code')
    const refreshed = insertThemeHeadScript(stale)
    expect(refreshed).toBe(inserted)
    expect(refreshed.match(/data-hilos-theme-head/g)).toHaveLength(1)
  })

  it('refuses a page without a head close', () => {
    expect(() => insertThemeHeadScript('<html><head>')).toThrow(
      'missing </head>',
    )
  })
})

describe('deferred stylesheet detection', () => {
  it('finds the Angular late style but ignores regular and print-only styles', () => {
    const late = `<link rel="stylesheet" href="styles.css" media="print" onload="this.media='all'">`
    expect(findDeferredStylesheet(`<head>${late}</head>`)).toBe(late)
    expect(
      findDeferredStylesheet('<link rel="stylesheet" href="styles.css">'),
    ).toBeNull()
    expect(
      findDeferredStylesheet('<link rel="stylesheet" media="print">'),
    ).toBeNull()
  })
})
