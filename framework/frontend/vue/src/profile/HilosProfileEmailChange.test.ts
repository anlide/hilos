// Covers what the email window's view owns (HIL-1169): the step list marking
// the step on screen, the address in the text of step one, a refusal kept on
// its step, the send block of each code step asking for another code, and the
// outcome naming what the address was and is now, the window held with its
// body locked while a step is out (HIL-1763), and the dialog's calm region
// saying the send line of the code step on screen (HIL-1810). The steps
// are the session's record (HIL-1182), so the fake server tells it before it
// answers, as the session holder does.
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
  profileFlowsSchema,
  SIGNAL_CODE_SEND_PROGRESS,
  SIGNAL_PROFILE_FLOWS,
  type ActionLifecycle,
  type HilosConnection,
} from '@hilos/core/base'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'

import HilosProfileEmailChange from './HilosProfileEmailChange.vue'

// The modal teleports to <body>, so assertions query the document.
afterEach(() => {
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
  tellSend({ state: null })
})
enableAutoUnmount(afterEach)

function byId(id: string): HTMLElement {
  const found = document.querySelector<HTMLElement>(`[data-id="${id}"]`)
  if (!found) throw new Error(`Missing ${id}`)
  return found
}

function typeInto(id: string, value: string): void {
  const field = byId(id) as HTMLInputElement
  field.value = value
  field.dispatchEvent(new Event('input'))
}

const listeners: ((signal: { type: string; data: unknown }) => void)[] = []
bindProfileFlows({
  on: (_event: string, listener: (signal: never) => void) => {
    listeners.push(
      listener as (signal: { type: string; data: unknown }) => void,
    )
    return () => undefined
  },
} as unknown as HilosConnection)

