// Covers a person's own account deletion (HIL-302): the state read off the wire
// with its moments on the local scale, the store that takes it from the page and
// from the person's group, the four actions under the names and keys the server
// reads, the window's steps — the confirmation asked or skipped, step 1 without
// a code, a deletion scheduled or called off in another tab — and the days left.
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  CODE_SEND_STATE_SENT,
  CODE_SEND_STATE_QUEUED,
  bindCodeSendProgress,
  SIGNAL_CODE_SEND_PROGRESS,
} from '../../src/auth/authSendProgress.js'
import {
  ACCOUNT_DELETION_OPERATION,
  accountDeletionDaysLeft,
  accountDeletionGraceDays,
  createHilosAccountDeletionActions,
  createHilosAccountDeletionFlow,
  createHilosAccountDeletionStore,
  formatAccountDeletionDays,
  readHilosAccountDeletionState,
  SIGNAL_ACCOUNT_DELETION_STATE,
} from '../../src/profile/accountDeletion.js'
import {
  ActionError,
  type ActionLifecycle,
} from '../../src/connection/actionLifecycle.js'
import { type HilosConnection } from '../../src/connection/HilosConnection.js'
import { type ProjectSignal } from '../../src/protocol/parseSignal.js'
import { applyServerTime } from '../../src/session/serverClock.js'
import { ScopeManager } from '../../src/state/ScopeManager.js'
import {
  bindProfileFlows,
  profileFlowsSchema,
  SIGNAL_PROFILE_FLOWS,
} from '../../src/profile/profileFlows.js'

/** A browser clock parked at a known moment. */
const LOCAL_NOW = 1_700_000_000_000

/** How far ahead of this browser the server's clock runs, in ms. */
const SERVER_DRIFT_MS = 45_000

/** One day, in ms. */
const DAY = 86_400_000

/** Bind the session's held flow list to a fake connection. */
function bound(): (flows: unknown[]) => void {
  const listeners: ((signal: { type: string; data: unknown }) => void)[] = []
  bindProfileFlows({
    on: (_event: string, listener: (signal: never) => void) => {
      listeners.push(
        listener as (signal: { type: string; data: unknown }) => void,
      )
      return () => undefined
    },
  } as unknown as HilosConnection)
  return (flows) => {
    for (const listener of listeners) {
      listener({
        type: SIGNAL_PROFILE_FLOWS,
        data: profileFlowsSchema.parse({ flows }),
      })
    }
  }
}

const tell = bound()

/** The deletion step the session holder publishes after a live code order. */
function record(address = OPENING.destination) {
  return {
    operation: 'delete_account',
    step: 'code_sent',
    address,
    target: null,
  }
}

/** A connection double that replays project signals to its listeners. */
function fakeConnection() {
  const listeners: Array<(signal: ProjectSignal) => void> = []

  return {
    on(event: string, listener: (payload: never) => void): () => void {
      if (event !== 'projectSignal') {
        return () => {}
      }
      const typed = listener as (signal: ProjectSignal) => void
      listeners.push(typed)

      return () => {
        listeners.splice(listeners.indexOf(typed), 1)
      }
    },
    emit(type: string, data: unknown): void {
      const signal = { kind: 'project', type, data } as unknown as ProjectSignal
      for (const listener of [...listeners]) {
        listener(signal)
      }
    },
  }
}

/** A scheduled deletion as the wire carries it, on the server's scale. */
function wireScheduled() {
  return {
    deletion: {
      requestedAt: LOCAL_NOW + SERVER_DRIFT_MS,
      effectiveAt: LOCAL_NOW + SERVER_DRIFT_MS + 30 * DAY,
    },
  }
}

/**
 * A lifecycle recording every dispatch and answering each by name — a reply,
 * or a refusal with the server's sentence.
 *
 * @param answers What each action name answers; a string is a refusal.
 */
