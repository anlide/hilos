// A person's own account deletion (HIL-302): the state the danger zone and its
// window draw, the four actions they dispatch, and the window's steps.
// Framework-agnostic, so the three HilosAccountDeletion views stay thin
// (multiframework-core.md).
//
// The state arrives whole, twice over: as the `accountDeletion` data of the
// profile page that draws the zone for the first render, and as
// `hilos_account_deletion_state` to the person's group after every start and
// cancel, from whichever tab made it. The view of the zone and of the window is
// DERIVED from it: a scheduled deletion turns the zone into a warning and the
// window into "Deletion in progress" in every open tab at once.
//
// Starting asks three things of the server in turn — the operation's own
// confirmation (delete_account, the step-up), the window's opening, the code —
// and the start itself spends the code. Calling it off asks for nothing:
// changing one's mind must be easier than deleting.
import { z } from 'zod'

import {
  ActionError,
  type ActionHandle,
  type ActionLifecycle,
} from '../connection/actionLifecycle.js'
import { type HilosConnection } from '../connection/HilosConnection.js'
import {
  createHilosStepUpActions,
  createHilosStepUpStep,
  type HilosStepUpStep,
} from '../auth/stepUp.js'
import { formatDurationInWords } from '../format/duration.js'
import { type ProjectSignal } from '../protocol/parseSignal.js'
import { toLocal } from '../session/serverClock.js'
import { type ScopeManager } from '../state/ScopeManager.js'
import {
  createSignal,
  subscribeSignal,
  type ReadonlySignal,
  type WritableSignal,
} from '../state/signal.js'

/** Client→server: open the window (PHP `HILOS_ACCOUNT_DELETION_OPEN`). */
export const HILOS_ACCOUNT_DELETION_OPEN_ACTION = 'hilos_account_deletion_open'

/** Client→server: send the code (PHP `HILOS_ACCOUNT_DELETION_CODE`). */
export const HILOS_ACCOUNT_DELETION_CODE_ACTION = 'hilos_account_deletion_code'

/** Client→server: start the deletion (PHP `HILOS_ACCOUNT_DELETION_START`). */
export const HILOS_ACCOUNT_DELETION_START_ACTION =
  'hilos_account_deletion_start'

/** Client→server: call it off (PHP `HILOS_ACCOUNT_DELETION_CANCEL`). */
export const HILOS_ACCOUNT_DELETION_CANCEL_ACTION =
  'hilos_account_deletion_cancel'

/**
 * Server→client (WS_GROUP): one person's deletion state, whole (PHP
 * `HilosSignalConstants::HILOS_ACCOUNT_DELETION_STATE`).
 */
export const SIGNAL_ACCOUNT_DELETION_STATE = 'hilos_account_deletion_state'

/**
 * The data key the profile page carries the state under (PHP
 * `AccountDeletionStateProjector::SECTION`).
 */
export const PROFILE_ACCOUNT_DELETION_SECTION = 'accountDeletion'

/** The protected operation the window confirms first (PHP `StepUpOperationKey::DELETE_ACCOUNT`). */
export const ACCOUNT_DELETION_OPERATION = 'delete_account'

/** How often the days left are counted again while the zone is on screen. */
export const ACCOUNT_DELETION_TICK_MS = 60_000

/** One day, in ms — the unit the days left are counted in. */
const DAY_MS = 86_400_000

/** One day, in seconds — the unit the day phrases are spelled in. */
const DAY_SECONDS = 86_400

/**
 * The state payload (PHP `AccountDeletionStateSignalData`). Moments are SERVER
 * epoch ms here and local in {@link HilosAccountDeletionState}.
 */
export const accountDeletionStateSchema = z.looseObject({
  deletion: z
    .looseObject({ requestedAt: z.number(), effectiveAt: z.number() })
    .nullable(),
})

/**
 * The state signal schema keyed for a connection's `projectSchemas`, so the
 * parse boundary validates the frame before the store reads it.
 * {@link createHilosConnection} merges it in.
 */
export const ACCOUNT_DELETION_SIGNAL_SCHEMAS = {
  [SIGNAL_ACCOUNT_DELETION_STATE]: accountDeletionStateSchema,
}

/** A scheduled deletion, in the reader's own clock. */
export interface HilosScheduledDeletion {
  /** The LOCAL moment the deletion was asked for, in epoch ms. */
  readonly requestedAt: number
  /** The LOCAL moment the account is erased, in epoch ms. */
  readonly effectiveAt: number
}

