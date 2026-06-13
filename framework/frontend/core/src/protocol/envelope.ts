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

/**
 * Payload of the framework action-failure signal (`type: 'action_error'`, PHP
 * `PageActionErrorSignalData`): the failed action's name and a human-readable
 * reason. The `outcome: 'fail'` marker rides the envelope, not this payload.
 * Parsed natively by the core parse boundary (action_error is a framework
 * signal, not a project schema); the request-correlated acknowledgement
 * lifecycle lands at step 7.4.
 */
export const actionErrorSignalDataSchema = z.looseObject({
  action: z.string(),
  reason: z.string(),
})

export type ActionErrorSignalData = z.infer<typeof actionErrorSignalDataSchema>