function scriptedLifecycle(answers: Record<string, unknown>) {
  const sent: Array<{ name: string; payload: unknown }> = []
  const actions = {
    dispatch: (
      name: string,
      payload: unknown,
      options: { replySchema?: { parse(value: unknown): unknown } } = {},
    ) => {
      sent.push({ name, payload })
      const answer =
        answers[name] ??
        (name === 'hilos_account_deletion_code'
          ? {
              sent: true,
              resendAt: 1_900_000_000_000,
              expiresAt: 1_900_000_600_000,
            }
          : [])
      if (typeof answer === 'string') {
        return { done: Promise.reject(new ActionError(name, 'fail', answer)) }
      }
      if (answer instanceof Promise) {
        return { done: answer.then((value) => ({ reply: value })) }
      }

      if (
        name === 'hilos_account_deletion_code' &&
        (answer as { expiresAt?: number | null }).expiresAt != null
      ) {
        tell([record()])
      }
      if (
        name === 'hilos_account_deletion_start' ||
        name === 'hilos_profile_flow_cancel'
      ) {
        tell([])
      }

      return {
        done: Promise.resolve({
          reply:
            options.replySchema === undefined
              ? answer
              : options.replySchema.parse(answer),
        }),
      }
    },
  } as unknown as ActionLifecycle

  return { actions, sent }
}

/** A window over a scripted lifecycle and a store fed by hand. */
function flowSetup(answers: Record<string, unknown>) {
  const connection = fakeConnection()
  const scopes = new ScopeManager()
  const { actions, sent } = scriptedLifecycle(answers)
  const store = createHilosAccountDeletionStore({
    connection: connection as unknown as HilosConnection,
    scopes,
  })
  store.start()
  store.applyState({ deletion: null })
  const flow = createHilosAccountDeletionFlow({ actions }, store)
  flow.follow()

  return { connection, flow, sent, store }
}

const OPENING = {
  graceDays: 30,
  channel: 'email',
  destination: 'me@example.test',
}
const SKIP = { required: false, purpose: 'delete your account' }
const ASK = {
  required: true,
  purpose: 'delete your account',
  method: 'password',
}

afterEach(() => {
  tell([])
  applyServerTime(Date.now())
  vi.useRealTimers()
})

/** Feed the session's send line through its normal signal binding. */
function boundLine(): (line: Record<string, unknown>) => void {
  const listeners: ((signal: { type: string; data: unknown }) => void)[] = []
  bindCodeSendProgress({
    on: (_event: string, listener: (signal: never) => void) => {
      listeners.push(
        listener as (signal: { type: string; data: unknown }) => void,
      )
      return () => undefined
    },
  } as unknown as HilosConnection)
  return (data) => {
    for (const listener of listeners)
      listener({ type: SIGNAL_CODE_SEND_PROGRESS, data })
  }
}

const showLine = boundLine()

/** Complete or interrupt one action independently of the session frames. */
function pendingSend() {
  let answer!: (value: { reply: unknown }) => void
  let reject!: (error: ActionError) => void
  const done = new Promise<{ reply: unknown }>((resolve, fail) => {
    answer = resolve
    reject = fail
  })
  return { done, answer, reject }
}

describe('readHilosAccountDeletionState', () => {
  it('preserves both absolute server moments', () => {
    vi.useFakeTimers()
    vi.setSystemTime(LOCAL_NOW)
    applyServerTime(LOCAL_NOW + SERVER_DRIFT_MS)

    expect(readHilosAccountDeletionState(wireScheduled())).toStrictEqual({
      deletion: {
        requestedAt: LOCAL_NOW + SERVER_DRIFT_MS,
        effectiveAt: LOCAL_NOW + SERVER_DRIFT_MS + 30 * DAY,
      },
    })
  })

  it('reads no deletion, and refuses what is not a state', () => {
    expect(readHilosAccountDeletionState({ deletion: null })).toStrictEqual({
      deletion: null,
    })
    expect(readHilosAccountDeletionState(null)).toBeNull()
    expect(
      readHilosAccountDeletionState({ deletion: { requestedAt: 'now' } }),
    ).toBeNull()
  })
})

describe('the state store', () => {
  it('takes the state from the page data and then from the group frames', () => {
    const connection = fakeConnection()
    const scopes = new ScopeManager()
    const store = createHilosAccountDeletionStore({
      connection: connection as unknown as HilosConnection,
      scopes,
    })
    scopes
      .openPage('hilos_profile_security')
      .data.set('accountDeletion', { deletion: null })
    store.start()
    expect(store.state.get()).toStrictEqual({ deletion: null })

    applyServerTime(Date.now() + SERVER_DRIFT_MS)
    scopes.page()!.data.set('accountDeletion', wireScheduled())
    expect(store.state.get()?.deletion).toEqual({
      requestedAt: 1_700_000_045_000,
      effectiveAt: 1_702_592_045_000,
    })
    connection.emit(SIGNAL_ACCOUNT_DELETION_STATE, {
      deletion: { requestedAt: 1000, effectiveAt: 2000 },
    })
    expect(store.state.get()?.deletion).toEqual({
      requestedAt: 1000,
      effectiveAt: 2000,
    })

    store.dispose()
    expect(store.state.get()).toBeNull()
  })
})

