import { By } from '@angular/platform-browser'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  ActionError,
  AUTH_CODE_REASON_RATE_LIMITED,
  bindCodeSendProgress,
  CODE_SEND_PURPOSE_STEP_UP,
  CODE_SEND_STATE_FAILED,
  CODE_SEND_STATE_HELD,
  CODE_SEND_STATE_NOT_SENT,
  CODE_SEND_STATE_QUEUED,
  CODE_SEND_STATE_SENDING,
  CODE_SEND_STATE_SENT,
  createConnectionInteractionState,
  bindProfileFlows,
  createHilosProfileEmailChangeFlow,
  createSignal,
  EMAIL_CHANGE_OPERATION,
  profileFlowsSchema,
  SIGNAL_CODE_SEND_PROGRESS,
  SIGNAL_PROFILE_FLOWS,
  type ActionLifecycle,
  type HilosConnection,
} from '@hilos/core/base'
import { type ModalController } from '@hilos/core/base'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { HilosProfileEmailChange } from '../src/profile/HilosProfileEmailChange.js'
import { HilosModal } from '../src/HilosModal.js'
import { HILOS_CONNECTION_INTERACTION } from '../src/hilosConnectionInteraction.js'

/** A send gate that reopened long ago: another code may be asked for at once. */
const PAST = 1_000_000_000_000
const SEND_REPLY = {
  sent: true,
  resendAt: PAST,
  expiresAt: 1_900_000_600_000,
}

