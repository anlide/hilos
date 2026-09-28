import {
  ActionError,
  createSignal,
  ScopeManager,
  resolveHilosProfileSignInMethods,
  type HilosAuthContext,
  type ProjectSignal,
} from '@hilos/core'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import HilosProfileSignInPage from './HilosProfileSignInPage.vue'

afterEach(() => {
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
})
enableAutoUnmount(afterEach)
const methods = resolveHilosProfileSignInMethods(
  [
    {
      id: 1,
      type: 'password',
      identifier: 'a@example.test',
      verified: true,
      provider: null,
    },
    {
      id: 2,
      type: 'sms',
      identifier: '+15551234567',
      verified: true,
      provider: null,
    },
  ],
  [],
)
/** The server's answer to the add-a-way-in confirmation start. */
const NO_STEP = { required: false, purpose: 'add a way to sign in' }
const PASSWORD_STEP = {
  required: true,
  purpose: 'add a way to sign in',
  method: 'password',
}
function setup(opening: object = NO_STEP) {
  const listeners = new Set<(signal: ProjectSignal) => void>()
  const dispatch = vi.fn((action: string) => ({
    loading: createSignal(false),
    done: Promise.resolve(
      action === 'hilos_step_up_start' ? { reply: opening } : {},
    ),
  }))
  const context = {
    scopes: new ScopeManager(),
    actions: { dispatch },
    channels: [],
    connection: {
      on: (_: string, listener: (signal: ProjectSignal) => void) => {
        listeners.add(listener)
        return () => listeners.delete(listener)
      },
    },
  } as unknown as HilosAuthContext
  const wrapper = mount(HilosProfileSignInPage, {
    props: { context, methods },
    attachTo: document.body,
    global: { stubs: { HilosPageHeading: true } },
  })
  return {
    wrapper,
    dispatch,
    emit: (type: string, data: unknown) => {
      for (const listener of listeners)
        listener({ kind: 'project', type, data } as ProjectSignal)
    },
  }
}
function byId(id: string): HTMLElement {
  const node = document.querySelector<HTMLElement>(`[data-id="${id}"]`)
  if (!node) throw new Error(`Missing ${id}`)
  return node
}
async function fill(id: string, value: string): Promise<void> {
  const input = byId(id) as HTMLInputElement
  input.value = value
  input.dispatchEvent(new Event('input', { bubbles: true }))
  await flushPromises()
}

