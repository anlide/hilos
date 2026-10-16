import { z } from 'zod'
import { onBrowserValueErased } from '../browser/browserValueChanges.js'
import {
  type ActionHandle,
  type ActionLifecycle,
} from '../connection/actionLifecycle.js'
import { type HilosConnection } from '../connection/HilosConnection.js'
import { applyTheme } from '../dom/applyTheme.js'
import { entityFragmentSchema } from '../protocol/scopePayload.js'
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

const THEME_PICK_ACTION = 'hilos_theme_pick'
const THEME_ADOPT_ACTION = 'hilos_theme_adopt'
const accountThemeSchema = z.object({
  themePick: z.enum(['light', 'dark', 'system']).nullable(),
  themePickInitialized: z.boolean(),
})
const themeSessionSchema = z.object({
  entities: z.object({ currentUser: entityFragmentSchema.nullable() }),
})

const pickSignal = createSignal<ThemePick>(null)
// Catalog true/system until the first bind, the same pair an empty browser reads.
const settingsSignal = createSignal<ThemeSettings>(DEFAULT_THEME_SETTINGS)
const modeSignal = createSignal<ThemeMode>('light')

/** The current tab's theme position, including no choice. */
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
  sendPick(pick: ThemePick): ActionHandle
}

let activeBinding: ThemeBinding | null = null

/**
 * Ask to save an account's position on the application's bound lifecycle.
 * Only the authoritative handshake changes the tab, browser copy and DOM.
 *
 * @param pick The position to save, or null to return to the site default.
 * @returns The tracked handle of the account write.
 * @throws Error When no theme binding exists — a programming error.
 */
export function sendHilosThemePick(pick: ThemePick): ActionHandle {
  if (activeBinding === null) {
    throw new Error(
      'sendHilosThemePick() before bindThemeState(): bootHilos binds the theme.',
    )
  }

  return activeBinding.sendPick(pick)
}

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
 * @param actions The application's one action reply lifecycle.
 * @returns A release for this binding's browser and connection listeners.
 */
export function bindThemeState(
  connection: HilosConnection,
  scopes: ScopeManager,
  actions: ActionLifecycle,
): () => void {
  activeBinding?.release()

  const serverSettings = sessionThemeSettings(scopes)
  let currentUserId: string | number | null = null
  const adoptionAttempts = new Map<
    string | number,
    Set<NonNullable<ThemePick>>
  >()
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
    if (
      currentUserId === null &&
      (event.key === THEME_PICK_STORAGE_KEY || event.key === null)
    ) {
      pickSignal.set(readThemePick())
      recompute()
    }
  }
  if (typeof globalThis.addEventListener === 'function') {
    globalThis.addEventListener('storage', onStorage)
  }

  const stopErase = onBrowserValueErased((key) => {
    if (currentUserId === null && key === THEME_PICK_STORAGE_KEY) {
      pickSignal.set(readThemePick())
      recompute()
    }
  })
  const stopState = connection.on('state', (state) => {
    if (state === 'connected') {
      adoptionAttempts.clear()
    }
  })
  const stopConnection = connection.on('projectSignal', (signal) => {
    if (
      signal.type !== SIGNAL_HANDSHAKE_RESPONSE &&
      signal.type !== SIGNAL_THEME_SETTINGS
    ) {
      return
    }
    const incoming = settingsFromSignal(signal.type, signal.data)
    if (incoming !== null) {
      // bindSessionScope was registered first. Read its typed selector after it
      // ingests this valid frame so all browser consumers see the same pair.
      settingsSignal.set(serverSettings.get())
      writeThemeSettings(settingsSignal.get())
    }
    if (signal.type === SIGNAL_HANDSHAKE_RESPONSE) {
      const session = themeSessionSchema.safeParse(signal.data)
      if (session.success) {
        const user = session.data.entities.currentUser
        currentUserId = user?.id ?? null
        if (user !== null) {
          const accountTheme = accountThemeSchema.safeParse(user)
          if (accountTheme.success) {
            const { themePick, themePickInitialized } = accountTheme.data
            if (themePick !== null || themePickInitialized) {
              pickSignal.set(themePick)
              writeThemePick(themePick)
            } else {
              const pick = pickSignal.get()
              const attempted = adoptionAttempts.get(user.id) ?? new Set()
              if (pick !== null && !attempted.has(pick)) {
                // Mark before dispatch: restates, including a refusal's later
                // null answer, cannot start a retry cycle on this socket.
                attempted.add(pick)
                adoptionAttempts.set(user.id, attempted)
                void actions
                  .dispatch(THEME_ADOPT_ACTION, { themePick: pick })
                  .done.catch(() => {
                    // Background adoption keeps the visible choice on refusal,
                    // timeout or disconnect; no surface owes it a toast.
                  })
              }
            }
          }
        }
        // Anonymous preserves both the tab's memory and the browser copy.
      }
    }
    recompute()
  })

  const binding: ThemeBinding = {
    setPick(pick): void {
      pickSignal.set(pick)
      writeThemePick(pick)
      recompute()
    },
    sendPick: (pick) =>
      actions.dispatch(THEME_PICK_ACTION, { themePick: pick }),
    release(): void {
      stopConnection()
      stopState()
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
