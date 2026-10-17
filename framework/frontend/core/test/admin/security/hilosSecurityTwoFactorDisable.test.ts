// The window that turns the second factor off for everyone (HIL-1303): it
// stands on the confirmation step first, or skips it, or says why it cannot
// open; then it lists what turning it off does, the trusted-browser line by the
// second row, and writes "off" on Turn off. A refusal keeps it open, the first
// row saying off closes it, another tab's confirmation moves it on, and a
// viewer of the admin view mode does not get it at all.
import { afterEach, describe, expect, it } from 'vitest'
import {
  createHilosSecurityTwoFactorTable,
  HilosSecondFactorSettingKey,
} from '../../../src/admin/security/hilosSecurityTwoFactor.js'
import {
  createHilosTwoFactorDisable,
  HILOS_TWO_FACTOR_DISABLE_COPY,
} from '../../../src/admin/security/hilosSecurityTwoFactorDisable.js'
import {
  bindStepUpConfirmed,
  SIGNAL_STEP_UP_CONFIRMED,
} from '../../../src/auth/stepUpConfirmed.js'
import {
  ActionError,
  type ActionLifecycle,
} from '../../../src/connection/actionLifecycle.js'
import { type HilosConnection } from '../../../src/connection/HilosConnection.js'
import { type ProjectSignal } from '../../../src/protocol/parseSignal.js'
import { bindAdminAccess } from '../../../src/session/adminAccess.js'
import { bindSessionScope } from '../../../src/session/sessionScope.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'

let releaseAccess: (() => void) | null = null

afterEach(() => {
  releaseAccess?.()
  releaseAccess = null
})

/**
 * The tab's table, its frames and its actions; the step-up answers by `stepUp`.
 *
 * @param stepUp How the server answers the opening of the step.
 * @param switchReply What the write itself answers: success, or a refusal.
 */
function world(
  stepUp: 'skip' | 'ask' | 'refused' = 'skip',
  switchReply: Error | null = null,
) {
  const listeners = new Set<(frame: ProjectSignal) => void>()
  const sent: Array<{ name: string; data: unknown }> = []
  const answers: Record<string, unknown> = {
    hilos_step_up_start:
      stepUp === 'refused'
        ? new ActionError(
            'hilos_step_up_start',
            'fail',
            'Only an active administrator can do this',
          )
        : {
            required: stepUp === 'ask',
            purpose: 'turn the second factor off',
            method: 'password',
          },
    security_2fa_switch_set: switchReply ?? {},
  }
  const connection = {
    on(event: string, listener: (frame: ProjectSignal) => void) {
      if (event !== 'projectSignal') return () => {}
      listeners.add(listener)
      return () => listeners.delete(listener)
    },
    sendTableViewport: () => true,
    sendTableRendered: () => true,
    sendTableRowFocus: () => true,
  } as unknown as HilosConnection
  const actions = {
    dispatch(name: string, data: unknown) {
      sent.push({ name, data })
      const reply = answers[name] ?? {}
      return {
        done:
          reply instanceof Error
            ? Promise.reject(reply)
            : Promise.resolve({ reply }),
      }
    },
  } as unknown as ActionLifecycle
  const scopes = new ScopeManager()
  const table = createHilosSecurityTwoFactorTable({
    connection,
    scopes,
    actions,
  })

  bindStepUpConfirmed(connection)

  /**
   * The table window with the two switches as the server says them.
   *
   * @param enabled The first row.
   * @param forget The second row.
   */
  function rows(enabled: string, forget = 'true'): void {
    table.controller.ingestSubscriptionWindow(
      [
        [HilosSecondFactorSettingKey.enabled, enabled],
        [HilosSecondFactorSettingKey.forgetTrustsOnDisable, forget],
        [HilosSecondFactorSettingKey.trustDays, '30'],
      ].map(([rowKey, value]) => ({
        rowKey,
        slots: { setting: { rowKey, value, defaultValue: 'true' } },
      })),
      3,
      true,
      null,
      null,
      10,
      undefined,
      [],
    )
  }

  rows('true')
  const window = createHilosTwoFactorDisable({ actions }, table.controller)
  window.start()

  return {
    window,
    rows,
    sent,
    answers,
    names: () => sent.map((entry) => entry.name),
    /** Put this browser in the admin view mode, as a non-admin viewer. */
    viewer() {
      bindSessionScope(connection, scopes)
      releaseAccess = bindAdminAccess(scopes)
      for (const listener of [...listeners])
        listener({
          kind: 'project',
          type: 'handshake_response',
          data: {
            entities: { currentUser: { id: 10, admin: false, name: 'Olena' } },
            data: { adminViewMode: true },
          },
          envelope: {},
        } as unknown as ProjectSignal)
    },
    /** Another tab of the session confirmed these operations (HIL-1330). */
    confirmedElsewhere(operations: string[]) {
      for (const listener of [...listeners])
        listener({
          type: SIGNAL_STEP_UP_CONFIRMED,
          data: { operations },
        } as unknown as ProjectSignal)
    },
  }
}

