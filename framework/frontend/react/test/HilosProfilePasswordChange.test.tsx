import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  ActionError,
  bindCodeSendProgress,
  bindProfileFlows,
  CODE_SEND_PURPOSE_STEP_UP,
  CODE_SEND_STATE_FAILED,
  CODE_SEND_STATE_QUEUED,
  CODE_SEND_STATE_SENT,
  createHilosProfilePasswordChangeFlow,
  PASSWORD_CHANGE_OPERATION,
  SIGNAL_CODE_SEND_PROGRESS,
  SIGNAL_PROFILE_FLOWS,
  type ActionLifecycle,
  type HilosConnection,
  type ProjectSignal,
} from '@hilos/core/base'
import { HilosProfilePasswordChange } from '../src/profile/HilosProfilePasswordChange.js'

afterEach(() => {
  cleanup()
  tellSend({ state: null })
})

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

/** A code request's answer whose resend gate opens in a minute. */
const SENT_RESEND_LATER = {
  sent: true,
  resendAt: Date.now() + 60_000,
  expiresAt: Date.now() + 600_000,
}

/**
 * Tell the session's send line as the server frames it, through the binding the
 * application boots with; the binding is let go once the frame is in, and a
 * null state takes the line away.
 *
 * @param line The frame's fields beside its message, which is none.
 */
function tellSend(line: Record<string, unknown>): void {
  const sendListeners: ((signal: { type: string; data: unknown }) => void)[] =
    []
  const unbind = bindCodeSendProgress({
    on: (_event: string, listener: (signal: never) => void) => {
      sendListeners.push(
        listener as (signal: { type: string; data: unknown }) => void,
      )
      return () => undefined
    },
  } as unknown as HilosConnection)
  act(() => {
    for (const listener of sendListeners)
      listener({
        type: SIGNAL_CODE_SEND_PROGRESS,
        data: { message: null, ...line },
      })
  })
  unbind()
}

/**
 * One frame of the line a send follows.
 *
 * @param purpose The operation that ordered the send.
 * @param ticket The send the line follows.
 * @param state The step the send reached.
 * @param more The provider's detail, when the frame carries one.
 */
function sendLine(
  purpose: string,
  ticket: string,
  state: string,
  more: Record<string, unknown> = {},
): Record<string, unknown> {
  return { state, channel: 'email', purpose, ticket, ...more }
}

/** What the dialog's calm region says now. */
function calmText(): string | null {
  return document.querySelector('[data-id="profile-password-live-polite"]')!
    .textContent
}

/**
 * The element itself, or else the first thing inside it, that matches the
 * selector: the root of a block is the likeliest place for a voice to return.
 *
 * @param element The block to look through, root included.
 * @param selector What a live voice looks like.
 */
