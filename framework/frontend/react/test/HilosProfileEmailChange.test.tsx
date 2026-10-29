import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  ActionError,
  bindProfileFlows,
  createHilosProfileEmailChangeFlow,
  SIGNAL_PROFILE_FLOWS,
  type ActionLifecycle,
  type HilosConnection,
  type ProjectSignal,
} from '@hilos/core'
import { HilosProfileEmailChange } from '../src/profile/HilosProfileEmailChange.js'

afterEach(cleanup)

const listeners: Array<(signal: ProjectSignal) => void> = []
bindProfileFlows({
  on: (_: string, listener: (signal: ProjectSignal) => void) => {
    listeners.push(listener)
    return () => undefined
  },
} as unknown as HilosConnection)

/**
 * Tell the session's record of the email change, as the frame carries it.
 *
 * @param step The record's step, or null when the session has none.
 * @param target The new address on the last step, null on the others.
 */
function tell(step: string | null, target: string | null = null): void {
  const flows =
    step === null
      ? []
      : [
          {
            operation: 'change_email',
            step,
            address: 'old@example.test',
            target,
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
      purpose: 'change your email',
      method: 'password',
    },
    profile_change_email_current_request: SENT,
    profile_change_email_new_request: SENT,
  }
  const flow = createHilosProfileEmailChangeFlow({
    actions: {
      dispatch: (action: string, payload?: unknown) => {
        dispatched.push({ action, payload })
        if (pending[action]) return pending[action]
        if (action === 'profile_change_email_current_request')
          tell('current_sent')
        if (action === 'profile_change_email_new_request')
          tell('new_sent', 'new@example.test')
        return {
          done:
            typeof answers[action] === 'string'
              ? Promise.reject(new Error(answers[action] as string))
              : Promise.resolve({ reply: answers[action] ?? [] }),
        }
      },
    } as unknown as ActionLifecycle,
  })
  const view = render(<HilosProfileEmailChange flow={flow} />)
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

describe('HilosProfileEmailChange', () => {
  it('holds step-up fields and every close path until confirmation settles, then permits retry', async () => {
    const { flow, node, dispatched, hold } = setup()
    await act(async () => {
      await flow.open('old@example.test')
    })
    const field = node('step-up-password') as HTMLInputElement
    const form = node('profile-email-step-up')
    const live = node('profile-email-live-assertive')
    fireEvent.change(field, { target: { value: 'secret' } })
    const pending = hold('hilos_step_up_confirm')
    await act(async () => {
      fireEvent.submit(form)
    })

    expect(field.matches(':disabled')).toBe(true)
    expect(node('profile-email-cancel').matches(':disabled')).toBe(true)
    expect(node('modal-close').matches(':disabled')).toBe(true)
    expect(
      node('profile-email-step-up-confirm').closest('fieldset:disabled'),
    ).toBeNull()
    await act(async () => {
      fireEvent.click(node('profile-email-step-up-confirm'))
      fireEvent.submit(form)
      fireEvent.click(node('profile-email-cancel'))
      fireEvent.click(node('modal-close'))
      fireEvent.keyDown(node('modal'), { key: 'Escape' })
      fireEvent.click(node('modal'))
    })
    expect(
      dispatched.filter((d) => d.action === 'hilos_step_up_confirm'),
    ).toHaveLength(1)
    expect(node('step-up-password')).toBe(field)
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
    expect(node('profile-email-cancel').matches(':disabled')).toBe(false)
    expect(node('modal-close').matches(':disabled')).toBe(false)
    expect(node('profile-email-live-assertive')).toBe(live)
    expect(live.textContent).toBe('Password was refused')
    expect(node('step-up-error').textContent).toContain('Password was refused')
    const retry = hold('hilos_step_up_confirm')
    await act(async () => {
      fireEvent.click(node('profile-email-step-up-confirm'))
    })
    await act(async () => {
      retry.resolve()
    })
    expect(flow.step.get()).toBe('send-current')
    await act(async () => {
      fireEvent.click(node('profile-email-cancel'))
    })
    expect(node('modal')).toBeNull()
    flow.dispose()
  })

  it('keeps each submitted draft mounted through pending and refusal, then completes the email flow', async () => {
    const { flow, answers, node, dispatched, hold } = setup()
    answers.hilos_step_up_start = {
      required: false,
      purpose: 'change your email',
    }
    tell('current_sent')
    await act(async () => {
      await flow.open('old@example.test')
    })
    const live = node('profile-email-live-assertive')
    for (const scenario of [
      {
        field: 'profile-email-code-current',
        button: 'profile-email-confirm-current',
        value: '123456',
        action: 'profile_change_email_current_confirm',
        next: 'current_proven',
        reply: undefined,
      },
      {
        field: 'profile-email-new',
        button: 'profile-email-send-new',
        value: 'new@example.test',
        action: 'profile_change_email_new_request',
        next: 'new_sent',
        reply: SENT,
      },
      {
        field: 'profile-email-code-new',
        button: 'profile-email-confirm-new',
        value: '654321',
        action: 'profile_change_email_new_confirm',
        next: null,
        reply: undefined,
      },
    ]) {
      const field = node(scenario.field) as HTMLInputElement
      const form = field.closest('form')!
      expect(node(scenario.button).matches(':disabled')).toBe(true)
      fireEvent.change(field, { target: { value: scenario.value } })
      const slot = node('profile-email-error-slot')
      const pending = hold(scenario.action)
      await act(async () => {
        fireEvent.submit(form)
      })
      expect(field.matches(':disabled')).toBe(true)
      expect(node('profile-email-cancel').matches(':disabled')).toBe(true)
      expect(node('modal-close').matches(':disabled')).toBe(true)
      expect(node(scenario.button).closest('fieldset:disabled')).toBeNull()
      expect(node(scenario.button).getAttribute('aria-busy')).toBe('true')
      await act(async () => {
        fireEvent.click(node(scenario.button))
        fireEvent.submit(form)
        fireEvent.click(node('profile-email-cancel'))
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
      expect(node('profile-email-cancel').matches(':disabled')).toBe(false)
      expect(node('modal-close').matches(':disabled')).toBe(false)
      expect(node(scenario.button).matches(':disabled')).toBe(false)
      expect(node('profile-email-error-slot')).toBe(slot)
      expect(node('profile-email-error').textContent).toContain(
        'Please try this step again',
      )
      expect(node('profile-email-live-assertive')).toBe(live)
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
      expect(node(scenario.field)).toBe(field)
      await act(async () => {
        tell(
          scenario.next,
          scenario.next === 'new_sent' ? 'new@example.test' : null,
        )
        retry.resolve(scenario.reply)
      })
      expect(
        dispatched.filter((d) => d.action === scenario.action),
      ).toHaveLength(2)
    }
    expect(flow.step.get()).toBe('done')
    expect(node('profile-email-now').textContent).toBe('new@example.test')
    await act(async () => {
      fireEvent.click(node('profile-email-done'))
    })
    expect(node('modal')).toBeNull()
    flow.dispose()
  })

  it('confirms step-up via form submit when credential is typed, ignores empty submit, and binds confirm button to form', async () => {
    const { flow, node, dispatched } = setup()
    await act(async () => {
      await flow.open('old@example.test')
    })

    const form = node('profile-email-step-up')
    const confirm = node('profile-email-step-up-confirm')

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

  it('draws the send block on the current address code step and asks for another code from it', async () => {
    const { flow, answers, node } = setup()
    answers.hilos_step_up_start = {
      required: false,
      purpose: 'change your email',
    }
    await act(async () => {
      await flow.open('old@example.test')
    })
    await act(async () => {
      fireEvent.click(node('profile-email-send-current'))
    })

    expect(node('profile-email-current-send')).not.toBeNull()
    const sendAgain = vi.spyOn(flow, 'sendAgain')
    const again = node('profile-email-current-send-again') as HTMLButtonElement
    expect(again.disabled).toBe(false)
    await act(async () => {
      fireEvent.click(again)
    })
    expect(sendAgain).toHaveBeenCalledOnce()
  })

  it('draws the send block on the new address code step and asks for another code from it', async () => {
    const { flow, answers, node } = setup()
    answers.hilos_step_up_start = {
      required: false,
      purpose: 'change your email',
    }
    tell('current_proven')
    await act(async () => {
      await flow.open('old@example.test')
    })
    fireEvent.change(node('profile-email-new'), {
      target: { value: 'new@example.test' },
    })
    await act(async () => {
      fireEvent.click(node('profile-email-send-new'))
    })

    expect(node('profile-email-new-send')).not.toBeNull()
    const sendAgain = vi.spyOn(flow, 'sendAgain')
    const again = node('profile-email-new-send-again') as HTMLButtonElement
    expect(again.disabled).toBe(false)
    await act(async () => {
      fireEvent.click(again)
    })
    expect(sendAgain).toHaveBeenCalledOnce()
  })
})
