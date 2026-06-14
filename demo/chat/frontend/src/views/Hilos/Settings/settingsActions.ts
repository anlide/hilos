// The Hilos settings actions: the add / update / delete submits and their shared
// failure feedback. The backend SettingsPage reports a rejected settings action
// through the table_action_error signal; it has no registered schema, so it
// arrives as an `unknownSignal` and we react to its type only, never its raw
// payload (the parse boundary stays the one place that interprets a frame —
// wire-protocol.md). Success is observed state-driven in the view (the changed
// row returns over the live settings table).
import { createSignal, type ReadonlySignal } from '@hilos/core'

import { connection } from '../../../bootstrap/connection'

// Backend action and failure signal names (PHP ChatSignalConstants).
const SETTING_ADD = 'setting_add'
const SETTING_UPDATE = 'setting_update'
const SETTING_DELETE = 'setting_delete'
const TABLE_ACTION_ERROR = 'table_action_error'

const error = createSignal<string | null>(null)

/** The latest settings action failure reason, or null when clear. */
export const settingError: ReadonlySignal<string | null> = error

// A rejected settings action arrives as the backend's table_action_error ack;
// surface a generic message (the reason text stays behind the unregistered schema).
connection.on('unknownSignal', (signal) => {
  if (signal.type === TABLE_ACTION_ERROR) {
    error.set('The settings action could not be completed. Please try again.')
  }
})

/** Clear the settings error (on opening or cancelling a form). */
export function clearSettingError(): void {
  error.set(null)
}

/**
 * Create a custom value for a catalog setting. Returns false, sending nothing,
 * when the connection is not connected.
 *
 * @param key The setting key.
 * @param value The custom value to persist.
 */
export function sendSettingAdd(key: string, value: string): boolean {
  error.set(null)

  return connection.sendAction(SETTING_ADD, { key, value })
}

/**
 * Update a persisted setting's value. A null value resets it to the catalog
 * default. Returns false, sending nothing, when the connection is not connected.
 *
 * @param key The setting key.
 * @param value The new value, or null to reset to the catalog default.
 */
export function sendSettingUpdate(key: string, value: string | null): boolean {
  error.set(null)

  return connection.sendAction(SETTING_UPDATE, { key, value })
}

/**
 * Delete an orphan setting (a key not in the catalog). Returns false, sending
 * nothing, when the connection is not connected.
 *
 * @param key The setting key.
 */
export function sendSettingDelete(key: string): boolean {
  error.set(null)

  return connection.sendAction(SETTING_DELETE, { key })
}
