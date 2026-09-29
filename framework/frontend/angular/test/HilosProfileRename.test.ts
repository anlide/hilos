import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  createHilosProfileRenameFlow,
  createSignal,
  type ActionLifecycle,
  type HilosProfileRename as HilosProfileRenameBinding,
} from '@hilos/core'
import { afterEach, describe, expect, it } from 'vitest'
import { HilosProfileRename } from '../src/profile/HilosProfileRename.js'

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
  const name = createSignal('Ann')
  const refusal = createSignal<string | null>(null)
  const rename: HilosProfileRenameBinding = {
    send: () => true,
    refusal,
    clearRefusal: () => refusal.set(null),
    stepUpOperation: 'change_name',
    minLength: 2,
    maxLength: 64,
  }
  const flow = createHilosProfileRenameFlow(
    {
      actions: {
        dispatch: (action: string, payload?: unknown) => {
          dispatched.push({ action, payload })
          return {
            done:
              action === 'hilos_step_up_start'
                ? Promise.resolve({
                    reply: {
                      required: true,
                      purpose: 'change your name',
                      method: 'password',
                    },
                  })
                : action === 'hilos_step_up_confirm'
                  ? Promise.resolve({ reply: [] })
                  : Promise.reject(new Error(`unexpected ${action}`)),
          }
        },
      } as unknown as ActionLifecycle,
    },
    name,
    rename,
  )
  const fixture = TestBed.createComponent(HilosProfileRename)
  fixture.componentRef.setInput('flow', flow)
  fixture.detectChanges()

  const node = (id: string) =>
    document.querySelector<HTMLElement>(`[data-id="${id}"]`)!

  return { flow, dispatched, fixture, node }
}

describe('HilosProfileRename', () => {
  it('confirms step-up via form submit when credential is typed, ignores empty submit, and binds confirm button to form', async () => {
    const { flow, dispatched, fixture, node } = setup()
    await flow.open()
    await settle(fixture)

    const form = node('profile-name-step-up')
    const confirm = node('profile-name-step-up-confirm')

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