describe('createHilosTwoFactorDisable', () => {
  it('goes straight to what turning it off does when no step is needed, and turns it off', async () => {
    const w = world('skip')

    await w.window.open()
    expect(w.window.step.get()).toBe('consequences')
    expect(w.names()).toEqual(['hilos_step_up_start'])
    expect(w.sent[0]?.data).toEqual({ operation: 'disable_second_factor' })

    await Promise.all([w.window.confirm(), w.window.confirm()])

    expect(w.names()).toEqual([
      'hilos_step_up_start',
      'security_2fa_switch_set',
    ])
    expect(w.sent.at(-1)?.data).toEqual({
      key: 'auth.second_factor.enabled',
      enabled: false,
    })
    expect(w.window.step.get()).toBe('closed')
    expect(w.window.busy.get()).toBe(false)
    w.window.dispose()
  })

  it('asks the confirmation first, and only then what turning it off does', async () => {
    const w = world('ask')

    await w.window.open()
    expect(w.window.step.get()).toBe('step-up')

    // Turn off is not offered yet.
    await w.window.confirm()
    expect(w.names()).toEqual(['hilos_step_up_start'])

    w.window.stepUp.password.set('a proof')
    await w.window.confirmStepUp()
    expect(w.names()).toEqual(['hilos_step_up_start', 'hilos_step_up_confirm'])
    expect(w.window.step.get()).toBe('consequences')

    await w.window.confirm()
    expect(w.names().at(-1)).toBe('security_2fa_switch_set')
    expect(w.window.step.get()).toBe('closed')
    expect(w.window.stepUp.password.get()).toBe('')
    w.window.dispose()
  })

  it('stays on the step when the confirmation is refused', async () => {
    const w = world('ask')
    w.answers.hilos_step_up_confirm = new ActionError(
      'hilos_step_up_confirm',
      'fail',
      'Wrong password',
    )

    await w.window.open()
    w.window.stepUp.password.set('wrong')
    await w.window.confirmStepUp()

    expect(w.window.step.get()).toBe('step-up')
    expect(w.window.stepUp.refusal.get()).toBe('Wrong password')
    expect(w.window.busy.get()).toBe(false)
    w.window.dispose()
  })

  it('says why the step cannot open, and writes nothing', async () => {
    const w = world('refused')

    await w.window.open()

    expect(w.window.step.get()).toBe('refused')
    expect(w.window.refusal.get()).toBe(
      'Only an active administrator can do this',
    )
    await w.window.confirm()
    expect(w.names()).toEqual(['hilos_step_up_start'])
    w.window.dispose()
  })

  it('keeps the window open with the refusal of the write', async () => {
    const w = world(
      'skip',
      new ActionError(
        'security_2fa_switch_set',
        'fail',
        'The confirmation has expired',
      ),
    )

    await w.window.open()
    await w.window.confirm()

    expect(w.window.step.get()).toBe('consequences')
    expect(w.window.refusal.get()).toBe('The confirmation has expired')
    expect(w.window.busy.get()).toBe(false)
    w.window.dispose()
  })

  it('goes on to what turning it off does when another tab of the session confirms it', async () => {
    const w = world('ask')
    await w.window.open()

    w.confirmedElsewhere(['disable_second_factor'])

    expect(w.window.step.get()).toBe('consequences')
    expect(w.names()).toEqual(['hilos_step_up_start'])
    w.window.dispose()
  })

  it('closes once the first row says off, whoever turned it off', async () => {
    const w = world('ask')
    await w.window.open()
    w.rows('false')
    expect(w.window.step.get()).toBe('closed')

    w.rows('true')
    const v = world('skip')
    await v.window.open()
    v.rows('false')
    expect(v.window.step.get()).toBe('closed')

    // Off already: there is nothing to turn off.
    await v.window.open()
    expect(v.window.step.get()).toBe('closed')
    expect(v.names()).toEqual(['hilos_step_up_start'])
    w.window.dispose()
    v.window.dispose()
  })

  it('does not open for a viewer of the admin view mode', async () => {
    const w = world('skip')
    w.viewer()

    await w.window.open()

    expect(w.window.step.get()).toBe('closed')
    expect(w.names()).toEqual([])
    w.window.dispose()
  })

  it('changes nothing on Cancel, and a late opening does not reopen it', async () => {
    const w = world('ask')
    const opening = w.window.open()
    expect(w.window.step.get()).toBe('opening')
    w.window.close()
    await opening

    expect(w.window.step.get()).toBe('closed')
    expect(w.names()).toEqual(['hilos_step_up_start'])
    w.window.dispose()
  })

  it('lists what turning it off does, the trusted browsers by the second row', () => {
    const w = world()
    const copy = HILOS_TWO_FACTOR_DISABLE_COPY

    expect(w.window.consequences.get()).toStrictEqual([
      copy.noCode,
      copy.kept,
      copy.trustsForgotten,
      copy.removals,
      copy.stepUp,
      copy.waiting,
    ])

    w.rows('true', 'false')
    expect(w.window.consequences.get()[2]).toBe(copy.trustsKept)
    w.window.dispose()
  })
})