describe('the four actions', () => {
  it('sends each under the name and keys the server reads', async () => {
    const { actions, sent } = scriptedLifecycle({
      hilos_account_deletion_open: OPENING,
    })
    const deletion = createHilosAccountDeletionActions({ actions })

    const opened = await deletion.open().done
    await deletion.sendCode().done
    await deletion.start('302302').done
    await deletion.cancel().done

    expect(opened.reply).toStrictEqual(OPENING)
    expect(sent).toStrictEqual([
      { name: 'hilos_account_deletion_open', payload: {} },
      { name: 'hilos_account_deletion_code', payload: {} },
      { name: 'hilos_account_deletion_start', payload: { code: '302302' } },
      { name: 'hilos_account_deletion_cancel', payload: {} },
    ])
  })
})

describe('the window', () => {
  it('opens on the session code step when its address matches', async () => {
    tell([record()])
    const { flow } = flowSetup({
      hilos_step_up_start: SKIP,
      hilos_account_deletion_open: OPENING,
    })
    await flow.open()
    expect(flow.step.get()).toBe('code')
    expect(flow.code.get()).toBe('')
  })

  it('starts at the explanation when the record belongs to an old address', async () => {
    tell([record('old@example.test')])
    const { flow } = flowSetup({
      hilos_step_up_start: SKIP,
      hilos_account_deletion_open: OPENING,
    })
    await flow.open()
    expect(flow.step.get()).toBe('explain')
  })

  it('ignores a record when there is no address for a code', async () => {
    tell([record()])
    const { flow } = flowSetup({
      hilos_step_up_start: SKIP,
      hilos_account_deletion_open: {
        ...OPENING,
        channel: null,
        destination: null,
      },
    })
    await flow.open()
    expect(flow.step.get()).toBe('explain')
    tell([])
    expect(flow.step.get()).toBe('explain')
  })

  it('follows the code step live and keeps code already typed on it', async () => {
    const { flow } = flowSetup({
      hilos_step_up_start: SKIP,
      hilos_account_deletion_open: OPENING,
    })
    await flow.open()
    flow.code.set('stale')
    tell([record()])
    expect(flow.step.get()).toBe('code')
    expect(flow.code.get()).toBe('')
    flow.code.set('123456')
    tell([record()])
    expect(flow.code.get()).toBe('123456')
  })

  it('closes the code step when its session record disappears', async () => {
    tell([record()])
    const { flow } = flowSetup({
      hilos_step_up_start: SKIP,
      hilos_account_deletion_open: OPENING,
    })
    await flow.open()
    tell([])
    expect(flow.step.get()).toBe('closed')
  })

  it('waits for its own pending action when its record disappears', async () => {
    tell([record()])
    let release!: (value: unknown) => void
    const waiting = new Promise<unknown>((resolve) => {
      release = resolve
    })
    const { flow } = flowSetup({
      hilos_step_up_start: SKIP,
      hilos_account_deletion_open: OPENING,
      hilos_account_deletion_code: waiting,
    })
    await flow.open()
    const sending = flow.sendAgain()
    tell([])
    expect(flow.step.get()).toBe('code')
    release({ sent: false, resendAt: LOCAL_NOW, expiresAt: null })
    await sending
    expect(flow.step.get()).toBe('code')
  })

  it('cancels the session flow from the code step and closes on the answer', async () => {
    tell([record()])
    const { flow, sent } = flowSetup({
      hilos_step_up_start: SKIP,
      hilos_account_deletion_open: OPENING,
    })
    await flow.open()
    flow.close()
    await vi.waitFor(() => expect(flow.step.get()).toBe('closed'))
    expect(sent.at(-1)).toStrictEqual({
      name: 'hilos_profile_flow_cancel',
      payload: { operation: 'delete_account' },
    })
  })

  it('keeps the code window open when canceling its flow is refused', async () => {
    tell([record()])
    const { flow } = flowSetup({
      hilos_step_up_start: SKIP,
      hilos_account_deletion_open: OPENING,
      hilos_profile_flow_cancel: 'Cannot cancel',
    })
    await flow.open()
    flow.close()
    await vi.waitFor(() => expect(flow.refusal.get()).toBe('Cannot cancel'))
    expect(flow.step.get()).toBe('code')
  })

  it('closes step 1 and disposes without canceling the session flow', async () => {
    const { flow, sent } = flowSetup({
      hilos_step_up_start: SKIP,
      hilos_account_deletion_open: OPENING,
    })
    await flow.open()
    flow.close()
    expect(sent).toHaveLength(2)
    await flow.open()
    tell([record()])
    flow.dispose()
    tell([])
    expect(flow.step.get()).toBe('code')
    expect(
      sent.every((entry) => entry.name !== 'hilos_profile_flow_cancel'),
    ).toBe(true)
  })

  it('stays on step 1 when the cooldown has no live code', async () => {
    const { flow, sent } = flowSetup({
      hilos_step_up_start: SKIP,
      hilos_account_deletion_open: OPENING,
      hilos_account_deletion_code: {
        sent: false,
        resendAt: LOCAL_NOW + 60_000,
        expiresAt: null,
      },
    })
    await flow.open()
    await flow.next()
    expect(flow.step.get()).toBe('explain')
    await flow.next()
    expect(flow.step.get()).toBe('explain')
    expect(
      sent.filter((entry) => entry.name === 'hilos_account_deletion_code'),
    ).toHaveLength(2)
  })

  it('skips the confirmation it is not asked for and lands on step 1', async () => {
    const { flow, sent } = flowSetup({
      hilos_step_up_start: SKIP,
      hilos_account_deletion_open: OPENING,
    })

    await flow.open()

    expect(flow.step.get()).toBe('explain')
    expect(flow.opening.get()).toStrictEqual(OPENING)
    expect(sent.map((entry) => entry.name)).toStrictEqual([
      'hilos_step_up_start',
      'hilos_account_deletion_open',
    ])
  })

  it('asks the confirmation first, then opens the steps', async () => {
    const { flow } = flowSetup({
      hilos_step_up_start: ASK,
      hilos_account_deletion_open: OPENING,
    })

    await flow.open()
    expect(flow.step.get()).toBe('step-up')

    flow.stepUp.password.set('secret')
    await flow.confirmStepUp()
    expect(flow.step.get()).toBe('explain')
  })

  it('stays on the confirmation with its refusal when it cannot be given', async () => {
    const { flow } = flowSetup({
      hilos_step_up_start:
        "This is not available while you work in someone else's account",
    })

    await flow.open()

    expect(flow.step.get()).toBe('step-up')
    expect(flow.stepUp.refusal.get()).toBe(
      "This is not available while you work in someone else's account",
    )
  })

  it('sends the code from step 1 and starts with it from step 2', async () => {
    const { flow, sent } = flowSetup({
      hilos_step_up_start: SKIP,
      hilos_account_deletion_open: OPENING,
    })
    await flow.open()

    await flow.next()
    expect(flow.step.get()).toBe('code')
    flow.code.set('302302')
    await flow.start()

    expect(flow.step.get()).toBe('in-progress')
    expect(sent.at(-1)).toStrictEqual({
      name: 'hilos_account_deletion_start',
      payload: { code: '302302' },
    })
  })

  it('starts from step 1 when no code can reach the account', async () => {
    const { flow, sent } = flowSetup({
      hilos_step_up_start: SKIP,
      hilos_account_deletion_open: {
        ...OPENING,
        channel: null,
        destination: null,
      },
    })
    await flow.open()

    await flow.next()

    expect(flow.step.get()).toBe('in-progress')
    expect(sent.at(-1)).toStrictEqual({
      name: 'hilos_account_deletion_start',
      payload: { code: '' },
    })
  })

  it('keeps the step and says the refusal above the buttons', async () => {
    const { flow } = flowSetup({
      hilos_step_up_start: SKIP,
      hilos_account_deletion_open: OPENING,
      hilos_account_deletion_start: 'Invalid or expired code',
    })
    await flow.open()
    await flow.next()
    flow.code.set('000000')

    await flow.start()

    expect(flow.step.get()).toBe('code')
    expect(flow.refusal.get()).toBe('Invalid or expired code')
    expect(flow.code.get()).toBe('000000')
  })

  it('closes a window on step 1 when another tab schedules deletion', async () => {
    const { flow, store } = flowSetup({
      hilos_step_up_start: SKIP,
      hilos_account_deletion_open: OPENING,
    })
    await flow.open()

    store.applyState(wireScheduled())

    expect(flow.step.get()).toBe('closed')
  })

  it('closes a window on the code step when another browser schedules deletion', async () => {
    tell([record()])
    const { flow, store } = flowSetup({
      hilos_step_up_start: SKIP,
      hilos_account_deletion_open: OPENING,
    })
    await flow.open()
    store.applyState(wireScheduled())
    expect(flow.step.get()).toBe('closed')
  })

  it("shows in progress when state arrives during this window's start", async () => {
    tell([record()])
    let release!: (value: unknown) => void
    const waiting = new Promise<unknown>((resolve) => {
      release = resolve
    })
    const { flow, store } = flowSetup({
      hilos_step_up_start: SKIP,
      hilos_account_deletion_open: OPENING,
      hilos_account_deletion_start: waiting,
    })
    await flow.open()
    const starting = flow.start()
    store.applyState(wireScheduled())
    expect(flow.step.get()).toBe('in-progress')
    release([])
    await starting
    expect(flow.step.get()).toBe('in-progress')
  })

  it('shows in progress on its start answer without waiting for state', async () => {
    tell([record()])
    let release!: (value: unknown) => void
    const waiting = new Promise<unknown>((resolve) => {
      release = resolve
    })
    const { flow } = flowSetup({
      hilos_step_up_start: SKIP,
      hilos_account_deletion_open: OPENING,
      hilos_account_deletion_start: waiting,
    })
    await flow.open()
    const starting = flow.start()
    tell([])
    expect(flow.step.get()).toBe('code')
    release([])
    await starting
    expect(flow.step.get()).toBe('in-progress')
  })

  it('closes "in progress" when the deletion is called off in another tab', async () => {
    const { flow, store } = flowSetup({})
    store.applyState(wireScheduled())
    await flow.open()
    expect(flow.step.get()).toBe('in-progress')

    store.applyState({ deletion: null })

    expect(flow.step.get()).toBe('closed')
  })

  it('calls it off with one press and closes', async () => {
    const { flow, sent, store } = flowSetup({})
    store.applyState(wireScheduled())
    await flow.open()

    await flow.cancel()

    expect(sent.map((entry) => entry.name)).toStrictEqual([
      'hilos_account_deletion_cancel',
    ])
    expect(flow.step.get()).toBe('closed')
  })

  it('stays closed when a reply arrives after the window was closed', async () => {
    const { flow } = flowSetup({
      hilos_step_up_start: ASK,
      hilos_account_deletion_open: OPENING,
    })
    await flow.open()
    flow.stepUp.password.set('secret')

    const confirming = flow.confirmStepUp()
    flow.close()
    await confirming

    expect(flow.step.get()).toBe('closed')
  })

  it('stops following the state once disposed', async () => {
    const { flow, store } = flowSetup({
      hilos_step_up_start: SKIP,
      hilos_account_deletion_open: OPENING,
    })
    await flow.open()
    flow.dispose()

    store.applyState(wireScheduled())

    expect(flow.step.get()).toBe('explain')
  })

  it('lands on the refusal when the window cannot open', async () => {
    const { flow } = flowSetup({
      hilos_step_up_start: SKIP,
      hilos_account_deletion_open:
        'Your account is already scheduled for deletion',
    })

    await flow.open()

    expect(flow.step.get()).toBe('refused')
    expect(flow.refusal.get()).toBe(
      'Your account is already scheduled for deletion',
    )
  })
})

