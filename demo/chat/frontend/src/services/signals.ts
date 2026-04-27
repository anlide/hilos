import { SignalDefinition, type SignalParser } from '@hilos/sdk/services/signals'
import {
  extractEntitiesEnvelope,
  extractFrontendChangesEnvelope,
  hasEntities,
  hasFrontendChanges,
  type EntitiesEnvelope,
  type FrontendChangesEnvelope,
} from '@hilos/sdk/types'
import type { WebSocketOutcome } from '@hilos/sdk/types/websocket-messages'

const isRecord = (value: unknown): value is Record<string, unknown> => {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

/**
 * Chat-specific signal definition — adds demo/chat-level conveniences on top
 * of the framework {@link SignalDefinition}.
 *
 * Provides factories for signals that carry framework transport envelopes.
 */
export class ChatSignalDefinition<
  T extends string = string,
  D = undefined,
  O extends WebSocketOutcome | null = null,
> extends SignalDefinition<T, D, O> {
  /**
   * Build a signal definition whose `data` payload must contain an
   * `entities` key (server's EntitiesChangesDTO transport shape).
   *
   * The caller supplies a pure `extract` function that receives a fully
   * parsed {@link EntitiesEnvelope} and the original raw payload, and
   * returns the narrowed typed value. Return `null` to reject the message.
   */
  static fromEntitiesEnvelope<T extends string, D>(
    type: T,
    extract: (envelope: EntitiesEnvelope, raw: Record<string, unknown>) => D | null,
  ): ChatSignalDefinition<T, D, null> {
    const parser: SignalParser<D> = (raw: unknown): D | null => {
      if (!isRecord(raw) || !hasEntities(raw)) {
        return null
      }
      const envelope = extractEntitiesEnvelope(raw)
      if (envelope === null) {
        return null
      }
      return extract(envelope, raw)
    }
    return new ChatSignalDefinition(type, parser, null)
  }

  /**
   * Build a signal definition whose `data` payload must contain a `frontend`
   * key (server's FrontendChangesDTO transport shape).
   */
  static fromFrontendChangesEnvelope<T extends string, D>(
    type: T,
    extract: (envelope: FrontendChangesEnvelope, raw: Record<string, unknown>) => D | null,
  ): ChatSignalDefinition<T, D, null> {
    const parser: SignalParser<D> = (raw: unknown): D | null => {
      if (!isRecord(raw) || !hasFrontendChanges(raw)) {
        return null
      }
      const envelope = extractFrontendChangesEnvelope(raw)
      if (envelope === null) {
        return null
      }
      return extract(envelope, raw)
    }
    return new ChatSignalDefinition(type, parser, null)
  }
}
