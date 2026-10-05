// The profile windows a browser session is half-way through (HIL-1182): how far
// the email change, password change, account deletion and add-method dialog have got, held by the SESSION rather
// than by the tab that opened them.
//
// A window used to keep its step in the tab that drew it, so a second tab of the
// same browser started over from step one and a reload threw the flow away. The
// server now remembers the step and what was proven on it, and says so on this
// frame to every tab of the session — on every step and on every handshake. A
// window opened in any tab therefore stands on the step already reached, and an
// open window moves when a neighbour moves it; another browser of the same
// person is told nothing.
//
// Like the send-progress line and the toast stack, the frame carries the WHOLE
// list rather than a change, so a reconnect and an ordinary step are one and the
// same sentence; an empty list is the legal frame that closes every window.
//
// The name, the action and the step values are byte-equal to the backend
// `HilosSignalConstants` / `HilosProfileFlow` constants.
import { z } from 'zod'

import { type HilosConnection } from '../connection/HilosConnection.js'
import { type ProjectSignal } from '../protocol/parseSignal.js'
import {
  computedSignal,
  createSignal,
  type ReadonlySignal,
} from '../state/signal.js'

/** Signal `type` of the list (PHP `HilosSignalConstants::HILOS_PROFILE_FLOWS`). */
export const SIGNAL_PROFILE_FLOWS = 'hilos_profile_flows'

/**
 * Client→server: discard one window's flow for the whole session (PHP
 * `HilosSignalConstants::HILOS_PROFILE_FLOW_CANCEL`). Payload `{operation}`,
 * answered with an empty success.
 */
export const PROFILE_FLOW_CANCEL_ACTION = 'hilos_profile_flow_cancel'

/** Email change: a code went to the address the account holds now (PHP `STEP_CURRENT_SENT`). */
export const PROFILE_FLOW_STEP_CURRENT_SENT = 'current_sent'

/** Email change: that code matched; the new address is next (PHP `STEP_CURRENT_PROVEN`). */
export const PROFILE_FLOW_STEP_CURRENT_PROVEN = 'current_proven'

/** Email change: a code went to the new address the entry's `target` names (PHP `STEP_NEW_SENT`). */
export const PROFILE_FLOW_STEP_NEW_SENT = 'new_sent'

/** Password change or account deletion: a code went to the account's address (PHP `STEP_CODE_SENT`). */
export const PROFILE_FLOW_STEP_CODE_SENT = 'code_sent'

/** Password change: that code matched; the new password is next (PHP `STEP_CODE_PROVEN`). */
export const PROFILE_FLOW_STEP_CODE_PROVEN = 'code_proven'

/** Add a way to sign in: a code went to the phone number being added (PHP `STEP_PHONE_SENT`). */
export const PROFILE_FLOW_STEP_PHONE_SENT = 'phone_sent'

/** Add a way to sign in: a code went to the address a password is being added on (PHP `STEP_EMAIL_SENT`). */
export const PROFILE_FLOW_STEP_EMAIL_SENT = 'email_sent'

/**
 * The frame: every live flow of the session, which may be none.
 *
 * `operation` names the window (`change_email`, `change_password`, `delete_account`, `add_sign_in_method`), `step` says
 * what has happened in it, `address` is the account's address the proof stands
 * on, and `target` is the new email on `new_sent`, the added address or number
 * on `phone_sent` or `email_sent`, and null elsewhere.
 */
export const profileFlowsSchema = z.looseObject({
  flows: z.array(
    z.looseObject({
      operation: z.string(),
      step: z.string(),
      address: z.string(),
      target: z.string().nullable().default(null),
    }),
  ),
})

/** One window's flow as the session remembers it. */
export interface HilosProfileFlowState {
  /** The window - the operation key it confirms (`change_email`, `change_password`, `delete_account`, `add_sign_in_method`). */
  readonly operation: string
  /** What has happened in it - one of the `PROFILE_FLOW_STEP_*` values. */
  readonly step: string
  /** The account's address the proof stands on. */
  readonly address: string
  /** New email on `new_sent`, added address or number on `phone_sent` or `email_sent`, `null` otherwise. */
  readonly target: string | null
}

const profileFlows = createSignal<readonly HilosProfileFlowState[]>([])

/**
 * Every flow this browser session has going, as the server last said.
 *
 * A HELD value rather than a subscription, for the reason the send-progress line
 * is one: the list is published on every handshake, before any page mounts its
 * window, and a window opened later reads it from here.
 */
export const hilosProfileFlows: ReadonlySignal<
  readonly HilosProfileFlowState[]
> = profileFlows

/**
 * Route the session's profile-flow frames into {@link hilosProfileFlows}.
 *
 * Bound by `bootHilos` before the socket opens, so the handshake's list is never
 * missed. An empty list is stored as the absence it means.
 *
 * @param connection The application's Hilos connection.
 * @returns Unsubscribe for the registered signal handler.
 */
export function bindProfileFlows(connection: HilosConnection): () => void {
  return connection.on('projectSignal', (signal: ProjectSignal) => {
    if (signal.type !== SIGNAL_PROFILE_FLOWS) {
      return
    }
    const data = signal.data as ReturnType<typeof profileFlowsSchema.parse>
    profileFlows.set(
      data.flows.map((flow) => ({
        operation: flow.operation,
        step: flow.step,
        address: flow.address,
        target: flow.target,
      })),
    )
  })
}

/**
 * The flow of one window in this session, or `null` when nobody has one going.
 *
 * @param operation The window's operation key.
 */
export function hilosProfileFlowFor(
  operation: string,
): ReadonlySignal<HilosProfileFlowState | null> {
  return computedSignal(
    () =>
      profileFlows.get().find((flow) => flow.operation === operation) ?? null,
  )
}