describe('the days left', () => {
  it('rounds up to a whole day and never says less than one', () => {
    expect(accountDeletionDaysLeft(LOCAL_NOW + 30 * DAY, LOCAL_NOW)).toBe(30)
    expect(accountDeletionDaysLeft(LOCAL_NOW + 29 * DAY + 1, LOCAL_NOW)).toBe(
      30,
    )
    expect(accountDeletionDaysLeft(LOCAL_NOW + 1_000, LOCAL_NOW)).toBe(1)
    expect(accountDeletionDaysLeft(LOCAL_NOW - DAY, LOCAL_NOW)).toBe(1)
  })

  describe.each([1, 7, 30, 45])('a %i-day grace period', (graceDays) => {
    it.each([-1, -100, -300, -60_000, -300_000])(
      'stays within the original period with clock estimation error %i ms',
      (estimationError) => {
        vi.useFakeTimers()
        vi.setSystemTime(LOCAL_NOW)
        const requestedAt = LOCAL_NOW + SERVER_DRIFT_MS
        const effectiveAt = requestedAt + graceDays * DAY
        applyServerTime(requestedAt + estimationError)

        const bound = accountDeletionGraceDays(requestedAt, effectiveAt)
        expect(bound).toBe(graceDays)
        expect(accountDeletionDaysLeft(effectiveAt, Date.now())).toBe(
          graceDays + 1,
        )
        expect(accountDeletionDaysLeft(effectiveAt, Date.now(), bound)).toBe(
          graceDays,
        )
      },
    )

    it('counts down elapsed days and keeps the lower bound with exact clocks', () => {
      vi.useFakeTimers()
      vi.setSystemTime(LOCAL_NOW)
      const requestedAt = LOCAL_NOW + SERVER_DRIFT_MS
      const effectiveAt = requestedAt + graceDays * DAY
      applyServerTime(requestedAt)
      const bound = accountDeletionGraceDays(requestedAt, effectiveAt)

      expect(accountDeletionDaysLeft(effectiveAt, Date.now(), bound)).toBe(
        graceDays,
      )
      expect(
        accountDeletionDaysLeft(effectiveAt, Date.now() + DAY, bound),
      ).toBe(Math.max(1, graceDays - 1))
      expect(
        accountDeletionDaysLeft(
          effectiveAt,
          Date.now() + graceDays * DAY,
          bound,
        ),
      ).toBe(1)
      expect(
        accountDeletionDaysLeft(
          effectiveAt,
          Date.now() + (graceDays + 1) * DAY,
          bound,
        ),
      ).toBe(1)
    })
  })

  it('keeps 30 days when 29 days and one millisecond remain', () => {
    expect(
      accountDeletionDaysLeft(LOCAL_NOW + 30 * DAY, LOCAL_NOW + DAY - 1, 30),
    ).toBe(30)
  })

  it.each([undefined, null, 0, -1, 1.5, -1.5, NaN, Infinity, -Infinity])(
    'keeps the uncapped result for an absent or invalid bound %s',
    (bound) => {
      vi.useFakeTimers()
      vi.setSystemTime(LOCAL_NOW)
      applyServerTime(LOCAL_NOW - 1)
      const effectiveAt = LOCAL_NOW + 30 * DAY

      expect(accountDeletionDaysLeft(effectiveAt, Date.now())).toBe(31)
      expect(accountDeletionDaysLeft(effectiveAt, Date.now(), bound)).toBe(31)
    },
  )

  it('spells one day and many', () => {
    expect(formatAccountDeletionDays(1)).toBe('1 day')
    expect(formatAccountDeletionDays(30)).toBe('30 days')
  })
})

