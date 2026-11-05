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
  profileFlowsSchema,
  SIGNAL_CODE_SEND_PROGRESS,
  SIGNAL_PROFILE_FLOWS,
  type ActionLifecycle,
  type HilosConnection,
} from '@hilos/core/base'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import HilosProfilePasswordChange from './HilosProfilePasswordChange.vue'

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
const listeners: ((signal: { type: string; data: unknown }) => void)[] = []
bindProfileFlows({
  on: (_event: string, listener: (signal: never) => void) => {
    listeners.push(
      listener as (signal: { type: string; data: unknown }) => void,
    )
    return () => undefined
  },
} as unknown as HilosConnection)

/**
 * Tell the session's record of the password change, as the frame carries it -
 * the steps are the session's (HIL-1182), told before the step is answered.
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
      type: SIGNAL_PROFILE_FLOWS,
      data: profileFlowsSchema.parse({ flows }),
    })
}

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
  return byId('profile-password-live-polite').textContent
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

const FRAME_AFTER: Record<string, string | null> = {
  profile_change_password_code_request: 'code_sent',
  profile_change_password_code_confirm: 'code_proven',
  profile_change_password: null,
}

// The fieldset the modal stands its body in; a request in flight disables it.
function bodyLock(): HTMLFieldSetElement | null {
  return document.querySelector('[data-id="modal"] .modal-body > fieldset')
}

// Every way a person closes the window: the ×, Cancel, Escape and the backdrop.
function tryClosing(): void {
  byId('modal-close').click()
  byId('profile-password-cancel').click()
  byId('modal').dispatchEvent(
    new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
  )
  byId('modal').click()
}

/**
 * A mounted window whose server answers from `answers`; a string refuses.
 *
 * @param hold Actions whose reply stays in flight until the test settles or refuses it.
 */
