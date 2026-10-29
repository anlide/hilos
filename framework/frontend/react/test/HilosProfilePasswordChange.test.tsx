import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  ActionError,
  bindProfileFlows,
  createHilosProfilePasswordChangeFlow,
  SIGNAL_PROFILE_FLOWS,
  type ActionLifecycle,
  type HilosConnection,
  type ProjectSignal,
} from '@hilos/core'
import { HilosProfilePasswordChange } from '../src/profile/HilosProfilePasswordChange.js'

afterEach(cleanup)

const listeners: Array<(signal: ProjectSignal) => void> = []
bindProfileFlows({
  on: (_: string, listener: (signal: ProjectSignal) => void) => {
    listeners.push(listener)
    return () => undefined
  },
} as unknown as HilosConnection)

/**
 * Tell the session's record of the password change, as the frame carries it.
 *
 * @param step The record's step, or null when the session has none.
 */
function tell(step: string | null): void {
  const flows =
    step === null
      ? []
      : [
          {
            operation: 'change_password',
            step,
            address: 'a@example.test',
            target: null,
          },
        ]
  for (const listener of listeners)
    listener({
      kind: 'project',
      type: SIGNAL_PROFILE_FLOWS,
      data: { flows },
    } as ProjectSignal)
}

/** The answer to a code request: the code is out, and another may follow at once. */
const SENT = {
  sent: true,
  resendAt: Date.now() - 1_000,
  expiresAt: Date.now() + 600_000,
}

/** The record each action leaves behind, told before its answer arrives. */
const FRAME_AFTER: Record<string, string> = {
  profile_change_password_code_request: 'code_sent',
}

function deferredReply() {
  let resolve!: (reply?: unknown) => void
  let reject!: (error: unknown) => void
  const done = new Promise<{ reply?: unknown }>((yes, no) => {
    resolve = (reply) => yes({ reply })
    reject = no
  })
  return { done, resolve, reject }
}

function setup() {
  tell(null)
  const dispatched: Array<{ action: string; payload?: unknown }> = []
  const pending: Record<string, ReturnType<typeof deferredReply>> = {}
  const answers: Record<string, unknown> = {
    hilos_step_up_start: {
      required: true,
      purpose: 'change your password',
      method: 'password',
    },
    profile_change_password_open: {
      channel: 'email',
      destination: 'a@example.test',
    },
    profile_change_password_code_request: SENT,
  }
  const flow = createHilosProfilePasswordChangeFlow({
    actions: {
      dispatch: (action: string, payload?: unknown) => {
        dispatched.push({ action, payload })
        if (pending[action]) return pending[action]
        if (action in FRAME_AFTER) tell(FRAME_AFTER[action] ?? null)
        return {
          done:
            typeof answers[action] === 'string'
              ? Promise.reject(new Error(answers[action] as string))
              : Promise.resolve({ reply: answers[action] ?? [] }),
        }
      },
    } as unknown as ActionLifecycle,
  })
  const view = render(<HilosProfilePasswordChange flow={flow} />)
  const node = (id: string) =>
    document.querySelector(`[data-id="${id}"]`) as HTMLElement

  return {
    flow,
    answers,
    dispatched,
    view,
    node,
    hold(action: string) {
      const reply = deferredReply()
      pending[action] = reply
      return reply
    },
  }
}

