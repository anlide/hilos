import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  createHilosProfilePasswordChangeFlow,
  type ActionLifecycle,
} from '@hilos/core'
import { afterEach, describe, expect, it } from 'vitest'
import { HilosProfilePasswordChange } from '../src/profile/HilosProfilePasswordChange.js'

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

  return { flow, dispatched, fixture, node }
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
})