/** A code request's answer whose resend gate opens in a minute. */
const SEND_REPLY_RESEND_LATER = {
  sent: true,
  resendAt: Date.now() + 60_000,
  expiresAt: 1_900_000_600_000,
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
 * The session's record of the email change at one step.
 *
 * @param step What has happened in the flow.
 * @param target The new address on the last step, null before it.
 */
function record(step: string, target: string | null = null) {
  return {
    operation: 'change_email',
    step,
    address: 'old@example.test',
    target,
  }
}

/**
 * Tell every tab the session's flows, as the frame carries them.
 *
 * @param flows The session's live flows, none for an ended one.
 */
function tell(flows: unknown[]): void {
  for (const listener of listeners)
    listener({
      type: SIGNAL_PROFILE_FLOWS,
      data: profileFlowsSchema.parse({ flows }),
    })
}

/**
 * Tell the session's send line as the server frames it, through the binding the
 * application boots with; the binding is let go once the frame is in, and a
 * null state takes the line away.
 *
 * @param line The frame's fields beside its message, which is none.
 * @param fixture The window to draw once the frame is in, when there is one.
 */
function tellSend(
  line: Record<string, unknown>,
  fixture?: ComponentFixture<unknown>,
): void {
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
  fixture?.detectChanges()
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

/** What the dialog's calm region says now, past the template's own spacing. */
function calmText(): string | undefined {
  return document
    .querySelector('[data-id="profile-email-live-polite"]')!
    .textContent?.trim()
}

/**
 * The block's element-host, or else its root, or else the first thing inside
 * either, that matches the selector: the host and the root of a block are the
 * likeliest places for a voice to return.
 *
 * @param block The root of a step-up or send block, drawn inside its host.
 * @param selector What a live voice looks like.
 */
function selfOrInside(block: Element, selector: string): Element | null {
  const host = block.closest('hilos-step-up-step, hilos-send-progress') ?? block
  return host.matches(selector) ? host : host.querySelector(selector)
}

/**
 * Type into a field of the window the way a person does, and draw the result.
 *
 * @param fixture The window under test.
 * @param field The field to type into.
 * @param value What is typed.
 */
function typeInto(
  fixture: ComponentFixture<unknown>,
  field: HTMLElement,
  value: string,
): void {
  const input = field as HTMLInputElement
  input.value = value
  input.dispatchEvent(new Event('input', { bubbles: true }))
  fixture.detectChanges()
}

afterEach(() => {
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
  tellSend({ state: null })
})

async function settle(fixture: ComponentFixture<unknown>): Promise<void> {
  await fixture.whenStable()
  fixture.detectChanges()
}

async function flush(fixture: ComponentFixture<unknown>): Promise<void> {
  for (let tick = 0; tick < 10; tick += 1) await Promise.resolve()
  fixture.detectChanges()
}

function held<T>(): {
  promise: Promise<T>
  reject: (reason: unknown) => void
  resolve: (value: T) => void
} {
  let resolve!: (value: T) => void
  let reject!: (reason: unknown) => void
  const promise = new Promise<T>((accept, refuse) => {
    resolve = accept
    reject = refuse
  })

  return { promise, reject, resolve }
}

function attemptClose(
  fixture: ComponentFixture<unknown>,
  node: (id: string) => HTMLElement,
  cancelId: string,
): void {
  node('modal-close').click()
  node(cancelId).click()
  node('modal').dispatchEvent(
    new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
  )
  node('modal').click()
  const modal = fixture.debugElement.query(By.directive(HilosModal))
    .componentInstance as unknown as { modal: ModalController }
  modal.modal.requestClose()
  fixture.detectChanges()
}

function setup() {
  tell([])
  // The frame the server tells before it answers the tab that sent the action.
  const frames: Record<string, unknown[]> = {}
  const dispatched: Array<{ action: string; payload?: unknown }> = []
  const answers: Record<string, unknown> = {
    hilos_step_up_start: {
      required: true,
      purpose: 'change your email',
      method: 'password',
    },
  }
  const flow = createHilosProfileEmailChangeFlow({
    actions: {
      dispatch: (action: string, payload?: unknown) => {
        dispatched.push({ action, payload })
        if (frames[action] !== undefined) tell(frames[action])
        const answer = answers[action]
        return {
          done:
            typeof answer === 'string'
              ? Promise.reject(new Error(answer))
              : answer instanceof Promise
                ? answer.then((reply) => ({ reply }))
                : Promise.resolve({ reply: answer ?? [] }),
        }
      },
    } as unknown as ActionLifecycle,
  })
  const fixture = TestBed.createComponent(HilosProfileEmailChange)
  fixture.componentRef.setInput('flow', flow)
  fixture.detectChanges()

  const node = (id: string) =>
    document.querySelector<HTMLElement>(`[data-id="${id}"]`)!

  return { flow, answers, frames, dispatched, fixture, node }
}

describe('HilosProfileEmailChange', () => {
  it('confirms step-up via form submit when credential is typed, ignores empty submit, and binds confirm button to form', async () => {
    const { flow, dispatched, fixture, node } = setup()
    await flow.open('old@example.test')
    await settle(fixture)

    const form = node('profile-email-step-up')
    const confirm = node('profile-email-step-up-confirm')

    expect(confirm.getAttribute('type')).toBe('submit')
    expect(confirm.getAttribute('form')).toBe(form.id)

    form.dispatchEvent(new Event('submit', { cancelable: true }))
    await settle(fixture)
    expect(dispatched.some((d) => d.action === 'hilos_step_up_confirm')).toBe(
      false,
    )

    const input = node('step-up-password') as HTMLInputElement
    input.value = 'secret'
    input.dispatchEvent(new Event('input', { bubbles: true }))
    await settle(fixture)

    form.dispatchEvent(new Event('submit', { cancelable: true }))
    await settle(fixture)

    const call = dispatched.find((d) => d.action === 'hilos_step_up_confirm')
    expect(call).toBeDefined()
    expect(call?.payload).toMatchObject({
      password: 'secret',
    })

    flow.dispose()
    fixture.destroy()
  })

  it('draws the send block on the current-address code step and asks the flow for another code from it', async () => {
    const { flow, answers, frames, fixture, node } = setup()
    answers.hilos_step_up_start = {
      required: false,
      purpose: 'change your email',
    }
    answers.profile_change_email_current_request = SEND_REPLY
    frames.profile_change_email_current_request = [record('current_sent')]
    await flow.open('old@example.test')
    await flow.submit()
    await settle(fixture)

    expect(node('profile-email-current-send')).not.toBeNull()
    const again = node('profile-email-current-send-again') as HTMLButtonElement
    expect(again.disabled).toBe(false)
    const sendAgain = vi.spyOn(flow, 'sendAgain')
    again.click()
    await settle(fixture)
    expect(sendAgain).toHaveBeenCalledOnce()

    flow.dispose()
    fixture.destroy()
  })

  it('draws the send block on the new-address code step and asks the flow for another code from it', async () => {
    const { flow, answers, frames, fixture, node } = setup()
    answers.hilos_step_up_start = {
      required: false,
      purpose: 'change your email',
    }
    answers.profile_change_email_new_request = SEND_REPLY
    frames.profile_change_email_new_request = [
      record('new_sent', 'new@example.test'),
    ]
    tell([record('current_proven')])
    await flow.open('old@example.test')
    flow.newEmail.set('new@example.test')
    await flow.submit()
    await settle(fixture)

    expect(node('profile-email-new-send')).not.toBeNull()
    const again = node('profile-email-new-send-again') as HTMLButtonElement
    expect(again.disabled).toBe(false)
    const sendAgain = vi.spyOn(flow, 'sendAgain')
    again.click()
    await settle(fixture)
    expect(sendAgain).toHaveBeenCalledOnce()

    flow.dispose()
    fixture.destroy()
  })

  it('locks the confirmation step while its request is pending, preserves the code on refusal, and retries', async () => {
    const { flow, answers, fixture, node } = setup()
    const response = held<unknown>()
    answers.hilos_step_up_start = {
      required: false,
      purpose: 'change your email',
    }
    answers.profile_change_email_new_confirm = response.promise
    tell([record('new_sent', 'new@example.test')])
    await flow.open('old@example.test')
    await settle(fixture)

    const form = node('profile-email-code-new').closest('form')!
    const code = node('profile-email-code-new') as HTMLInputElement
    code.value = '123456'
    code.dispatchEvent(new Event('input', { bubbles: true }))
    await settle(fixture)
    const cancel = node('profile-email-cancel') as HTMLButtonElement

    node('profile-email-confirm-new').click()
    await flush(fixture)

    expect(flow.busy.get()).toBe(true)
    expect(code.matches(':disabled')).toBe(true)
    expect(code.value).toBe('123456')
    expect(node('profile-email-code-new').closest('form')).toBe(form)
    expect(cancel.matches(':disabled')).toBe(true)
    expect(node('modal-close').matches(':disabled')).toBe(true)
    attemptClose(fixture, node, 'profile-email-cancel')
    expect(node('modal-confirm')).toBeNull()
    expect(node('modal')).not.toBeNull()

    response.reject(
      new ActionError(
        'profile_change_email_new_confirm',
        'fail',
        'The address was not changed',
      ),
    )
    await flush(fixture)
    await settle(fixture)

    expect(flow.busy.get()).toBe(false)
    expect(code.matches(':disabled')).toBe(false)
    expect(code.value).toBe('123456')
    expect(node('profile-email-code-new').closest('form')).toBe(form)
    expect(node('profile-email-error').textContent).toContain(
      'The address was not changed',
    )
    expect(cancel.matches(':disabled')).toBe(false)
    expect(node('modal')).not.toBeNull()
    expect(node('modal-confirm')).toBeNull()

    answers.profile_change_email_new_confirm = {}
    node('profile-email-confirm-new').click()
    await settle(fixture)
    expect(node('profile-email-outcome')).not.toBeNull()
    node('profile-email-done').click()
    await settle(fixture)
    expect(node('modal')).toBeNull()

    flow.dispose()
    fixture.destroy()
  })

  it('says every turn of the send through one calm region standing in the dialog before the first send', async () => {
    const { flow, answers, frames, fixture, node } = setup()
    answers.hilos_step_up_start = {
      required: false,
      purpose: 'change your email',
    }
    answers.profile_change_email_current_request = SEND_REPLY_RESEND_LATER
    frames.profile_change_email_current_request = [record('current_sent')]
    await flow.open('old@example.test')
    await settle(fixture)
    const calm = node('profile-email-live-polite')
    expect(calm.getAttribute('role')).toBe('status')
    expect(calm.getAttribute('aria-live')).toBe('polite')
    expect(calm.closest('[aria-modal="true"]')).not.toBeNull()
    expect(calmText()).toBe('')

    node('profile-email-send-current').click()
    await flush(fixture)
    await settle(fixture)
    expect(node('profile-email-code-current')).not.toBeNull()
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
      tellSend(frame, fixture)
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
    typeInto(fixture, node('profile-email-code-current'), '000000')
    const refused = held<unknown>()
    answers.profile_change_email_current_confirm = refused.promise
    node('profile-email-confirm-current').click()
    await flush(fixture)
    refused.reject(
      new ActionError(
        'profile_change_email_current_confirm',
        'fail',
        'That code is not right',
      ),
    )
    await flush(fixture)
    await settle(fixture)
    expect(node('profile-email-live-assertive').textContent?.trim()).toBe(
      'That code is not right',
    )
    expect(node('profile-email-live-polite')).toBe(calm)
    expect(calmText()).toBe(
      'Sent to old@example.test earlier — that code still works',
    )

    flow.dispose()
    fixture.destroy()
  })

  it('says the line to the address of each code step and falls silent between them', async () => {
    const { flow, answers, frames, fixture, node } = setup()
    answers.hilos_step_up_start = {
      required: false,
      purpose: 'change your email',
    }
    answers.profile_change_email_current_request = SEND_REPLY
    frames.profile_change_email_current_request = [record('current_sent')]
    answers.profile_change_email_current_confirm = {}
    frames.profile_change_email_current_confirm = [record('current_proven')]
    answers.profile_change_email_new_request = SEND_REPLY
    frames.profile_change_email_new_request = [
      record('new_sent', 'new@example.test'),
    ]
    await flow.open('old@example.test')
    await settle(fixture)
    const calm = node('profile-email-live-polite')
    node('profile-email-send-current').click()
    await flush(fixture)
    await settle(fixture)
    tellSend(windowLine('current', CODE_SEND_STATE_SENT), fixture)
    expect(calmText()).toBe('Sent to old@example.test')

    typeInto(fixture, node('profile-email-code-current'), '111111')
    node('profile-email-confirm-current').click()
    await flush(fixture)
    await settle(fixture)
    expect(node('profile-email-new')).not.toBeNull()
    expect(node('profile-email-live-polite')).toBe(calm)
    expect(calmText()).toBe('')

    typeInto(fixture, node('profile-email-new'), ' New@Example.test ')
    node('profile-email-send-new').click()
    await flush(fixture)
    await settle(fixture)
    tellSend(windowLine('new', CODE_SEND_STATE_SENDING), fixture)
    expect(node('profile-email-live-polite')).toBe(calm)
    expect(calmText()).toBe('Sending to new@example.test…')
    expect(node('profile-email-new-send').textContent).toContain(
      'Sending to new@example.test…',
    )

    // The confirmation's line belongs to another step: the window's code step
    // does not take it up.
    tellSend(confirmationLine(CODE_SEND_STATE_SENT), fixture)
    expect(calmText()).toBe('')

    flow.dispose()
    fixture.destroy()
  })

  it('leaves the line of a closed window behind and says the next attempt from a new region', async () => {
    const { flow, answers, frames, fixture, node } = setup()
    answers.hilos_step_up_start = {
      required: false,
      purpose: 'change your email',
    }
    answers.profile_change_email_current_request = SEND_REPLY
    frames.profile_change_email_current_request = [record('current_sent')]
    await flow.open('old@example.test')
    await settle(fixture)
    const first = node('modal')
    node('profile-email-send-current').click()
    await flush(fixture)
    await settle(fixture)
    tellSend(windowLine('closed', CODE_SEND_STATE_SENT), fixture)
    expect(calmText()).toBe('Sent to old@example.test')

    flow.close()
    tell([])
    await flush(fixture)
    await settle(fixture)
    expect(node('modal')).toBeNull()

    // The window opens as a new dialog; the region it projects stands in it
    // with nothing to say.
    await flow.open('old@example.test')
    await settle(fixture)
    expect(node('modal')).not.toBe(first)
    expect(
      node('profile-email-live-polite').closest('[aria-modal="true"]'),
    ).toBe(node('modal'))
    expect(calmText()).toBe('')
    node('profile-email-send-current').click()
    await flush(fixture)
    await settle(fixture)
    tellSend(windowLine('again', CODE_SEND_STATE_QUEUED), fixture)
    expect(calmText()).toBe('Queued for sending…')

    flow.dispose()
    fixture.destroy()
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
      const { flow, answers, fixture, node } = setup()
      answers.hilos_step_up_start = {
        required: true,
        purpose: 'change your email',
        method,
        destination,
      }
      await flow.open('old@example.test')
      await settle(fixture)
      const calm = node('profile-email-live-polite')
      expect(calmText()).toBe('')

      // The window's own line is not the confirmation's.
      tellSend(windowLine('own', CODE_SEND_STATE_SENT), fixture)
      expect(calmText()).toBe('')

      tellSend(confirmationLine(CODE_SEND_STATE_HELD), fixture)
      expect(node('profile-email-live-polite')).toBe(calm)
      expect(calmText()).toBe(said)
      expect(
        selfOrInside(
          node('step-up'),
          '[role="status"], [role="alert"], [aria-live]',
        ),
      ).toBeNull()

      flow.dispose()
      fixture.destroy()
    },
  )

  it('keeps a refused confirmation in the alert and says no line without an opening', async () => {
    const { flow, answers, fixture, node } = setup()
    const refused = held<unknown>()
    answers.hilos_step_up_start = refused.promise
    const opened = flow.open('old@example.test')
    await flush(fixture)
    refused.reject(
      new ActionError('hilos_step_up_start', 'fail', 'Too many attempts'),
    )
    await opened
    await settle(fixture)
    tellSend(confirmationLine(CODE_SEND_STATE_SENT), fixture)
    expect(node('profile-email-live-assertive').textContent?.trim()).toBe(
      'Too many attempts',
    )
    expect(calmText()).toBe('')

    flow.dispose()
    fixture.destroy()
  })

  it('leaves the confirmation line behind once the window stands on its own code step', async () => {
    const { flow, answers, frames, fixture, node } = setup()
    answers.hilos_step_up_start = {
      required: true,
      purpose: 'change your email',
      method: 'email_code',
      destination: 'person@example.test',
    }
    answers.profile_change_email_current_request = SEND_REPLY
    frames.profile_change_email_current_request = [record('current_sent')]
    await flow.open('old@example.test')
    await settle(fixture)
    tellSend(confirmationLine(CODE_SEND_STATE_SENT), fixture)
    expect(calmText()).toBe('Sent to person@example.test')

    typeInto(fixture, node('step-up-code'), '123456')
    node('profile-email-step-up').dispatchEvent(
      new Event('submit', { cancelable: true }),
    )
    await flush(fixture)
    await settle(fixture)
    node('profile-email-send-current').click()
    await flush(fixture)
    await settle(fixture)
    expect(node('profile-email-code-current')).not.toBeNull()
    expect(calmText()).toBe('')
    tellSend(windowLine('own', CODE_SEND_STATE_SENT), fixture)
    expect(calmText()).toBe('Sent to old@example.test')

    flow.dispose()
    fixture.destroy()
  })
})

it.each(['email_code', 'sms_code'] as const)(
  'holds the window during a real %s step-up resend and preserves the independent connection lock',
  async (method) => {
    const connection = {
      state: 'connected',
      on: () => () => undefined,
    } as unknown as HilosConnection
    const interaction = createConnectionInteractionState(connection)
    TestBed.configureTestingModule({
      providers: [
        {
          provide: HILOS_CONNECTION_INTERACTION,
          useValue: createSignal(interaction),
        },
      ],
    })
    const { flow, answers, dispatched, fixture, node } = setup()
    const opening = {
      required: true,
      method,
      purpose: 'change your email',
      destination: method === 'email_code' ? 'a@example.test' : '+12025550123',
      send: {
        sent: true,
        resendAt: 1_000_000_000_000,
        expiresAt: 1_900_000_600_000,
      },
    }
    answers.hilos_step_up_start = opening
    await flow.open('old@example.test')
    await settle(fixture)

    const form = node('profile-email-step-up') as HTMLFormElement
    const code = node('step-up-code') as HTMLInputElement
    const again = node('step-up-send-again') as HTMLButtonElement
    const sendAgain = vi.spyOn(flow.stepUp, 'sendAgain')
    const cancel = node('profile-email-cancel') as HTMLButtonElement
    const text = node('step-up-text').textContent
    expect(text).toContain(opening.destination)
    code.value = '123456'
    code.dispatchEvent(new Event('input', { bubbles: true }))
    await settle(fixture)
    expect(flow.stepUp.code.get()).toBe('123456')

    const response = held<unknown>()
    answers.hilos_step_up_start = response.promise
    again.click()
    await flush(fixture)

    expect(flow.busy.get()).toBe(false)
    expect(flow.stepUp.busy.get()).toBe(true)
    expect(code.matches(':disabled')).toBe(true)
    expect(code.value).toBe('')
    expect(flow.stepUp.code.get()).toBe('')
    expect(node('step-up-code')).toBe(code)
    expect(node('profile-email-step-up')).toBe(form)
    expect(node('step-up-text').textContent).toBe(text)
    expect(code.closest('[inert], [aria-hidden="true"]')).toBeNull()
    expect(form.querySelector(`label[for="${code.id}"]`)?.textContent).toBe(
      'Code',
    )
    expect(cancel.matches(':disabled')).toBe(true)
    expect(node('modal-close').matches(':disabled')).toBe(true)
    expect(
      node('profile-email-step-up-confirm').getAttribute('aria-busy'),
    ).not.toBe('true')
    again.click()
    attemptClose(fixture, node, 'profile-email-cancel')
    expect(
      dispatched.filter((call) => call.action === 'hilos_step_up_start').length,
    ).toBe(2)
    expect(node('modal-confirm')).toBeNull()
    expect(node('profile-email-step-up')).toBe(form)

    response.reject(
      new ActionError(
        'hilos_step_up_start',
        'fail',
        'Another code could not be sent',
      ),
    )
    await sendAgain.mock.results[0]!.value
    await settle(fixture)

    expect(flow.busy.get()).toBe(false)
    expect(flow.stepUp.busy.get()).toBe(false)
    expect(code.matches(':disabled')).toBe(false)
    expect(cancel.matches(':disabled')).toBe(false)
    expect(node('modal-close').matches(':disabled')).toBe(false)
    expect(again.matches(':disabled')).toBe(false)
    expect(node('profile-email-step-up')).toBe(form)
    expect(node('step-up-code')).toBe(code)
    expect(code.value).toBe('')
    expect(node('step-up-error').textContent).toContain(
      'Another code could not be sent',
    )
    expect(node('profile-email-live-assertive').textContent).toContain(
      'Another code could not be sent',
    )
    expect(node('modal-confirm')).toBeNull()

    code.value = '654321'
    code.dispatchEvent(new Event('input', { bubbles: true }))
    await settle(fixture)
    expect(code.value).toBe('654321')
    expect(flow.stepUp.code.get()).toBe('654321')
    const retry = held<unknown>()
    answers.hilos_step_up_start = retry.promise
    again.click()
    await flush(fixture)
    expect(flow.busy.get()).toBe(false)
    expect(flow.stepUp.busy.get()).toBe(true)
    expect(code.matches(':disabled')).toBe(true)
    interaction.setConnection({
      ...connection,
      state: 'disconnected',
    } as HilosConnection)
    fixture.detectChanges()
    retry.resolve(opening)
    await sendAgain.mock.results[1]!.value
    await settle(fixture)

    expect(flow.busy.get()).toBe(false)
    expect(flow.stepUp.busy.get()).toBe(false)
    expect(code.matches(':disabled')).toBe(true)
    expect(node('profile-email-step-up')).toBe(form)
    expect(code.value).toBe('')
    expect(node('step-up-error')).toBeNull()
    const calls = dispatched.slice()
    attemptClose(fixture, node, 'profile-email-cancel')
    node('profile-email-step-up-confirm').click()
    form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
    await flush(fixture)
    expect(dispatched).toEqual(calls)
    expect(node('profile-email-step-up')).toBe(form)
    expect(node('modal-confirm')).toBeNull()

    interaction.setConnection(connection)
    await settle(fixture)
    expect(code.matches(':disabled')).toBe(false)
    expect(cancel.matches(':disabled')).toBe(false)
    expect(again.matches(':disabled')).toBe(false)
    expect(node('step-up-code')).toBe(code)
    expect(node('profile-email-step-up')).toBe(form)
    expect(dispatched).toEqual(calls)

    const passed = held<unknown>()
    answers.hilos_step_up_start = passed.promise
    again.click()
    await flush(fixture)
    expect(flow.busy.get()).toBe(false)
    expect(flow.stepUp.busy.get()).toBe(true)
    expect(code.matches(':disabled')).toBe(true)
    passed.resolve({ required: false, purpose: opening.purpose })
    await sendAgain.mock.results[2]!.value
    await settle(fixture)
    expect(flow.stepUp.busy.get()).toBe(false)
    expect(flow.step.get()).toBe('send-current')
    expect(node('profile-email-send-current').matches(':disabled')).toBe(false)
    expect(dispatched.map((call) => call.action)).toEqual([
      'hilos_step_up_start',
      'hilos_step_up_start',
      'hilos_step_up_start',
      'hilos_step_up_start',
    ])
    fixture.destroy()
    flow.dispose()
    interaction.dispose()
  },
)
