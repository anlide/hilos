// The mark the header and the profile row share. Separate from resolveThemeMode,
// which is the source of the pre-boot head script (HIL-1431): the mark shows the
// position, including "this is the default", and disappears when switching is
// off. The color the page wears is a different question.
import { type ThemeSettings } from '../session/sessionScope.js'
import { type ThemePick } from './themeRule.js'

/** A menu position: the three choices, without "nothing picked". */
export type ThemePosition = 'light' | 'dark' | 'system'

/** The mark the header and the profile row draw. Null means no control at all. */
export interface ThemeChoice {
  /** The position the icon and the checked row show. */
  position: ThemePosition
  /** True when the person never picked and the mark is the installation default. */
  isDefault: boolean
}

/** Menu order, icons and labels. The header and the profile row both read this. */
export const THEME_POSITIONS: readonly {
  value: ThemePosition
  icon: string
  label: string
}[] = [
  { value: 'light', icon: 'bi-sun', label: 'Light' },
  { value: 'dark', icon: 'bi-moon-stars', label: 'Dark' },
  { value: 'system', icon: 'bi-circle-half', label: 'System' },
]

/** Words the menu and the default badge share. */
export const THEME_COPY = { menu: 'Theme', default: 'default' } as const

/**
 * The position to mark, or null when the installation has switching off.
 * An explicit pick equal to the default is the person's own choice.
 *
 * @param pick The person's position, or no choice.
 * @param settings The installation's complete theme settings.
 */
export function resolveThemeChoice(
  pick: ThemePick,
  settings: ThemeSettings,
): ThemeChoice | null {
  if (!settings.switchingEnabled) {
    return null
  }

  return {
    position: pick ?? settings.defaultTheme,
    isDefault: pick === null,
  }
}

/**
 * The accessible name of the header icon for one position.
 *
 * @param position The position the icon shows.
 */
export function formatHilosThemeLabel(position: ThemePosition): string {
  const row = THEME_POSITIONS.find((item) => item.value === position)

  return `Theme: ${row === undefined ? position : row.label}`
}
