// Covers what the email window's view owns (HIL-1169): the step list marking
// the step on screen, the address in the text of step one, a refusal kept on
// its step, and the outcome naming what the address was and is now. The steps
// are the session's record (HIL-1182), so the fake server tells it before it
// answers, as the session holder does.
import {
  ActionError,
  bindProfileFlows,
  createHilosProfileEmailChangeFlow,
  profileFlowsSchema,
  SIGNAL_PROFILE_FLOWS,
  type ActionLifecycle,
  type HilosConnection,
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

const listeners: ((signal: { type: string; data: unknown }) => void)[] = []
bindProfileFlows({
  on: (_event: string, listener: (signal: never) => void) => {
    listeners.push(
      listener as (signal: { type: string; data: unknown }) => void,
    )
    return () => undefined
  },
} as unknown as HilosConnection)

/** Tell the session's record of the email change, as the frame carries it. */
function tell(step: string | null, target: string | null = null): void {
  const flows =
    step === null
      ? []
      : [
          {
            operation: 'change_email',
            step,
            address: 'old@example.test',
            target,
          },
        ]
  for (const listener of listeners)
    listener({
      type: SIGNAL_PROFILE_FLOWS,
      data: profileFlowsSchema.parse({ flows }),
    })
}

/** What the server tells the session when a step lands, before it answers the step. */
function frameAfter(action: string, payload: unknown): void {
  if (action === 'profile_change_email_current_request') tell('current_sent')
  if (action === 'profile_change_email_current_confirm') tell('current_proven')
  if (action === 'profile_change_email_new_request')
    tell('new_sent', (payload as { email: string }).email.toLowerCase())
  if (action === 'profile_change_email_new_confirm') tell(null)
}

/** A mounted window whose server answers each action from the table; a string refuses. */
function setup(answers: Record<string, unknown> = {}) {
  tell(null)
  const dispatched: Array<{ action: string; payload?: unknown }> = []
  const table: Record<string, unknown> = {
    hilos_step_up_start: { required: false, purpose: 'change your email' },
    ...answers,
  }
  const flow = createHilosProfileEmailChangeFlow({
    actions: {
      dispatch: (action: string, payload?: unknown) => {
        dispatched.push({ action, payload })
        if (typeof table[action] === 'string')
          return {
            done: Promise.reject(
              new ActionError(action, 'fail', table[action]),
            ),
          }
        frameAfter(action, payload)
        return { done: Promise.resolve({ reply: table[action] ?? [] }) }
      },
    } as unknown as ActionLifecycle,
  })
  mount(HilosProfileEmailChange, { props: { flow }, attachTo: document.body })
  return { flow, dispatched }
}

async function settle(): Promise<void> {
  await flushPromises()
  await nextTick()
}

describe('HilosProfileEmailChange', () => {
  it('walks the steps with the current one marked and names both addresses at the end', async () => {
    const { flow } = setup()
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
    const { flow } = setup({
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

  it('confirms step-up via form submit when credential is typed, ignores empty submit, and binds confirm button to form', async () => {
    const { flow, dispatched } = setup({
      hilos_step_up_start: {
        required: true,
        purpose: 'change your email',
        method: 'password',
      },
    })
    await flow.open('old@example.test')
    await settle()

    const form = byId('profile-email-step-up')
    const confirm = byId('profile-email-step-up-confirm')
    expect(confirm.getAttribute('type')).toBe('submit')
    expect(confirm.getAttribute('form')).toBe(form.id)

    form.dispatchEvent(new Event('submit', { cancelable: true }))
    await settle()
    expect(dispatched.some((d) => d.action === 'hilos_step_up_confirm')).toBe(
      false,
    )

    typeInto('step-up-password', 'secret')
    form.dispatchEvent(new Event('submit', { cancelable: true }))
    await settle()

    const call = dispatched.find((d) => d.action === 'hilos_step_up_confirm')
    expect(call).toBeDefined()
    expect(call?.payload).toMatchObject({
      password: 'secret',
    })
  })
})