function setup(hold: string[] = []) {
  tell(null)
  const dispatched: Array<{ name: string; payload?: unknown }> = []
  const held: Array<{ settle(): void; refuse(reason: string): void }> = []
  const answers: Record<string, unknown> = {
    hilos_step_up_start: { required: false, purpose: 'change your password' },
    profile_change_password_open: {
      channel: 'email',
      destination: 'a@example.test',
    },
  }
  const flow = createHilosProfilePasswordChangeFlow({
    actions: {
      dispatch: (name: string, payload?: unknown) => {
        dispatched.push({ name, payload })
        if (hold.includes(name))
          return {
            done: new Promise((resolve, reject) =>
              held.push({
                settle: () => {
                  if (name in FRAME_AFTER) tell(FRAME_AFTER[name] ?? null)
                  resolve({ reply: answers[name] ?? [] })
                },
                refuse: (reason) =>
                  reject(new ActionError(name, 'fail', reason)),
              }),
            ),
          }
        if (typeof answers[name] === 'string')
          return {
            done: Promise.reject(new ActionError(name, 'fail', answers[name])),
          }
        if (name in FRAME_AFTER) tell(FRAME_AFTER[name] ?? null)
        return { done: Promise.resolve({ reply: answers[name] ?? [] }) }
      },
    } as unknown as ActionLifecycle,
  })
  mount(HilosProfilePasswordChange, {
    props: { flow },
    attachTo: document.body,
  })
  return { flow, answers, dispatched, held }
}
describe('password change modal', () => {
  it.each([true, false])(
    'focuses each field and names the saved session choice %s',
    async (signOutOthers) => {
      const { flow } = setup()
      await flow.open()
      await flushPromises()
      expect(byId('profile-password-destination').textContent).toContain(
        'a@example.test',
      )
      expect(byId('profile-password-error-slot')).toBeDefined()
      byId('profile-password-send-code').click()
      await flushPromises()
      expect(document.activeElement).toBe(byId('profile-password-code'))
      flow.code.set('123456')
      await flushPromises()
      byId('profile-password-confirm-code').click()
      await flushPromises()
      expect(document.activeElement).toBe(byId('profile-password-new'))
      expect(
        (byId('profile-password-sign-out-others') as HTMLInputElement).checked,
      ).toBe(true)
      flow.newPassword.set('new-secret')
      flow.signOutOthers.set(signOutOthers)
      await flushPromises()
      byId('profile-password-save').click()
      await flushPromises()
      expect(byId('profile-password-outcome-sessions').textContent).toContain(
        signOutOthers
          ? 'Other sessions were signed out.'
          : 'Other sessions stay signed in.',
      )
      expect(byId('profile-password-outcome').textContent).toContain(
        'Password reset codes, if any were sent, no longer work.',
      )
    },
  )
  it('keeps the outside refusal slot through operation confirmation', async () => {
    const { flow, answers } = setup()
    answers.hilos_step_up_start = {
      required: true,
      purpose: 'change your password',
      method: 'password',
    }
    await flow.open()
    await flushPromises()
    const slot = byId('profile-password-error-slot')
    flow.stepUp.password.set('current-secret')
    await flow.confirmStepUp()
    await flushPromises()
    expect(byId('profile-password-error-slot')).toBe(slot)
  })
  it('keeps the password field and uses the permanent live region for a refusal', async () => {
    const { flow, answers } = setup()
    await flow.open()
    await flow.sendCode()
    flow.code.set('123456')
    await flow.confirmCode()
    flow.newPassword.set('short')
    answers.profile_change_password = 'Password is too short'
    await flow.save()
    await flushPromises()
    expect(byId('profile-password-error').textContent).toContain(
      'Password is too short',
    )
    expect(byId('profile-password-live').textContent).toBe(
      'Password is too short',
    )
    expect((byId('profile-password-new') as HTMLInputElement).value).toBe(
      'short',
    )
    expect(byId('profile-password-error-slot')).toBeDefined()
  })
  it('holds the window and locks the new password while it is saved, and frees it after a refusal', async () => {
    const { flow, held } = setup(['profile_change_password'])
    await flow.open()
    await flow.sendCode()
    flow.code.set('123456')
    await flow.confirmCode()
    flow.newPassword.set('short')
    await flushPromises()
    const field = byId('profile-password-new') as HTMLInputElement
    const body = bodyLock()
    const cancel = byId('profile-password-cancel') as HTMLButtonElement
    const close = byId('modal-close') as HTMLButtonElement
    expect(body?.contains(field)).toBe(true)
    expect(body?.disabled).toBe(false)
    expect(cancel.disabled).toBe(false)

    byId('profile-password-save').click()
    await flushPromises()
    expect(held).toHaveLength(1)
    expect(body?.disabled).toBe(true)
    expect(cancel.disabled).toBe(true)
    expect(close.disabled).toBe(true)
    tryClosing()
    await flushPromises()
    expect(document.querySelector('[data-id="modal"]')).not.toBeNull()
    expect(document.querySelector('[data-id="modal-confirm"]')).toBeNull()

    held[0]!.refuse('Password is too short')
    await flushPromises()
    expect(byId('profile-password-new')).toBe(field)
    expect(field.value).toBe('short')
    expect(body?.disabled).toBe(false)
    expect(cancel.disabled).toBe(false)
    expect(close.disabled).toBe(false)
    expect(byId('profile-password-error').textContent).toContain(
      'Password is too short',
    )

    flow.newPassword.set('new-secret')
    await flushPromises()
    byId('profile-password-save').click()
    await flushPromises()
    held[1]!.settle()
    await flushPromises()
    expect(byId('profile-password-outcome')).toBeDefined()
  })
  it('draws the send block on the code step and sends again from it', async () => {
    const { flow, answers } = setup()
    answers.profile_change_password_code_request = SENT_RESEND_OPEN
    await flow.open()
    await flow.sendCode()
    await flushPromises()
    expect(byId('profile-password-send')).toBeDefined()
    expect(byId('profile-password-code').hasAttribute('aria-describedby')).toBe(
      false,
    )
    const sendAgain = vi.spyOn(flow, 'sendAgain')
    const again = byId('profile-password-send-again') as HTMLButtonElement
    expect(again.disabled).toBe(false)
    again.click()
    await flushPromises()
    expect(sendAgain).toHaveBeenCalledOnce()
  })
  it('opens a step-up refusal with Cancel alone', async () => {
    const { flow, answers } = setup()
    answers.hilos_step_up_start =
      "This is not available while you work in someone else's account"
    await flow.open()
    await flushPromises()
    expect(byId('profile-password-error').textContent).toContain(
      'not available',
    )
    expect(
      document.querySelector('[data-id="profile-password-step-up-confirm"]'),
    ).toBeNull()
    byId('profile-password-cancel').click()
    await flushPromises()
    expect(flow.step.get()).toBe('closed')
  })
  it('confirms step-up via form submit when credential is typed, ignores empty submit, and binds confirm button to form', async () => {
    const { flow, answers, dispatched } = setup()
    answers.hilos_step_up_start = {
      required: true,
      purpose: 'change your password',
      method: 'password',
    }
    await flow.open()
    await flushPromises()

    const form = byId('profile-password-step-up')
    const confirm = byId('profile-password-step-up-confirm')
    expect(confirm.getAttribute('type')).toBe('submit')
    expect(confirm.getAttribute('form')).toBe(form.id)

    form.dispatchEvent(new Event('submit', { cancelable: true }))
    await flushPromises()
    expect(
      dispatched.some((item) => item.name === 'hilos_step_up_confirm'),
    ).toBe(false)

    flow.stepUp.password.set('current-secret')
    form.dispatchEvent(new Event('submit', { cancelable: true }))
    await flushPromises()
    const call = dispatched.find(
      (item) => item.name === 'hilos_step_up_confirm',
    )
    expect(call).toBeDefined()
    expect(call?.payload).toMatchObject({
      password: 'current-secret',
    })
  })
  it('says the code step line to the opening destination through a region that outlives the step', async () => {
    const { flow, answers } = setup()
    answers.profile_change_password_code_request = SENT_RESEND_OPEN
    await flow.open()
    await flushPromises()
    const calm = byId('profile-password-live-polite')
    expect(calm.getAttribute('role')).toBe('status')
    expect(calm.getAttribute('aria-live')).toBe('polite')
    expect(calm.closest('[aria-modal="true"]')).not.toBeNull()
    expect(calmText()).toBe('')

    byId('profile-password-send-code').click()
    await flushPromises()
    tellSend(sendLine(PASSWORD_CHANGE_OPERATION, 'own', CODE_SEND_STATE_SENT))
    await flushPromises()
    expect(byId('profile-password-live-polite')).toBe(calm)
    expect(calmText()).toBe('Sent to a@example.test')
    tellSend(
      sendLine(PASSWORD_CHANGE_OPERATION, 'own', CODE_SEND_STATE_FAILED, {
        detail: 'mailbox unavailable',
      }),
    )
    await flushPromises()
    expect(calmText()).toBe('Could not send: mailbox unavailable')
    expect(byId('profile-password-send').textContent).toContain(
      'Could not send: mailbox unavailable',
    )
    expect(
      selfOrInside(byId('profile-password-send'), '[role], [aria-live]'),
    ).toBeNull()

    // The confirmation's line is not this step's.
    tellSend(
      sendLine(CODE_SEND_PURPOSE_STEP_UP, 'confirmation', CODE_SEND_STATE_SENT),
    )
    await flushPromises()
    expect(calmText()).toBe('')
    tellSend(sendLine(PASSWORD_CHANGE_OPERATION, 'own', CODE_SEND_STATE_SENT))
    await flushPromises()

    flow.code.set('123456')
    await flushPromises()
    byId('profile-password-confirm-code').click()
    await flushPromises()
    expect(byId('profile-password-new')).toBeDefined()
    expect(byId('profile-password-live-polite')).toBe(calm)
    expect(calmText()).toBe('')
  })
  it('keeps a refused code in the alert and the send line in the calm region', async () => {
    const { flow, answers } = setup()
    answers.profile_change_password_code_request = SENT_RESEND_OPEN
    answers.profile_change_password_code_confirm = 'That code is not right'
    await flow.open()
    await flushPromises()
    byId('profile-password-send-code').click()
    await flushPromises()
    tellSend(sendLine(PASSWORD_CHANGE_OPERATION, 'own', CODE_SEND_STATE_SENT))
    await flushPromises()
    expect(calmText()).toBe('Sent to a@example.test')
    expect(byId('profile-password-live').textContent).toBe('')

    flow.code.set('000000')
    await flushPromises()
    byId('profile-password-confirm-code').click()
    await flushPromises()
    expect(byId('profile-password-live').textContent).toBe(
      'That code is not right',
    )
    expect(calmText()).toBe('Sent to a@example.test')
    expect(byId('profile-password-code')).toBeDefined()
  })
  it('draws the countdown on the send row and keeps it out of the calm region', async () => {
    const { flow, answers } = setup()
    answers.profile_change_password_code_request = SENT_RESEND_LATER
    await flow.open()
    await flushPromises()
    byId('profile-password-send-code').click()
    await flushPromises()
    tellSend(sendLine(PASSWORD_CHANGE_OPERATION, 'own', CODE_SEND_STATE_SENT))
    await flushPromises()

    const row = byId('profile-password-send')
    expect(row.textContent).toContain('Sent to a@example.test')
    expect(row.textContent).toContain('Send again in')
    expect(calmText()).toBe('Sent to a@example.test')
    expect(calmText()).not.toContain('Send again in')
  })
  it('leaves the line of a closed window behind and says the next attempt from a new region', async () => {
    const { flow, answers } = setup()
    answers.profile_change_password_code_request = SENT_RESEND_OPEN
    await flow.open()
    await flow.sendCode()
    await flushPromises()
    const first = byId('profile-password-live-polite')
    tellSend(
      sendLine(PASSWORD_CHANGE_OPERATION, 'closed', CODE_SEND_STATE_SENT),
    )
    await flushPromises()
    expect(calmText()).toBe('Sent to a@example.test')

    flow.close()
    await flushPromises()
    tell(null)
    await flushPromises()
    expect(document.querySelector('[data-id="modal"]')).toBeNull()

    await flow.open()
    await flushPromises()
    expect(byId('profile-password-live-polite')).not.toBe(first)
    expect(calmText()).toBe('')
    await flow.sendCode()
    await flushPromises()
    expect(byId('profile-password-code')).toBeDefined()
    expect(calmText()).toBe('')
    tellSend(
      sendLine(PASSWORD_CHANGE_OPERATION, 'again', CODE_SEND_STATE_QUEUED),
    )
    await flushPromises()
    expect(calmText()).toBe('Queued for sending…')
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
      const { flow, answers } = setup()
      answers.hilos_step_up_start = {
        required: true,
        purpose: 'change your password',
        method,
        destination,
      }
      await flow.open()
      await flushPromises()
      const calm = byId('profile-password-live-polite')
      tellSend(sendLine(PASSWORD_CHANGE_OPERATION, 'own', CODE_SEND_STATE_SENT))
      await flushPromises()
      expect(calmText()).toBe('')

      tellSend(
        sendLine(
          CODE_SEND_PURPOSE_STEP_UP,
          'confirmation',
          CODE_SEND_STATE_SENT,
        ),
      )
      await flushPromises()
      expect(byId('profile-password-live-polite')).toBe(calm)
      expect(calmText()).toBe(said)
      expect(
        selfOrInside(
          byId('step-up'),
          '[role="status"], [role="alert"], [aria-live]',
        ),
      ).toBeNull()
    },
  )
})
