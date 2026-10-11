import { describe, expect, it } from 'vitest'
import { themeSettingsSchema } from '../../src/session/sessionScope.js'
import {
  DEFAULT_THEME_SETTINGS,
  parseThemePick,
  parseThemeSettings,
} from '../../src/theme/themeStored.js'

describe('stored theme values', () => {
  it('accepts the three positions and treats every other value as no choice', () => {
    for (const pick of ['light', 'dark', 'system'] as const) {
      expect(parseThemePick(pick)).toBe(pick)
    }
    for (const raw of [null, '', 'default', 'LIGHT', 'null', '{}']) {
      expect(parseThemePick(raw)).toBeNull()
    }
  })

  it('accepts complete settings and strips unknown fields', () => {
    for (const switchingEnabled of [true, false]) {
      for (const defaultTheme of ['light', 'dark', 'system'] as const) {
        const settings = { switchingEnabled, defaultTheme, extra: 'ignored' }
        expect(
          parseThemeSettings(JSON.stringify(settings), DEFAULT_THEME_SETTINGS),
        ).toEqual({
          switchingEnabled,
          defaultTheme,
        })
      }
    }
  })

  it('falls back for absent, malformed and incomplete settings', () => {
    const fallback = { switchingEnabled: false, defaultTheme: 'dark' } as const
    for (const raw of [
      null,
      '{',
      'null',
      'true',
      '[]',
      '"text"',
      '{}',
      '{"switchingEnabled":true}',
      '{"defaultTheme":"dark"}',
      '{"switchingEnabled":1,"defaultTheme":"dark"}',
      '{"switchingEnabled":true,"defaultTheme":"unknown"}',
    ]) {
      expect(parseThemeSettings(raw, fallback)).toBe(fallback)
    }
  })

  it('agrees with the wire schema on the same JSON samples', () => {
    const samples = [
      { switchingEnabled: true, defaultTheme: 'light' },
      { switchingEnabled: false, defaultTheme: 'dark', extra: 1 },
      { switchingEnabled: true, defaultTheme: 'system' },
      { switchingEnabled: 1, defaultTheme: 'dark' },
      { switchingEnabled: true, defaultTheme: 'unknown' },
      { defaultTheme: 'dark' },
      [],
      null,
      'text',
    ]
    for (const sample of samples) {
      const parsed = parseThemeSettings(
        JSON.stringify(sample),
        DEFAULT_THEME_SETTINGS,
      )
      expect(parsed !== DEFAULT_THEME_SETTINGS).toBe(
        themeSettingsSchema.safeParse(sample).success,
      )
    }
  })
})
