// The window that turns the second factor off for everyone (HIL-1303), opened
// by the first switch of the two-step verification tab while it is on — the
// switch itself does not move. It stands on the "Confirm it's you" step of its
// operation first, in the order of the account card's windows (HIL-1275), then
// lists what turning it off does and sends it on "Turn off". The server checks
// the administrator's confirmation before it writes, so a confirmation that ran
// out meanwhile is refused and the window stays open with the refusal; the
// answer comes once the whole of it is applied, and the window closes on it.
// The table is the window's only source of truth about the factor: the moment
// its first row says off — this window's write, another tab's, another
// administrator's — the window closes, because it must not turn off what is off
// already. Turning it back on is the switch alone, with no window.
//
// Framework-agnostic, so the three HilosSecurity2faPage views only draw it
// (multiframework-core.md); the words are here once for all three.
import {
  createHilosStepUpActions,
  createHilosStepUpStep,
  type HilosStepUpStep,
} from '../../auth/stepUp.js'
import { ActionError } from '../../connection/actionLifecycle.js'
import { hilosAdminAccess } from '../../session/adminAccess.js'
import { type Hideable } from '../../state/hiddenValue.js'
import {
  computedSignal,
  createSignal,
  subscribeSignal,
  type ReadonlySignal,
} from '../../state/signal.js'
import { type TableViewportController } from '../../table/TableViewportController.js'
import {
  createHilosSecurityTwoFactorActions,
  HilosSecondFactorSettingKey,
  type HilosTwoFactorContext,
  type HilosTwoFactorSettingRow,
} from './hilosSecurityTwoFactor.js'

/** The step-up operation that guards turning the factor off (PHP `StepUpOperationKey::DISABLE_SECOND_FACTOR`). */
export const DISABLE_SECOND_FACTOR_OPERATION = 'disable_second_factor'

/** The words of the window, one set for the three frontends. */
export const HILOS_TWO_FACTOR_DISABLE_COPY = {
  title: 'Turn two-factor off',
  intro: 'Once two-factor is off:',
  noCode: 'No sign-in asks for a code.',
  kept: "People's apps and backup codes are kept and work again once it is back on.",
  trustsForgotten:
    'Trusted browsers are forgotten: once it is back on, they ask for the code again.',
  trustsKept:
    'Trusted browsers are kept: once it is back on, they skip the code until their trust runs out.',
  removals: 'Removals people have asked for still happen on their date.',
  stepUp:
    'Confirming an operation asks for a password or a code by email or text instead of the app.',
  waiting: 'Browsers waiting for a code go back to the start of signing in.',
  confirm: 'Turn off',
  cancel: 'Cancel',
} as const

/** The text a switch row carries when it is off. */
const SWITCH_OFF = 'false'

/**
 * Where the window stands. `closed` — no window; `opening` — the switch waits
 * for the server, the window not drawn yet; `step-up` — "Confirm it's you";
 * `consequences` — what turning it off does, and "Turn off"; `refused` — the
 * step could not open, its sentence and Cancel alone.
 */
export type HilosTwoFactorDisableStep =
  'closed' | 'opening' | 'step-up' | 'consequences' | 'refused'

