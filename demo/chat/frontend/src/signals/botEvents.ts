import { SignalDefinition, parseEmptyPayload } from '@hilos/sdk/services/signals'
import type { FrontendChangesEnvelope } from '@hilos/sdk/types'
import { ChatSignalDefinition } from '@/services/signals'
import { parseBotPresencePayloads, type BotPresencePayload } from '@/entities/frontendStateParsers'

/**
 * Bot presence / metadata change signals.
 *
 * Bot join/left signals carry a frontend `botPresence` update. Bot metadata
 * still comes via `entities.updates.bots` in the same frame, which
 * ChatEntitiesReceiver applies before these handlers run.
 */
export const botJoined = ChatSignalDefinition.fromFrontendChangesEnvelope<'bot_joined', BotPresencePayload>(
  'bot_joined',
  (frontend) => extractBotPresence(frontend, 'online'),
)

export const botLeft = ChatSignalDefinition.fromFrontendChangesEnvelope<'bot_left', BotPresencePayload>(
  'bot_left',
  (frontend) => extractBotPresence(frontend, 'offline'),
)

export const botUpdated = new SignalDefinition<'bot_updated', undefined>(
  'bot_updated',
  parseEmptyPayload,
)

function extractBotPresence(
  frontend: FrontendChangesEnvelope,
  expectedPresence: BotPresencePayload['presence'],
): BotPresencePayload | null {
  const presence = parseBotPresencePayloads(frontend.updates?.botPresence)
  if (presence === null || presence.length !== 1 || presence[0].presence !== expectedPresence) {
    return null
  }
  return presence[0]
}
