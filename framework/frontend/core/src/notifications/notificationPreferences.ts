// Notification channel preferences for the profile section and its summaries.
// Initial state rides in page_response; updates arrive on the user's existing
// notification group. The shared store never makes an optimistic toggle.
import { z } from 'zod'
import { type HilosPageCrumb } from '../admin/identity/hilosPageIdentity.js'
import { type HilosConnection } from '../connection/HilosConnection.js'
import { type ScopeManager } from '../state/ScopeManager.js'
import {
  computedSignal,
  createSignal,
  subscribeSignal,
  type ReadonlySignal,
  type WritableSignal,
} from '../state/signal.js'

/**
 * Server→client signal `type` carrying the full channel → allowed map after a
 * toggle (PHP `NotificationSignalName::PREFERENCES_CHANGED`). Fanned to every one
 * of the recipient's connections so all their tabs and devices agree; the payload
 * is the complete togglable set, not a delta.
 */
export const NOTIFICATION_SIGNAL_PREFERENCES_CHANGED =
  'notification_preferences_changed'

/**
 * Client→server action toggling one channel's opt in/out, owned by the notifications
 * library (PHP `NotificationPreferenceAction::CHANNEL_SET`). Payload is the channel
 * name and desired state; the acting user is resolved server-side from the
 * connection, never carried.
 */
export const NOTIFICATION_ACTION_CHANNEL_SET =
  'profile_notification_channel_set'

/**
 * One channel's row in the profile section (PHP `NotificationChannelState`): the
 * channel's registry name, its human label, whether the recipient currently allows
 * it (sparse opt-out — no muted row means allowed), and whether they have a
 * resolvable address for it. A row with no address is shown disabled with an
 * "add an address" hint rather than hidden. `config` carries the channel's public
 * frontend opt-in config keyed by field — present only for a channel that needs
 * one (push carries its `vapid_public` key so the browser can subscribe); it is
 * never a secret.
 */
const channelStateSchema = z.looseObject({
  channel: z.string(),
  label: z.string(),
  allowed: z.boolean(),
  hasAddress: z.boolean(),
  config: z.record(z.string(), z.string()).optional(),
})

export type HilosNotificationChannelState = z.infer<typeof channelStateSchema>

/**
 * The profile "Notifications" section payload (PHP `NotificationPreferencesSectionData`):
 * one row per globally enabled channel and a `mandatoryNote` flag telling the view
 * to render the always-on line when the project declares any mandatory type.
 */
export const notificationPreferencesSectionSchema = z.looseObject({
  channels: z.array(channelStateSchema),
  mandatoryNote: z.boolean(),
})

export type HilosNotificationPreferencesSection = z.infer<
  typeof notificationPreferencesSectionSchema
>

/**
 * Payload of the changed signal (PHP `NotificationPreferencesChangedSignalData`):
 * the full channel → allowed map over the togglable channels. `true` means allowed
 * (no muted row), `false` means muted.
 */
const preferencesChangedSchema = z.looseObject({
  channels: z.record(z.string(), z.boolean()),
})

export type HilosNotificationPreferencesChanged = z.infer<
  typeof preferencesChangedSchema
>

/**
 * The preference signal schema keyed for a connection's `projectSchemas`, so the
 * parse boundary validates the changed map before {@link HilosNotificationPreferencesStore}
 * ingests it. {@link createHilosConnection} merges it in, so a project never
 * restates it. The section snapshot is not here: it rides the profile page data,
 * parsed with {@link notificationPreferencesSectionSchema} at that slot.
 */
export const NOTIFICATION_PREFERENCE_SIGNAL_SCHEMAS = {
  [NOTIFICATION_SIGNAL_PREFERENCES_CHANGED]: preferencesChangedSchema,
}

/** The reactive preference state a profile section view renders. */
export interface HilosNotificationPreferencesStore {
  /** The channel rows, in the backend's order (globally enabled channels). */
  readonly channels: ReadonlySignal<readonly HilosNotificationChannelState[]>
  /** Whether to render the mandatory-types always-on note. */
  readonly mandatoryNote: ReadonlySignal<boolean>
  /** The channels whose toggle is mid-flight, so the view shows a per-row loader. */
  readonly pending: ReadonlySignal<ReadonlySet<string>>
  /**
   * Replace the section from a fresh subscription payload (the authoritative
   * snapshot). Clears any pending toggles — the snapshot settles them all.
   *
   * @param section The section rows plus the mandatory note.
   */
  applySection(section: HilosNotificationPreferencesSection): void
  /**
   * Apply a live changed map: set each listed channel's `allowed` to the map value
   * and settle its pending toggle. Channels absent from the map (e.g. a no-address
   * row) keep their state; a map key with no matching row is ignored — the section
   * defines the visible set.
   *
   * @param channels The channel → allowed map from the changed signal.
   */
  applyChangedMap(channels: Readonly<Record<string, boolean>>): void
  /**
   * Mark a channel's toggle as in-flight (the clicker, before the action is sent),
   * so the view disables the row and shows its loader until the changed signal or
   * an error settles it.
   *
   * @param channel The channel being toggled.
   */
  markPending(channel: string): void
  /**
   * Clear a channel's pending toggle without changing its state — the view calls
   * this when the action send fails or is rejected, so a settled snapshot never
   * arrives to clear it.
   *
   * @param channel The channel whose toggle to settle.
   */
  clearPending(channel: string): void
  /** Reset to empty (section unmount, sign-out). */
  clear(): void
}

/**
 * Create an independent preferences store.
 *
 * Applications use the shared {@link hilosNotificationPreferences}; this factory
 * exists so a test (or a second window) gets its own state.
 */
