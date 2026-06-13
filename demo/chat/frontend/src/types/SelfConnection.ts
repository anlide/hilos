// The current connection's own composer state — a value type, not an entity. It
// rides the main page's `selfConnection` data slot (a single-row backend DATA
// source keyed by this socket's acceptKey), carrying the re-send rate-limit
// countdown and the outbound moderation state the composer reflects. Files are
// out of scope here, so the upload fields of the wire payload are not projected.
// Wire keys mirror the backend SelfConnectionSignalData / OutboundModerationBrowserPayload.
import { readNumber, readString, readStringOrNull } from '@hilos/core'

/** Outbound moderation lifecycle phase (PHP `ConnectionRuntimeConstants::OUTBOUND_MODERATION_PHASE_*`). */
export type ModerationPhase = 'checking' | 'rejected' | 'unavailable'

const MODERATION_PHASES: readonly ModerationPhase[] = [
  'checking',
  'rejected',
  'unavailable',
]

/** The connection-local outbound moderation state, present only while a submit is in flight or unresolved. */
export interface OutboundModeration {
  phase: ModerationPhase
  /** The submitted text held by the backend — restored into the input on a non-checking phase. */
  text: string
  /** A rejection or unavailable reason, or null. */
  reason: string | null
}

/** The composer-relevant projection of the `selfConnection` data slot. */
export interface SelfConnection {
  /** Seconds the backend still rate-limits the next submit; 0 when free to send. */
  messageRateLimitSecondsRemaining: number
  /** The outbound moderation state, or null when none is in flight. */
  moderation: OutboundModeration | null
}

/**
 * Narrow the `outboundModerationState` nested slot to a typed moderation state,
 * or null when absent or carrying an unknown phase.
 *
 * @param raw The raw `outboundModerationState` value from the payload.
 */
function toModeration(raw: unknown): OutboundModeration | null {
  if (typeof raw !== 'object' || raw === null) {
    return null
  }
  const fields = raw as Record<string, unknown>
  const phase = fields.phase
  if (!MODERATION_PHASES.includes(phase as ModerationPhase)) {
    return null
  }

  return {
    phase: phase as ModerationPhase,
    text: readString(fields, 'text'),
    reason: readStringOrNull(fields, 'reason'),
  }
}

/**
 * Project the raw `selfConnection` data slot into the typed composer state, or
 * undefined before the first selfConnection payload lands.
 *
 * @param raw The raw `selfConnection` data-slot value from the page scope.
 */
export function toSelfConnection(raw: unknown): SelfConnection | undefined {
  if (typeof raw !== 'object' || raw === null) {
    return undefined
  }
  const fields = raw as Record<string, unknown>

  return {
    messageRateLimitSecondsRemaining: readNumber(
      fields,
      'messageRateLimitSecondsRemaining',
    ),
    moderation: toModeration(fields.outboundModerationState),
  }
}
