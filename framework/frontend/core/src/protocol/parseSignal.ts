// The canonical parse boundary: the only place a raw socket frame may be
// interpreted (wire-protocol.md). Narrowing is layered — raw unknown → signal
// envelope → concrete signal by `type` tag. Shape-sniffing a message anywhere
// else is a gross violation.
import { SIGNAL_TYPE_HANDSHAKE } from './constants.js'
import {
  signalEnvelopeSchema,
  handshakeSignalDataSchema,
  type SignalEnvelope,
} from './envelope.js'

/** A frame that parsed into a signal the core understands or tolerates. */
export type ParsedSignal =
  | { kind: 'handshake'; build: string; envelope: SignalEnvelope }
  | { kind: 'unknown'; type: string; envelope: SignalEnvelope }

export type HandshakeSignal = Extract<ParsedSignal, { kind: 'handshake' }>
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
 * Unknown signal `type` values parse successfully as `kind: 'unknown'` — the
 * project layer narrows them further; only frames violating the envelope
 * contract itself come back as failures.
 */
export function parseSignal(raw: unknown): ParseResult {
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

    default:
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
