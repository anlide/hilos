// The profile's second-factor section (HIL-494): the reactive store the three
// HilosProfileSecurityPage views render, and the profile actions they dispatch.
// Framework-agnostic, so the views stay thin (multiframework-core.md).
//
// The section arrives whole, twice over: as the `secondFactor` data of the
// /profile/security subscription for the first render, and as
// `hilos_second_factor_state` to the person's group after every write to their
// factor, from whichever tab or browser made it. Whole rather than a diff, so a
// tab that missed one change never draws the next on top of a picture that is no
// longer true. The administrator's policy frame is the one thing that changes the
// section without a new copy of it, and it is read here as news newer than the
// copy (sessionSecondFactorPolicy).
//
// Every action but the first enrolment, the wait and the removal request starts
// with a code — from an app or a backup code — because a stolen live session must
// not strip or copy the factor. The server judges the code; the store carries it.
import { z } from 'zod'

import {
  type ActionHandle,
  type ActionLifecycle,
} from '../connection/actionLifecycle.js'
import { type HilosConnection } from '../connection/HilosConnection.js'
import { type ProjectSignal } from '../protocol/parseSignal.js'
import { toLocal } from '../session/serverClock.js'
import {
  sessionSecondFactorPolicy,
  type SecondFactorPolicy,
} from '../session/sessionScope.js'
import { type ScopeManager } from '../state/ScopeManager.js'
import {
  computedSignal,
  createSignal,
  subscribeSignal,
  type ReadonlySignal,
} from '../state/signal.js'

/**
 * Server→client (WS_GROUP): one person's second-factor section, whole (PHP
 * `HilosSignalConstants::HILOS_SECOND_FACTOR_STATE`).
 */
export const SIGNAL_SECOND_FACTOR_STATE = 'hilos_second_factor_state'

/**
 * The data key the /profile/security subscription carries the section under
 * (PHP `AbstractHilosProfileSecurityPage::SECOND_FACTOR_SECTION`).
 */
export const PROFILE_SECOND_FACTOR_SECTION = 'secondFactor'

// Client→server profile actions (PHP `HilosSignalConstants::PROFILE_SECOND_FACTOR_*`).
const ACTION_ENROLL_START = 'profile_second_factor_enroll_start'
const ACTION_ENROLL_CONFIRM = 'profile_second_factor_enroll_confirm'
const ACTION_REMOVE = 'profile_second_factor_remove'
const ACTION_CODES_SHOW = 'profile_second_factor_codes_show'
const ACTION_CODES_RENEW = 'profile_second_factor_codes_renew'
const ACTION_RESET_WAIT_SET = 'profile_second_factor_reset_wait_set'
const ACTION_RESET_REQUEST = 'profile_second_factor_reset_request'
const ACTION_RESET_CANCEL = 'profile_second_factor_reset_cancel'

/**
 * The section payload (PHP `SecondFactorStateSignalData`). Moments are SERVER
 * epoch ms here and local in {@link HilosSecondFactorState}.
 */
export const secondFactorStateSchema = z.looseObject({
  authenticators: z.array(
    z.looseObject({
      id: z.number(),
      label: z.string(),
      createdAt: z.number(),
      lastUsedAt: z.number().nullable(),
    }),
  ),
  backupCodesLeft: z.number(),
  backupCodesTotal: z.number(),
  required: z.boolean(),
  resetWait: z.looseObject({
    days: z.number(),
    pendingDays: z.number().nullable(),
    pendingFrom: z.number().nullable(),
    defaultDays: z.number(),
    minDays: z.number(),
    maxDays: z.number(),
  }),
  reset: z
    .looseObject({ requestedAt: z.number(), effectiveAt: z.number() })
    .nullable(),
})

/**
 * The section signal schema keyed for a connection's `projectSchemas`, so the
 * parse boundary validates the frame before {@link HilosSecondFactorStore}
 * reads it. {@link createHilosConnection} merges it in.
 */
export const SECOND_FACTOR_SIGNAL_SCHEMAS = {
  [SIGNAL_SECOND_FACTOR_STATE]: secondFactorStateSchema,
}

/** One connected authenticator app, as the section lists it. */
export interface HilosSecondFactorAuthenticator {
  /** The authenticator id the remove action names. */
  readonly id: number
  /** The name the person gave the app when connecting it. */
  readonly label: string
  /** The LOCAL moment it was connected, in epoch ms. */
  readonly createdAt: number
  /** The LOCAL moment a code from it last passed, or `null` when none has. */
  readonly lastUsedAt: number | null
}