function selfOrInside(element: Element, selector: string): Element | null {
  return element.matches(selector) ? element : element.querySelector(selector)
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

  it('says the code step line to the opening destination through a region that outlives the step', async () => {
    const { flow, answers, node, hold } = setup()
    answers.hilos_step_up_start = {
      required: false,
      purpose: 'change your password',
    }
    await act(async () => {
      await flow.open()
    })
    const calm = node('profile-password-live-polite')
    expect(calm.getAttribute('role')).toBe('status')
    expect(calm.getAttribute('aria-live')).toBe('polite')
    expect(calm.closest('[aria-modal="true"]')).not.toBeNull()
    expect(calmText()).toBe('')

    await act(async () => {
      fireEvent.click(node('profile-password-send-code'))
    })
    tellSend(sendLine(PASSWORD_CHANGE_OPERATION, 'own', CODE_SEND_STATE_SENT))
    expect(node('profile-password-live-polite')).toBe(calm)
    expect(calmText()).toBe('Sent to a@example.test')
    tellSend(
      sendLine(PASSWORD_CHANGE_OPERATION, 'own', CODE_SEND_STATE_FAILED, {
        detail: 'mailbox unavailable',
      }),
    )
    expect(calmText()).toBe('Could not send: mailbox unavailable')
    expect(node('profile-password-send').textContent).toContain(
      'Could not send: mailbox unavailable',
    )
    expect(
      selfOrInside(node('profile-password-send'), '[role], [aria-live]'),
    ).toBeNull()

    // The confirmation's line is not this step's.
    tellSend(
      sendLine(CODE_SEND_PURPOSE_STEP_UP, 'confirmation', CODE_SEND_STATE_SENT),
    )
    expect(calmText()).toBe('')
    tellSend(sendLine(PASSWORD_CHANGE_OPERATION, 'own', CODE_SEND_STATE_SENT))
    expect(calmText()).toBe('Sent to a@example.test')

    fireEvent.change(node('profile-password-code'), {
      target: { value: '123456' },
    })
    const proving = hold('profile_change_password_code_confirm')
    await act(async () => {
      fireEvent.click(node('profile-password-confirm-code'))
    })
    await act(async () => {
      tell('code_proven')
      proving.resolve()
    })
    expect(node('profile-password-new')).not.toBeNull()
    expect(node('profile-password-live-polite')).toBe(calm)
    expect(calmText()).toBe('')
    flow.dispose()
  })

  it('keeps a refused code in the alert and the send line in the calm region', async () => {
    const { flow, answers, node, hold } = setup()
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
    tellSend(sendLine(PASSWORD_CHANGE_OPERATION, 'own', CODE_SEND_STATE_SENT))
    expect(calmText()).toBe('Sent to a@example.test')
    expect(node('profile-password-live').textContent).toBe('')

    fireEvent.change(node('profile-password-code'), {
      target: { value: '000000' },
    })
    const refused = hold('profile_change_password_code_confirm')
    await act(async () => {
      fireEvent.click(node('profile-password-confirm-code'))
    })
    await act(async () => {
      refused.reject(
        new ActionError(
          'profile_change_password_code_confirm',
          'fail',
          'That code is not right',
        ),
      )
    })
    expect(node('profile-password-live').textContent).toBe(
      'That code is not right',
    )
    expect(calmText()).toBe('Sent to a@example.test')
    expect(node('profile-password-code')).not.toBeNull()
    flow.dispose()
  })

  it('draws the countdown on the send row and keeps it out of the calm region', async () => {
    const { flow, answers, node } = setup()
    answers.hilos_step_up_start = {
      required: false,
      purpose: 'change your password',
    }
    answers.profile_change_password_code_request = SENT_RESEND_LATER
    await act(async () => {
      await flow.open()
    })
    await act(async () => {
      fireEvent.click(node('profile-password-send-code'))
    })
    tellSend(sendLine(PASSWORD_CHANGE_OPERATION, 'own', CODE_SEND_STATE_SENT))

    const row = node('profile-password-send')
    expect(row.textContent).toContain('Sent to a@example.test')
    expect(row.textContent).toContain('Send again in')
    expect(calmText()).toBe('Sent to a@example.test')
    expect(calmText()).not.toContain('Send again in')
    flow.dispose()
  })

  it('leaves the line of a closed window behind and says the next attempt from a new region', async () => {
    const { flow, answers, node } = setup()
    answers.hilos_step_up_start = {
      required: false,
      purpose: 'change your password',
    }
    await act(async () => {
      await flow.open()
    })
    await act(async () => {
      await flow.sendCode()
    })
    const first = node('profile-password-live-polite')
    tellSend(
      sendLine(PASSWORD_CHANGE_OPERATION, 'closed', CODE_SEND_STATE_SENT),
    )
    expect(calmText()).toBe('Sent to a@example.test')

    await act(async () => {
      flow.close()
      tell(null)
    })
    expect(node('modal')).toBeNull()

    await act(async () => {
      await flow.open()
    })
    expect(node('profile-password-live-polite')).not.toBe(first)
    expect(calmText()).toBe('')
    await act(async () => {
      await flow.sendCode()
    })
    expect(node('profile-password-code')).not.toBeNull()
    expect(calmText()).toBe('')
    tellSend(
      sendLine(PASSWORD_CHANGE_OPERATION, 'again', CODE_SEND_STATE_QUEUED),
    )
    expect(calmText()).toBe('Queued for sending…')
    flow.dispose()
  })

  it.each([
    {
      method: 'sms_code',
      destination: '+15550001111',
      said: 'Sent to +15550001111',
    },
    { method: 'second_factor', destination: undefined, said: '' },
  ])(
    'says the confirmation line of a $method step only when a code was sent',
    async ({ method, destination, said }) => {
      const { flow, answers, node } = setup()
      answers.hilos_step_up_start = {
        required: true,
        purpose: 'change your password',
        method,
        destination,
      }
      await act(async () => {
        await flow.open()
      })
      const calm = node('profile-password-live-polite')
      tellSend(sendLine(PASSWORD_CHANGE_OPERATION, 'own', CODE_SEND_STATE_SENT))
      expect(calmText()).toBe('')

      tellSend(
        sendLine(
          CODE_SEND_PURPOSE_STEP_UP,
          'confirmation',
          CODE_SEND_STATE_SENT,
        ),
      )
      expect(node('profile-password-live-polite')).toBe(calm)
      expect(calmText()).toBe(said)
      expect(
        selfOrInside(
          node('step-up'),
          '[role="status"], [role="alert"], [aria-live]',
        ),
      ).toBeNull()
      flow.dispose()
    },
  )
})