describe('HilosProfilePasswordChange', () => {
  it('locks step-up fields and close paths through a refusal and permits confirmation retry', async () => {
    const { flow, node, dispatched, hold } = setup()
    await act(async () => {
      await flow.open()
    })
    const field = node('step-up-password') as HTMLInputElement
    const form = node('profile-password-step-up')
    const live = node('profile-password-live')
    fireEvent.change(field, { target: { value: 'secret' } })
    const pending = hold('hilos_step_up_confirm')
    await act(async () => {
      fireEvent.submit(form)
    })
    expect(field.matches(':disabled')).toBe(true)
    expect(node('profile-password-cancel').matches(':disabled')).toBe(true)
    expect(node('modal-close').matches(':disabled')).toBe(true)
    expect(
      node('profile-password-step-up-confirm').closest('fieldset:disabled'),
    ).toBeNull()
    await act(async () => {
      fireEvent.submit(form)
      fireEvent.click(node('profile-password-step-up-confirm'))
      fireEvent.click(node('profile-password-cancel'))
      fireEvent.click(node('modal-close'))
      fireEvent.keyDown(node('modal'), { key: 'Escape' })
      fireEvent.click(node('modal'))
    })
    expect(node('step-up-password')).toBe(field)
    expect(
      dispatched.filter((d) => d.action === 'hilos_step_up_confirm'),
    ).toHaveLength(1)
    expect(document.querySelector('[data-id="modal-confirm"]')).toBeNull()
    await act(async () => {
      pending.reject(
        new ActionError(
          'hilos_step_up_confirm',
          'fail',
          'Password was refused',
        ),
      )
    })
    expect(node('step-up-password')).toBe(field)
    expect(field.value).toBe('secret')
    expect(field.matches(':disabled')).toBe(false)
    expect(node('profile-password-cancel').matches(':disabled')).toBe(false)
    expect(node('modal-close').matches(':disabled')).toBe(false)
    expect(node('profile-password-live')).toBe(live)
    expect(live.textContent).toBe('Password was refused')
    expect(node('step-up-error').textContent).toContain('Password was refused')
    const retry = hold('hilos_step_up_confirm')
    await act(async () => {
      fireEvent.click(node('profile-password-step-up-confirm'))
    })
    await act(async () => {
      retry.resolve()
    })
    expect(flow.step.get()).toBe('start')
    await act(async () => {
      fireEvent.click(node('profile-password-cancel'))
    })
    expect(node('modal')).toBeNull()
    flow.dispose()
  })

  it('retains code and password drafts through pending and refusal and closes from the successful outcome', async () => {
    const { flow, answers, node, dispatched, hold } = setup()
    answers.hilos_step_up_start = {
      required: false,
      purpose: 'change your password',
    }
    tell('code_sent')
    await act(async () => {
      await flow.open()
    })
    const live = node('profile-password-live')
    const slot = node('profile-password-error-slot')
    for (const scenario of [
      {
        field: 'profile-password-code',
        button: 'profile-password-confirm-code',
        value: '123456',
        action: 'profile_change_password_code_confirm',
        next: 'code_proven',
      },
      {
        field: 'profile-password-new',
        button: 'profile-password-save',
        value: 'new secret',
        action: 'profile_change_password',
        next: null,
      },
    ]) {
      const field = node(scenario.field) as HTMLInputElement
      const form = field.closest('form')!
      const others = document.querySelector<HTMLInputElement>(
        '[data-id="profile-password-sign-out-others"]',
      )
      if (others) fireEvent.click(others)
      const choice = others?.checked
      expect(node(scenario.button).matches(':disabled')).toBe(true)
      fireEvent.change(field, { target: { value: scenario.value } })
      const pending = hold(scenario.action)
      await act(async () => {
        fireEvent.submit(form)
      })
      expect(field.matches(':disabled')).toBe(true)
      if (others) expect(others.matches(':disabled')).toBe(true)
      expect(node('profile-password-cancel').matches(':disabled')).toBe(true)
      expect(node('modal-close').matches(':disabled')).toBe(true)
      expect(node(scenario.button).closest('fieldset:disabled')).toBeNull()
      expect(node(scenario.button).getAttribute('aria-busy')).toBe('true')
      await act(async () => {
        fireEvent.submit(form)
        fireEvent.click(node(scenario.button))
        fireEvent.click(node('profile-password-cancel'))
        fireEvent.click(node('modal-close'))
        fireEvent.keyDown(node('modal'), { key: 'Escape' })
        fireEvent.click(node('modal'))
      })
      expect(node(scenario.field)).toBe(field)
      expect(document.querySelector('[data-id="modal-confirm"]')).toBeNull()
      expect(
        dispatched.filter((d) => d.action === scenario.action),
      ).toHaveLength(1)
      await act(async () => {
        pending.reject(
          new ActionError(
            scenario.action,
            'fail',
            'Please try this step again',
          ),
        )
      })
      expect(node(scenario.field)).toBe(field)
      expect(field.value).toBe(scenario.value)
      expect(field.matches(':disabled')).toBe(false)
      if (others) {
        expect(node('profile-password-sign-out-others')).toBe(others)
        expect(others.checked).toBe(choice)
        expect(others.matches(':disabled')).toBe(false)
      }
      expect(node('profile-password-cancel').matches(':disabled')).toBe(false)
      expect(node('modal-close').matches(':disabled')).toBe(false)
      expect(node(scenario.button).matches(':disabled')).toBe(false)
      expect(node('profile-password-error-slot')).toBe(slot)
      expect(node('profile-password-error').textContent).toContain(
        'Please try this step again',
      )
      expect(node('profile-password-live')).toBe(live)
      expect(live.textContent).toBe('Please try this step again')
      fireEvent.change(field, { target: { value: '' } })
      expect(node(scenario.button).matches(':disabled')).toBe(true)
      await act(async () => {
        fireEvent.submit(form)
      })
      expect(
        dispatched.filter((d) => d.action === scenario.action),
      ).toHaveLength(1)
      fireEvent.change(field, { target: { value: scenario.value } })
      const retry = hold(scenario.action)
      await act(async () => {
        fireEvent.click(node(scenario.button))
      })
      await act(async () => {
        tell(scenario.next)
        retry.resolve()
      })
      expect(
        dispatched.filter((d) => d.action === scenario.action),
      ).toHaveLength(2)
    }
    expect(flow.step.get()).toBe('done')
    await act(async () => {
      fireEvent.click(node('profile-password-done'))
    })
    expect(node('modal')).toBeNull()
    flow.dispose()
  })

  it('confirms step-up via form submit when credential is typed, ignores empty submit, and binds confirm button to form', async () => {
    const { flow, node, dispatched } = setup()
    await act(async () => {
      await flow.open()
    })

    const form = node('profile-password-step-up')
    const confirm = node('profile-password-step-up-confirm')

    expect(confirm.getAttribute('type')).toBe('submit')
    expect(confirm.getAttribute('form')).toBe(form.id)

    await act(async () => {
      fireEvent.submit(form)
    })
    expect(dispatched.some((d) => d.action === 'hilos_step_up_confirm')).toBe(
      false,
    )

    fireEvent.change(node('step-up-password'), { target: { value: 'secret' } })
    await act(async () => {
      fireEvent.submit(form)
    })
    const call = dispatched.find((d) => d.action === 'hilos_step_up_confirm')
    expect(call).toBeDefined()
    expect(call?.payload).toMatchObject({
      password: 'secret',
    })
  })

  it('draws the send block on the code step and asks for another code from it', async () => {
    const { flow, answers, node } = setup()
    answers.hilos_step_up_start = {
      required: false,
      purpose: 'change your password',
    }
    await act(async () => {
      await flow.open()
    })
    await act(async () => {
      fireEvent.click(node('profile-password-send-code'))
    })

    expect(node('profile-password-send')).not.toBeNull()
    expect(document.querySelectorAll('[inert] [data-id]')).toHaveLength(0)
    const sendAgain = vi.spyOn(flow, 'sendAgain')
    const again = node('profile-password-send-again') as HTMLButtonElement
    expect(again.disabled).toBe(false)
    await act(async () => {
      fireEvent.click(again)
    })
    expect(sendAgain).toHaveBeenCalledOnce()
  })
})
