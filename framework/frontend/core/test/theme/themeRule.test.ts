import { describe, expect, it } from 'vitest'
import { resolveThemeMode, type ThemePick } from '../../src/theme/themeRule.js'

const picks: readonly ThemePick[] = [null, 'light', 'dark', 'system']
const defaults = ['light', 'dark', 'system'] as const

describe('resolveThemeMode', () => {
  it.each(picks)(
    'resolves every setting and system state for pick %s',
    (pick) => {
      for (const switchingEnabled of [false, true]) {
        for (const defaultTheme of defaults) {
          for (const systemDark of [false, true]) {
            const position = switchingEnabled
              ? (pick ?? defaultTheme)
              : defaultTheme
            const expected =
              position === 'system' ? (systemDark ? 'dark' : 'light') : position

            expect(
              resolveThemeMode(
                pick,
                { switchingEnabled, defaultTheme },
                systemDark,
              ),
            ).toBe(expected)
          }
        }
      }
    },
  )

  it('keeps an explicit choice equal to the default when the default moves', () => {
    expect(
      resolveThemeMode(
        'light',
        { switchingEnabled: true, defaultTheme: 'dark' },
        true,
      ),
    ).toBe('light')
  })
})
