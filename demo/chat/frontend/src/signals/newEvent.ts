import { eventPayloadToEvent, parseEventPayloads, type EventPayload } from '@/entities/parsers'
import { ChatSignalDefinition } from '@/services/signals'
import type { Event } from '@/types/domain/Event'

/**
 * Parsed payload of a `new_event` signal.
 *
 * Backend ships freshly emitted chat events inside `entities.updates.events`
 * (or `entities.full.events` for some admin broadcasts). This signal
 * normalizes that and produces ready-to-use Event domain objects alongside
 * the raw payloads (needed for rename self-sync logic).
 */
export interface NewEventPayload {
  events: Array<InstanceType<typeof Event>>
  rawPayloads: EventPayload[]
}

export const newEvent = ChatSignalDefinition.fromEntitiesEnvelope<'new_event', NewEventPayload>(
  'new_event',
  (envelope) => {
    const fromUpdates = parseEventPayloads(envelope.updates?.events) ?? []
    const fromFull = parseEventPayloads(envelope.full?.events) ?? []
    const rawPayloads = fromUpdates.length > 0 ? fromUpdates : fromFull
    return {
      events: rawPayloads.map(eventPayloadToEvent),
      rawPayloads,
    }
  },
)
