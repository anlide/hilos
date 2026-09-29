// Covers the profile root's name window (HIL-1169, in HIL-1134's shape): the
// confirmation asked or skipped, the draft and snapshot taken when the form
// shows, the project's length bounds, success by the name SENT, Take theirs
// while the rename flies, the project's refusal, and a name moved elsewhere
// while the form is open.
import { describe, expect, it, vi } from 'vitest'
import {
  ActionError,
  type ActionLifecycle,
} from '../../src/connection/actionLifecycle.js'
import {
  createHilosProfileRenameFlow,
  type HilosProfileRename,
} from '../../src/profile/profileRename.js'
import { createSignal } from '../../src/state/signal.js'

const SKIP = { required: false, purpose: 'change your name' }
const ASK = { required: true, purpose: 'change your name', method: 'password' }

function setup(stepUp: unknown = SKIP, sends = true) {
  const dispatch = vi.fn(
    (
      name: string,
      payload: unknown,
      options: { replySchema?: { parse(value: unknown): unknown } } = {},
    ): { done: Promise<{ reply: unknown }> } => {
      void payload
      const answer = name === 'hilos_step_up_start' ? stepUp : []
      return {
        done:
          typeof answer === 'string'
            ? Promise.reject(new ActionError(name, 'fail', answer))
            : Promise.resolve({
                reply: options.replySchema?.parse(answer) ?? answer,
              }),
      }
    },
  )
  const name = createSignal('Ann')
  const refusal = createSignal<string | null>(null)
  const sent: string[] = []
  const rename: HilosProfileRename = {
    send(next) {
      sent.push(next)
      return sends
    },
    refusal,
    clearRefusal: () => refusal.set(null),
    stepUpOperation: 'change_name',
    minLength: 2,
    maxLength: 64,
  }
  const flow = createHilosProfileRenameFlow(
    { actions: { dispatch } as unknown as ActionLifecycle },
    name,
    rename,
  )
  return { flow, dispatch, name, refusal, sent }
}

describe('profile rename window', () => {
  it("skips the confirmation the project's operation does not ask and shows the live name", async () => {
    const { flow, dispatch } = setup()
    await flow.open()
    expect(dispatch).toHaveBeenCalledWith(
      'hilos_step_up_start',
      { operation: 'change_name' },
      expect.anything(),
    )
    expect(flow.step.get()).toBe('form')
    expect(flow.draft.get()).toBe('Ann')
    expect(flow.edit.get().dirty).toBe(false)
    expect(flow.asksBeforeClosing.get()).toBe(false)
  })

  it('confirms the person first and takes the name as it stands when the form shows', async () => {
    const { flow, name } = setup(ASK)
    await flow.open()
    expect(flow.step.get()).toBe('step-up')
    name.set('Anna')
    flow.stepUp.password.set('secret')
    await flow.confirmStepUp()
    expect(flow.step.get()).toBe('form')
    expect(flow.draft.get()).toBe('Anna')
  })

  it('stays on the confirmation when the operation is refused', async () => {
    const { flow } = setup('Not available under impersonation')
    await flow.open()
    expect(flow.step.get()).toBe('step-up')
    expect(flow.stepUp.refusal.get()).toBe('Not available under impersonation')
    expect(flow.stepUp.opening.get()).toBeNull()
  })

  it("holds Save outside the project's bounds", async () => {
    const { flow, sent } = setup()
    await flow.open()
    flow.draft.set(' A ')
    expect(flow.valid.get()).toBe(false)
    flow.save()
    flow.draft.set('x'.repeat(65))
    expect(flow.valid.get()).toBe(false)
    flow.save()
    expect(sent).toEqual([])
    expect(flow.step.get()).toBe('form')
  })

  it('closes an unchanged draft without a round-trip', async () => {
    const { flow, sent } = setup()
    await flow.open()
    flow.save()
    expect(sent).toEqual([])
    expect(flow.step.get()).toBe('closed')
  })

  it('closes once the live name reaches the name it sent, not another one', async () => {
    const { flow, name, sent } = setup()
    await flow.open()
    flow.draft.set('  Bob  ')
    expect(flow.asksBeforeClosing.get()).toBe(true)
    flow.save()
    expect(sent).toEqual(['Bob'])
    expect(flow.busy.get()).toBe(true)
    name.set('Carl')
    expect(flow.step.get()).toBe('form')
    expect(flow.busy.get()).toBe(true)
    name.set('Bob')
    expect(flow.step.get()).toBe('closed')
    expect(flow.busy.get()).toBe(false)
  })

  it('keeps waiting for its own name after Take theirs while the rename flies', async () => {
    const { flow, name } = setup()
    await flow.open()
    flow.draft.set('Bob')
    flow.save()
    name.set('Carl')
    expect(flow.edit.get().conflict).toBe(true)
    expect(flow.notice.get()).toBe('Changed elsewhere to "Carl".')
    flow.takeTheirs()
    expect(flow.draft.get()).toBe('Carl')
    expect(flow.busy.get()).toBe(true)
    name.set('Bob')
    expect(flow.step.get()).toBe('closed')
  })

  it("releases the button on the project's refusal and keeps the window open", async () => {
    const { flow, refusal } = setup()
    await flow.open()
    flow.draft.set('Bob')
    flow.save()
    refusal.set('That name is not allowed')
    expect(flow.busy.get()).toBe(false)
    expect(flow.step.get()).toBe('form')
    expect(flow.refusal.get()).toBe('That name is not allowed')
    expect(flow.draft.get()).toBe('Bob')
  })

  it('waits for nothing when the rename did not leave', async () => {
    const { flow, sent } = setup(SKIP, false)
    await flow.open()
    flow.draft.set('Bob')
    flow.save()
    expect(sent).toEqual(['Bob'])
    expect(flow.busy.get()).toBe(false)
    expect(flow.step.get()).toBe('form')
  })

  it('takes a name moved elsewhere into an untouched form and says so', async () => {
    const { flow, name } = setup()
    await flow.open()
    name.set('Anna')
    expect(flow.draft.get()).toBe('Anna')
    expect(flow.edit.get().dirty).toBe(false)
    expect(flow.notice.get()).toBe('Updated just now')
  })

  it('keeps the draft over the other side on Keep mine', async () => {
    const { flow, name } = setup()
    await flow.open()
    flow.draft.set('Bob')
    name.set('Carl')
    expect(flow.edit.get().conflict).toBe(true)
    flow.keepMine()
    expect(flow.edit.get().conflict).toBe(false)
    expect(flow.draft.get()).toBe('Bob')
  })
})
