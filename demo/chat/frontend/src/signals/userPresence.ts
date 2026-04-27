import { ChatSignalDefinition } from '@/services/signals'
import {
  parseUserConnectionStatsPayloads,
  parseUserPresencePayloads,
} from '@/entities/frontendStateParsers'

/**
 * Runtime-derived user presence update.
 *
 * User state is applied by ChatFrontendStateReceiver before this marker handler runs.
 */
export const userPresenceUpdate = ChatSignalDefinition.fromFrontendChangesEnvelope<'user_presence_update', undefined>(
  'user_presence_update',
  (envelope) => {
    const presenceItems = envelope.updates?.userPresence ?? envelope.full?.userPresence
    if (parseUserPresencePayloads(presenceItems) === null) {
      return null
    }

    const statsItems = envelope.updates?.userConnectionStats ?? envelope.full?.userConnectionStats
    if (statsItems !== undefined && parseUserConnectionStatsPayloads(statsItems) === null) {
      return null
    }

    return undefined
  },
)