/**
 * The person's removal wait and the administrator's bounds on it. A shorter
 * wait waits itself: until `pendingFrom` the wait in force stays `days`, and
 * `pendingDays` is what it becomes then.
 */
export interface HilosSecondFactorResetWait {
  /** The wait in force, in days. */
  readonly days: number
  /** A shorter wait the person chose that is not in force yet, or `null`. */
  readonly pendingDays: number | null
  /** The LOCAL moment that shorter wait takes over, or `null`. */
  readonly pendingFrom: number | null
  /** The wait a person starts on. */
  readonly defaultDays: number
  /** The shortest wait a person may choose. */
  readonly minDays: number
  /** The longest wait a person may choose. */
  readonly maxDays: number
}

/** A removal of the factor that stands, waiting for its date. */
export interface HilosSecondFactorReset {
  /** The LOCAL moment it was asked for. */
  readonly requestedAt: number
  /** The LOCAL moment it takes effect. */
  readonly effectiveAt: number
}

/** The profile's second-factor section, as the page draws it. */
export interface HilosSecondFactorState {
  /** The connected apps; empty means the factor is off. */
  readonly authenticators: readonly HilosSecondFactorAuthenticator[]
  /** Unused codes of the person's backup set. */
  readonly backupCodesLeft: number
  /** Codes in that set. */
  readonly backupCodesTotal: number
  /**
   * Whether the administrator requires a second factor of this person — the
   * last app cannot be removed then, and the line says why.
   */
  readonly required: boolean
  /** The removal wait and its bounds. */
  readonly resetWait: HilosSecondFactorResetWait
  /** The removal that stands, or `null` when none does. */
  readonly reset: HilosSecondFactorReset | null
}

/**
 * Read a section frame or page slot into the state the page draws, or `null`
 * when what arrived is not a section — a half section would draw a factor that
 * is not there.
 *
 * @param raw The section as the wire carried it.
 */
export function readHilosSecondFactorState(
  raw: unknown,
): HilosSecondFactorState | null {
  const parsed = secondFactorStateSchema.safeParse(raw)
  if (!parsed.success) {
    return null
  }
  const data = parsed.data
  const wait = data.resetWait

  return {
    authenticators: data.authenticators.map((entry) => ({
      id: entry.id,
      label: entry.label,
      createdAt: toLocal(entry.createdAt),
      lastUsedAt: entry.lastUsedAt === null ? null : toLocal(entry.lastUsedAt),
    })),
    backupCodesLeft: data.backupCodesLeft,
    backupCodesTotal: data.backupCodesTotal,
    required: data.required,
    resetWait: {
      days: wait.days,
      pendingDays: wait.pendingDays,
      pendingFrom: wait.pendingFrom === null ? null : toLocal(wait.pendingFrom),
      defaultDays: wait.defaultDays,
      minDays: wait.minDays,
      maxDays: wait.maxDays,
    },
    reset:
      data.reset === null
        ? null
        : {
            requestedAt: toLocal(data.reset.requestedAt),
            effectiveAt: toLocal(data.reset.effectiveAt),
          },
  }
}

/**
 * The section with a policy frame NEWER than it laid over it. The bounds are the
 * administrator's and move with the frame. Whether the factor is required of
 * THIS person moves only where the policy alone decides it: `none` and
 * `everyone` do, while `admins` turns on who the administrators are, which the
 * server knows and this tab does not — the next section it sends says it.
 *
 * @param state The section as it was sent.
 * @param policy The policy frame that arrived after it.
 */
function underPolicy(
  state: HilosSecondFactorState,
  policy: SecondFactorPolicy,
): HilosSecondFactorState {
  const required =
    policy.required === 'everyone'
      ? true
      : policy.required === 'none'
        ? false
        : state.required

  return {
    ...state,
    required,
    resetWait: {
      ...state.resetWait,
      defaultDays: policy.resetWaitDefaultDays,
      minDays: policy.resetWaitMinDays,
      maxDays: policy.resetWaitMaxDays,
    },
  }
}