export function createHilosNotificationPreferencesStore(): HilosNotificationPreferencesStore {
  const channels: WritableSignal<readonly HilosNotificationChannelState[]> =
    createSignal<readonly HilosNotificationChannelState[]>([])
  const mandatoryNote = createSignal(false)
  const pending: WritableSignal<ReadonlySet<string>> = createSignal<
    ReadonlySet<string>
  >(new Set())

  function settlePending(channel: string): void {
    const current = pending.get()
    if (!current.has(channel)) {
      return
    }
    const next = new Set(current)
    next.delete(channel)
    pending.set(next)
  }

  return {
    channels,
    mandatoryNote,
    pending,
    applySection(section) {
      channels.set(section.channels)
      mandatoryNote.set(section.mandatoryNote)
      pending.set(new Set())
    },
    applyChangedMap(changed) {
      channels.set(
        channels
          .get()
          .map((row) =>
            Object.prototype.hasOwnProperty.call(changed, row.channel)
              ? { ...row, allowed: changed[row.channel] }
              : row,
          ),
      )
      for (const channel of Object.keys(changed)) {
        settlePending(channel)
      }
    },
    markPending(channel) {
      const current = pending.get()
      if (current.has(channel)) {
        return
      }
      const next = new Set(current)
      next.add(channel)
      pending.set(next)
    },
    clearPending(channel) {
      settlePending(channel)
    },
    clear() {
      channels.set([])
      mandatoryNote.set(false)
      pending.set(new Set())
    },
  }
}

/**
 * The application-wide preferences store.
 *
 * One per loaded SDK: the profile section view renders it, and the SDK/demo layer
 * feeds it from the profile page data and the changed signal. A test that needs
 * isolation builds its own with {@link createHilosNotificationPreferencesStore}.
 */
export const hilosNotificationPreferences: HilosNotificationPreferencesStore =
  createHilosNotificationPreferencesStore()

/** Page-data key carrying the notification preference section. */
export const PROFILE_NOTIFICATION_PREFERENCES_SECTION =
  'notificationPreferences'

/**
 * Page-data key naming the profile section where an address is added, or null
 * when the project serves none (PHP `AbstractHilosProfileNotificationsPage::ADDRESS_SECTION`, HIL-1166).
 */
export const PROFILE_NOTIFICATION_ADDRESS_SECTION = 'addressSection'

const addressSectionSchema = z.looseObject({
  page: z.string(),
  label: z.string(),
})

/**
 * The section a channel without an address points at, from the notifications
 * page's answer (HIL-1166). Only the server knows which pages the project
 * serves, so the hint links there only when the answer names the section; a
 * missing or malformed slot reads as none.
 *
 * @param scopes The page's scope manager.
 */
export function hilosNotificationAddressSection(
  scopes: ScopeManager,
): ReadonlySignal<HilosPageCrumb | null> {
  const slot = scopes.pageDataSignal(PROFILE_NOTIFICATION_ADDRESS_SECTION)

  return computedSignal(() => {
    const parsed = addressSectionSchema.safeParse(slot.get())

    return parsed.success
      ? { page: parsed.data.page, label: parsed.data.label }
      : null
  })
}

/** The notification preference rows' words shared by the three view layers. */
export const HILOS_NOTIFICATION_PREFERENCES_COPY = {
  noAddress: 'Add an address in your profile to enable this channel.',
  noAddressBefore: 'Add an address in',
  noAddressAfter: 'to enable this channel.',
} as const

/** The stores and connection of a page carrying notification preferences. */
export interface HilosProfileNotificationsContext {
  readonly connection: HilosConnection
  readonly scopes: ScopeManager
}

/**
 * Feed the preference store from one page's initial answer and live changes.
 *
 * @param context The page's connection and scope manager.
 * @param store The store to feed, defaulting to the shared preferences store.
 * @returns Teardown that removes the listeners and clears the page's state.
 */
export function startHilosNotificationPreferences(
  context: HilosProfileNotificationsContext,
  store: HilosNotificationPreferencesStore = hilosNotificationPreferences,
): () => void {
  const section = context.scopes.pageDataSignal(
    PROFILE_NOTIFICATION_PREFERENCES_SECTION,
  )
  function applySection(raw: unknown): void {
    const parsed = notificationPreferencesSectionSchema.safeParse(raw)
    if (parsed.success) store.applySection(parsed.data)
  }
  applySection(section.get())
  const stopSection = subscribeSignal(section, applySection)
  const stopChanged = context.connection.on('projectSignal', (signal) => {
    if (signal.type === NOTIFICATION_SIGNAL_PREFERENCES_CHANGED) {
      store.applyChangedMap(
        (signal.data as HilosNotificationPreferencesChanged).channels,
      )
    }
  })
  return () => {
    stopSection()
    stopChanged()
    store.clear()
  }
}

/**
 * Describe the enabled channels that can currently reach the account.
 *
 * @param channels The projected channel rows in catalog order.
 */
export function describeHilosNotificationChannels(
  channels: readonly HilosNotificationChannelState[],
): string {
  const names = channels
    .filter((row) => row.allowed && row.hasAddress)
    .map((row) =>
      row.label === row.label.toUpperCase()
        ? row.label
        : row.label.toLowerCase(),
    )
  if (names.length === 0) return 'All channels are off'
  const list =
    names.length === 1
      ? names[0]
      : `${names.slice(0, -1).join(', ')} and ${names[names.length - 1]}`
  return `${list.charAt(0).toUpperCase()}${list.slice(1)} ${names.length === 1 ? 'is' : 'are'} on`
}
