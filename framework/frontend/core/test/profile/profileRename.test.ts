// Covers the profile root's name window (HIL-1169, in HIL-1134's shape): the
// confirmation asked or skipped, the draft and snapshot taken when the form
// shows, the project's length bounds, success by the name SENT, Take theirs
// while the rename flies, the project's refusal, and a name moved elsewhere
// while the form is open.
import { describe, expect, it, vi } from 'vitest'
import {
  ActionErrorStore,
  type ActionErrorSource,
} from '../../src/connection/ActionErrorStore.js'
import {
  ActionError,
  type ActionLifecycle,
} from '../../src/connection/actionLifecycle.js'
import { HilosConnection } from '../../src/connection/HilosConnection.js'
import {
  createHilosProfileRename,
  createHilosProfileRenameFlow,
  PROFILE_NAME_SET_ACTION,
  type HilosProfileRename,
} from '../../src/profile/profileRename.js'
import { createSignal } from '../../src/state/signal.js'

const SKIP = { required: false, purpose: 'change your name' }
const ASK = { required: true, purpose: 'change your name', method: 'password' }

function setup(
  stepUp: unknown = SKIP,
  sends = true,
  binding?: HilosProfileRename,
) {
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
  const rename: HilosProfileRename = binding ?? {
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

function setupBinding() {
  const listeners = new Set<Parameters<ActionErrorSource['on']>[1]>()
  const on = vi.fn(
    (
      _event: 'actionError',
      listener: Parameters<ActionErrorSource['on']>[1],
    ) => {
      listeners.add(listener)

      return () => listeners.delete(listener)
    },
  )
  const sendAction = vi.fn<(action: string, data: unknown) => boolean>(
    () => true,
  )
  const connection = { on, sendAction } as unknown as HilosConnection
  const errors = new ActionErrorStore(connection)
  const emit = (action: string, reason: string): void => {
    for (const listener of listeners) listener({ action, reason })
  }

  return { connection, errors, emit, on, sendAction }
}

describe('standard profile rename binding', () => {
  it('sends the exact framework action and payload with the standard operation and bounds', () => {
    const { connection, errors, sendAction } = setupBinding()
    const rename = createHilosProfileRename(connection, errors)

    expect(PROFILE_NAME_SET_ACTION).toBe('hilos_profile_name_set')
    expect(rename.send('Bob')).toBe(true)
    expect(sendAction.mock.calls).toEqual([
      ['hilos_profile_name_set', { name: 'Bob' }],
    ])
    expect(rename.stepUpOperation).toBe('change_name')
    expect(rename.minLength).toBe(2)
    expect(rename.maxLength).toBe(64)
    errors.dispose()
  })

  it('returns false and keeps the window closed without an authoritative session', async () => {
    const connection = new HilosConnection({ url: 'ws://unused' })
    const errors = new ActionErrorStore(connection)
    const rename = createHilosProfileRename(connection, errors)
    const { flow } = setup(SKIP, true, rename)

    expect(rename.send('Bob')).toBe(false)
    await flow.open()
    flow.draft.set('Bob')
    flow.save()
    expect(flow.busy.get()).toBe(false)
    expect(flow.step.get()).toBe('closed')
    expect(flow.draft.get()).toBe('Bob')
    flow.dispose()
    errors.dispose()
  })

  it('reuses the supplied store without clearing on construction or adding a listener', () => {
    const { connection, errors, emit, on } = setupBinding()
    emit(PROFILE_NAME_SET_ACTION, 'Names cannot be checked right now.')
    const rename = createHilosProfileRename(connection, errors)

    expect(on).toHaveBeenCalledTimes(1)
    expect(rename.refusal).toBe(errors.signal(PROFILE_NAME_SET_ACTION))
    expect(rename.refusal.get()).toBe('Names cannot be checked right now.')
    errors.dispose()
    emit(PROFILE_NAME_SET_ACTION, 'A later refusal')
    expect(rename.refusal.get()).toBe('Names cannot be checked right now.')
  })

  it('keeps the server reason verbatim and isolates both refusals and clearing by action', () => {
    const { connection, errors, emit } = setupBinding()
    const rename = createHilosProfileRename(connection, errors)
    const reason = ' Server name refusal.\nKeep these words. '

    emit('rename', 'Old project rename refused')
    expect(rename.refusal.get()).toBeNull()
    emit(PROFILE_NAME_SET_ACTION, reason)
    emit('hilos_profile_photo_set', 'Photo refused')
    expect(rename.refusal.get()).toBe(reason)
    rename.clearRefusal()
    expect(rename.refusal.get()).toBeNull()
    expect(errors.signal('rename').get()).toBe('Old project rename refused')
    expect(errors.signal('hilos_profile_photo_set').get()).toBe('Photo refused')
    errors.dispose()
  })

  it('keeps the legacy custom binding refusal and live-echo lifecycle', async () => {
    const { connection, errors, emit, sendAction } = setupBinding()
    const standard = createHilosProfileRename(connection, errors)
    const rename: HilosProfileRename = {
      send: standard.send,
      refusal: standard.refusal,
      clearRefusal: standard.clearRefusal,
      stepUpOperation: standard.stepUpOperation,
      minLength: standard.minLength,
      maxLength: standard.maxLength,
    }
    const { flow, name } = setup(SKIP, true, rename)
    emit(PROFILE_NAME_SET_ACTION, 'An earlier refusal')

    await flow.open()
    expect(flow.refusal.get()).toBeNull()
    flow.draft.set('  Bob  ')
    emit(PROFILE_NAME_SET_ACTION, 'A refusal before saving')
    sendAction.mockImplementation(() => {
      expect(flow.refusal.get()).toBeNull()

      return true
    })
    flow.save()
    expect(sendAction).toHaveBeenLastCalledWith('hilos_profile_name_set', {
      name: 'Bob',
    })
    expect(name.get()).toBe('Ann')
    expect(flow.busy.get()).toBe(true)
    emit('rename', 'An unrelated refusal')
    expect(flow.busy.get()).toBe(true)
    emit(PROFILE_NAME_SET_ACTION, 'Names cannot be checked right now.')
    expect(flow.refusal.get()).toBe('Names cannot be checked right now.')
    expect(flow.busy.get()).toBe(false)
    expect(flow.step.get()).toBe('form')
    expect(flow.draft.get()).toBe('  Bob  ')

    flow.save()
    expect(sendAction).toHaveBeenCalledTimes(2)
    expect(flow.busy.get()).toBe(true)
    name.set('Carl')
    expect(flow.edit.get().conflict).toBe(true)
    expect(flow.step.get()).toBe('form')
    flow.keepMine()
    expect(flow.edit.get().conflict).toBe(false)
    expect(flow.draft.get()).toBe('  Bob  ')
    name.set('Bob')
    expect(flow.step.get()).toBe('closed')
    expect(flow.busy.get()).toBe(false)
    flow.dispose()
    errors.dispose()
  })
})

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