/** A code request's answer whose resend gate is already open. */
const SENT_RESEND_OPEN = {
  sent: true,
  resendAt: Date.now() - 60_000,
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
  for (const listener of sendListeners)
    listener({
      type: SIGNAL_CODE_SEND_PROGRESS,
      data: { message: null, ...line },
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

/** What the dialog's calm region says now. */
function calmText(): string | null {
  return byId('profile-email-live-polite').textContent
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

/** Tell the session's record of the email change, as the frame carries it. */
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
      type: SIGNAL_PROFILE_FLOWS,
      data: profileFlowsSchema.parse({ flows }),
    })
}

/** What the server tells the session when a step lands, before it answers the step. */
function frameAfter(action: string, payload: unknown): void {
  if (action === 'profile_change_email_current_request') tell('current_sent')
  if (action === 'profile_change_email_current_confirm') tell('current_proven')
  if (action === 'profile_change_email_new_request')
    tell('new_sent', (payload as { email: string }).email.toLowerCase())
  if (action === 'profile_change_email_new_confirm') tell(null)
}

// The fieldset the modal stands its body in; a request in flight disables it.
function bodyLock(): HTMLFieldSetElement | null {
  return document.querySelector('[data-id="modal"] .modal-body > fieldset')
}

// Every way a person closes the window: the ×, Cancel, Escape and the backdrop.
function tryClosing(): void {
  byId('modal-close').click()
  byId('profile-email-cancel').click()
  byId('modal').dispatchEvent(
    new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
  )
  byId('modal').click()
}

/**
 * A mounted window whose server answers each action from the table; a string refuses.
 *
 * @param answers What each action answers.
 * @param hold Actions whose reply stays in flight until the test settles or refuses it.
 */
function setup(answers: Record<string, unknown> = {}, hold: string[] = []) {
  tell(null)
  const dispatched: Array<{ action: string; payload?: unknown }> = []
  const held: Array<{ settle(): void; refuse(reason: string): void }> = []
  const table: Record<string, unknown> = {
    hilos_step_up_start: { required: false, purpose: 'change your email' },
    ...answers,
  }
  const flow = createHilosProfileEmailChangeFlow({
    actions: {
      dispatch: (action: string, payload?: unknown) => {
        dispatched.push({ action, payload })
        if (hold.includes(action))
          return {
            done: new Promise((resolve, reject) =>
              held.push({
                settle: () => {
                  frameAfter(action, payload)
                  resolve({ reply: table[action] ?? [] })
                },
                refuse: (reason) =>
                  reject(new ActionError(action, 'fail', reason)),
              }),
            ),
          }
        if (typeof table[action] === 'string')
          return {
            done: Promise.reject(
              new ActionError(action, 'fail', table[action]),
            ),
          }
        frameAfter(action, payload)
        return { done: Promise.resolve({ reply: table[action] ?? [] }) }
      },
    } as unknown as ActionLifecycle,
  })
  mount(HilosProfileEmailChange, { props: { flow }, attachTo: document.body })
  return { flow, dispatched, held }
}

async function settle(): Promise<void> {
  await flushPromises()
  await nextTick()
}

describe('HilosProfileEmailChange', () => {
  it('walks the steps with the current one marked and names both addresses at the end', async () => {
    const { flow } = setup()
    await flow.open('old@example.test')
    await settle()
    expect(
      byId('profile-email-steps').querySelector('[aria-current="step"]')
        ?.textContent,
    ).toContain('Code to your current address')
    expect(byId('profile-email-send-current')).toBeTruthy()
    expect(document.body.textContent).toContain(
      'We will send a code to old@example.test to make sure it is you.',
    )

    byId('profile-email-send-current').click()
    await settle()
    typeInto('profile-email-code-current', '111111')
    await nextTick()
    byId('profile-email-confirm-current').click()
    await settle()
    typeInto('profile-email-new', 'New@Example.test')
    await nextTick()
    byId('profile-email-send-new').click()
    await settle()
    expect(
      byId('profile-email-steps').querySelector('[aria-current="step"]')
        ?.textContent,
    ).toContain('Code from the new address')
    typeInto('profile-email-code-new', '222222')
    await nextTick()
    byId('profile-email-confirm-new').click()
    await settle()

    expect(byId('profile-email-was').textContent).toBe('old@example.test')
    expect(byId('profile-email-now').textContent).toBe('new@example.test')
    expect(byId('profile-email-outcome').textContent).toContain(
      'A notice went to both.',
    )
  })

  it('keeps a refusal on its step with the typed code', async () => {
    const { flow } = setup({
      profile_change_email_current_confirm: 'That code is not right',
    })
    await flow.open('old@example.test')
    await settle()
    byId('profile-email-send-current').click()
    await settle()
    typeInto('profile-email-code-current', '000000')
    await nextTick()
    byId('profile-email-confirm-current').click()
    await settle()

    expect(byId('profile-email-error').textContent).toContain(
      'That code is not right',
    )
    expect((byId('profile-email-code-current') as HTMLInputElement).value).toBe(
      '000000',
    )
  })

  it('holds the window and locks the code while its check is out, and frees it after a refusal', async () => {
    const { flow, held } = setup({}, ['profile_change_email_current_confirm'])
    await flow.open('old@example.test')
    await settle()
    byId('profile-email-send-current').click()
    await settle()
    typeInto('profile-email-code-current', '000000')
    await nextTick()
    const field = byId('profile-email-code-current') as HTMLInputElement
    const body = bodyLock()
    const cancel = byId('profile-email-cancel') as HTMLButtonElement
    const close = byId('modal-close') as HTMLButtonElement
    expect(body?.contains(field)).toBe(true)
    expect(body?.disabled).toBe(false)
    expect(cancel.disabled).toBe(false)

    byId('profile-email-confirm-current').click()
    await settle()
    expect(held).toHaveLength(1)
    expect(body?.disabled).toBe(true)
    expect(cancel.disabled).toBe(true)
    expect(close.disabled).toBe(true)
    tryClosing()
    await settle()
    expect(document.querySelector('[data-id="modal"]')).not.toBeNull()
    expect(document.querySelector('[data-id="modal-confirm"]')).toBeNull()

    held[0]!.refuse('That code is not right')
    await settle()
    expect(byId('profile-email-code-current')).toBe(field)
    expect(field.value).toBe('000000')
    expect(body?.disabled).toBe(false)
    expect(cancel.disabled).toBe(false)
    expect(close.disabled).toBe(false)
    expect(byId('profile-email-error').textContent).toContain(
      'That code is not right',
    )

    typeInto('profile-email-code-current', '111111')
    await nextTick()
    byId('profile-email-confirm-current').click()
    await settle()
    held[1]!.settle()
    await settle()
    expect(byId('profile-email-new')).toBeDefined()
  })

  it('draws the send block on both code steps and sends again from each', async () => {
    const { flow } = setup({
      profile_change_email_current_request: SENT_RESEND_OPEN,
      profile_change_email_new_request: SENT_RESEND_OPEN,
    })
    const sendAgain = vi.spyOn(flow, 'sendAgain')
    await flow.open('old@example.test')
    await settle()
    byId('profile-email-send-current').click()
    await settle()
    expect(byId('profile-email-current-send')).toBeDefined()
    byId('profile-email-current-send-again').click()
    await settle()
    expect(sendAgain).toHaveBeenCalledTimes(1)

    typeInto('profile-email-code-current', '111111')
    await nextTick()
    byId('profile-email-confirm-current').click()
    await settle()
    typeInto('profile-email-new', 'new@example.test')
    await nextTick()
    byId('profile-email-send-new').click()
    await settle()
    expect(byId('profile-email-new-send')).toBeDefined()
    byId('profile-email-new-send-again').click()
    await settle()
    expect(sendAgain).toHaveBeenCalledTimes(2)
  })

  it('confirms step-up via form submit when credential is typed, ignores empty submit, and binds confirm button to form', async () => {
    const { flow, dispatched } = setup({
      hilos_step_up_start: {
        required: true,
        purpose: 'change your email',
        method: 'password',
      },
    })
    await flow.open('old@example.test')
    await settle()

    const form = byId('profile-email-step-up')
    const confirm = byId('profile-email-step-up-confirm')
    expect(confirm.getAttribute('type')).toBe('submit')
    expect(confirm.getAttribute('form')).toBe(form.id)

    form.dispatchEvent(new Event('submit', { cancelable: true }))
    await settle()
    expect(dispatched.some((d) => d.action === 'hilos_step_up_confirm')).toBe(
      false,
    )

    typeInto('step-up-password', 'secret')
    form.dispatchEvent(new Event('submit', { cancelable: true }))
    await settle()

    const call = dispatched.find((d) => d.action === 'hilos_step_up_confirm')
    expect(call).toBeDefined()
    expect(call?.payload).toMatchObject({
      password: 'secret',
    })
  })

  it('says every turn of the send through one calm region standing in the dialog before the first send', async () => {
    const { flow } = setup({
      profile_change_email_current_request: SENT_RESEND_LATER,
      profile_change_email_current_confirm: 'That code is not right',
    })
    await flow.open('old@example.test')
    await settle()
    const calm = byId('profile-email-live-polite')
    expect(calm.getAttribute('role')).toBe('status')
    expect(calm.getAttribute('aria-live')).toBe('polite')
    expect(calm.closest('[aria-modal="true"]')).not.toBeNull()
    expect(calmText()).toBe('')

    byId('profile-email-send-current').click()
    await settle()
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
      await nextTick()
      expect(byId('profile-email-live-polite')).toBe(calm)
      expect(calmText()).toBe(said)
    }

    // The countdown and the line show on the row, which has no voice of its own.
    const row = byId('profile-email-current-send')
    expect(row.textContent).toContain('Send again in')
    expect(row.textContent).toContain(
      'Sent to old@example.test earlier — that code still works',
    )
    expect(selfOrInside(row, '[role], [aria-live]')).toBeNull()

    // A refusal interrupts through its own region; the send line stays calm.
    typeInto('profile-email-code-current', '000000')
    await nextTick()
    byId('profile-email-confirm-current').click()
    await settle()
    expect(byId('profile-email-live-assertive').textContent).toContain(
      'That code is not right',
    )
    expect(calmText()).toBe(
      'Sent to old@example.test earlier — that code still works',
    )
  })

  it('says the line to the address of each code step and falls silent between them', async () => {
    const { flow } = setup({
      profile_change_email_current_request: SENT_RESEND_OPEN,
      profile_change_email_new_request: SENT_RESEND_OPEN,
    })
    await flow.open('old@example.test')
    await settle()
    const calm = byId('profile-email-live-polite')
    byId('profile-email-send-current').click()
    await settle()
    tellSend(windowLine('current', CODE_SEND_STATE_SENT))
    await nextTick()
    expect(calmText()).toBe('Sent to old@example.test')

    typeInto('profile-email-code-current', '111111')
    await nextTick()
    byId('profile-email-confirm-current').click()
    await settle()
    expect(byId('profile-email-new')).toBeDefined()
    expect(byId('profile-email-live-polite')).toBe(calm)
    expect(calmText()).toBe('')

    typeInto('profile-email-new', ' New@Example.test ')
    await nextTick()
    byId('profile-email-send-new').click()
    await settle()
    tellSend(windowLine('new', CODE_SEND_STATE_SENDING))
    await nextTick()
    expect(byId('profile-email-live-polite')).toBe(calm)
    expect(calmText()).toBe('Sending to new@example.test…')
    expect(byId('profile-email-new-send').textContent).toContain(
      'Sending to new@example.test…',
    )

    // The confirmation's line belongs to another step: the window's code step
    // does not take it up.
    tellSend({
      state: CODE_SEND_STATE_SENT,
      channel: 'email',
      purpose: CODE_SEND_PURPOSE_STEP_UP,
      ticket: 'confirmation',
    })
    await nextTick()
    expect(calmText()).toBe('')
  })

  it('leaves the line of a closed window behind and says the next attempt from a new region', async () => {
    const { flow } = setup({
      profile_change_email_current_request: SENT_RESEND_OPEN,
    })
    await flow.open('old@example.test')
    await settle()
    const first = byId('profile-email-live-polite')
    byId('profile-email-send-current').click()
    await settle()
    tellSend(windowLine('closed', CODE_SEND_STATE_SENT))
    await nextTick()
    expect(calmText()).toBe('Sent to old@example.test')

    flow.close()
    await settle()
    tell(null)
    await settle()
    expect(document.querySelector('[data-id="modal"]')).toBeNull()

    await flow.open('old@example.test')
    await settle()
    expect(byId('profile-email-live-polite')).not.toBe(first)
    expect(calmText()).toBe('')
    byId('profile-email-send-current').click()
    await settle()
    tellSend(windowLine('again', CODE_SEND_STATE_QUEUED))
    await nextTick()
    expect(calmText()).toBe('Queued for sending…')
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
      const { flow } = setup({
        hilos_step_up_start: {
          required: true,
          purpose: 'change your email',
          method,
          destination,
        },
      })
      await flow.open('old@example.test')
      await settle()
      const calm = byId('profile-email-live-polite')
      expect(calmText()).toBe('')

      // The window's own line is not the confirmation's.
      tellSend(windowLine('own', CODE_SEND_STATE_SENT))
      await nextTick()
      expect(calmText()).toBe('')

      tellSend({
        state: CODE_SEND_STATE_HELD,
        channel: 'email',
        purpose: CODE_SEND_PURPOSE_STEP_UP,
        ticket: 'confirmation',
      })
      await nextTick()
      expect(byId('profile-email-live-polite')).toBe(calm)
      expect(calmText()).toBe(said)
      expect(
        selfOrInside(
          byId('step-up'),
          '[role="status"], [role="alert"], [aria-live]',
        ),
      ).toBeNull()
    },
  )

  it('keeps a refused confirmation in the alert and says no line without an opening', async () => {
    const { flow } = setup({ hilos_step_up_start: 'Too many attempts' })
    await flow.open('old@example.test')
    await settle()
    tellSend({
      state: CODE_SEND_STATE_SENT,
      channel: 'email',
      purpose: CODE_SEND_PURPOSE_STEP_UP,
      ticket: 'confirmation',
    })
    await nextTick()
    expect(byId('profile-email-live-assertive').textContent).toContain(
      'Too many attempts',
    )
    expect(calmText()).toBe('')
  })

  it('leaves the confirmation line behind once the window stands on its own code step', async () => {
    const { flow } = setup({
      hilos_step_up_start: {
        required: true,
        purpose: 'change your email',
        method: 'email_code',
        destination: 'person@example.test',
      },
      profile_change_email_current_request: SENT_RESEND_OPEN,
    })
    await flow.open('old@example.test')
    await settle()
    tellSend({
      state: CODE_SEND_STATE_SENT,
      channel: 'email',
      purpose: CODE_SEND_PURPOSE_STEP_UP,
      ticket: 'confirmation',
    })
    await nextTick()
    expect(calmText()).toBe('Sent to person@example.test')

    typeInto('step-up-code', '123456')
    byId('profile-email-step-up').dispatchEvent(
      new Event('submit', { cancelable: true }),
    )
    await settle()
    byId('profile-email-send-current').click()
    await settle()
    expect(byId('profile-email-code-current')).toBeDefined()
    expect(calmText()).toBe('')
    tellSend(windowLine('own', CODE_SEND_STATE_SENT))
    await nextTick()
    expect(calmText()).toBe('Sent to old@example.test')
  })
})