describe('profile sign-in page dialogs', () => {
  it('announces a refused unlink inside the dialog and closes if another tab removes its method', async () => {
    const world = setup()
    world.dispatch.mockImplementationOnce(() => ({
      loading: createSignal(false),
      done: Promise.reject(
        new ActionError(
          'unlink',
          'fail',
          'You cannot remove your only login method',
        ),
      ),
    }))
    await world.wrapper.find('[data-id="identity-unlink"]').trigger('click')
    expect(byId('profile-unlink-modal').textContent).toContain(
      'You can still use: Phone',
    )
    byId('identity-unlink-yes').click()
    await flushPromises()
    expect(byId('profile-unlink-modal').textContent).toContain(
      'You cannot remove your only login method',
    )
    expect(
      byId('profile-unlink-modal').querySelector('[aria-live="assertive"]'),
    ).not.toBeNull()
    await world.wrapper.setProps({ methods: methods.slice(1) })
    expect(
      document.querySelector('[data-id="profile-unlink-modal"]'),
    ).toBeNull()
  })
  it('opens the confirmed password flow and leaves it open on another tab’s update', async () => {
    const world = setup()
    world.dispatch.mockImplementationOnce(() => ({
      loading: createSignal(false),
      done: Promise.resolve({
        reply: { required: false, purpose: 'change your password' },
      }),
    }))
    world.dispatch.mockImplementationOnce(() => ({
      loading: createSignal(false),
      done: Promise.resolve({ reply: { channel: null, destination: null } }),
    }))
    await world.wrapper
      .get('[data-id="profile-password-change"]')
      .trigger('click')
    await flushPromises()
    await fill('profile-password-new', 'new-secret')
    world.emit('profile_password_updated', { mode: 'changed' })
    await flushPromises()
    expect(byId('profile-password-modal')).toBeDefined()
    byId('profile-password-save').click()
    await flushPromises()
    expect(world.dispatch).toHaveBeenCalledWith('profile_change_password', {
      code: '',
      newPassword: 'new-secret',
      signOutOthers: true,
    })
    expect(byId('profile-password-outcome')).toBeDefined()
  })
  it('moves from phone to code in one dialog and focuses the new field', async () => {
    const world = setup()
    await world.wrapper.get('[data-id="profile-sign-in-add"]').trigger('click')
    await flushPromises()
    byId('profile-sign-in-choose-phone').click()
    await flushPromises()
    expect(document.activeElement).toBe(byId('profile-add-sms-phone'))
    await fill('profile-add-sms-phone', '+15557654321')
    byId('profile-add-sms-request').click()
    await flushPromises()
    expect(document.activeElement).toBe(byId('profile-add-sms-code'))
    await fill('profile-add-sms-code', '123456')
    byId('profile-add-sms-confirm').click()
    await flushPromises()
    expect(world.dispatch).toHaveBeenLastCalledWith('profile_add_sms_confirm', {
      phone: '+15557654321',
      code: '123456',
    })
    expect(
      document.querySelector('[data-id="profile-sign-in-add-modal"]'),
    ).toBeNull()
  })
  it('asks the server before opening and starts at the chooser when no step is needed', async () => {
    const world = setup()
    await world.wrapper.get('[data-id="profile-sign-in-add"]').trigger('click')
    await flushPromises()
    expect(world.dispatch).toHaveBeenCalledWith(
      'hilos_step_up_start',
      { operation: 'add_sign_in_method' },
      expect.anything(),
    )
    expect(byId('profile-sign-in-choose-phone')).toBeDefined()
    expect(document.querySelector('[data-id="step-up"]')).toBeNull()
    expect(document.querySelector('.modal-title')?.textContent).toBe(
      'Add a way to sign in',
    )
  })
  it('opens at the confirmation step, confirms with Enter in the password field, then shows the chooser', async () => {
    const world = setup(PASSWORD_STEP)
    await world.wrapper.get('[data-id="profile-sign-in-add"]').trigger('click')
    await flushPromises()
    expect(document.querySelector('.modal-title')?.textContent).toBe(
      "Confirm it's you",
    )
    expect(
      document.querySelector('[data-id="profile-sign-in-choose-phone"]'),
    ).toBeNull()
    expect(
      document.querySelector('[data-id="profile-sign-in-add-back"]'),
    ).toBeNull()
    expect(byId('profile-sign-in-add-step-up-confirm')).toBeDefined()
    await fill('step-up-password', 'secret')
    byId('profile-sign-in-add-step-up').dispatchEvent(
      new Event('submit', { cancelable: true }),
    )
    await flushPromises()
    expect(world.dispatch).toHaveBeenLastCalledWith('hilos_step_up_confirm', {
      operation: 'add_sign_in_method',
      method: 'password',
      code: '',
      backupCode: false,
      password: 'secret',
      passkey: null,
    })
    expect(byId('profile-sign-in-choose-phone')).toBeDefined()
    expect(document.querySelector('.modal-title')?.textContent).toBe(
      'Add a way to sign in',
    )
  })
  it('shows a refused opening as a line with Cancel alone', async () => {
    const world = setup()
    world.dispatch.mockImplementationOnce(() => ({
      loading: createSignal(false),
      done: Promise.reject(
        new ActionError('step-up', 'fail', 'Too many codes'),
      ),
    }))
    await world.wrapper.get('[data-id="profile-sign-in-add"]').trigger('click')
    await flushPromises()
    expect(byId('profile-sign-in-add-error').textContent).toContain(
      'Too many codes',
    )
    expect(byId('profile-sign-in-add-cancel')).toBeDefined()
    expect(
      document.querySelector('[data-id="profile-sign-in-add-step-up-confirm"]'),
    ).toBeNull()
    expect(
      document.querySelector('[data-id="profile-sign-in-choose-phone"]'),
    ).toBeNull()
  })
  it('leaves Change enabled for a sole password and explains its disabled removal', async () => {
    const world = setup()
    await world.wrapper.setProps({
      methods: [{ ...methods[0], canUnlink: false }],
    })
    expect(
      world.wrapper.get('[data-id="identity-unlink"]').attributes('disabled'),
    ).toBeDefined()
    expect(
      world.wrapper.get('[data-id="identity-unlink-blocked"]').text(),
    ).toBe('You cannot remove your only login method')
    expect(
      world.wrapper
        .get('[data-id="profile-password-change"]')
        .attributes('disabled'),
    ).toBeUndefined()
  })
})