/** A person's deletion state: the deletion that stands, or `null`. */
export interface HilosAccountDeletionState {
  /** The scheduled deletion, or `null` when none stands. */
  readonly deletion: HilosScheduledDeletion | null
}

/**
 * Read a state frame or page slot into the state the zone draws, or `null` when
 * what arrived is not a state.
 *
 * @param raw The state as the wire carried it.
 */
export function readHilosAccountDeletionState(
  raw: unknown,
): HilosAccountDeletionState | null {
  const parsed = accountDeletionStateSchema.safeParse(raw)
  if (!parsed.success) {
    return null
  }
  const deletion = parsed.data.deletion

  return {
    deletion:
      deletion === null
        ? null
        : {
            requestedAt: toLocal(deletion.requestedAt),
            effectiveAt: toLocal(deletion.effectiveAt),
          },
  }
}

/**
 * Whole days left until the erasure, rounded up and never below one — "1 day
 * left" is said through the last day.
 *
 * @param effectiveAt The LOCAL moment of the erasure, in epoch ms.
 * @param now The LOCAL moment now, in epoch ms.
 */
export function accountDeletionDaysLeft(
  effectiveAt: number,
  now: number,
): number {
  return Math.max(1, Math.ceil((effectiveAt - now) / DAY_MS))
}

/**
 * A number of days in words: "1 day", "30 days".
 *
 * @param days Whole days.
 */
export function formatAccountDeletionDays(days: number): string {
  return formatDurationInWords(days * DAY_SECONDS)
}

/** The project context the zone reads from and dispatches over. */
export interface HilosAccountDeletionContext {
  /** The connection the person's state frames arrive on. */
  readonly connection: HilosConnection
  /** The scope manager whose page data carries the first copy. */
  readonly scopes: ScopeManager
  /** The action lifecycle the four actions dispatch over. */
  readonly actions: ActionLifecycle
}

/** The reactive state a profile page's danger zone renders. */
export interface HilosAccountDeletionStore {
  /** The state as it stands, or `null` before the first copy arrives. */
  readonly state: ReadonlySignal<HilosAccountDeletionState | null>
  /**
   * Replace the state from a copy of it; a copy that is not a state is ignored.
   *
   * @param raw The state as the wire carried it.
   */
  applyState(raw: unknown): void
  /** Start taking the state from the page slot and the group frames — call on mount. */
  start(): void
  /** Stop taking it and forget it — call on unmount. */
  dispose(): void
}

/**
 * Create the deletion state store of one profile page.
 *
 * @param context The project context (connection, scope stores).
 */
