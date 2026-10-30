import { By } from '@angular/platform-browser'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  ActionError,
  createConnectionInteractionState,
  createHilosProfilePasswordChangeFlow,
  createSignal,
  type ActionLifecycle,
  type HilosConnection,
} from '@hilos/core'
import { type ModalController } from '@hilos/core/base'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { HilosProfilePasswordChange } from '../src/profile/HilosProfilePasswordChange.js'
import { HilosModal } from '../src/HilosModal.js'
import { HILOS_CONNECTION_INTERACTION } from '../src/hilosConnectionInteraction.js'

/** A send gate that reopened long ago: another code may be asked for at once. */
const PAST = 1_000_000_000_000

afterEach(() => {
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
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
