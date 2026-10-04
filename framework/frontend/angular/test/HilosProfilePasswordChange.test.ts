import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  createHilosProfilePasswordChangeFlow,
  type ActionLifecycle,
} from '@hilos/core'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { HilosProfilePasswordChange } from '../src/profile/HilosProfilePasswordChange.js'

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
        return {
          done:
            typeof answers[action] === 'string'
              ? Promise.reject(new Error(answers[action] as string))
              : Promise.resolve({ reply: answers[action] ?? [] }),
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
})
