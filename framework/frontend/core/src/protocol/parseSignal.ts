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
  SIGNAL_TYPE_PROTECTED_MODE,
  SIGNAL_TYPE_TABLE_VIEWPORT_APPEND,
  SIGNAL_TYPE_TABLE_VIEWPORT_COUNT,
  SIGNAL_TYPE_TABLE_VIEWPORT_DELTA,
  SIGNAL_TYPE_TABLE_WINDOW,
} from './constants.js'
import {
  signalEnvelopeSchema,
  handshakeSignalDataSchema,
  actionErrorSignalDataSchema,
  actionSuccessSignalDataSchema,
  tableWindowSignalDataSchema,
  tableViewportDeltaSignalDataSchema,
  tableViewportCountSignalDataSchema,
  tableViewportAppendSignalDataSchema,
  type SignalEnvelope,
  type TableWindowSignalData,
  type TableViewportDeltaSignalData,
  type TableViewportCountSignalData,
  type TableViewportAppendSignalData,
} from './envelope.js'
import {
  protectedModeBlockSchema,
  toProtectedModeStatus,
  type ProtectedModeStatus,
} from './protectedMode.js'

/**
 * Project-declared concrete signal schemas, keyed by signal `type`. Framework
 * signal types take precedence and cannot be shadowed. `data` stays `unknown`
 * on the parsed signal until the declaration-merging typing of the action
 * step; the consumer narrows it with a typed selector per declared schema.
 */
export type ProjectSignalSchemas = Record<string, ZodType>

/** A frame that parsed into a signal the core understands or tolerates. */
export type ParsedSignal =
  | {
      kind: 'handshake'
      build: string
      protectedMode: ProtectedModeStatus
      envelope: SignalEnvelope
    }
  | {
      kind: 'protectedMode'
      state: ProtectedModeStatus
      envelope: SignalEnvelope
    }
  | {
      kind: 'actionSuccess'
      action: string
      message: string | undefined
      reply: unknown
      requestId: string | undefined
      envelope: SignalEnvelope
    }
  | {
      kind: 'actionError'
      action: string
      reason: string
      errorCode: string | undefined
      requestId: string | undefined
      envelope: SignalEnvelope
    }
  | {
      kind: 'tableWindow'
      data: TableWindowSignalData
      envelope: SignalEnvelope
    }
  | {
      kind: 'tableViewportDelta'
      data: TableViewportDeltaSignalData
      envelope: SignalEnvelope
    }
  | {
      kind: 'tableViewportCount'
      data: TableViewportCountSignalData
      envelope: SignalEnvelope
    }
  | {
      kind: 'tableViewportAppend'
      data: TableViewportAppendSignalData
      envelope: SignalEnvelope
    }
  | { kind: 'project'; type: string; data: unknown; envelope: SignalEnvelope }
  | { kind: 'unknown'; type: string; envelope: SignalEnvelope }

export type HandshakeSignal = Extract<ParsedSignal, { kind: 'handshake' }>
export type ProtectedModeSignal = Extract<
  ParsedSignal,
  { kind: 'protectedMode' }
>
export type ActionSuccessSignal = Extract<
  ParsedSignal,
  { kind: 'actionSuccess' }
>
export type ActionErrorSignal = Extract<ParsedSignal, { kind: 'actionError' }>
export type TableWindowSignal = Extract<ParsedSignal, { kind: 'tableWindow' }>
export type TableViewportDeltaSignal = Extract<
  ParsedSignal,
  { kind: 'tableViewportDelta' }
>
export type TableViewportCountSignal = Extract<
  ParsedSignal,
  { kind: 'tableViewportCount' }
>
export type TableViewportAppendSignal = Extract<
  ParsedSignal,
  { kind: 'tableViewportAppend' }
>
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
 * Framework signal types (`handshake`, `protected_mode`, `action_success`,
 * `action_error`) are
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
          protectedMode: toProtectedModeStatus(data.data.protectedMode),
          envelope: envelope.data,
        },
      }
    }

    case SIGNAL_TYPE_PROTECTED_MODE: {
      // Unlike the welcome's tolerated block, this frame IS the message: one that
      // cannot be read would leave the client guessing whether it is frozen, so it
      // is rejected outright and the state stays whatever the client last knew.
      const data = protectedModeBlockSchema.safeParse(envelope.data.data)
      if (!data.success) {
        return {
          ok: false,
          failure: {
            kind: 'invalid-signal-data',
            type: SIGNAL_TYPE_PROTECTED_MODE,
            message: data.error.message,
          },
        }
      }

      return {
        ok: true,
        signal: {
          kind: 'protectedMode',
          state: toProtectedModeStatus(data.data),
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
          message: data.data.message,
          reply: data.data.reply,
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
          errorCode: data.data.errorCode,
          requestId: envelope.data.requestId,
          envelope: envelope.data,
        },
      }
    }

    case SIGNAL_TYPE_TABLE_WINDOW: {
      const data = tableWindowSignalDataSchema.safeParse(envelope.data.data)
      if (!data.success) {
        return {
          ok: false,
          failure: {
            kind: 'invalid-signal-data',
            type: SIGNAL_TYPE_TABLE_WINDOW,
            message: data.error.message,
          },
        }
      }

      return {
        ok: true,
        signal: {
          kind: 'tableWindow',
          data: data.data,
          envelope: envelope.data,
        },
      }
    }

    case SIGNAL_TYPE_TABLE_VIEWPORT_DELTA: {
      const data = tableViewportDeltaSignalDataSchema.safeParse(
        envelope.data.data,
      )
      if (!data.success) {
        return {
          ok: false,
          failure: {
            kind: 'invalid-signal-data',
            type: SIGNAL_TYPE_TABLE_VIEWPORT_DELTA,
            message: data.error.message,
          },
        }
      }

      return {
        ok: true,
        signal: {
          kind: 'tableViewportDelta',
          data: data.data,
          envelope: envelope.data,
        },
      }
    }

    case SIGNAL_TYPE_TABLE_VIEWPORT_COUNT: {
      const data = tableViewportCountSignalDataSchema.safeParse(
        envelope.data.data,
      )
      if (!data.success) {
        return {
          ok: false,
          failure: {
            kind: 'invalid-signal-data',
            type: SIGNAL_TYPE_TABLE_VIEWPORT_COUNT,
            message: data.error.message,
          },
        }
      }

      return {
        ok: true,
        signal: {
          kind: 'tableViewportCount',
          data: data.data,
          envelope: envelope.data,
        },
      }
    }

    case SIGNAL_TYPE_TABLE_VIEWPORT_APPEND: {
      const data = tableViewportAppendSignalDataSchema.safeParse(
        envelope.data.data,
      )
      if (!data.success) {
        return {
          ok: false,
          failure: {
            kind: 'invalid-signal-data',
            type: SIGNAL_TYPE_TABLE_VIEWPORT_APPEND,
            message: data.error.message,
          },
        }
      }

      return {
        ok: true,
        signal: {
          kind: 'tableViewportAppend',
          data: data.data,
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
