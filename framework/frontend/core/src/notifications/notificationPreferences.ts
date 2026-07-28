// Notification-preferences core: the framework-owned reactive store for the
// profile "Notifications" section (HIL-485 frontend; HIL-485 backend fans the
// signal and computes the section). Unlike the notification center, this section
// owns no page subscription and no per-user group of its own — it rides the
// existing profile page subscription. The section snapshot arrives as page data
// in that subscription's payload (backend `AbstractHilosProfilePage::NOTIFICATION_SECTION`),
// and live multi-device updates arrive as the `notification_preferences_changed`
// project signal fanned to the user's connections after any toggle. The store
// here is the reactive half an SDK section view renders; the SDK/demo layer feeds
// it (the section from the profile page data, the changed map from the signal) and
// dispatches the toggle action, because the profile page is a project surface.
import { z } from 'zod'
import {
  createSignal,
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
 * Client→server action toggling one channel's opt in/out, mounted on the profile
 * page (PHP `NotificationPreferenceAction::CHANNEL_SET`). Payload is the channel
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
 * "add an address" hint rather than hidden.
 */
const channelStateSchema = z.looseObject({
  channel: z.string(),
  label: z.string(),
  allowed: z.boolean(),
  hasAddress: z.boolean(),
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