export function createHilosAccountDeletionStore(
  context: Pick<HilosAccountDeletionContext, 'connection' | 'scopes'>,
): HilosAccountDeletionStore {
  const state = createSignal<HilosAccountDeletionState | null>(null)
  let stop: (() => void) | null = null

  function applyState(raw: unknown): void {
    const next = readHilosAccountDeletionState(raw)
    if (next !== null) {
      state.set(next)
    }
  }

  return {
    state,
    applyState,
    start() {
      stop?.()
      const section = context.scopes.pageDataSignal(
        PROFILE_ACCOUNT_DELETION_SECTION,
      )
      applyState(section.get())
      const offSection = subscribeSignal(section, applyState)
      const offFrames = context.connection.on(
        'projectSignal',
        (signal: ProjectSignal) => {
          if (signal.type === SIGNAL_ACCOUNT_DELETION_STATE) {
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
      state.set(null)
    },
  }
}

/**
 * An empty reply arrives as an empty JSON array — a PHP array with nothing in
 * it — so the reply schema reads that as an object with nothing in it.
 *
 * @param shape The members of the reply.
 */
function replySchema<T extends z.ZodRawShape>(shape: T) {
  return z.preprocess(
    (value) => (Array.isArray(value) && value.length === 0 ? {} : value),
    z.object(shape),
  )
}

/** The window's opening: the grace period, and where the code goes. */
const openingReplySchema = replySchema({
  graceDays: z.number(),
  channel: z.enum(['email', 'phone']).nullable(),
  destination: z.string().nullable(),
})

/** The window's opening (PHP `AccountDeletionOpeningReplyDTO`). */
export type HilosAccountDeletionOpening = z.infer<typeof openingReplySchema>

/**
 * The four actions. Each is a tracked action: its `done` rejects with the
 * server's sentence on a refusal, which the window shows above its buttons.
 */
export interface HilosAccountDeletionActions {
  /** Open the window: the grace period and where the code goes. */
  open(): ActionHandle<HilosAccountDeletionOpening>
  /** Send the code to the account's address. */
  sendCode(): ActionHandle
  /**
   * Start the deletion.
   *
   * @param code The code the address received; empty when no code was sent.
   */
  start(code: string): ActionHandle
  /** Call the scheduled deletion off. */
  cancel(): ActionHandle
}

/**
 * The four actions, bound to one action lifecycle.
 *
 * @param context The project context (the action lifecycle they dispatch over).
 */
export function createHilosAccountDeletionActions(
  context: Pick<HilosAccountDeletionContext, 'actions'>,
): HilosAccountDeletionActions {
  const actions = context.actions

  return {
    open() {
      return actions.dispatch(
        HILOS_ACCOUNT_DELETION_OPEN_ACTION,
        {},
        { replySchema: openingReplySchema },
      )
    },
    sendCode() {
      return actions.dispatch(HILOS_ACCOUNT_DELETION_CODE_ACTION, {})
    },
    start(code) {
      return actions.dispatch(HILOS_ACCOUNT_DELETION_START_ACTION, { code })
    },
    cancel() {
      return actions.dispatch(HILOS_ACCOUNT_DELETION_CANCEL_ACTION, {})
    },
  }
}

/**
 * Where the window stands. `closed` — no window; `opening` — the zone's button
 * waits for the server, the window not drawn yet; `step-up` — the operation's
 * confirmation; `explain` — step 1, what will happen; `code` — step 2, the
 * code; `in-progress` — the scheduled deletion and "Keep my account";
 * `refused` — the window could not open, its sentence and Cancel alone.
 */
export type HilosAccountDeletionStep =
  | 'closed'
  | 'opening'
  | 'step-up'
  | 'explain'
  | 'code'
  | 'in-progress'
  | 'refused'

/** The window's steps and what they submit. */
export interface HilosAccountDeletionFlow {
  /** Where the window stands. */
  readonly step: ReadonlySignal<HilosAccountDeletionStep>
  /** The operation's confirmation, drawn on the `step-up` step. */
  readonly stepUp: HilosStepUpStep
  /** The window's opening, once the server answered it. */
  readonly opening: ReadonlySignal<HilosAccountDeletionOpening | null>
  /** The code as typed on step 2. */
  readonly code: WritableSignal<string>
  /** Whether a submit is on its way — its button loads, a second press does not go. */
  readonly busy: ReadonlySignal<boolean>
  /** The server's sentence above the buttons, or `null`. */
  readonly refusal: ReadonlySignal<string | null>
  /** Open the window from the zone: "in progress" when a deletion stands, the steps otherwise. */
  open(): Promise<void>
  /** Submit the operation's confirmation, then open the steps. */
  confirmStepUp(): Promise<void>
  /** Leave step 1: send the code, or start at once when no code can reach the account. */
  next(): Promise<void>
  /** Start the deletion with the typed code. */
  start(): Promise<void>
  /** Call the scheduled deletion off. */
  cancel(): Promise<void>
  /** Close the window, whatever step it is on; nothing started is started. */
  close(): void
  /** Start following the state — call on mount. */
  follow(): void
  /** Stop following the state — call on unmount. */
  dispose(): void
}

/**
 * The sentence of a refused action, or the generic one.
 *
 * @param error What the action rejected with.
 */
function actionMessage(error: unknown): string {
  return error instanceof ActionError ? error.message : 'The action failed.'
}

/**
 * Create the window of one danger zone.
 *
 * The window follows the state as well as its own submits: a deletion
 * scheduled by another tab turns an open window into "Deletion in progress",
 * and one called off by another tab closes a window that showed it. A reply
 * that arrives after the window was closed lands nowhere: it does not open the
 * window again.
 *
 * @param context The project context (the action lifecycle the submits dispatch over).
 * @param store The state store of the same page.
 */
export function createHilosAccountDeletionFlow(
  context: Pick<HilosAccountDeletionContext, 'actions'>,
  store: Pick<HilosAccountDeletionStore, 'state'>,
): HilosAccountDeletionFlow {
  const actions = createHilosAccountDeletionActions(context)
  const stepUp = createHilosStepUpStep(
    createHilosStepUpActions(context.actions),
  )
  const step = createSignal<HilosAccountDeletionStep>('closed')
  const opening = createSignal<HilosAccountDeletionOpening | null>(null)
  const code = createSignal('')
  const busy = createSignal(false)
  const refusal = createSignal<string | null>(null)
  // Counts the closes: a submit remembers the round it was sent in, and its
  // reply moves the window only while that round is still the current one.
  let round = 0
  let stop: (() => void) | null = null

  function scheduled(): boolean {
    return store.state.get()?.deletion != null
  }

  function onState(): void {
    const current = step.get()
    if (scheduled()) {
      if (current !== 'closed' && current !== 'in-progress') {
        busy.set(false)
        refusal.set(null)
        step.set('in-progress')
      }
    } else if (current === 'in-progress') {
      close()
    }
  }

  /**
   * Run one submit: busy while it is out, its refusal above the buttons.
   *
   * @param submit The submit.
   * @returns Whether it went through.
   */
  async function run(submit: () => Promise<void>): Promise<boolean> {
    busy.set(true)
    refusal.set(null)
    try {
      await submit()
      return true
    } catch (error) {
      refusal.set(actionMessage(error))
      return false
    } finally {
      busy.set(false)
    }
  }

  /**
   * Ask the window's opening and land on step 1, or on the refusal.
   *
   * @param started The round the window was opened in.
   */
  async function openSteps(started: number): Promise<void> {
    const opened = await run(async () => {
      const result = await actions.open().done
      opening.set(result.reply as HilosAccountDeletionOpening)
    })
    if (round !== started || scheduled()) {
      return
    }
    step.set(opened ? 'explain' : 'refused')
  }

  function close(): void {
    round += 1
    step.set('closed')
    opening.set(null)
    code.set('')
    refusal.set(null)
  }

  return {
    step,
    stepUp,
    opening,
    code,
    busy,
    refusal,
    async open() {
      if (busy.get()) {
        return
      }
      if (scheduled()) {
        refusal.set(null)
        step.set('in-progress')
        return
      }
      opening.set(null)
      code.set('')
      refusal.set(null)
      step.set('opening')
      busy.set(true)
      const started = round
      const verdict = await stepUp.open(ACCOUNT_DELETION_OPERATION)
      busy.set(false)
      if (verdict === 'skip') {
        await openSteps(started)
      } else if (round === started && step.get() === 'opening') {
        step.set('step-up')
      }
    },
    async confirmStepUp() {
      if (busy.get()) {
        return
      }
      busy.set(true)
      const started = round
      const confirmed = await stepUp.confirm()
      busy.set(false)
      if (confirmed && round === started) {
        await openSteps(started)
      }
    },
    async next() {
      if (busy.get() || step.get() !== 'explain') {
        return
      }
      if (opening.get()?.channel == null) {
        await run(async () => {
          await actions.start('').done
        })
        return
      }
      const started = round
      if (
        (await run(async () => {
          await actions.sendCode().done
        })) &&
        round === started
      ) {
        step.set('code')
      }
    },
    async start() {
      if (busy.get() || step.get() !== 'code') {
        return
      }
      await run(async () => {
        await actions.start(code.get()).done
      })
    },
    async cancel() {
      if (busy.get()) {
        return
      }
      if (
        await run(async () => {
          await actions.cancel().done
        })
      ) {
        close()
      }
    },
    close,
    follow() {
      stop?.()
      stop = subscribeSignal(store.state, onState)
      onState()
    },
    dispose() {
      stop?.()
      stop = null
    },
  }
}

/**
 * The copy of the zone and its window (HIL-302). `{graceDays}`, `{days}` are
 * day phrases ({@link formatAccountDeletionDays}); `{date}`, `{requestedDate}`
 * calendar dates; `{destination}` the address the code went to.
 */
export const HILOS_ACCOUNT_DELETION_COPY = {
  zoneTitle: 'Delete account',
  zoneText:
    'Starts a deletion with a grace period. You can stop it until the period is over; after that it cannot be undone.',
  zoneButton: 'Delete my account…',
  scheduledTitle: 'Account deletion scheduled',
  scheduledText: 'Your account will be deleted on {date} — {days} left.',
  scheduledButton: 'Keep my account…',
  title: 'Delete your account',
  steps: ['What will happen', 'Confirm with a code'],
  explainErase: 'After {graceDays} your account and data are erased for good',
  explainProviders: 'Sign-in through providers is revoked',
  explainSubscriptions: 'Active subscriptions have to be canceled separately',
  explainChangeMind: 'You can change your mind until the period is over.',
  continue: 'Continue',
  codeSent:
    'We sent a code to {destination}. The deletion starts only after you enter it.',
  codeLabel: 'Code',
  start: 'Start deletion',
  cancel: 'Cancel',
  progressTitle: 'Deletion in progress',
  progressLead: 'Your account will be deleted in {days}',
  progressText:
    'On {date}. Requested on {requestedDate}. Until then everything works as usual.',
  close: 'Close',
  keep: 'Keep my account',
} as const
