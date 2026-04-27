import { ChatSignalDefinition } from '@/services/signals'

/**
 * Runtime-derived user presence update.
 *
 * The user entity is applied by ChatEntitiesReceiver before this marker handler runs.
 */
export const userPresenceUpdate = ChatSignalDefinition.fromEntitiesEnvelope<'user_presence_update', undefined>(
  'user_presence_update',
  () => undefined,
)
