import { SIGNAL_TYPE_SIGNAL_BATCH } from './constants.js'
import { signalBatchEnvelopeSchema, signalEnvelopeSchema } from './envelope.js'
import type { ParseFailure } from './parseSignal.js'

export type UnpackSignalFramesResult =
  { ok: true; frames: unknown[] } | { ok: false; failure: ParseFailure }

/**
 * Decode one physical WebSocket message, validating its outer envelope first.
 * Inner frames stay unknown until parseSignalValue validates each independently.
 *
 * @param raw The raw text payload off the socket; binary frames are refused.
 */
export function unpackSignalFrames(raw: unknown): UnpackSignalFramesResult {
  if (typeof raw !== 'string') {
    return { ok: false, failure: { kind: 'non-text-frame' } }
  }

  let value: unknown
  try {
    value = JSON.parse(raw)
  } catch {
    return { ok: false, failure: { kind: 'malformed-json', raw } }
  }

  const envelope = signalEnvelopeSchema.safeParse(value)
  if (!envelope.success) {
    return {
      ok: false,
      failure: { kind: 'invalid-envelope', message: envelope.error.message },
    }
  }
  if (envelope.data.type !== SIGNAL_TYPE_SIGNAL_BATCH) {
    return { ok: true, frames: [envelope.data] }
  }

  const batch = signalBatchEnvelopeSchema.safeParse(envelope.data)
  if (!batch.success) {
    return {
      ok: false,
      failure: {
        kind: 'invalid-signal-data',
        type: SIGNAL_TYPE_SIGNAL_BATCH,
        message: batch.error.message,
      },
    }
  }

  return { ok: true, frames: batch.data.data.frames }
}
