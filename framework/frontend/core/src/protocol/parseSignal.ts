// The canonical parse boundary: the only place a raw socket frame may be
// interpreted (wire-protocol.md). Narrowing is layered — raw unknown → signal
// envelope → concrete signal by `type` tag. The framework owns the envelope
// and category layers; the project supplies schemas for its concrete leaf
// signals. Shape-sniffing a message anywhere else is a gross violation.
import type { ZodType } from 'zod'
import {
  SIGNAL_TYPE_ACTION_ERROR,
  SIGNAL_TYPE_ACTION_SUCCESS,
  SIGNAL_TYPE_HANDSHAKE,
} from './constants.js'
import {
  signalEnvelopeSchema,
  handshakeSignalDataSchema,
  actionErrorSignalDataSchema,
  actionSuccessSignalDataSchema,
  type SignalEnvelope,
} from './envelope.js'

/**
 * Project-declared concrete signal schemas, keyed by signal `type`. Framework
 * signal types take precedence and cannot be shadowed. `data` stays `unknown`
 * on the parsed signal until the declaration-merging typing of the action
 * step; the consumer narrows it with a typed selector per declared schema.
 */
export type ProjectSignalSchemas = Record<string, ZodType>

/** A frame that parsed into a signal the core understands or tolerates. */
export type ParsedSignal =
  | { kind: 'handshake'; build: string; envelope: SignalEnvelope }
  | {
      kind: 'actionSuccess'
      action: string
      requestId: string | undefined
      envelope: SignalEnvelope
    }
  | {
      kind: 'actionError'
      action: string
      reason: string
      requestId: string | undefined
      envelope: SignalEnvelope
    }
  | { kind: 'project'; type: string; data: unknown; envelope: SignalEnvelope }
  | { kind: 'unknown'; type: string; envelope: SignalEnvelope }

export type HandshakeSignal = Extract<ParsedSignal, { kind: 'handshake' }>
export type ActionSuccessSignal = Extract<
  ParsedSignal,
  { kind: 'actionSuccess' }
>
export type ActionErrorSignal = Extract<ParsedSignal, { kind: 'actionError' }>
export type ProjectSignal = Extract<ParsedSignal, { kind: 'project' }>
export type UnknownSignal = Extract<ParsedSignal, { kind: 'unknown' }>

/**
 * A frame that failed the parse boundary. The connection survives every
 * failure — the server is authoritative and a bad frame is reported, never
 * fatal client-side.
 */
export type ParseFailure =
  | { kind: 'non-text-frame' }
  | { kind: 'malformed-json'; raw: string }
  | { kind: 'invalid-envelope'; message: string }
  | { kind: 'invalid-signal-data'; type: string; message: string }

export type ParseResult =
  | { ok: true; signal: ParsedSignal }
  | { ok: false; failure: ParseFailure }

/**
 * Parse one raw WebSocket frame into a typed signal.
 *
 * Framework signal types (`handshake`, `action_success`, `action_error`) are
 * owned by the core and parse to their own kind ahead of any project schema —
 * the two action replies surfacing the envelope's `requestId` for correlation.
 * A `type` with a
 * project schema validates against it and parses as `kind: 'project'`. Unknown
 * signal `type` values parse successfully as `kind: 'unknown'` — tolerated and
 * observable; only frames violating the envelope contract or a declared schema
 * come back as failures.
 *
 * @param raw The raw frame payload off the socket.
 * @param projectSchemas Project-declared concrete signal schemas by `type`.
 */
export function parseSignal(
  raw: unknown,
  projectSchemas?: ProjectSignalSchemas,
): ParseResult {
  if (typeof raw !== 'string') {
    return { ok: false, failure: { kind: 'non-text-frame' } }
  }

  let json: unknown
  try {
    json = JSON.parse(raw)
  } catch {
    return { ok: false, failure: { kind: 'malformed-json', raw } }
  }

  const envelope = signalEnvelopeSchema.safeParse(json)
  if (!envelope.success) {
    return {
      ok: false,
      failure: { kind: 'invalid-envelope', message: envelope.error.message },
    }
  }

  switch (envelope.data.type) {
    case SIGNAL_TYPE_HANDSHAKE: {
      const data = handshakeSignalDataSchema.safeParse(envelope.data.data)
      if (!data.success) {
        return {
          ok: false,
          failure: {
            kind: 'invalid-signal-data',
            type: SIGNAL_TYPE_HANDSHAKE,
            message: data.error.message,
          },
        }
      }

      return {
        ok: true,
        signal: {
          kind: 'handshake',
          build: data.data.build,
          envelope: envelope.data,
        },
      }
    }

    case SIGNAL_TYPE_ACTION_SUCCESS: {
      const data = actionSuccessSignalDataSchema.safeParse(envelope.data.data)
      if (!data.success) {
        return {
          ok: false,
          failure: {
            kind: 'invalid-signal-data',
            type: SIGNAL_TYPE_ACTION_SUCCESS,
            message: data.error.message,
          },
        }
      }

      return {
        ok: true,
        signal: {
          kind: 'actionSuccess',
          action: data.data.action,
          requestId: envelope.data.requestId,
          envelope: envelope.data,
        },
      }
    }

    case SIGNAL_TYPE_ACTION_ERROR: {
      const data = actionErrorSignalDataSchema.safeParse(envelope.data.data)
      if (!data.success) {
        return {
          ok: false,
          failure: {
            kind: 'invalid-signal-data',
            type: SIGNAL_TYPE_ACTION_ERROR,
            message: data.error.message,
          },
        }
      }

      return {
        ok: true,
        signal: {
          kind: 'actionError',
          action: data.data.action,
          reason: data.data.reason,
          requestId: envelope.data.requestId,
          envelope: envelope.data,
        },
      }
    }

    default: {
      const schema = projectSchemas?.[envelope.data.type]
      if (schema) {
        const data = schema.safeParse(envelope.data.data)
        if (!data.success) {
          return {
            ok: false,
            failure: {
              kind: 'invalid-signal-data',
              type: envelope.data.type,
              message: data.error.message,
            },
          }
        }

        return {
          ok: true,
          signal: {
            kind: 'project',
            type: envelope.data.type,
            data: data.data,
            envelope: envelope.data,
          },
        }
      }

      return {
        ok: true,
        signal: {
          kind: 'unknown',
          type: envelope.data.type,
          envelope: envelope.data,
        },
      }
    }
  }
}
