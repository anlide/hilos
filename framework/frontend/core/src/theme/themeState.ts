import { onBrowserValueErased } from '../browser/browserValueChanges.js'
import { type HilosConnection } from '../connection/HilosConnection.js'
import { applyTheme } from '../dom/applyTheme.js'
import {
  sessionThemeSettings,
  SIGNAL_HANDSHAKE_RESPONSE,
  SIGNAL_THEME_SETTINGS,
  themeSettingsSchema,
  type ThemeSettings,
} from '../session/sessionScope.js'
import { type ScopeManager } from '../state/ScopeManager.js'
import {
  computedSignal,
  createSignal,
  type ReadonlySignal,
} from '../state/signal.js'
import { resolveThemeChoice, type ThemeChoice } from './themeChoice.js'
import {
  DEFAULT_THEME_SETTINGS,
  readThemePick,
  readThemeSettings,
  THEME_PICK_STORAGE_KEY,
  writeThemePick,
  writeThemeSettings,
} from './themeBrowser.js'
import {
  resolveThemeMode,
  type ThemeMode,
  type ThemePick,
} from './themeRule.js'

const pickSignal = createSignal<ThemePick>(null)
// Catalog true/system until the first bind, the same pair an empty browser reads.
const settingsSignal = createSignal<ThemeSettings>(DEFAULT_THEME_SETTINGS)
const modeSignal = createSignal<ThemeMode>('light')

/** The guest's current theme position, including no choice. */
export const hilosThemePick: ReadonlySignal<ThemePick> = pickSignal

/** The light or dark mode currently applied to the page. */
export const hilosThemeMode: ReadonlySignal<ThemeMode> = modeSignal

/**
 * The mark the header and the profile row read. Null means switching is off,
 * so there is no control. It follows the same settings the worn theme does,
 * including the snapshot remembered before the handshake.
 */
export const hilosThemeChoice: ReadonlySignal<ThemeChoice | null> =
  computedSignal(() =>
    resolveThemeChoice(pickSignal.get(), settingsSignal.get()),
  )

interface ThemeBinding {
  release(): void
  setPick(pick: ThemePick): void
}

let activeBinding: ThemeBinding | null = null

/**
 * Change the current tab immediately and remember the guest's position.
 *
 * @param pick The new position, or null to clear the choice.
 */
export function setHilosThemePick(pick: ThemePick): void {
  if (activeBinding !== null) {
    activeBinding.setPick(pick)

    return
  }

  pickSignal.set(pick)
  writeThemePick(pick)
}

/**
 * Read one settings node from a session signal without treating missing or
 * malformed data as a replacement for a valid browser snapshot.
 *
 * @param type The delivered signal name.
 * @param data Its payload.
 */
function settingsFromSignal(type: string, data: unknown): ThemeSettings | null {
  let candidate: unknown = data
  if (type === SIGNAL_HANDSHAKE_RESPONSE) {
    if (typeof data !== 'object' || data === null || !('data' in data)) {
      return null
    }
    const plain = data.data
    if (
      typeof plain !== 'object' ||
      plain === null ||
      !('themeSettings' in plain)
    ) {
      return null
    }
    candidate = plain.themeSettings
  } else if (type !== SIGNAL_THEME_SETTINGS) {
    return null
  }

  const parsed = themeSettingsSchema.safeParse(candidate)

  return parsed.success
    ? {
        switchingEnabled: parsed.data.switchingEnabled,
        defaultTheme: parsed.data.defaultTheme,
      }
    : null
}

/**
 * Bind theme inputs before the socket opens. A second bind releases the first;
 * the returned release cannot undo a newer binding.
 *
 * @param connection The application's one connection.
 * @param scopes Its session scope, already bound to the connection.
 * @returns A release for this binding's browser and connection listeners.
 */
export function bindThemeState(
  connection: HilosConnection,
  scopes: ScopeManager,
): () => void {
  activeBinding?.release()

  const serverSettings = sessionThemeSettings(scopes)
  settingsSignal.set(readThemeSettings())
  let systemDark = false
  let media: MediaQueryList | null = null
  try {
    media = globalThis.matchMedia?.('(prefers-color-scheme: dark)') ?? null
    systemDark = media?.matches ?? false
  } catch {
    // A prerender or restricted browser has no system preference to follow.
  }

  const recompute = (): void => {
    const mode = resolveThemeMode(
      pickSignal.get(),
      settingsSignal.get(),
      systemDark,
    )
    modeSignal.set(mode)
    applyTheme(mode)
  }

  pickSignal.set(readThemePick())
  recompute()

  const onSystemChange = (event: MediaQueryListEvent): void => {
    systemDark = event.matches
    recompute()
  }
  try {
    media?.addEventListener('change', onSystemChange)
  } catch {
    // A media query without change events still supplies its initial value.
  }

  const onStorage = (event: StorageEvent): void => {
    if (event.key === THEME_PICK_STORAGE_KEY || event.key === null) {
      pickSignal.set(readThemePick())
      recompute()
    }
  }
  if (typeof globalThis.addEventListener === 'function') {
    globalThis.addEventListener('storage', onStorage)
  }

  const stopErase = onBrowserValueErased((key) => {
    if (key === THEME_PICK_STORAGE_KEY) {
      pickSignal.set(readThemePick())
      recompute()
    }
  })
  const stopConnection = connection.on('projectSignal', (signal) => {
    const incoming = settingsFromSignal(signal.type, signal.data)
    if (incoming === null) {
      return
    }
    // bindSessionScope was registered first. Read its typed selector after it
    // ingests this valid frame so all browser consumers see the same pair.
    settingsSignal.set(serverSettings.get())
    writeThemeSettings(settingsSignal.get())
    recompute()
  })

  const binding: ThemeBinding = {
    setPick(pick): void {
      pickSignal.set(pick)
      writeThemePick(pick)
      recompute()
    },
    release(): void {
      stopConnection()
      stopErase()
      if (typeof globalThis.removeEventListener === 'function') {
        globalThis.removeEventListener('storage', onStorage)
      }
      try {
        media?.removeEventListener('change', onSystemChange)
      } catch {
        // The media query may not have supported registration either.
      }
      if (activeBinding === binding) {
        activeBinding = null
      }
    },
  }
  activeBinding = binding

  return binding.release
}
