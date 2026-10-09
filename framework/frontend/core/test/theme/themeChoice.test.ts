import { describe, expect, it } from 'vitest'
import {
  formatHilosThemeLabel,
  resolveThemeChoice,
  THEME_COPY,
  THEME_POSITIONS,
  type ThemePosition,
} from '../../src/theme/themeChoice.js'
import { type ThemePick } from '../../src/theme/themeRule.js'

const picks: readonly ThemePick[] = [null, 'light', 'dark', 'system']
const defaults = ['light', 'dark', 'system'] as const

describe('resolveThemeChoice', () => {
  it.each(picks)('resolves switching and the default for pick %s', (pick) => {
    for (const defaultTheme of defaults) {
      expect(
        resolveThemeChoice(pick, { switchingEnabled: false, defaultTheme }),
      ).toBeNull()
      expect(
        resolveThemeChoice(pick, { switchingEnabled: true, defaultTheme }),
      ).toEqual({
        position: pick ?? defaultTheme,
        isDefault: pick === null,
      })
    }
  })

  it("treats an explicit choice equal to the default as the person's own", () => {
    expect(
      resolveThemeChoice('light', {
        switchingEnabled: true,
        defaultTheme: 'light',
      }),
    ).toEqual({ position: 'light', isDefault: false })
  })
})

describe('theme menu copy', () => {
  it('lists Light, Dark and System in menu order', () => {
    expect(
      THEME_POSITIONS.map((row) => [row.value, row.icon, row.label]),
    ).toEqual([
      ['light', 'bi-sun', 'Light'],
      ['dark', 'bi-moon-stars', 'Dark'],
      ['system', 'bi-circle-half', 'System'],
    ])
    expect(THEME_COPY).toEqual({ menu: 'Theme', default: 'default' })
  })

  it.each([
    ['light', 'Theme: Light'],
    ['dark', 'Theme: Dark'],
    ['system', 'Theme: System'],
  ] as const)('names %s as %s', (position: ThemePosition, label: string) => {
    expect(formatHilosThemeLabel(position)).toBe(label)
  })
})
