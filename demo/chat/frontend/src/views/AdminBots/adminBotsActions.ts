// The bots admin actions: the create / update / delete submits and their shared
// failure feedback. The backend AdminBotsPage reports a rejected action through
// the table_action_error signal; it has no registered schema, so it arrives as an
// `unknownSignal` and we react to its type only, never its raw payload (the parse
// boundary stays the one place that interprets a frame — wire-protocol.md).
// Success is observed state-driven in the view (the changed row returns over the
// live bots table).
import { createSignal, type ReadonlySignal } from '@hilos/core'

import { connection } from '../../bootstrap/connection'

// Backend action and failure signal names (PHP ChatSignalConstants).
const BOT_CREATE = 'bot_create'
const BOT_UPDATE = 'bot_update'
const BOT_DELETE = 'bot_delete'
const TABLE_ACTION_ERROR = 'table_action_error'

/** The editable fields a bot create or update carries. */
export interface BotInput {
  /** Display name (required). */
  name: string
  /** Description, or null when unset. */
  description: string | null
  /** Writing style, or null when unset. */
  style: string | null
  /** Preferred topics, or null when unset. */
  topics: string | null
  /** Personality traits, or null when unset. */
  personality: string | null
  /** Whether the bot is active (its agent should run). */
  active: boolean
}

const error = createSignal<string | null>(null)

/** The latest bots action failure reason, or null when clear. */
export const botError: ReadonlySignal<string | null> = error

// A rejected bot action arrives as the backend's table_action_error ack; surface
// a generic message (the reason text stays behind the unregistered schema).
connection.on('unknownSignal', (signal) => {
  if (signal.type === TABLE_ACTION_ERROR) {
    error.set('The bot action could not be completed. Please try again.')
  }
})

/** Clear the bots error (on opening or cancelling a form). */
export function clearBotError(): void {
  error.set(null)
}

/**
 * Create a bot. Returns false, sending nothing, when the connection is not
 * connected.
 *
 * @param input The new bot's fields.
 */
export function sendBotCreate(input: BotInput): boolean {
  error.set(null)

  return connection.sendAction(BOT_CREATE, { ...input })
}

/**
 * Update a bot's fields. Returns false, sending nothing, when the connection is
 * not connected.
 *
 * @param id The bot id to update.
 * @param input The bot's new fields.
 */
export function sendBotUpdate(id: number, input: BotInput): boolean {
  error.set(null)

  return connection.sendAction(BOT_UPDATE, { id, ...input })
}

/**
 * Delete a bot. Returns false, sending nothing, when the connection is not
 * connected.
 *
 * @param id The bot id to delete.
 */
export function sendBotDelete(id: number): boolean {
  error.set(null)

  return connection.sendAction(BOT_DELETE, { id })
}
