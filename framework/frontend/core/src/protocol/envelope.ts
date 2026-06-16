// The signal envelope: the abstract layer every server frame is narrowed
// through before any concrete signal is typed (wire-protocol.md, layered
// discriminated-union parsing). The envelope is deliberately loose — unknown
// extra keys and unknown signal types must survive parsing so a newer backend
// never breaks an older client.
import { z } from 'zod'

/**
 * Abstract envelope of every server→client frame:
 * `{type, data, outcome?, requestId?, time?}` as serialized by the daemon.
 *
 * `outcome` marks action acknowledgements (success/fail); `requestId` echoes the
 * client-minted id that correlates an action reply with its action; `time` is
 * the reserved server clock tick in milliseconds. All ride the envelope, not
 * the payload.
 */
export const signalEnvelopeSchema = z.looseObject({
  type: z.string().min(1),
  data: z.unknown(),
  outcome: z.enum(['success', 'fail']).optional(),
  requestId: z.string().optional(),
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
 * reason. The `outcome: 'fail'` marker and the correlating `requestId` ride the
 * envelope, not this payload. Parsed natively by the core parse boundary
 * (action_error is a framework signal, not a project schema); the
 * request-correlated acknowledgement lifecycle (ActionLifecycle) consumes the
 * echoed requestId.
 */
export const actionErrorSignalDataSchema = z.looseObject({
  action: z.string(),
  reason: z.string(),
})

export type ActionErrorSignalData = z.infer<typeof actionErrorSignalDataSchema>

/**
 * Payload of the framework action-success reply (`type: 'action_success'`, PHP
 * `PageActionSuccessSignalData`): the committed action's name. The reply carries
 * no domain body — the real state arrives over the page payload — and the
 * correlating `requestId` plus the `outcome: 'success'` marker ride the
 * envelope, not this payload.
 */
export const actionSuccessSignalDataSchema = z.looseObject({
  action: z.string(),
})

export type ActionSuccessSignalData = z.infer<
  typeof actionSuccessSignalDataSchema
>
