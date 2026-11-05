import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import {
  ActionError,
  AUTH_CODE_REASON_RATE_LIMITED,
  bindCodeSendProgress,
  bindProfileFlows,
  CODE_SEND_PURPOSE_STEP_UP,
  CODE_SEND_STATE_FAILED,
  CODE_SEND_STATE_HELD,
  CODE_SEND_STATE_NOT_SENT,
  CODE_SEND_STATE_QUEUED,
  CODE_SEND_STATE_SENDING,
  CODE_SEND_STATE_SENT,
  createHilosProfileEmailChangeFlow,
  EMAIL_CHANGE_OPERATION,
  SIGNAL_CODE_SEND_PROGRESS,
  SIGNAL_PROFILE_FLOWS,
  type ActionLifecycle,
  type HilosConnection,
  type ProjectSignal,
} from '@hilos/core'
import { HilosProfileEmailChange } from '../src/profile/HilosProfileEmailChange.js'

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
 * One frame of the line a send of this window follows.
 *
 * @param ticket The send the line follows.
 * @param state The step the send reached.
 * @param more The provider's detail or the gate's reason, when the frame carries one.
 */
function windowLine(
  ticket: string,
  state: string,
  more: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    state,
    channel: 'email',
    purpose: EMAIL_CHANGE_OPERATION,
    ticket,
    ...more,
  }
}

/**
 * One frame of the line the confirmation's own send follows.
 *
 * @param state The step the send reached.
 */
function confirmationLine(state: string): Record<string, unknown> {
  return {
    state,
    channel: 'email',
    purpose: CODE_SEND_PURPOSE_STEP_UP,
    ticket: 'confirmation',
  }
}