/** The project context the section reads from and dispatches over. */
export interface HilosSecondFactorContext {
  /** The connection the person's section frames arrive on. */
  readonly connection: HilosConnection
  /** The scope manager whose session scope holds the policy frame. */
  readonly scopes: ScopeManager
  /** The action lifecycle the profile actions dispatch over. */
  readonly actions: ActionLifecycle
}

/** The reactive section a profile security page renders. */
export interface HilosSecondFactorStore {
  /** The section as it stands, or `null` before the first copy arrives. */
  readonly state: ReadonlySignal<HilosSecondFactorState | null>
  /**
   * Replace the section from a copy of it — the subscription's data slot or a
   * frame. A copy that is not a section is ignored rather than drawn.
   *
   * @param raw The section as the wire carried it.
   */
  applyState(raw: unknown): void
  /**
   * Start taking the section — from the page's data slot, where the
   * subscription answer puts it, and from the frames of the person's group —
   * call on mount.
   */
  start(): void
  /** Stop taking them and forget the section — call on unmount. */
  dispose(): void
}

/**
 * Create the section store of one profile page.
 *
 * @param context The project context (connection, scope stores, actions).
 */
export function createHilosSecondFactorStore(
  context: HilosSecondFactorContext,
): HilosSecondFactorStore {
  const policy = sessionSecondFactorPolicy(context.scopes)
  // The section, and the policy frame that stood when it came: a frame arriving
  // LATER is news the section was not computed under.
  const held = createSignal<{
    readonly state: HilosSecondFactorState
    readonly policy: SecondFactorPolicy | null
  } | null>(null)
  let stop: (() => void) | null = null

  function applyState(raw: unknown): void {
    const state = readHilosSecondFactorState(raw)
    if (state !== null) {
      held.set({ state, policy: policy.get() })
    }
  }

  return {
    state: computedSignal(() => {
      const current = held.get()
      if (current === null) {
        return null
      }
      const latest = policy.get()

      return latest === null || latest === current.policy
        ? current.state
        : underPolicy(current.state, latest)
    }),
    applyState,
    start() {
      stop?.()
      const section = context.scopes.pageDataSignal(
        PROFILE_SECOND_FACTOR_SECTION,
      )
      applyState(section.get())
      const offSection = subscribeSignal(section, applyState)
      const offFrames = context.connection.on(
        'projectSignal',
        (signal: ProjectSignal) => {
          if (signal.type === SIGNAL_SECOND_FACTOR_STATE) {
            applyState(signal.data)
          }
        },
      )
      stop = () => {
        offSection()
        offFrames()
      }
    },
    dispose() {
      stop?.()
      stop = null
      held.set(null)
    },
  }
}

/**
 * A code the person shows to prove they still hold the factor: from an app, or a
 * backup code — the person says which, and the server burns a backup code.
 */
export interface HilosSecondFactorProof {
  /** The code as typed. */
  readonly code: string
  /** Whether it is a backup code rather than one from an app. */
  readonly backupCode: boolean
}

/** One backup code of the person's set, as the "Show" screen lists it. */
export interface HilosBackupCodeEntry {
  /** The code in display form. */
  readonly code: string
  /** Whether it was spent — the list strikes it through. */
  readonly used: boolean
}

/**
 * An empty reply arrives as an empty JSON array — a PHP array with nothing in it
 * — so the reply schemas read that as an object with nothing in it.
 *
 * @param shape The members of the reply.
 */
function replySchema<T extends z.ZodRawShape>(shape: T) {
  return z.preprocess(
    (value) => (Array.isArray(value) && value.length === 0 ? {} : value),
    z.object(shape),
  )
}

/** The reply of an enrolment started: the app being connected and its secret, once. */
const enrollStartReplySchema = replySchema({
  authenticatorId: z.number(),
  secret: z.string(),
  otpauthUri: z.string(),
})

/** The reply of an enrolment confirmed: the backup codes, for the first app only. */
const enrollConfirmReplySchema = replySchema({
  backupCodes: z.array(z.string()).optional(),
})

/** The reply of a new set of backup codes. */
const codesRenewReplySchema = replySchema({
  backupCodes: z.array(z.string()),
})

/** The reply of "Show": the set, the spent ones marked. */
const codesShowReplySchema = replySchema({
  codes: z.array(z.object({ code: z.string(), used: z.boolean() })),
})

/** An enrolment started from the profile. */
export type HilosSecondFactorEnrollment = z.infer<typeof enrollStartReplySchema>

