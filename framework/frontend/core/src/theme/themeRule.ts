import { type ThemeSettings } from '../session/sessionScope.js'

/** A person's stored position; null means that no position was chosen. */
export type ThemePick = 'light' | 'dark' | 'system' | null

/** The one Bootstrap color mode the page actually wears. */
export type ThemeMode = 'light' | 'dark'

/**
 * Resolve the page's color mode from the pick, installation settings and system.
 * This pure rule is also the source for the pre-boot head script (HIL-1431).
 *
 * @param pick The person's position, or no choice.
 * @param settings The installation's complete theme settings.
 * @param systemDark Whether the system prefers dark colors.
 */
export function resolveThemeMode(
  pick: ThemePick,
  settings: ThemeSettings,
  systemDark: boolean,
): ThemeMode {
  const position = settings.switchingEnabled
    ? (pick ?? settings.defaultTheme)
    : settings.defaultTheme

  return position === 'system' ? (systemDark ? 'dark' : 'light') : position
}
