// Covers what the name window's view owns (HIL-1169): the confirmation first
// when the operation asks and the field focused once it gives way to the form,
// Save held while the draft equals the live name, the refusal in the form, and
// the notice when the name moved elsewhere.
import {
  ActionError,
  createHilosProfileRenameFlow,
  createSignal,
  type ActionLifecycle,
  type HilosProfileRename,
} from '@hilos/core'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'
import { nextTick } from 'vue'

import HilosProfileRenameModal from './HilosProfileRename.vue'

// The modal teleports to <body>, so assertions query the document.
afterEach(() => {
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
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

function setup(stepUp: unknown = { required: false, purpose: 'rename' }) {
  const name = createSignal('Ann')
  const refusal = createSignal<string | null>(null)
  const sent: string[] = []
  const dispatched: Array<{ action: string; payload?: unknown }> = []
  const rename: HilosProfileRename = {
    send(next) {
      sent.push(next)
      return true
    },
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
                ? Promise.resolve({ reply: stepUp })
                : action === 'hilos_step_up_confirm'
                  ? Promise.resolve({ reply: [] })
                  : Promise.reject(new ActionError(action, 'fail', 'no')),
          }
        },
      } as unknown as ActionLifecycle,
    },
    name,
    rename,
  )
  mount(HilosProfileRenameModal, { props: { flow }, attachTo: document.body })
  return { flow, name, refusal, sent, dispatched }
}

describe('HilosProfileRename', () => {
  it('opens on the live name with Save held until the draft changes', async () => {
    const { flow, sent } = setup()
    await flow.open()
    await flushPromises()

    expect((byId('profile-name-input') as HTMLInputElement).value).toBe('Ann')
    expect(byId('profile-rename-save').hasAttribute('disabled')).toBe(true)
    typeInto('profile-name-input', 'Bob')
    await nextTick()
    expect(byId('profile-rename-save').hasAttribute('disabled')).toBe(false)
    byId('profile-rename-save').click()
    await nextTick()
    expect(sent).toEqual(['Bob'])
    expect(byId('profile-rename-cancel').hasAttribute('disabled')).toBe(true)
  })

  it('asks the confirmation first and focuses the field once it gives way', async () => {
    const { flow, dispatched } = setup({
      required: true,
      purpose: 'change your name',
      method: 'password',
    })
    await flow.open()
    await flushPromises()
    expect(document.querySelector('.modal-title')?.textContent?.trim()).toBe(
      "Confirm it's you",
    )
    expect(document.querySelector('[data-id="profile-name-input"]')).toBeNull()

    const form = byId('profile-name-step-up')
    const confirm = byId('profile-name-step-up-confirm')
    expect(confirm.getAttribute('type')).toBe('submit')
    expect(confirm.getAttribute('form')).toBe(form.id)

    form.dispatchEvent(new Event('submit', { cancelable: true }))
    await flushPromises()
    expect(dispatched.some((d) => d.action === 'hilos_step_up_confirm')).toBe(
      false,
    )

    typeInto('step-up-password', 'secret')
    form.dispatchEvent(new Event('submit', { cancelable: true }))
    await flushPromises()
    await nextTick()
    expect(document.activeElement).toBe(byId('profile-name-input'))
    const call = dispatched.find((d) => d.action === 'hilos_step_up_confirm')
    expect(call).toBeDefined()
    expect(call?.payload).toMatchObject({
      password: 'secret',
    })
  })

  it("shows the project's refusal in the form and says when the name moved elsewhere", async () => {
    const { flow, name, refusal } = setup()
    await flow.open()
    await flushPromises()
    refusal.set('That name is not allowed')
    await nextTick()
    expect(byId('profile-rename-error').textContent).toContain(
      'That name is not allowed',
    )

    name.set('Anna')
    await nextTick()
    expect(byId('profile-edit-notice').textContent).toContain(
      'Updated just now',
    )
    expect((byId('profile-name-input') as HTMLInputElement).value).toBe('Anna')
  })
})