/** The window that turns the second factor off, and what it submits. */
export interface HilosTwoFactorDisable {
  /** Where the window stands. */
  readonly step: ReadonlySignal<HilosTwoFactorDisableStep>
  /** The operation's confirmation, drawn on the `step-up` step. */
  readonly stepUp: HilosStepUpStep
  /**
   * What turning it off does, one line each, in the order the window lists
   * them; the line about trusted browsers follows the second row as it stands.
   */
  readonly consequences: ReadonlySignal<readonly string[]>
  /** Whether a submit is on its way — its button loads, a second press does not go. */
  readonly busy: ReadonlySignal<boolean>
  /** The server's sentence above the buttons, or `null`. */
  readonly refusal: ReadonlySignal<string | null>
  /** Follow the first row, to close the window once it says off — call on mount. */
  start(): void
  /**
   * Open the window from the first switch while it is on. A viewer of the admin
   * view mode acts on nothing, so the window does not open for one.
   */
  open(): Promise<void>
  /** Submit the operation's confirmation, then show what turning it off does. */
  confirmStepUp(): Promise<void>
  /** Turn the factor off; the window closes once it is. */
  confirm(): Promise<void>
  /** Close the window and forget the round — Cancel and the cross. */
  close(): void
  /** Stop following the row and close the window — call on unmount. */
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
 * Create the window that turns the second factor off, over the tab's table.
 *
 * @param context The project context (the action lifecycle the step and the write dispatch over).
 * @param controller The settings table whose first two rows the window reads.
 */
export function createHilosTwoFactorDisable(
  context: Pick<HilosTwoFactorContext, 'actions'>,
  controller: TableViewportController<HilosTwoFactorSettingRow>,
): HilosTwoFactorDisable {
  const actions = createHilosSecurityTwoFactorActions(context)
  // Passing the step without this window's own Confirm means another tab of
  // the session confirmed it (HIL-1330): the confirmation is this browser's,
  // so this window goes on to what turning it off does.
  const stepUp = createHilosStepUpStep(
    createHilosStepUpActions(context.actions),
    () => {
      if (step.get() === 'step-up') {
        step.set('consequences')
      }
    },
  )
  const step = createSignal<HilosTwoFactorDisableStep>('closed')
  const busy = createSignal(false)
  const refusal = createSignal<string | null>(null)
  // Counts the closes: a submit remembers the round it was sent in, and its
  // answer moves the window only while that round is still the current one.
  let round = 0
  let stop: (() => void) | null = null

  /**
   * The value a setting's row carries now, or undefined while it is not shown.
   *
   * @param settingKey The setting.
   */
  function valueOf(settingKey: string): Hideable<string> | undefined {
    return controller.rows.get().find((entry) => entry.rowKey === settingKey)
      ?.row?.value
  }

  const turnedOff = computedSignal(
    () => valueOf(HilosSecondFactorSettingKey.enabled) === SWITCH_OFF,
  )

  function close(): void {
    round += 1
    step.set('closed')
    busy.set(false)
    refusal.set(null)
    stepUp.password.set('')
    stepUp.code.set('')
  }

  return {
    step,
    stepUp,
    consequences: computedSignal(() => {
      const copy = HILOS_TWO_FACTOR_DISABLE_COPY

      return [
        copy.noCode,
        copy.kept,
        valueOf(HilosSecondFactorSettingKey.forgetTrustsOnDisable) ===
        SWITCH_OFF
          ? copy.trustsKept
          : copy.trustsForgotten,
        copy.removals,
        copy.stepUp,
        copy.waiting,
      ]
    }),
    busy,
    refusal,
    start() {
      stop?.()
      stop = subscribeSignal(turnedOff, (off) => {
        if (off && step.get() !== 'closed') {
          close()
        }
      })
    },
    async open() {
      if (
        hilosAdminAccess.get() === 'view' ||
        step.get() !== 'closed' ||
        turnedOff.get()
      ) {
        return
      }
      refusal.set(null)
      step.set('opening')
      busy.set(true)
      const started = round
      const verdict = await stepUp.open(DISABLE_SECOND_FACTOR_OPERATION)
      if (round !== started) {
        return
      }
      busy.set(false)
      if (verdict === 'skip') {
        step.set('consequences')
      } else if (verdict === 'ask') {
        step.set('step-up')
      } else {
        refusal.set(stepUp.refusal.get())
        step.set('refused')
      }
    },
    async confirmStepUp() {
      if (busy.get() || step.get() !== 'step-up') {
        return
      }
      busy.set(true)
      const started = round
      const confirmed = await stepUp.confirm()
      if (round !== started) {
        return
      }
      busy.set(false)
      if (confirmed && step.get() === 'step-up') {
        step.set('consequences')
      }
    },
    async confirm() {
      if (busy.get() || step.get() !== 'consequences') {
        return
      }
      busy.set(true)
      refusal.set(null)
      const started = round
      try {
        await actions.switchSet(HilosSecondFactorSettingKey.enabled, false).done
        if (round === started) {
          close()
        }
      } catch (error) {
        if (round === started) {
          busy.set(false)
          refusal.set(actionMessage(error))
        }
      }
    },
    close,
    dispose() {
      stop?.()
      stop = null
      close()
    },
  }
}
