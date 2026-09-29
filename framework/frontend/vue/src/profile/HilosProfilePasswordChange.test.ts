import {
  ActionError,
  createHilosProfilePasswordChangeFlow,
  type ActionLifecycle,
} from '@hilos/core'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'
import HilosProfilePasswordChange from './HilosProfilePasswordChange.vue'

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
function setup() {
  const dispatched: Array<{ name: string; payload?: unknown }> = []
  const answers: Record<string, unknown> = {
    hilos_step_up_start: { required: false, purpose: 'change your password' },
    profile_change_password_open: {
      channel: 'email',
      destination: 'a@example.test',
    },
  }
  const flow = createHilosProfilePasswordChangeFlow({
    actions: {
      dispatch: (name: string, payload?: unknown) => {
        dispatched.push({ name, payload })
        return {
          done:
            typeof answers[name] === 'string'
              ? Promise.reject(new ActionError(name, 'fail', answers[name]))
              : Promise.resolve({ reply: answers[name] ?? [] }),
        }
      },
    } as unknown as ActionLifecycle,
  })
  mount(HilosProfilePasswordChange, {
    props: { flow },
    attachTo: document.body,
  })
  return { flow, answers, dispatched }
}
describe('password change modal', () => {
  it.each([true, false])(
    'focuses each field and names the saved session choice %s',
    async (signOutOthers) => {
      const { flow } = setup()
      await flow.open()
      await flushPromises()
      expect(byId('profile-password-destination').textContent).toContain(
        'a@example.test',
      )
      expect(byId('profile-password-error-slot')).toBeDefined()
      byId('profile-password-send-code').click()
      await flushPromises()
      expect(document.activeElement).toBe(byId('profile-password-code'))
      flow.code.set('123456')
      await flushPromises()
      byId('profile-password-confirm-code').click()
      await flushPromises()
      expect(document.activeElement).toBe(byId('profile-password-new'))
      expect(
        (byId('profile-password-sign-out-others') as HTMLInputElement).checked,
      ).toBe(true)
      flow.newPassword.set('new-secret')
      flow.signOutOthers.set(signOutOthers)
      await flushPromises()
      byId('profile-password-save').click()
      await flushPromises()
      expect(byId('profile-password-outcome-sessions').textContent).toContain(
        signOutOthers
          ? 'Other sessions were signed out.'
          : 'Other sessions stay signed in.',
      )
      expect(byId('profile-password-outcome').textContent).toContain(
        'Password reset codes, if any were sent, no longer work.',
      )
    },
  )
  it('keeps the outside refusal slot through operation confirmation', async () => {
    const { flow, answers } = setup()
    answers.hilos_step_up_start = {
      required: true,
      purpose: 'change your password',
      method: 'password',
    }
    await flow.open()
    await flushPromises()
    const slot = byId('profile-password-error-slot')
    flow.stepUp.password.set('current-secret')
    await flow.confirmStepUp()
    await flushPromises()
    expect(byId('profile-password-error-slot')).toBe(slot)
  })
  it('keeps the password field and uses the permanent live region for a refusal', async () => {
    const { flow, answers } = setup()
    await flow.open()
    await flow.sendCode()
    flow.code.set('123456')
    await flow.confirmCode()
    flow.newPassword.set('short')
    answers.profile_change_password = 'Password is too short'
    await flow.save()
    await flushPromises()
    expect(byId('profile-password-error').textContent).toContain(
      'Password is too short',
    )
    expect(byId('profile-password-live').textContent).toBe(
      'Password is too short',
    )
    expect((byId('profile-password-new') as HTMLInputElement).value).toBe(
      'short',
    )
    expect(byId('profile-password-error-slot')).toBeDefined()
  })
  it('opens a step-up refusal with Cancel alone', async () => {
    const { flow, answers } = setup()
    answers.hilos_step_up_start =
      "This is not available while you work in someone else's account"
    await flow.open()
    await flushPromises()
    expect(byId('profile-password-error').textContent).toContain(
      'not available',
    )
    expect(
      document.querySelector('[data-id="profile-password-step-up-confirm"]'),
    ).toBeNull()
    byId('profile-password-cancel').click()
    await flushPromises()
    expect(flow.step.get()).toBe('closed')
  })
  it('confirms step-up via form submit when credential is typed, ignores empty submit, and binds confirm button to form', async () => {
    const { flow, answers, dispatched } = setup()
    answers.hilos_step_up_start = {
      required: true,
      purpose: 'change your password',
      method: 'password',
    }
    await flow.open()
    await flushPromises()

    const form = byId('profile-password-step-up')
    const confirm = byId('profile-password-step-up-confirm')
    expect(confirm.getAttribute('type')).toBe('submit')
    expect(confirm.getAttribute('form')).toBe(form.id)

    form.dispatchEvent(new Event('submit', { cancelable: true }))
    await flushPromises()
    expect(
      dispatched.some((item) => item.name === 'hilos_step_up_confirm'),
    ).toBe(false)

    flow.stepUp.password.set('current-secret')
    form.dispatchEvent(new Event('submit', { cancelable: true }))
    await flushPromises()
    const call = dispatched.find(
      (item) => item.name === 'hilos_step_up_confirm',
    )
    expect(call).toBeDefined()
    expect(call?.payload).toMatchObject({
      password: 'current-secret',
    })
  })
})
