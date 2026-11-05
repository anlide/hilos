import { By } from '@angular/platform-browser'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  ActionError,
  bindCodeSendProgress,
  bindProfileFlows,
  CODE_SEND_PURPOSE_STEP_UP,
  CODE_SEND_STATE_FAILED,
  CODE_SEND_STATE_QUEUED,
  CODE_SEND_STATE_SENT,
  createConnectionInteractionState,
  createHilosProfilePasswordChangeFlow,
  createSignal,
  PASSWORD_CHANGE_OPERATION,
  SIGNAL_CODE_SEND_PROGRESS,
  SIGNAL_PROFILE_FLOWS,
  type ActionLifecycle,
  type HilosConnection,
  type ProjectSignal,
} from '@hilos/core'
import { type ModalController } from '@hilos/core/base'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { HilosProfilePasswordChange } from '../src/profile/HilosProfilePasswordChange.js'
import { HilosModal } from '../src/HilosModal.js'
import { HILOS_CONNECTION_INTERACTION } from '../src/hilosConnectionInteraction.js'

/** A send gate that reopened long ago: another code may be asked for at once. */
const PAST = 1_000_000_000_000

/** A code request held with no live code: the window stands on the code step by its own word. */
const HELD_WITHOUT_CODE = {
  sent: false,
  resendAt: PAST,
  expiresAt: null,
}

/** A code request's answer whose resend gate opens in a minute. */
const SENT_RESEND_LATER = {
  sent: true,
  resendAt: Date.now() + 60_000,
  expiresAt: Date.now() + 600_000,
}

afterEach(() => {
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
  tellSend({ state: null })
  tellRecord(null)
})

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
 * Tell the session's record of the password change, through the binding the
 * application boots with; the binding is let go once the frame is in.
 *
 * @param step The record's step, or null when the session has none.
 * @param fixture The window to draw once the frame is in, when there is one.
 */
