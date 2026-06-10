// The signal envelope: the abstract layer every server frame is narrowed
// through before any concrete signal is typed (wire-protocol.md, layered
// discriminated-union parsing). The envelope is deliberately loose — unknown
// extra keys and unknown signal types must survive parsing so a newer backend
// never breaks an older client.
import { z } from 'zod'

/**
 * Abstract envelope of every server→client frame:
 * `{type, data, outcome?, time?}` as serialized by the daemon.
 *
 * `outcome` marks action acknowledgements (success/fail); `time` is the
 * reserved server clock tick in milliseconds. Both ride the envelope, not the
 * payload.
 */
export const signalEnvelopeSchema = z.looseObject({
  type: z.string().min(1),
  data: z.unknown(),
  outcome: z.enum(['success', 'fail']).optional(),
  time: z.number().int().optional(),
})

export type SignalEnvelope = z.infer<typeof signalEnvelopeSchema>

/** Payload of the framework welcome (`type: 'handshake'`), sent first after the socket opens. */
export const handshakeSignalDataSchema = z.looseObject({
  build: z.string().min(1),
})

export type HandshakeSignalData = z.infer<typeof handshakeSignalDataSchema>
