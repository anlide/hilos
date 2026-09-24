// The reply of a sign-in action read as the flow outcome the machine applies, and
// the step/intent pair off the wire narrowed into a patch (HIL-409). Its own module
// because two dispatchers read it — the wire of the surface and the WebAuthn
// ceremony, whose confirm can be answered with a step since a sign-in may be held on
// its second factor (HIL-494) — and the ceremony is itself a dependency of the wire.
import { z } from 'zod'

import {
  type AuthFlowState,
  type AuthFlowSubmitOutcome,
  type AuthIntent,
  type AuthStep,
} from './authFlow.js'

/** The steps a backend reply or a converge may name (PHP `AuthFlowStep`). */
const FLOW_STEPS: readonly AuthStep[] = [
  'identifier',
  'consent',
  'code',
  'code_expired',
  'second_factor',
  'second_factor_setup',
  'second_factor_codes',
  'second_factor_reset',
  'second_factor_reset_requested',
  'set_password',
  'external',
  'done',
]

/** The intents a backend reply or a converge may name (PHP `AuthFlowIntent`). */
const FLOW_INTENTS: readonly AuthIntent[] = ['login', 'register', 'recovery']

/**
 * What a second-factor screen needs beyond its step (PHP `SecondFactorStepData`,
 * HIL-494). The two moments-and-days members ride every such answer and may be
 * null; the secret and the codes ride only the answer that shows them.
 */
const secondFactorStepDataSchema = z.object({
  trustDeviceDays: z.number().nullable(),
  resetEffectiveAt: z.number().nullable(),
  setup: z.object({ secret: z.string(), otpauthUri: z.string() }).optional(),
  backupCodes: z.array(z.string()).optional(),
})

/**
 * A submit reply (PHP `Hilos\Auth\Flow\AuthFlowOutcome`), optional because most
 * of these actions answer with nothing at all: a sign-in upgrades the session
 * and the gate closes the surface off the current-user signal, and an action
 * that answered nothing is the success its ack already made it.
 *
 * `next` rides a FAILURE too, which is the shape's one load-bearing oddity: a
 * rejected submit on this surface usually moves (a taken address becomes a
 * sign-in, an expired hold goes back to the identifier field).
 */
export const authFlowOutcomeSchema = z
  .object({
    ok: z.boolean(),
    next: z
      .object({ step: z.string(), intent: z.string() })
      .partial()
      .optional(),
    code: z.string().optional(),
    message: z.string().optional(),
    resendAt: z.number().optional(),
    expiresAt: z.number().optional(),
    secondFactor: secondFactorStepDataSchema.optional(),
  })
  .optional()

/**
 * Narrow a step/intent pair off the wire into the patch the machine merges, or
 * null when the step is one this build has no screen for.
 *
 * The one narrowing both inbound halves of the converge property go through: a
 * submit reply names where the flow goes next, and a converge signal names the
 * same thing for a session that submitted nothing. A server one deploy ahead may
 * name a step that does not exist here, and ignoring it is a better answer than
 * a surface stuck on a screen it cannot draw.
 *
 * @param step The step name off the wire.
 * @param intent The intent name off the wire, which may be absent.
 * @returns The patch to merge, or null when the step is unknown.
 */
export function toFlowPatch(
  step: unknown,
  intent: unknown,
): Partial<AuthFlowState> | null {
  const known = FLOW_STEPS.find((candidate) => candidate === step)
  if (known === undefined) {
    return null
  }
  const knownIntent = FLOW_INTENTS.find((candidate) => candidate === intent)

  return knownIntent === undefined
    ? { step: known }
    : { step: known, intent: knownIntent }
}

/**
 * The outcome a validated reply carries.
 *
 * A reply that says nothing is the success its ack already made it: a sign-in
 * that upgraded the session answers nothing, and the gate closes the surface off
 * the current-user signal.
 *
 * @param reply The reply as {@link authFlowOutcomeSchema} validated it.
 * @returns The outcome the machine applies.
 */
export function authFlowOutcomeOf(
  reply: z.infer<typeof authFlowOutcomeSchema>,
): AuthFlowSubmitOutcome {
  if (reply === undefined) {
    return { ok: true }
  }
  const next = reply.next

  return {
    ok: reply.ok,
    message: reply.message,
    code: reply.code,
    next:
      next === undefined
        ? undefined
        : (toFlowPatch(next.step, next.intent) ?? undefined),
    resendAt: reply.resendAt,
    expiresAt: reply.expiresAt,
    secondFactor: reply.secondFactor,
  }
}
