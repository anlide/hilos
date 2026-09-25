import {
  hilosNotificationPreferences,
  NOTIFICATION_SIGNAL_PREFERENCES_CHANGED,
  notificationPreferencesSectionSchema,
  subscribeSignal,
  type HilosNotificationPreferencesChanged,
  type HilosNotificationPreferencesStore,
  type ProjectSignal,
} from '@hilos/core'

import { connection } from '../bootstrap/connection.js'
import { scopes } from '../bootstrap/session.js'

/** Page-data key carrying the notification preference section. */
const NOTIFICATION_PREFERENCES_DATA = 'notificationPreferences'

const notificationPreferencesData = scopes.pageDataSignal(
  NOTIFICATION_PREFERENCES_DATA,
)

/**
 * Feed the shared notification-preferences store from the current page scope.
 *
 * The profile and profile-devices pages both carry the same first-response
 * section; live changes then arrive as the same project signal. The returned
 * teardown clears the store so one page never leaves its rows behind for the next.
 *
 * @param store The preferences store to feed.
 * @returns Teardown for the page unmount.
 */
export function bindNotificationPreferences(
  store: HilosNotificationPreferencesStore = hilosNotificationPreferences,
): () => void {
  function applySection(raw: unknown): void {
    const parsed = notificationPreferencesSectionSchema.safeParse(raw)
    if (parsed.success) {
      store.applySection(parsed.data)
    }
  }

  applySection(notificationPreferencesData.get())
  const stopSection = subscribeSignal(notificationPreferencesData, applySection)
  const stopChanged = connection.on(
    'projectSignal',
    (signal: ProjectSignal) => {
      if (signal.type !== NOTIFICATION_SIGNAL_PREFERENCES_CHANGED) {
        return
      }
      store.applyChangedMap(
        (signal.data as HilosNotificationPreferencesChanged).channels,
      )
    },
  )

  return () => {
    stopSection()
    stopChanged()
    store.clear()
  }
}
