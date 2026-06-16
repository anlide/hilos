// The bots admin actions: the create / update / delete submits as tracked
// actions over the connection's reply lifecycle (action-lifecycle, step 7.4).
// Each returns an ActionHandle whose `done` resolves on the backend's
// `::success` ack and rejects (with the reason) on `::fail` — the view closes
// the modal on success and surfaces the failure (authoritative-backend, no
// optimism). The committed row returns separately over the live bots table.
import { type ActionHandle } from '@hilos/core'

import { actions } from '../../bootstrap/connection'

// Backend action names (PHP ChatSignalConstants).
const BOT_CREATE = 'bot_create'
const BOT_UPDATE = 'bot_update'
const BOT_DELETE = 'bot_delete'

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

/**
 * Create a bot as a tracked action.
 *
 * @param input The new bot's fields.
 */
export function sendBotCreate(input: BotInput): ActionHandle {
  return actions.dispatch(BOT_CREATE, { ...input })
}

/**
 * Update a bot's fields as a tracked action.
 *
 * @param id The bot id to update.
 * @param input The bot's new fields.
 */
export function sendBotUpdate(id: number, input: BotInput): ActionHandle {
  return actions.dispatch(BOT_UPDATE, { id, ...input })
}

/**
 * Delete a bot as a tracked action.
 *
 * @param id The bot id to delete.
 */
export function sendBotDelete(id: number): ActionHandle {
  return actions.dispatch(BOT_DELETE, { id })
}