function tellRecord(
  step: string | null,
  fixture?: ComponentFixture<unknown>,
): void {
  const flowListeners: ((signal: ProjectSignal) => void)[] = []
  const unbind = bindProfileFlows({
    on: (_event: string, listener: (signal: never) => void) => {
      flowListeners.push(listener as (signal: ProjectSignal) => void)
      return () => undefined
    },
  } as unknown as HilosConnection)
  const flows =
    step === null
      ? []
      : [
          {
            operation: PASSWORD_CHANGE_OPERATION,
            step,
            address: 'a@example.test',
            target: null,
          },
        ]
  for (const listener of flowListeners)
    listener({
      kind: 'project',
      type: SIGNAL_PROFILE_FLOWS,
      data: { flows },
    } as ProjectSignal)
  unbind()
  fixture?.detectChanges()
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

/** What the dialog's calm region says now, past the template's own spacing. */
function calmText(): string | undefined {
  return document
    .querySelector('[data-id="profile-password-live-polite"]')!
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
): void {
  node('modal-close').click()
  node('profile-password-cancel').click()
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
  const dispatched: Array<{ action: string; payload?: unknown }> = []
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
  }
  const flow = createHilosProfilePasswordChangeFlow({
    actions: {
      dispatch: (action: string, payload?: unknown) => {
        dispatched.push({ action, payload })
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
  const fixture = TestBed.createComponent(HilosProfilePasswordChange)
  fixture.componentRef.setInput('flow', flow)
  fixture.detectChanges()

  const node = (id: string) =>
    document.querySelector<HTMLElement>(`[data-id="${id}"]`)!

  return { flow, answers, dispatched, fixture, node }
}

describe('HilosProfilePasswordChange', () => {
  it('confirms step-up via form submit when credential is typed, ignores empty submit, and binds confirm button to form', async () => {
    const { flow, dispatched, fixture, node } = setup()
    await flow.open()
    await settle(fixture)

    const form = node('profile-password-step-up')
    const confirm = node('profile-password-step-up-confirm')

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

  it('holds the room with twins a locator, a focus trap and a screen reader pass by', async () => {
    const { flow, fixture, node } = setup()
    await flow.open()
    await settle(fixture)

    const twins = node('profile-password-modal').querySelectorAll('[inert]')
    expect(twins).toHaveLength(2)
    for (const twin of twins) {
      expect(twin.classList.contains('invisible')).toBe(true)
      expect(twin.getAttribute('aria-hidden')).toBe('true')
      expect(
        twin.querySelector(
          '[data-id], [data-autofocus], form, input, select, textarea, button',
        ),
      ).toBeNull()
    }

    flow.dispose()
    fixture.destroy()
  })

  it('draws the send block on the code step and asks the flow for another code from it', async () => {
    const { flow, answers, fixture, node } = setup()
    answers.hilos_step_up_start = {
      required: false,
      purpose: 'change your password',
    }
    answers.profile_change_password_code_request = {
      sent: false,
      resendAt: PAST,
      expiresAt: null,
    }
    await flow.open()
    await flow.sendCode()
    await settle(fixture)

    expect(node('profile-password-send')).not.toBeNull()
    const again = node('profile-password-send-again') as HTMLButtonElement
    expect(again.disabled).toBe(false)
    const sendAgain = vi.spyOn(flow, 'sendAgain')
    again.click()
    await settle(fixture)
    expect(sendAgain).toHaveBeenCalledOnce()

    flow.dispose()
    fixture.destroy()
  })

  it('locks the password draft while saving, keeps it on refusal, and follows the existing success step', async () => {
    const { flow, answers, fixture, node } = setup()
    const response = held<unknown>()
    answers.hilos_step_up_start = {
      required: false,
      purpose: 'change your password',
    }
    answers.profile_change_password_open = {
      channel: null,
      destination: null,
    }
    answers.profile_change_password = response.promise
    await flow.open()
    await settle(fixture)

    const form = node('profile-password-new').closest('form')!
    const password = node('profile-password-new') as HTMLInputElement
    const signOutOthers = node(
      'profile-password-sign-out-others',
    ) as HTMLInputElement
    password.value = 'New-password-123'
    password.dispatchEvent(new Event('input', { bubbles: true }))
    signOutOthers.checked = false
    signOutOthers.dispatchEvent(new Event('change', { bubbles: true }))
    await settle(fixture)
    const cancel = node('profile-password-cancel') as HTMLButtonElement

    node('profile-password-save').click()
    await flush(fixture)

    expect(flow.busy.get()).toBe(true)
    expect(password.matches(':disabled')).toBe(true)
    expect(signOutOthers.matches(':disabled')).toBe(true)
    expect(password.value).toBe('New-password-123')
    expect(signOutOthers.checked).toBe(false)
    expect(node('profile-password-new').closest('form')).toBe(form)
    expect(cancel.matches(':disabled')).toBe(true)
    expect(node('modal-close').matches(':disabled')).toBe(true)
    attemptClose(fixture, node)
    expect(node('modal-confirm')).toBeNull()
    expect(node('modal')).not.toBeNull()

    response.reject(
      new ActionError(
        'profile_change_password',
        'fail',
        'The password was not changed',
      ),
    )
    await flush(fixture)
    await settle(fixture)

    expect(flow.busy.get()).toBe(false)
    expect(password.matches(':disabled')).toBe(false)
    expect(signOutOthers.matches(':disabled')).toBe(false)
    expect(password.value).toBe('New-password-123')
    expect(signOutOthers.checked).toBe(false)
    expect(node('profile-password-new').closest('form')).toBe(form)
    expect(node('profile-password-error').textContent).toContain(
      'The password was not changed',
    )
    expect(cancel.matches(':disabled')).toBe(false)
    expect(node('modal')).not.toBeNull()
    expect(node('modal-confirm')).toBeNull()

    answers.profile_change_password = {}
    node('profile-password-save').click()
    await settle(fixture)
    expect(node('profile-password-outcome')).not.toBeNull()
    expect(node('modal')).not.toBeNull()
    node('profile-password-done').click()
    await settle(fixture)
    expect(node('modal')).toBeNull()

    flow.dispose()
    fixture.destroy()
  })

  it('says the code step line to the opening destination through a region that outlives the step', async () => {
    const { flow, answers, fixture, node } = setup()
    answers.hilos_step_up_start = {
      required: false,
      purpose: 'change your password',
    }
    answers.profile_change_password_code_request = HELD_WITHOUT_CODE
    await flow.open()
    await settle(fixture)
    const calm = node('profile-password-live-polite')
    expect(calm.getAttribute('role')).toBe('status')
    expect(calm.getAttribute('aria-live')).toBe('polite')
    expect(calm.closest('[aria-modal="true"]')).not.toBeNull()
    expect(calmText()).toBe('')

    node('profile-password-send-code').click()
    await flush(fixture)
    await settle(fixture)
    expect(node('profile-password-code')).not.toBeNull()
    tellSend(
      sendLine(PASSWORD_CHANGE_OPERATION, 'own', CODE_SEND_STATE_SENT),
      fixture,
    )
    expect(node('profile-password-live-polite')).toBe(calm)
    expect(calmText()).toBe('Sent to a@example.test')
    tellSend(
      sendLine(PASSWORD_CHANGE_OPERATION, 'own', CODE_SEND_STATE_FAILED, {
        detail: 'mailbox unavailable',
      }),
      fixture,
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
      fixture,
    )
    expect(calmText()).toBe('')
    tellSend(
      sendLine(PASSWORD_CHANGE_OPERATION, 'own', CODE_SEND_STATE_SENT),
      fixture,
    )
    expect(calmText()).toBe('Sent to a@example.test')

    // The session's record moves the window past the code: the form is
    // replaced, the region stays and falls silent.
    tellRecord('code_proven', fixture)
    expect(node('profile-password-new')).not.toBeNull()
    expect(node('profile-password-live-polite')).toBe(calm)
    expect(calmText()).toBe('')

    flow.dispose()
    fixture.destroy()
  })

  it('keeps a refused code in the alert and the send line in the calm region', async () => {
    const { flow, answers, fixture, node } = setup()
    answers.hilos_step_up_start = {
      required: false,
      purpose: 'change your password',
    }
    answers.profile_change_password_code_request = HELD_WITHOUT_CODE
    await flow.open()
    await flow.sendCode()
    await settle(fixture)
    tellSend(
      sendLine(PASSWORD_CHANGE_OPERATION, 'own', CODE_SEND_STATE_SENT),
      fixture,
    )
    expect(calmText()).toBe('Sent to a@example.test')
    expect(node('profile-password-live').textContent?.trim()).toBe('')

    const code = node('profile-password-code') as HTMLInputElement
    code.value = '000000'
    code.dispatchEvent(new Event('input', { bubbles: true }))
    await settle(fixture)
    const refused = held<unknown>()
    answers.profile_change_password_code_confirm = refused.promise
    code
      .closest('form')!
      .dispatchEvent(new Event('submit', { cancelable: true }))
    await flush(fixture)
    refused.reject(
      new ActionError(
        'profile_change_password_code_confirm',
        'fail',
        'That code is not right',
      ),
    )
    await flush(fixture)
    await settle(fixture)
    expect(node('profile-password-live').textContent?.trim()).toBe(
      'That code is not right',
    )
    expect(calmText()).toBe('Sent to a@example.test')
    expect(node('profile-password-code')).toBe(code)

    flow.dispose()
    fixture.destroy()
  })

  it('draws the countdown on the send row and keeps it out of the calm region', async () => {
    const { flow, answers, fixture, node } = setup()
    answers.hilos_step_up_start = {
      required: false,
      purpose: 'change your password',
    }
    answers.profile_change_password_code_request = SENT_RESEND_LATER
    await flow.open()
    await flow.sendCode()
    tellRecord('code_sent', fixture)
    await settle(fixture)
    expect(node('profile-password-code')).not.toBeNull()
    tellSend(
      sendLine(PASSWORD_CHANGE_OPERATION, 'own', CODE_SEND_STATE_SENT),
      fixture,
    )

    const row = node('profile-password-send')
    expect(row.textContent).toContain('Sent to a@example.test')
    expect(row.textContent).toContain('Send again in')
    expect(calmText()).toBe('Sent to a@example.test')
    expect(calmText()).not.toContain('Send again in')

    flow.dispose()
    fixture.destroy()
  })

  it('leaves the line of a closed window behind and says the next attempt in a new dialog', async () => {
    const { flow, answers, fixture, node } = setup()
    answers.hilos_step_up_start = {
      required: false,
      purpose: 'change your password',
    }
    answers.profile_change_password_code_request = HELD_WITHOUT_CODE
    await flow.open()
    await flow.sendCode()
    await settle(fixture)
    const first = node('modal')
    tellSend(
      sendLine(PASSWORD_CHANGE_OPERATION, 'closed', CODE_SEND_STATE_SENT),
      fixture,
    )
    expect(calmText()).toBe('Sent to a@example.test')

    flow.close()
    await settle(fixture)
    expect(node('modal')).toBeNull()

    // The window opens as a new dialog; the region it projects stands in it
    // with nothing to say.
    await flow.open()
    await settle(fixture)
    expect(node('modal')).not.toBe(first)
    expect(
      node('profile-password-live-polite').closest('[aria-modal="true"]'),
    ).toBe(node('modal'))
    expect(calmText()).toBe('')
    await flow.sendCode()
    await settle(fixture)
    expect(node('profile-password-code')).not.toBeNull()
    expect(calmText()).toBe('')
    tellSend(
      sendLine(PASSWORD_CHANGE_OPERATION, 'again', CODE_SEND_STATE_QUEUED),
      fixture,
    )
    expect(calmText()).toBe('Queued for sending…')

    flow.dispose()
    fixture.destroy()
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
      const { flow, answers, fixture, node } = setup()
      answers.hilos_step_up_start = {
        required: true,
        purpose: 'change your password',
        method,
        destination,
      }
      await flow.open()
      await settle(fixture)
      const calm = node('profile-password-live-polite')
      tellSend(
        sendLine(PASSWORD_CHANGE_OPERATION, 'own', CODE_SEND_STATE_SENT),
        fixture,
      )
      expect(calmText()).toBe('')

      tellSend(
        sendLine(
          CODE_SEND_PURPOSE_STEP_UP,
          'confirmation',
          CODE_SEND_STATE_SENT,
        ),
        fixture,
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
      fixture.destroy()
    },
  )
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
      purpose: 'change your password',
      destination: method === 'email_code' ? 'a@example.test' : '+12025550123',
      send: {
        sent: true,
        resendAt: 1_000_000_000_000,
        expiresAt: 1_900_000_600_000,
      },
    }
    answers.hilos_step_up_start = opening
    await flow.open()
    await settle(fixture)

    const form = node('profile-password-step-up') as HTMLFormElement
    const code = node('step-up-code') as HTMLInputElement
    const again = node('step-up-send-again') as HTMLButtonElement
    const sendAgain = vi.spyOn(flow.stepUp, 'sendAgain')
    const cancel = node('profile-password-cancel') as HTMLButtonElement
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
    expect(node('profile-password-step-up')).toBe(form)
    expect(node('step-up-text').textContent).toBe(text)
    expect(code.closest('[inert], [aria-hidden="true"]')).toBeNull()
    expect(form.querySelector(`label[for="${code.id}"]`)?.textContent).toBe(
      'Code',
    )
    expect(cancel.matches(':disabled')).toBe(true)
    expect(node('modal-close').matches(':disabled')).toBe(true)
    expect(
      node('profile-password-step-up-confirm').getAttribute('aria-busy'),
    ).not.toBe('true')
    again.click()
    attemptClose(fixture, node)
    expect(
      dispatched.filter((call) => call.action === 'hilos_step_up_start').length,
    ).toBe(2)
    expect(node('modal-confirm')).toBeNull()
    expect(node('profile-password-step-up')).toBe(form)

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
    expect(node('profile-password-step-up')).toBe(form)
    expect(node('step-up-code')).toBe(code)
    expect(code.value).toBe('')
    expect(node('step-up-error').textContent).toContain(
      'Another code could not be sent',
    )
    expect(node('profile-password-live').textContent).toContain(
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
    expect(node('profile-password-step-up')).toBe(form)
    expect(code.value).toBe('')
    expect(node('step-up-error')).toBeNull()
    const calls = dispatched.slice()
    attemptClose(fixture, node)
    node('profile-password-step-up-confirm').click()
    form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
    await flush(fixture)
    expect(dispatched).toEqual(calls)
    expect(node('profile-password-step-up')).toBe(form)
    expect(node('modal-confirm')).toBeNull()

    interaction.setConnection(connection)
    await settle(fixture)
    expect(code.matches(':disabled')).toBe(false)
    expect(cancel.matches(':disabled')).toBe(false)
    expect(again.matches(':disabled')).toBe(false)
    expect(node('step-up-code')).toBe(code)
    expect(node('profile-password-step-up')).toBe(form)
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
    expect(flow.step.get()).toBe('start')
    expect(node('profile-password-send-code').matches(':disabled')).toBe(false)
    expect(dispatched.map((call) => call.action)).toEqual([
      'hilos_step_up_start',
      'hilos_step_up_start',
      'hilos_step_up_start',
      'hilos_step_up_start',
      'profile_change_password_open',
    ])
    fixture.destroy()
    flow.dispose()
    interaction.dispose()
  },
)
