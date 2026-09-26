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
function setup() {
  const listeners = new Set<(signal: ProjectSignal) => void>()
  const dispatch = vi.fn(() => ({
    loading: createSignal(false),
    done: Promise.resolve({}),
  }))
  const context = {
    scopes: new ScopeManager(),
    actions: { dispatch },
    channels: [],
    termsPath: '/terms',
    privacyPath: '/privacy',
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
  it('changes a password in its dialog and closes on the authoritative frame', async () => {
    const world = setup()
    await world.wrapper
      .get('[data-id="profile-password-change"]')
      .trigger('click')
    expect(byId('profile-password-hint').textContent).toContain(
      'At least 8 characters',
    )
    await fill('profile-password-current', 'old-secret')
    await fill('profile-password-new', 'new-secret')
    await fill('profile-password-confirm', 'new-secret')
    byId('profile-password-save').click()
    await flushPromises()
    expect(world.dispatch).toHaveBeenCalledWith('profile_set_password', {
      currentPassword: 'old-secret',
      newPassword: 'new-secret',
    })
    expect(byId('profile-password-modal')).toBeDefined()
    world.emit('profile_password_updated', { mode: 'changed' })
    await flushPromises()
    expect(
      document.querySelector('[data-id="profile-password-modal"]'),
    ).toBeNull()
  })
  it('moves from phone to code in one dialog and focuses the new field', async () => {
    const world = setup()
    await world.wrapper.get('[data-id="profile-sign-in-add"]').trigger('click')
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