describe('the original grace period', () => {
  it.each([1, 7, 30, 45])(
    'reads %i server days at SQL second precision regardless of the browser clock',
    (graceDays) => {
      vi.useFakeTimers()
      vi.setSystemTime(LOCAL_NOW)
      applyServerTime(LOCAL_NOW - 300_000)
      const requestedAt = LOCAL_NOW + SERVER_DRIFT_MS
      const effectiveAt = requestedAt + graceDays * DAY

      expect(accountDeletionGraceDays(requestedAt, effectiveAt)).toBe(graceDays)
      const sqlEffectiveAt = effectiveAt - 1_000
      const bound = accountDeletionGraceDays(requestedAt, sqlEffectiveAt)
      expect(bound).toBe(graceDays)
      expect(accountDeletionDaysLeft(sqlEffectiveAt, Date.now(), bound)).toBe(
        graceDays,
      )
    },
  )

  it.each([
    [DAY + 1, 2],
    [1, 1],
    [0, 1],
    [-DAY, 1],
  ])('rounds a duration of %i ms up to %i days', (duration, expected) => {
    expect(accountDeletionGraceDays(LOCAL_NOW, LOCAL_NOW + duration)).toBe(
      expected,
    )
  })
})

it.each([-300_000, 300_000])(
  'keeps UTC state and the previous day rounding with drift %i',
  (drift) => {
    vi.useFakeTimers()
    vi.setSystemTime(LOCAL_NOW)
    applyServerTime(Date.now() + drift)
    const now = LOCAL_NOW - drift
    expect(
      readHilosAccountDeletionState({
        deletion: { requestedAt: LOCAL_NOW, effectiveAt: LOCAL_NOW + 30 * DAY },
      }),
    ).toEqual({
      deletion: { requestedAt: LOCAL_NOW, effectiveAt: LOCAL_NOW + 30 * DAY },
    })
    expect(accountDeletionDaysLeft(LOCAL_NOW + 30 * DAY, now)).toBe(30)
    expect(accountDeletionDaysLeft(LOCAL_NOW + 29 * DAY + 1, now)).toBe(30)
    expect(accountDeletionDaysLeft(LOCAL_NOW + 1_000, now)).toBe(1)
    expect(accountDeletionDaysLeft(LOCAL_NOW - DAY, now)).toBe(1)
  },
)

