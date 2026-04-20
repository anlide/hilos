import { SignalDefinition, parseEmptyPayload } from '@hilos/sdk/services/signals'

/**
 * Bot presence / metadata change signals.
 *
 * All three carry no body — the actual bot state comes via `entities.updates.bots`
 * in the same frame, which ChatEntitiesReceiver already applies. These
 * signals exist purely as markers and as extension points.
 */
export const botJoined = new SignalDefinition<'bot_joined', undefined>(
  'bot_joined',
  parseEmptyPayload,
)

export const botLeft = new SignalDefinition<'bot_left', undefined>('bot_left', parseEmptyPayload)

export const botUpdated = new SignalDefinition<'bot_updated', undefined>(
  'bot_updated',
  parseEmptyPayload,
)
