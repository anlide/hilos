// Covers what the email window's view owns (HIL-1169): the step list marking
// the step on screen, the address in the text of step one, a refusal kept on
// its step, and the outcome naming what the address was and is now.
import {
  ActionError,
  createHilosProfileEmailChangeFlow,
  type ActionLifecycle,
} from '@hilos/core'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'
import { nextTick } from 'vue'

import HilosProfileEmailChange from './HilosProfileEmailChange.vue'

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

/** A mounted window whose server answers each action from the table; a string refuses. */
function setup(answers: Record<string, unknown> = {}) {
  const table: Record<string, unknown> = {
    hilos_step_up_start: { required: false, purpose: 'change your email' },
    ...answers,
  }
  const flow = createHilosProfileEmailChangeFlow({
    actions: {
      dispatch: (action: string) => ({
        done:
          typeof table[action] === 'string'
            ? Promise.reject(new ActionError(action, 'fail', table[action]))
            : Promise.resolve({ reply: table[action] ?? [] }),
      }),
    } as unknown as ActionLifecycle,
  })
  mount(HilosProfileEmailChange, { props: { flow }, attachTo: document.body })
  return flow
}

async function settle(): Promise<void> {
  await flushPromises()
  await nextTick()
}

describe('HilosProfileEmailChange', () => {
  it('walks the steps with the current one marked and names both addresses at the end', async () => {
    const flow = setup()
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
    const flow = setup({
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
})
