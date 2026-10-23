// Covers what the name window's view owns (HIL-1169): the confirmation first
// when the operation asks and the field focused once it gives way to the form,
// Save held while the draft equals the live name, the refusal in the form, the
// notice when the name moved elsewhere, and the window held with its body
// locked while the rename or the confirmation is out (HIL-1763).
import {
  ActionError,
  createHilosProfileRenameFlow,
  createSignal,
  type ActionLifecycle,
  type HilosProfileRename,
} from '@hilos/core/base'
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

// The fieldset the modal stands its body in; a request in flight disables it.
function bodyLock(): HTMLFieldSetElement | null {
  return document.querySelector('[data-id="modal"] .modal-body > fieldset')
}

/**
 * Every way a person closes the window: the ×, Cancel, Escape and the backdrop.
 *
 * @param cancel The data-id of the Cancel on screen.
 */
function tryClosing(cancel: string): void {
  byId('modal-close').click()
  byId(cancel).click()
  byId('modal').dispatchEvent(
    new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
  )
  byId('modal').click()
}

/**
 * A mounted window over the project's rename; the rename lands when the test
 * moves the live name or sets the refusal.
 *
 * @param stepUp What the operation's confirmation answers on open.
 * @param hold Actions whose reply stays in flight until the test settles or refuses it.
 */
function setup(
  stepUp: unknown = { required: false, purpose: 'rename' },
  hold: string[] = [],
) {
  const name = createSignal('Ann')
  const refusal = createSignal<string | null>(null)
  const sent: string[] = []
  const dispatched: Array<{ action: string; payload?: unknown }> = []
  const held: Array<{ settle(): void; refuse(reason: string): void }> = []
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
          if (hold.includes(action))
            return {
              done: new Promise((resolve, reject) =>
                held.push({
                  settle: () => resolve({ reply: [] }),
                  refuse: (reason) =>
                    reject(new ActionError(action, 'fail', reason)),
                }),
              ),
            }
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
  return { flow, name, refusal, sent, dispatched, held }
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

  it('holds the window and locks the field while the rename is out, and frees them after a refusal', async () => {
    const { flow, name, refusal, sent } = setup()
    await flow.open()
    await flushPromises()
    typeInto('profile-name-input', 'Bob')
    await nextTick()
    const field = byId('profile-name-input') as HTMLInputElement
    const body = bodyLock()
    const cancel = byId('profile-rename-cancel') as HTMLButtonElement
    const close = byId('modal-close') as HTMLButtonElement
    expect(body?.contains(field)).toBe(true)
    expect(body?.disabled).toBe(false)

    byId('profile-rename-save').click()
    await nextTick()
    expect(sent).toEqual(['Bob'])
    expect(body?.disabled).toBe(true)
    expect(cancel.disabled).toBe(true)
    expect(close.disabled).toBe(true)
    tryClosing('profile-rename-cancel')
    await flushPromises()
    expect(document.querySelector('[data-id="modal"]')).not.toBeNull()
    expect(document.querySelector('[data-id="modal-confirm"]')).toBeNull()

    refusal.set('That name is not allowed')
    await nextTick()
    expect(byId('profile-name-input')).toBe(field)
    expect(field.value).toBe('Bob')
    expect(body?.disabled).toBe(false)
    expect(cancel.disabled).toBe(false)
    expect(close.disabled).toBe(false)
    expect(byId('profile-rename-error').textContent).toContain(
      'That name is not allowed',
    )

    byId('profile-rename-save').click()
    await nextTick()
    expect(sent).toEqual(['Bob', 'Bob'])
    name.set('Bob')
    await flushPromises()
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
  })

  it('holds the window and locks the password while the confirmation is out, and frees it after a refusal', async () => {
    const { flow, held } = setup(
      { required: true, purpose: 'change your name', method: 'password' },
      ['hilos_step_up_confirm'],
    )
    await flow.open()
    await flushPromises()
    typeInto('step-up-password', 'wrong')
    await nextTick()
    const form = byId('profile-name-step-up')
    const field = byId('step-up-password') as HTMLInputElement
    const body = bodyLock()
    const cancel = byId('profile-name-step-up-cancel') as HTMLButtonElement
    const close = byId('modal-close') as HTMLButtonElement
    expect(body?.contains(field)).toBe(true)
    expect(body?.disabled).toBe(false)
    expect(cancel.disabled).toBe(false)

    form.dispatchEvent(new Event('submit', { cancelable: true }))
    await nextTick()
    expect(held).toHaveLength(1)
    expect(body?.disabled).toBe(true)
    expect(cancel.disabled).toBe(true)
    expect(close.disabled).toBe(true)
    tryClosing('profile-name-step-up-cancel')
    await flushPromises()
    expect(document.querySelector('[data-id="modal"]')).not.toBeNull()
    expect(document.querySelector('[data-id="modal-confirm"]')).toBeNull()

    held[0]!.refuse('That password is not right')
    await flushPromises()
    expect(byId('step-up-password')).toBe(field)
    expect(field.value).toBe('wrong')
    expect(body?.disabled).toBe(false)
    expect(cancel.disabled).toBe(false)
    expect(close.disabled).toBe(false)
    expect(byId('step-up-error').textContent).toContain(
      'That password is not right',
    )

    typeInto('step-up-password', 'secret')
    form.dispatchEvent(new Event('submit', { cancelable: true }))
    await nextTick()
    held[1]!.settle()
    await flushPromises()
    expect((byId('profile-name-input') as HTMLInputElement).value).toBe('Ann')
  })
})
