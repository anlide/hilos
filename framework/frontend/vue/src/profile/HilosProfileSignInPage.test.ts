import {
  ActionError,
  bindProfileFlows,
  bindTimeZoneState,
  cancelOAuthTrip,
  createSignal,
  HILOS_PROFILE_IDENTITIES_LIST,
  profileFlowsSchema,
  SIGNAL_PROFILE_FLOWS,
  ScopeManager,
  resolveHilosProfileSignInMethods,
  type HilosAuthContext,
  type HilosConnection,
  type HilosProfileSignInMethod,
  type ProjectSignal,
} from '@hilos/core/base'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'
import HilosProfileSignInPage from './HilosProfileSignInPage.vue'

let releaseTimeZone: (() => void) | undefined

afterEach(() => {
  releaseTimeZone?.()
  releaseTimeZone = undefined
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
  vi.useRealTimers()
  vi.restoreAllMocks()
  delete (globalThis as { PublicKeyCredential?: unknown }).PublicKeyCredential
  delete (navigator as { credentials?: unknown }).credentials
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
/** A confirmation by an emailed code whose resend gate is already open. */
const EMAIL_STEP = {
  required: true,
  purpose: 'add a way to sign in',
  method: 'email_code',
  destination: 'a@example.test',
  send: { sent: true, resendAt: Date.now() - 60_000, expiresAt: null },
}
/** The methods the session offers: on, ready, in button order. */
const OFFERED = [
  { key: 'password', name: null },
  { key: 'sms', name: null },
  { key: 'oauth:github', name: 'GitHub' },
  { key: 'passkey', name: null },
]
const passkeyOnly = resolveHilosProfileSignInMethods(
  [
    {
      id: 3,
      type: 'passkey',
      identifier: 'opaque',
      verified: true,
      provider: null,
    },
  ],
  [],
)

/** Feed the framework list and its normalized identity fields into a page scope. */
function setMethods(
  scopes: ScopeManager,
  current: readonly HilosProfileSignInMethod[],
): void {
  const page = scopes.page() ?? scopes.openPage('hilos_profile_sign_in')
  const identities = current.map((method) => {
    const id = Number(method.key)
    const ref = { type: 'identities', id }
    page.entities.upsert(ref, {
      id,
      type: method.type,
      provider: method.provider,
      identifier: method.identifier,
      verified: method.verified,
    })
    return ref
  })
  const passkeyCredentials = current.flatMap((method) => {
    if (method.type !== 'passkey' || method.addedAt === null) return []
    const id = Number(method.key)
    const ref = { type: 'passkeyCredentials', id }
    page.entities.upsert(ref, {
      identityId: id,
      label: method.deviceName,
      createdAt: method.addedAt,
    })
    return [ref]
  })
  page.lists.upsert(HILOS_PROFILE_IDENTITIES_LIST, 1, {
    identities,
    passkeyCredentials,
  })
}

/**
 * A mounted page over a fake server.
 *
 * @param opening The answer to the confirmation start.
 * @param initial The ways in the account already has.
 * @param offered The methods the session offers.
 * @param resendAt The moment a code request's answer opens the resend gate.
 */
function setup(
  opening: object = NO_STEP,
  initial = methods,
  offered: readonly object[] = OFFERED,
  resendAt = 1_900_000_000_000,
) {
  const listeners = new Set<(signal: ProjectSignal) => void>()
  const emit = (type: string, data: unknown) => {
    for (const listener of listeners)
      listener({ kind: 'project', type, data } as ProjectSignal)
  }
  const dispatch = vi.fn(
    (action: string, payload?: { phone?: string; email?: string }) => {
      if (action === 'profile_add_sms_request' && payload?.phone)
        emit(
          SIGNAL_PROFILE_FLOWS,
          profileFlowsSchema.parse({
            flows: [
              {
                operation: 'add_sign_in_method',
                step: 'phone_sent',
                address: payload.phone,
                target: payload.phone,
              },
            ],
          }),
        )
      if (action === 'profile_add_password_request' && payload?.email)
        emit(
          SIGNAL_PROFILE_FLOWS,
          profileFlowsSchema.parse({
            flows: [
              {
                operation: 'add_sign_in_method',
                step: 'email_sent',
                address: payload.email,
                target: payload.email,
              },
            ],
          }),
        )
      if (
        action === 'profile_add_sms_confirm' ||
        action === 'hilos_profile_flow_cancel'
      )
        emit(SIGNAL_PROFILE_FLOWS, profileFlowsSchema.parse({ flows: [] }))
      return {
        loading: createSignal(false),
        done: Promise.resolve(
          action === 'hilos_step_up_start'
            ? { reply: opening }
            : action === 'profile_add_sms_request' ||
                action === 'profile_add_password_request'
              ? {
                  reply: {
                    sent: true,
                    resendAt,
                    expiresAt: resendAt + 600_000,
                  },
                }
              : {},
        ),
      }
    },
  )
  const scopes = new ScopeManager()
  scopes.session.data.set('authMethods', offered)
  setMethods(scopes, initial)
  const context = {
    scopes,
    actions: { dispatch },
    channels: [],
    connection: {
      on: (_: string, listener: (signal: ProjectSignal) => void) => {
        listeners.add(listener)
        return () => listeners.delete(listener)
      },
    },
  } as unknown as HilosAuthContext
  bindProfileFlows(context.connection as HilosConnection)
  emit(SIGNAL_PROFILE_FLOWS, profileFlowsSchema.parse({ flows: [] }))
  const wrapper = mount(HilosProfileSignInPage, {
    props: { context },
    attachTo: document.body,
    global: { stubs: { HilosPageHeading: true } },
  })
  return {
    wrapper,
    dispatch,
    setMethods: (next: readonly HilosProfileSignInMethod[]) =>
      setMethods(scopes, next),
    emit,
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

/** Make this browser one that can run WebAuthn, so the chooser offers a passkey. */
function supportPasskeys(): void {
  ;(globalThis as { PublicKeyCredential?: unknown }).PublicKeyCredential =
    class {}
  Object.defineProperty(navigator, 'credentials', {
    configurable: true,
    value: { create: vi.fn(), get: vi.fn() },
  })
}

/**
 * Whether a disabled fieldset around the element turns it off. happy-dom
 * matches `:disabled` on an element's own attribute only, so the lock a
 * fieldset gives is read off the fieldset itself.
 *
 * @param element The control to read.
 */
function lockedByFieldset(element: Element): boolean {
  return element.closest('fieldset[disabled]') !== null
}

/** Every way a person closes the add dialog: the ×, Cancel, Escape and the backdrop. */
function tryClosing(): void {
  byId('modal-close').click()
  byId('profile-sign-in-add-cancel').click()
  byId('modal').dispatchEvent(
    new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
  )
  byId('modal').click()
}

/** Whether the add dialog is still on screen, with no Discard step over it. */
function addStillOpen(): boolean {
  return (
    document.querySelector('[data-id="profile-sign-in-add-modal"]') !== null &&
    document.querySelector('[data-id="modal-confirm"]') === null
  )
}

/**
 * An answer that stays out until the test settles or refuses it.
 *
 * @param action The action the refusal names.
 */
function heldReply(action: string) {
  let settle!: (reply: object) => void
  let refuse!: (reason: string) => void
  const done = new Promise<{ reply: object }>((resolve, reject) => {
    settle = (reply) => resolve({ reply })
    refuse = (reason) => reject(new ActionError(action, 'fail', reason))
  })
  return {
    handle: () => ({ loading: createSignal(false), done }),
    settle,
    refuse,
  }
}

describe('profile sign-in page dialogs', () => {
  it.each(['unlink', 'add'] as const)(
    'reformats the passkey date without a server frame and keeps the %s dialog',
    async (dialog) => {
      let browserZone = 'Asia/Tokyo'
      const resolved = new Intl.DateTimeFormat().resolvedOptions()
      vi.spyOn(
        Intl.DateTimeFormat.prototype,
        'resolvedOptions',
      ).mockImplementation(() => ({ ...resolved, timeZone: browserZone }))
      releaseTimeZone = bindTimeZoneState({
        on: () => () => {},
      } as unknown as HilosConnection)
      const world = setup(NO_STEP, [
        {
          ...passkeyOnly[0]!,
          deviceName: 'Laptop key',
          addedAt: 1767227400000,
          canUnlink: true,
        },
        methods[0]!,
      ])
      const row = world.wrapper.get('[data-identity-key="3"]')
      const subtitle = row.get('[data-id="identity-passkey-added"]')
      expect(row.get('[data-id="identity-type"]').text()).toBe('Laptop key')
      expect(subtitle.text()).toBe('Passkey · added Jan 1, 2026')
      if (dialog === 'unlink') {
        await row.get('[data-id="identity-unlink"]').trigger('click')
      } else {
        await world.wrapper
          .get('[data-id="profile-sign-in-add"]')
          .trigger('click')
        await flushPromises()
        byId('profile-sign-in-choose-phone').click()
        await flushPromises()
        await fill('profile-add-sms-phone', '+15557654321')
      }
      const modal = byId('modal')
      const callsBefore = world.dispatch.mock.calls.length
      const field = dialog === 'add' ? byId('profile-add-sms-phone') : null

      browserZone = 'America/New_York'
      window.dispatchEvent(new Event('focus'))
      await nextTick()

      expect(row.get('[data-id="identity-passkey-added"]').element).toBe(
        subtitle.element,
      )
      expect(subtitle.text()).toBe('Passkey · added Dec 31, 2025')
      expect(byId('modal')).toBe(modal)
      expect(world.dispatch).toHaveBeenCalledTimes(callsBefore)
      if (dialog === 'unlink') {
        expect(byId('profile-unlink-modal').textContent).toContain(
          'You can still use: Password',
        )
      } else {
        expect(byId('profile-add-sms-phone')).toBe(field)
        expect((field as HTMLInputElement).value).toBe('+15557654321')
      }
    },
  )

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
    world.setMethods(methods.slice(1))
    await flushPromises()
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
      code: '123456',
    })
    expect(
      document.querySelector('[data-id="profile-sign-in-add-modal"]'),
    ).toBeNull()
  })
  it('asks before discarding a session code step with an empty draft and clears a code after a new frame', async () => {
    const world = setup()
    world.emit(
      SIGNAL_PROFILE_FLOWS,
      profileFlowsSchema.parse({
        flows: [
          {
            operation: 'add_sign_in_method',
            step: 'phone_sent',
            address: '+15551234567',
            target: '+15551234567',
          },
        ],
      }),
    )
    await world.wrapper.get('[data-id="profile-sign-in-add"]').trigger('click')
    await flushPromises()
    expect(byId('profile-add-sms-code')).toBeDefined()
    await fill('profile-add-sms-code', '123456')
    world.emit(
      SIGNAL_PROFILE_FLOWS,
      profileFlowsSchema.parse({
        flows: [
          {
            operation: 'add_sign_in_method',
            step: 'phone_sent',
            address: '+15559876543',
            target: '+15559876543',
          },
        ],
      }),
    )
    await flushPromises()
    expect((byId('profile-add-sms-code') as HTMLInputElement).value).toBe('')
    byId('profile-sign-in-add-cancel').click()
    await flushPromises()
    expect(byId('modal-confirm')).toBeDefined()
  })
  it.each([
    {
      initial: methods,
      choose: 'profile-sign-in-choose-phone',
      address: 'profile-add-sms-phone',
      value: '+15557654321',
      request: 'profile-add-sms-request',
      code: 'profile-add-sms-code',
      send: 'profile-add-sms-send',
      action: 'profile_add_sms_request',
      payload: { phone: '+15557654321' },
    },
    {
      initial: passkeyOnly,
      choose: 'profile-sign-in-choose-password',
      address: 'profile-add-password-email',
      value: 'me@example.test',
      request: 'profile-add-password-request',
      code: 'profile-add-password-code',
      send: 'profile-add-password-send',
      action: 'profile_add_password_request',
      payload: { email: 'me@example.test' },
    },
  ])('empties the typed code as $send asks for another one', async (way) => {
    const world = setup(NO_STEP, way.initial, OFFERED, Date.now() - 60_000)
    await world.wrapper.get('[data-id="profile-sign-in-add"]').trigger('click')
    await flushPromises()
    byId(way.choose).click()
    await flushPromises()
    await fill(way.address, way.value)
    byId(way.request).click()
    await flushPromises()
    expect(byId(way.send)).toBeDefined()
    await fill(way.code, '123456')

    // The resend stays unanswered: the field is emptied by the press, not by
    // the answer.
    world.dispatch.mockImplementationOnce(() => ({
      loading: createSignal(false),
      done: new Promise<never>(() => undefined),
    }))
    byId(`${way.send}-again`).click()
    await flushPromises()
    expect(world.dispatch).toHaveBeenLastCalledWith(
      way.action,
      way.payload,
      expect.anything(),
    )
    expect((byId(way.code) as HTMLInputElement).value).toBe('')
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
    world.setMethods(methods.slice(0, 1))
    await flushPromises()
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

describe('profile sign-in page passkey-only warning', () => {
  it('warns a passkey-only account with a button per offered way and leaves when another way arrives', async () => {
    const world = setup(NO_STEP, passkeyOnly)
    const block = world.wrapper.get('[data-id="profile-sign-in-passkey-only"]')
    expect(block.text()).toContain('Only your passkeys can sign you in')
    expect(
      block.find('[data-id="profile-sign-in-passkey-only-password"]').text(),
    ).toBe('Add a password')
    expect(
      block.find('[data-id="profile-sign-in-passkey-only-phone"]').text(),
    ).toBe('Add a phone')
    expect(
      block
        .find('[data-id="profile-sign-in-passkey-only-link-oauth:github"]')
        .text(),
    ).toBe('Link GitHub')
    expect(block.findAll('button')).toHaveLength(3)
    expect(
      world.wrapper
        .find('[data-id="profile-sign-in-passkey-only-none"]')
        .exists(),
    ).toBe(false)
    world.setMethods([...passkeyOnly, methods[1]])
    await flushPromises()
    expect(
      world.wrapper.find('[data-id="profile-sign-in-passkey-only"]').exists(),
    ).toBe(false)
  })
  it('keeps the warning without buttons when nothing but a passkey is on', () => {
    const world = setup(NO_STEP, passkeyOnly, [{ key: 'passkey', name: null }])
    const block = world.wrapper.get('[data-id="profile-sign-in-passkey-only"]')
    expect(block.findAll('button')).toHaveLength(0)
    expect(
      block.get('[data-id="profile-sign-in-passkey-only-none"]').text(),
    ).toBe('No other way to sign in is available here.')
  })
  it('opens the dialog straight at the pressed way once no confirmation is needed', async () => {
    const world = setup(NO_STEP, passkeyOnly)
    await world.wrapper
      .get('[data-id="profile-sign-in-passkey-only-password"]')
      .trigger('click')
    await flushPromises()
    expect(byId('profile-add-password-email')).toBeDefined()
    expect(
      document.querySelector('[data-id="profile-sign-in-choose-password"]'),
    ).toBeNull()
  })
  it('keeps the pressed way live-looking while the dialog opens and turns the others off at once', async () => {
    const world = setup(NO_STEP, passkeyOnly)
    let answer!: (value: { reply: object }) => void
    world.dispatch.mockImplementationOnce(() => ({
      loading: createSignal(false),
      done: new Promise<{ reply: object }>((resolve) => {
        answer = resolve
      }),
    }))
    const pressed = byId(
      'profile-sign-in-passkey-only-password',
    ) as HTMLButtonElement
    const others = [
      'profile-sign-in-passkey-only-phone',
      'profile-sign-in-passkey-only-link-oauth:github',
      'profile-sign-in-add',
    ].map((id) => byId(id) as HTMLButtonElement)
    const starts = () =>
      world.dispatch.mock.calls.filter(
        ([action]) => action === 'hilos_step_up_start',
      )

    pressed.click()
    await flushPromises()
    expect(starts()).toHaveLength(1)
    expect(pressed.getAttribute('aria-busy')).toBe('true')
    expect(pressed.getAttribute('aria-disabled')).toBe('true')
    expect(pressed.disabled).toBe(false)
    expect(others.map((button) => button.disabled)).toEqual([true, true, true])

    pressed.click()
    for (const button of others) button.click()
    await flushPromises()
    expect(starts()).toHaveLength(1)

    answer({ reply: NO_STEP })
    await flushPromises()
    expect(byId('profile-add-password-email')).toBeDefined()
  })
  it('leaves a method switched off by the administrator out of the chooser', async () => {
    const world = setup(NO_STEP, passkeyOnly, [{ key: 'password', name: null }])
    await world.wrapper.get('[data-id="profile-sign-in-add"]').trigger('click')
    await flushPromises()
    expect(byId('profile-sign-in-choose-password')).toBeDefined()
    expect(
      document.querySelector('[data-id="profile-sign-in-choose-phone"]'),
    ).toBeNull()
    expect(
      world.wrapper
        .find('[data-id="profile-sign-in-passkey-only-phone"]')
        .exists(),
    ).toBe(false)
  })
})

describe('profile sign-in add dialog in flight', () => {
  it.each([
    {
      way: 'provider',
      pressed: 'profile-oauth-link-oauth:github',
      others: [
        'profile-sign-in-choose-password',
        'profile-sign-in-choose-phone',
        'profile-passkey-add',
      ],
    },
    {
      way: 'passkey',
      pressed: 'profile-passkey-add',
      others: [
        'profile-sign-in-choose-password',
        'profile-sign-in-choose-phone',
        'profile-oauth-link-oauth:github',
      ],
    },
  ])(
    'holds the dialog while the $way is out, its button keeping its look and focus until 300 ms',
    async (row) => {
      supportPasskeys()
      vi.spyOn(window, 'open').mockReturnValue({
        closed: false,
        close: vi.fn(),
        location: { replace: vi.fn() },
      } as unknown as Window)
      const world = setup(NO_STEP, passkeyOnly)
      await world.wrapper
        .get('[data-id="profile-sign-in-add"]')
        .trigger('click')
      await flushPromises()
      const options = heldReply('hilos_passkey_register_options')
      world.dispatch.mockImplementationOnce(options.handle)
      const pressed = byId(row.pressed) as HTMLButtonElement
      const others = row.others.map((id) => byId(id) as HTMLButtonElement)
      const cancel = byId('profile-sign-in-add-cancel') as HTMLButtonElement
      const close = byId('modal-close') as HTMLButtonElement
      const spinner = () =>
        pressed.querySelector('[data-id="loading-button-spinner"]')
      expect(lockedByFieldset(pressed)).toBe(false)

      pressed.focus()
      vi.useFakeTimers()
      pressed.click()
      await nextTick()
      expect(world.dispatch).toHaveBeenLastCalledWith(
        row.way === 'provider'
          ? 'hilos_link_oauth_start'
          : 'hilos_passkey_register_options',
        row.way === 'provider'
          ? expect.objectContaining({ provider: 'oauth:github' })
          : {},
        ...(row.way === 'provider' ? [] : [expect.anything()]),
      )
      const sent = world.dispatch.mock.calls.length
      expect(pressed.getAttribute('aria-busy')).toBe('true')
      expect(pressed.getAttribute('aria-disabled')).toBe('true')
      expect(pressed.matches(':disabled')).toBe(false)
      expect(lockedByFieldset(pressed)).toBe(false)
      expect(spinner()).toBeNull()
      expect(document.activeElement).toBe(pressed)
      expect(others.map((button) => button.disabled)).toEqual([
        true,
        true,
        true,
      ])
      expect(cancel.disabled).toBe(true)
      expect(close.disabled).toBe(true)
      tryClosing()
      await nextTick()
      expect(addStillOpen()).toBe(true)

      vi.advanceTimersByTime(299)
      await nextTick()
      expect(spinner()).toBeNull()
      expect(pressed.classList.contains('disabled')).toBe(false)
      vi.advanceTimersByTime(1)
      await nextTick()
      expect(spinner()).not.toBeNull()
      expect(pressed.classList.contains('disabled')).toBe(true)
      expect(pressed.matches(':disabled')).toBe(false)

      pressed.click()
      for (const button of others) button.click()
      await nextTick()
      expect(world.dispatch).toHaveBeenCalledTimes(sent)
      expect(document.activeElement).toBe(pressed)

      // The trip ends in the waiting window, the passkey on a refusal; either
      // way the chooser stands as it did, and Cancel closes it.
      if (row.way === 'provider') cancelOAuthTrip()
      else options.refuse('Passkeys are switched off')
      await flushPromises()
      expect(pressed.hasAttribute('aria-busy')).toBe(false)
      expect(others.map((button) => button.disabled)).toEqual([
        false,
        false,
        false,
      ])
      expect(cancel.disabled).toBe(false)
      cancel.click()
      await nextTick()
      expect(
        document.querySelector('[data-id="profile-sign-in-add-modal"]'),
      ).toBeNull()
    },
  )
  it('holds the dialog and locks the password while the confirmation is out, and frees them after a refusal', async () => {
    const world = setup(PASSWORD_STEP)
    await world.wrapper.get('[data-id="profile-sign-in-add"]').trigger('click')
    await flushPromises()
    await fill('step-up-password', 'wrong')
    const confirmation = heldReply('hilos_step_up_confirm')
    world.dispatch.mockImplementationOnce(confirmation.handle)
    const field = byId('step-up-password') as HTMLInputElement
    const confirm = byId(
      'profile-sign-in-add-step-up-confirm',
    ) as HTMLButtonElement
    const cancel = byId('profile-sign-in-add-cancel') as HTMLButtonElement
    const close = byId('modal-close') as HTMLButtonElement
    expect(lockedByFieldset(field)).toBe(false)

    byId('profile-sign-in-add-step-up').dispatchEvent(
      new Event('submit', { cancelable: true }),
    )
    await nextTick()
    expect(world.dispatch).toHaveBeenLastCalledWith(
      'hilos_step_up_confirm',
      expect.objectContaining({ password: 'wrong' }),
    )
    expect(lockedByFieldset(field)).toBe(true)
    expect(confirm.getAttribute('aria-busy')).toBe('true')
    expect(confirm.matches(':disabled')).toBe(false)
    expect(cancel.disabled).toBe(true)
    expect(close.disabled).toBe(true)
    tryClosing()
    await flushPromises()
    expect(addStillOpen()).toBe(true)

    confirmation.refuse('That password is not right')
    await flushPromises()
    expect(byId('step-up-password')).toBe(field)
    expect(field.value).toBe('wrong')
    expect(lockedByFieldset(field)).toBe(false)
    expect(cancel.disabled).toBe(false)
    expect(close.disabled).toBe(false)
    expect(byId('step-up-error').textContent).toContain(
      'That password is not right',
    )
  })
  it('holds the dialog while the confirmation sends another code, with only the confirmation out', async () => {
    const world = setup(EMAIL_STEP)
    await world.wrapper.get('[data-id="profile-sign-in-add"]').trigger('click')
    await flushPromises()
    const resend = heldReply('hilos_step_up_start')
    world.dispatch.mockImplementationOnce(resend.handle)
    const field = byId('step-up-code') as HTMLInputElement
    const cancel = byId('profile-sign-in-add-cancel') as HTMLButtonElement
    const close = byId('modal-close') as HTMLButtonElement
    expect(lockedByFieldset(field)).toBe(false)
    expect(cancel.disabled).toBe(false)

    byId('step-up-send-again').click()
    await nextTick()
    expect(world.dispatch).toHaveBeenLastCalledWith(
      'hilos_step_up_start',
      { operation: 'add_sign_in_method' },
      expect.anything(),
    )
    expect(lockedByFieldset(field)).toBe(true)
    expect(cancel.disabled).toBe(true)
    expect(close.disabled).toBe(true)
    tryClosing()
    await flushPromises()
    expect(addStillOpen()).toBe(true)

    resend.settle(EMAIL_STEP)
    await flushPromises()
    expect(byId('step-up-code')).toBe(field)
    expect(lockedByFieldset(field)).toBe(false)
    expect(cancel.disabled).toBe(false)
    expect(close.disabled).toBe(false)
  })
  it('holds the dialog and locks the number while its code request is out, and frees them after a refusal', async () => {
    const world = setup()
    await world.wrapper.get('[data-id="profile-sign-in-add"]').trigger('click')
    await flushPromises()
    byId('profile-sign-in-choose-phone').click()
    await flushPromises()
    await fill('profile-add-sms-phone', '+15557654321')
    const request = heldReply('profile_add_sms_request')
    world.dispatch.mockImplementationOnce(request.handle)
    const field = byId('profile-add-sms-phone') as HTMLInputElement
    const send = byId('profile-add-sms-request') as HTMLButtonElement
    const back = byId('profile-sign-in-add-back') as HTMLButtonElement
    const cancel = byId('profile-sign-in-add-cancel') as HTMLButtonElement
    expect(lockedByFieldset(field)).toBe(false)

    send.click()
    await nextTick()
    expect(world.dispatch).toHaveBeenLastCalledWith(
      'profile_add_sms_request',
      { phone: '+15557654321' },
      expect.anything(),
    )
    expect(lockedByFieldset(field)).toBe(true)
    expect(send.getAttribute('aria-busy')).toBe('true')
    expect(send.matches(':disabled')).toBe(false)
    expect(back.disabled).toBe(true)
    expect(cancel.disabled).toBe(true)
    expect((byId('modal-close') as HTMLButtonElement).disabled).toBe(true)
    tryClosing()
    await flushPromises()
    expect(addStillOpen()).toBe(true)

    request.refuse('That number cannot get codes')
    await flushPromises()
    expect(byId('profile-add-sms-phone')).toBe(field)
    expect(field.value).toBe('+15557654321')
    expect(lockedByFieldset(field)).toBe(false)
    expect(back.disabled).toBe(false)
    expect(cancel.disabled).toBe(false)
    expect(byId('profile-add-sms-error').textContent).toContain(
      'That number cannot get codes',
    )
  })
})