/**
 * The profile actions of the section. Each is a tracked action: its `done`
 * resolves on the server's success with the reply parsed, and rejects with the
 * server's sentence on a refusal — the modal that sent it shows the sentence.
 * The section itself redraws from the frame every write sends, not from these
 * replies.
 */
export interface HilosSecondFactorActions {
  /**
   * Start connecting an app: the answer carries its secret, once. The first app
   * needs no proof; any further one does.
   *
   * @param proof A code from a connected app or a backup code, or `null` for the first app.
   */
  enrollStart(
    proof: HilosSecondFactorProof | null,
  ): ActionHandle<HilosSecondFactorEnrollment>
  /**
   * Finish connecting an app with its first code. The first app comes with a
   * set of backup codes, answered once here.
   *
   * @param authenticatorId The app the enrolment started.
   * @param code The first code the app shows.
   * @param label The name the person gives the app; empty leaves it to the server.
   */
  enrollConfirm(
    authenticatorId: number,
    code: string,
    label: string,
  ): ActionHandle<z.infer<typeof enrollConfirmReplySchema>>
  /**
   * Remove an app. The last one switches the factor off whole — codes, trusted
   * browsers and a standing removal go with it — and is refused while the
   * administrator requires a second factor.
   *
   * @param authenticatorId The app to remove.
   * @param proof A code from any connected app or a backup code.
   */
  remove(authenticatorId: number, proof: HilosSecondFactorProof): ActionHandle
  /**
   * List the backup codes, the spent ones marked.
   *
   * @param proof A code from a connected app or a backup code.
   */
  showCodes(
    proof: HilosSecondFactorProof,
  ): ActionHandle<z.infer<typeof codesShowReplySchema>>
  /**
   * Issue a new set of backup codes; the old set dies with it.
   *
   * @param proof A code from a connected app or a backup code — the NEXT code of
   *   an app, since the one that opened the list is spent.
   */
  renewCodes(
    proof: HilosSecondFactorProof,
  ): ActionHandle<z.infer<typeof codesRenewReplySchema>>
  /**
   * Set the removal wait. A longer wait holds at once; a shorter one waits out
   * the wait in force first; one outside the administrator's bounds is refused
   * with them.
   *
   * @param days The wait, in days.
   */
  setResetWait(days: number): ActionHandle
  /** Ask the delayed removal of the factor. */
  requestReset(): ActionHandle
  /** Cancel the removal that stands. */
  cancelReset(): ActionHandle
}

/**
 * The payload keys a proof travels under.
 *
 * @param proof The proof to send.
 */
function proofPayload(proof: HilosSecondFactorProof): {
  proofCode: string
  proofBackup: boolean
} {
  return { proofCode: proof.code, proofBackup: proof.backupCode }
}

/**
 * The profile actions of the section, bound to one action lifecycle.
 *
 * @param context The project context (the action lifecycle they dispatch over).
 */
export function createHilosSecondFactorActions(
  context: Pick<HilosSecondFactorContext, 'actions'>,
): HilosSecondFactorActions {
  const actions = context.actions

  return {
    enrollStart(proof) {
      return actions.dispatch(
        ACTION_ENROLL_START,
        proof === null
          ? { proofCode: null, proofBackup: false }
          : proofPayload(proof),
        { replySchema: enrollStartReplySchema },
      )
    },
    enrollConfirm(authenticatorId, code, label) {
      return actions.dispatch(
        ACTION_ENROLL_CONFIRM,
        { authenticatorId, code, label },
        { replySchema: enrollConfirmReplySchema },
      )
    },
    remove(authenticatorId, proof) {
      return actions.dispatch(ACTION_REMOVE, {
        authenticatorId,
        ...proofPayload(proof),
      })
    },
    showCodes(proof) {
      return actions.dispatch(ACTION_CODES_SHOW, proofPayload(proof), {
        replySchema: codesShowReplySchema,
      })
    },
    renewCodes(proof) {
      return actions.dispatch(ACTION_CODES_RENEW, proofPayload(proof), {
        replySchema: codesRenewReplySchema,
      })
    },
    setResetWait(days) {
      return actions.dispatch(ACTION_RESET_WAIT_SET, { days })
    },
    requestReset() {
      return actions.dispatch(ACTION_RESET_REQUEST, {})
    },
    cancelReset() {
      return actions.dispatch(ACTION_RESET_CANCEL, {})
    },
  }
}