afterEach(() => showLine({ state: null, message: null }))

describe.each(['deletion'])('send-progress visibility: %s', () => {
  function line(
    ticket: string,
    state = CODE_SEND_STATE_SENT,
    purpose = ACCOUNT_DELETION_OPERATION,
  ) {
    return { ticket, state, purpose, channel: 'email', message: null }
  }

  async function openWindow() {
    const answers: Record<string, unknown> = {
      hilos_step_up_start: SKIP,
      hilos_account_deletion_open: OPENING,
    }
    const world = flowSetup(answers)
    await world.flow.open()
    const action = 'hilos_account_deletion_code'
    const send = () => world.flow.next()
    const tellStep = () => tell([record()])
    const repeat = () => world.flow.sendAgain()
    const queue = (request: ReturnType<typeof pendingSend>) => {
      answers[action] = request.done.then((result) => result.reply)
    }
    const dispose = () => {
      world.flow.dispose()
      world.store.dispose()
    }
    const rejectOther = () => {
      answers.hilos_account_deletion_start = Promise.reject(
        new ActionError('hilos_account_deletion_start', 'fail', 'Wrong code'),
      )
      world.flow.code.set('123456')
      return world.flow.start()
    }
    const reopen = async () => {
      world.flow.close()
      await world.flow.open()
    }
    return {
      flow: world.flow,
      action,
      send,
      tellStep,
      repeat,
      queue,
      dispose,
      rejectOther,
      reopen,
    }
  }

  it.each([true, false])(
    'shows each new ticket before the step frame and successful reply with sent=%s',
    async (sent) => {
      const { flow, send, tellStep, repeat, queue, dispose } =
        await openWindow()
      showLine(line('old'))
      for (const [ticket, submit] of [
        ['first', send],
        ['second', repeat],
      ] as const) {
        const request = pendingSend()
        queue(request)
        const sending = submit()
        expect(flow.sendProgress.get()).toBeNull()
        showLine(line(ticket, CODE_SEND_STATE_QUEUED))
        expect(flow.sendProgress.get()).toMatchObject(
          line(ticket, CODE_SEND_STATE_QUEUED),
        )
        tellStep()
        expect(flow.sendProgress.get()).toMatchObject(
          line(ticket, CODE_SEND_STATE_QUEUED),
        )
        request.answer({
          reply: {
            sent,
            resendAt: 1_900_000_000_000,
            expiresAt: 1_900_000_600_000,
          },
        })
        await sending
        expect(flow.sendProgress.get()).toMatchObject(
          line(ticket, CODE_SEND_STATE_QUEUED),
        )
      }
      dispose()
    },
  )

  it.each([true, false])(
    'waits for a new ticket across lines, steps and a successful reply with sent=%s',
    async (sent) => {
      const { flow, send, tellStep, repeat, queue, dispose } =
        await openWindow()
      showLine(line('old'))
      const first = pendingSend()
      queue(first)
      const sending = send()
      expect(flow.sendProgress.get()).toBeNull()
      showLine(line('old'))
      tellStep()
      expect(flow.sendProgress.get()).toBeNull()
      showLine(line('first', CODE_SEND_STATE_QUEUED))
      expect(flow.sendProgress.get()).toMatchObject(
        line('first', CODE_SEND_STATE_QUEUED),
      )
      tellStep()
      first.answer({
        reply: {
          sent,
          resendAt: 1_900_000_000_000,
          expiresAt: 1_900_000_600_000,
        },
      })
      await sending
      expect(flow.sendProgress.get()?.ticket).toBe('first')

      const second = pendingSend()
      queue(second)
      const repeating = repeat()
      second.answer({
        reply: {
          sent,
          resendAt: 1_900_000_000_000,
          expiresAt: 1_900_000_600_000,
        },
      })
      await repeating
      showLine(line('first'))
      tellStep()
      expect(flow.sendProgress.get()).toBeNull()
      showLine(line('second', CODE_SEND_STATE_QUEUED))
      expect(flow.sendProgress.get()).toMatchObject(
        line('second', CODE_SEND_STATE_QUEUED),
      )
      dispose()
    },
  )

  it.each(['fail', 'disconnected'] as const)(
    'restores the current session line after %s on first send and repeat',
    async (outcome) => {
      for (const attempt of ['first', 'repeat']) {
        for (const current of ['old', 'new', 'removed', 'other']) {
          const { flow, action, send, tellStep, repeat, queue, dispose } =
            await openWindow()
          if (attempt === 'repeat') {
            const initial = pendingSend()
            queue(initial)
            const sending = send()
            showLine(line('initial', CODE_SEND_STATE_QUEUED))
            tellStep()
            initial.answer({
              reply: {
                sent: true,
                resendAt: 1_900_000_000_000,
                expiresAt: 1_900_000_600_000,
              },
            })
            await sending
          }
          showLine(line('old'))
          const request = pendingSend()
          queue(request)
          const sending = attempt === 'first' ? send() : repeat()
          expect(flow.sendProgress.get()).toBeNull()
          showLine(line('old'))
          expect(flow.sendProgress.get()).toBeNull()
          if (current === 'new') showLine(line('new', CODE_SEND_STATE_QUEUED))
          if (current === 'removed') showLine({ state: null, message: null })
          if (current === 'other')
            showLine(line('foreign', CODE_SEND_STATE_SENT, 'another_operation'))
          request.reject(new ActionError(action, outcome, 'Send refused'))
          await sending
          expect(flow.busy.get()).toBe(false)
          expect(flow.refusal.get()).not.toBeNull()
          if (current === 'old')
            expect(flow.sendProgress.get()).toMatchObject(line('old'))
          else if (current === 'new')
            expect(flow.sendProgress.get()).toMatchObject(
              line('new', CODE_SEND_STATE_QUEUED),
            )
          else expect(flow.sendProgress.get()).toBeNull()
          if (outcome === 'disconnected') {
            showLine(line('old'))
            expect(flow.sendProgress.get()).toMatchObject(line('old'))
            showLine(line('reconnected', CODE_SEND_STATE_QUEUED))
            expect(flow.sendProgress.get()).toMatchObject(
              line('reconnected', CODE_SEND_STATE_QUEUED),
            )
          }
          dispose()
          showLine({ state: null, message: null })
          tell([])
        }
      }
    },
  )
  it('keeps the old ticket hidden when confirmation fails after a successful send', async () => {
    const { flow, send, tellStep, queue, dispose, rejectOther } =
      await openWindow()
    showLine(line('old'))
    const request = pendingSend()
    queue(request)
    const sending = send()
    tellStep()
    request.answer({
      reply: {
        sent: true,
        resendAt: 1_900_000_000_000,
        expiresAt: 1_900_000_600_000,
      },
    })
    await sending
    expect(flow.sendProgress.get()).toBeNull()
    await rejectOther()
    expect(flow.refusal.get()).not.toBeNull()
    expect(flow.sendProgress.get()).toBeNull()
    dispose()
  })

  it('ignores an interrupted send from an earlier opening', async () => {
    const { flow, action, send, queue, dispose, reopen } = await openWindow()
    showLine(line('old'))
    const earlier = pendingSend()
    queue(earlier)
    const sending = send()
    await reopen()
    const current = pendingSend()
    queue(current)
    const sendingNow = send()
    earlier.reject(new ActionError(action, 'disconnected', 'Disconnected'))
    await sending
    expect(flow.busy.get()).toBe(true)
    expect(flow.refusal.get()).toBeNull()
    expect(flow.sendProgress.get()).toBeNull()
    current.reject(new ActionError(action, 'fail', 'Send refused'))
    await sendingNow
    expect(flow.sendProgress.get()).toMatchObject(line('old'))
    dispose()
  })
})