/** What the dialog's calm region says now. */
function calmText(): string | null {
  return document.querySelector('[data-id="profile-email-live-polite"]')!
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

  it('says every turn of the send through one calm region standing in the dialog before the first send', async () => {
    const { flow, answers, node, hold } = setup()
    answers.hilos_step_up_start = {
      required: false,
      purpose: 'change your email',
    }
    answers.profile_change_email_current_request = SENT_RESEND_LATER
    await act(async () => {
      await flow.open('old@example.test')
    })
    const calm = node('profile-email-live-polite')
    expect(calm.getAttribute('role')).toBe('status')
    expect(calm.getAttribute('aria-live')).toBe('polite')
    expect(calm.closest('[aria-modal="true"]')).not.toBeNull()
    expect(calmText()).toBe('')

    await act(async () => {
      fireEvent.click(node('profile-email-send-current'))
    })
    for (const [frame, said] of [
      [windowLine('first', CODE_SEND_STATE_QUEUED), 'Queued for sending…'],
      [
        windowLine('first', CODE_SEND_STATE_SENDING),
        'Sending to old@example.test…',
      ],
      [windowLine('first', CODE_SEND_STATE_SENT), 'Sent to old@example.test'],
      [
        windowLine('first', CODE_SEND_STATE_FAILED, {
          detail: 'mailbox unavailable',
        }),
        'Could not send: mailbox unavailable',
      ],
      [
        windowLine('first', CODE_SEND_STATE_HELD),
        'The last code sent to old@example.test was already used',
      ],
      [
        windowLine('first', CODE_SEND_STATE_NOT_SENT),
        'Not really sent to old@example.test — letters are written here, not mailed',
      ],
      [
        windowLine('first', CODE_SEND_STATE_SENT, {
          reason: AUTH_CODE_REASON_RATE_LIMITED,
        }),
        'Sent to old@example.test earlier — that code still works',
      ],
    ] as const) {
      tellSend(frame)
      expect(node('profile-email-live-polite')).toBe(calm)
      expect(calmText()).toBe(said)
    }

    // The countdown and the line show on the row, which has no voice of its own.
    const row = node('profile-email-current-send')
    expect(row.textContent).toContain('Send again in')
    expect(row.textContent).toContain(
      'Sent to old@example.test earlier — that code still works',
    )
    expect(selfOrInside(row, '[role], [aria-live]')).toBeNull()

    // A refusal interrupts through its own region; the send line stays calm.
    fireEvent.change(node('profile-email-code-current'), {
      target: { value: '000000' },
    })
    const refused = hold('profile_change_email_current_confirm')
    await act(async () => {
      fireEvent.click(node('profile-email-confirm-current'))
    })
    await act(async () => {
      refused.reject(
        new ActionError(
          'profile_change_email_current_confirm',
          'fail',
          'That code is not right',
        ),
      )
    })
    expect(node('profile-email-live-assertive').textContent).toBe(
      'That code is not right',
    )
    expect(node('profile-email-live-polite')).toBe(calm)
    expect(calmText()).toBe(
      'Sent to old@example.test earlier — that code still works',
    )
    flow.dispose()
  })

  it('says the line to the address of each code step and falls silent between them', async () => {
    const { flow, answers, node, hold } = setup()
    answers.hilos_step_up_start = {
      required: false,
      purpose: 'change your email',
    }
    await act(async () => {
      await flow.open('old@example.test')
    })
    const calm = node('profile-email-live-polite')
    await act(async () => {
      fireEvent.click(node('profile-email-send-current'))
    })
    tellSend(windowLine('current', CODE_SEND_STATE_SENT))
    expect(calmText()).toBe('Sent to old@example.test')

    fireEvent.change(node('profile-email-code-current'), {
      target: { value: '111111' },
    })
    const proving = hold('profile_change_email_current_confirm')
    await act(async () => {
      fireEvent.click(node('profile-email-confirm-current'))
    })
    await act(async () => {
      tell('current_proven')
      proving.resolve()
    })
    expect(node('profile-email-new')).not.toBeNull()
    expect(node('profile-email-live-polite')).toBe(calm)
    expect(calmText()).toBe('')

    fireEvent.change(node('profile-email-new'), {
      target: { value: ' New@Example.test ' },
    })
    await act(async () => {
      fireEvent.click(node('profile-email-send-new'))
    })
    tellSend(windowLine('new', CODE_SEND_STATE_SENDING))
    expect(node('profile-email-live-polite')).toBe(calm)
    expect(calmText()).toBe('Sending to new@example.test…')
    expect(node('profile-email-new-send').textContent).toContain(
      'Sending to new@example.test…',
    )

    // The confirmation's line belongs to another step: the window's code step
    // does not take it up.
    tellSend(confirmationLine(CODE_SEND_STATE_SENT))
    expect(calmText()).toBe('')
    flow.dispose()
  })

  it('leaves the line of a closed window behind and says the next attempt from a new region', async () => {
    const { flow, answers, node } = setup()
    answers.hilos_step_up_start = {
      required: false,
      purpose: 'change your email',
    }
    await act(async () => {
      await flow.open('old@example.test')
    })
    const first = node('profile-email-live-polite')
    await act(async () => {
      fireEvent.click(node('profile-email-send-current'))
    })
    tellSend(windowLine('closed', CODE_SEND_STATE_SENT))
    expect(calmText()).toBe('Sent to old@example.test')

    await act(async () => {
      flow.close()
      tell(null)
    })
    expect(node('modal')).toBeNull()

    await act(async () => {
      await flow.open('old@example.test')
    })
    expect(node('profile-email-live-polite')).not.toBe(first)
    expect(calmText()).toBe('')
    await act(async () => {
      fireEvent.click(node('profile-email-send-current'))
    })
    tellSend(windowLine('again', CODE_SEND_STATE_QUEUED))
    expect(calmText()).toBe('Queued for sending…')
    flow.dispose()
  })

  it.each([
    {
      method: 'email_code',
      destination: 'person@example.test',
      said: 'The last code sent to person@example.test was already used',
    },
    {
      method: 'sms_code',
      destination: '+15550001111',
      said: 'The last code sent to +15550001111 was already used',
    },
    { method: 'password', destination: undefined, said: '' },
    { method: 'passkey', destination: undefined, said: '' },
    { method: 'second_factor', destination: undefined, said: '' },
  ])(
    'says the confirmation line only for a sent $method code, to its destination',
    async ({ method, destination, said }) => {
      const { flow, answers, node } = setup()
      answers.hilos_step_up_start = {
        required: true,
        purpose: 'change your email',
        method,
        destination,
      }
      await act(async () => {
        await flow.open('old@example.test')
      })
      const calm = node('profile-email-live-polite')
      expect(calmText()).toBe('')

      // The window's own line is not the confirmation's.
      tellSend(windowLine('own', CODE_SEND_STATE_SENT))
      expect(calmText()).toBe('')

      tellSend(confirmationLine(CODE_SEND_STATE_HELD))
      expect(node('profile-email-live-polite')).toBe(calm)
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

  it('keeps a refused confirmation in the alert and says no line without an opening', async () => {
    const { flow, node, hold } = setup()
    const refused = hold('hilos_step_up_start')
    let opened!: Promise<void>
    await act(async () => {
      opened = flow.open('old@example.test')
    })
    await act(async () => {
      refused.reject(
        new ActionError('hilos_step_up_start', 'fail', 'Too many attempts'),
      )
      await opened
    })
    tellSend(confirmationLine(CODE_SEND_STATE_SENT))
    expect(node('profile-email-live-assertive').textContent).toBe(
      'Too many attempts',
    )
    expect(calmText()).toBe('')
    flow.dispose()
  })

  it('leaves the confirmation line behind once the window stands on its own code step', async () => {
    const { flow, answers, node } = setup()
    answers.hilos_step_up_start = {
      required: true,
      purpose: 'change your email',
      method: 'email_code',
      destination: 'person@example.test',
    }
    await act(async () => {
      await flow.open('old@example.test')
    })
    tellSend(confirmationLine(CODE_SEND_STATE_SENT))
    expect(calmText()).toBe('Sent to person@example.test')

    fireEvent.change(node('step-up-code'), { target: { value: '123456' } })
    await act(async () => {
      fireEvent.submit(node('profile-email-step-up'))
    })
    await act(async () => {
      fireEvent.click(node('profile-email-send-current'))
    })
    expect(node('profile-email-code-current')).not.toBeNull()
    expect(calmText()).toBe('')
    tellSend(windowLine('own', CODE_SEND_STATE_SENT))
    expect(calmText()).toBe('Sent to old@example.test')
    flow.dispose()
  })
})
