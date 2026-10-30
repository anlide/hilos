import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  ActionError,
  AUTH_ACTION_PASSKEY_REGISTER_OPTIONS,
  bindProfileFlows,
  createSignal,
  HILOS_PROFILE_IDENTITIES_LIST,
  PROFILE_UNLINK_IDENTITY_ACTION,
  profileFlowsSchema,
  ScopeManager,
  SIGNAL_PROFILE_FLOWS,
  resolveHilosProfileSignInMethods,
  type HilosAuthContext,
  type HilosConnection,
  type HilosProfileSignInMethod,
  type ProjectSignal,
} from '@hilos/core'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { profileSectionFixture } from '../../core/test/profile/profileSectionFixture.js'
import { HilosProfileSignInPage } from '../src/profile/HilosProfileSignInPage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

/** The server's answer to the add-a-way-in confirmation start. */
const NO_STEP = { required: false, purpose: 'add a way to sign in' }
const PASSWORD_STEP = {
  required: true,
  purpose: 'add a way to sign in',
  method: 'password',
}

/** The two ways that ask for a code, and what each step of theirs is called. */
const CODE_WAYS = [
  {
    way: 'phone',
    choose: 'profile-sign-in-choose-phone',
    address: 'profile-add-sms-phone',
    value: '+15551234567',
    payload: { phone: '+15551234567' },
    submit: 'profile-add-sms-request',
    action: 'profile_add_sms_request',
    code: 'profile-add-sms-code',
    send: 'profile-add-sms-send',
  },
  {
    way: 'password',
    choose: 'profile-sign-in-choose-password',
    address: 'profile-add-password-email',
    value: 'me@example.test',
    payload: { email: 'me@example.test' },
    submit: 'profile-add-password-request',
    action: 'profile_add_password_request',
    code: 'profile-add-password-code',
    send: 'profile-add-password-send',
  },
]

/** The methods the session offers: on, ready, in button order. */
const OFFERED = [
  { key: 'password', name: null },
  { key: 'sms', name: null },
  { key: 'oauth:github', name: 'GitHub' },
]
const PASSKEY_ONLY = resolveHilosProfileSignInMethods(
  [
    {
      id: 3,
      type: 'passkey',
      provider: null,
      identifier: 'opaque',
      verified: true,
    },
  ],
  [],
)
const MAGIC_LINK = resolveHilosProfileSignInMethods(
  [
    {
      id: 1,
      type: 'magic_link',
      provider: null,
      identifier: 'a@example.test',
      verified: true,
    },
  ],
  [],
)
const LINK_AND_PASSKEY = [...MAGIC_LINK, ...PASSKEY_ONLY]
const PROFILE_METHODS = resolveHilosProfileSignInMethods(
  [
    {
      id: 10,
      type: 'password',
      provider: null,
      identifier: 'a@example.test',
      verified: true,
    },
    {
      id: 11,
      type: 'sms',
      provider: null,
      identifier: '+15551234567',
      verified: true,
    },
    {
      id: 12,
      type: 'sms',
      provider: null,
      identifier: '+15557654321',
      verified: false,
    },
    {
      id: 13,
      type: 'magic_link',
      provider: null,
      identifier: 'b@example.test',
      verified: true,
    },
    {
      id: 14,
      type: 'oauth',
      provider: 'oauth:github',
      identifier: 'octocat',
      verified: true,
    },
    {
      id: 15,
      type: 'oauth',
      provider: 'oauth:google',
      identifier: 'c@example.test',
      verified: true,
    },
    {
      id: 16,
      type: 'oauth',
      provider: 'oauth:other',
      identifier: 'other-account',
      verified: false,
    },
    {
      id: 17,
      type: 'passkey',
      provider: null,
      identifier: 'opaque',
      verified: true,
    },
  ],
  [],
)

/** Feed the framework list and normalized identities to the page scope. */
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
  page.lists.upsert(HILOS_PROFILE_IDENTITIES_LIST, 1, {
    identities,
    passkeyCredentials: [],
  })
}

function setup(
  opening: object = NO_STEP,
  methods = resolveHilosProfileSignInMethods([], []),
  offered: readonly object[] = OFFERED,
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
                    resendAt: 1_900_000_000_000,
                    expiresAt: 1_900_000_600_000,
                  },
                }
              : {},
        ),
      }
    },
  )
  const context = {
    actions: { dispatch },
    connection: {
      on: (_: string, handler: (signal: ProjectSignal) => void) => {
        listeners.add(handler)
        return () => listeners.delete(handler)
      },
    },
    scopes: new ScopeManager(),
    channels: [],
  } as unknown as HilosAuthContext
  const stopFlows = bindProfileFlows(context.connection as HilosConnection)
  emit(SIGNAL_PROFILE_FLOWS, profileFlowsSchema.parse({ flows: [] }))
  context.scopes.session.data.set('authMethods', offered)
  setMethods(context.scopes, methods)
  const router = profileSectionFixture(context.scopes, 'hilos_profile_sign_in')
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: router }],
  })
  const fixture = TestBed.createComponent(HilosProfileSignInPage)
  fixture.componentRef.setInput('context', context)
  fixture.detectChanges()
  const node = (id: string) =>
    (fixture.nativeElement as HTMLElement).querySelector<HTMLElement>(
      `[data-id="${id}"]`,
    )!
  const fill = (id: string, value: string) => {
    const input = node(id) as HTMLInputElement
    input.value = value
    input.dispatchEvent(new Event('input', { bubbles: true }))
    fixture.detectChanges()
  }
  return {
    dispatch,
    fill,
    fixture,
    listeners,
    emit,
    stopFlows,
    node,
    setMethods: (next: readonly HilosProfileSignInMethod[]) => {
      setMethods(context.scopes, next)
      fixture.detectChanges()
    },
  }
}

afterEach(() => {
  vi.useRealTimers()
})

it('renders each profile method with its glyph and normalized subtitle', () => {
  const { node } = setup(NO_STEP, PROFILE_METHODS, [
    ...OFFERED,
    { key: 'oauth:google', name: 'Google' },
    { key: 'oauth:other', name: 'Other' },
  ])
  const rows = [
    ['10', 'bi-lock', 'Password', 'a@example.test'],
    ['11', 'bi-phone', 'Phone', '+15551234567 · verified'],
    ['12', 'bi-phone', 'Phone', '+15557654321'],
    ['13', 'bi-envelope', 'Email link', 'b@example.test'],
    ['14', 'bi-github', 'GitHub', 'octocat'],
    ['15', 'bi-google', 'Google', 'c@example.test'],
    ['16', 'bi-box-arrow-in-right', 'Other', 'other-account'],
  ] as const
  for (const [key, glyph, title, subtitle] of rows) {
    const row = fixtureRow(node, key)
    expect(Array.from(row.querySelector('i')!.classList).sort()).toEqual(
      ['bi', glyph, 'fs-5', 'text-body-secondary', 'flex-shrink-0'].sort(),
    )
    expect(
      row.querySelector('[data-id="identity-type"]')?.textContent?.trim(),
    ).toBe(title)
    expect(
      row.querySelector('[data-id="identity-identifier"]')?.textContent?.trim(),
    ).toBe(subtitle)
    expect(row.querySelector('[data-id="identity-verified"]')).toBeNull()
    expect(row.querySelector('[data-id="identity-unverified"]')).toBeNull()
  }
  const passkey = fixtureRow(node, '17')
  expect(passkey.querySelector('i')?.className).toContain('bi-fingerprint')
  expect(
    passkey
      .querySelector('[data-id="identity-passkey-added"]')
      ?.textContent?.trim(),
  ).toBe('Passkey')
  const add = node('profile-sign-in-add')
  expect(add.querySelector('i')?.className).toBe('bi bi-plus-lg me-1')
  expect(add.querySelector('i')?.getAttribute('aria-hidden')).toBe('true')
  expect(add.textContent).toContain('Add a way to sign in')
})

it('uses provider glyphs in the live chooser and its inert twin', async () => {
  const { fixture, node } = setup(NO_STEP, MAGIC_LINK, [
    ...OFFERED,
    { key: 'oauth:google', name: 'Google' },
    { key: 'oauth:other', name: 'Other' },
  ])
  node('profile-sign-in-add').click()
  await settle(fixture)
  const twin = (fixture.nativeElement as HTMLElement).querySelector(
    '[data-id="profile-sign-in-add-modal"] .invisible[inert].d-flex',
  )!
  for (const [provider, glyph] of [
    ['github', 'bi-github'],
    ['google', 'bi-google'],
    ['other', 'bi-box-arrow-in-right'],
  ]) {
    const live = node(`profile-oauth-link-oauth:${provider}`)
    const copy = Array.from(twin.querySelectorAll('span.btn')).find((choice) =>
      choice.textContent?.includes(
        provider === 'github'
          ? 'Continue with GitHub'
          : provider === 'google'
            ? 'Continue with Google'
            : 'Continue with Other',
      ),
    )!
    for (const choice of [live, copy]) {
      expect(Array.from(choice.querySelector('i')!.classList).sort()).toEqual(
        ['bi', glyph, 'fs-5'].sort(),
      )
      expect(choice.querySelector('i')?.getAttribute('aria-hidden')).toBe(
        'true',
      )
    }
  }
})

it('keeps Change available and explains why the sole password cannot be removed', () => {
  const { node, setMethods } = setup()
  setMethods(PROFILE_METHODS.slice(0, 1))
  const change = node('profile-password-change') as HTMLButtonElement
  const remove = node('identity-unlink') as HTMLButtonElement
  expect(change.disabled).toBe(false)
  expect(remove.disabled).toBe(true)
  expect(node('identity-unlink-blocked').textContent).toContain(
    'You cannot remove your only login method',
  )
  expect(node('identity-unlink-blocked').querySelector('i')?.className).toBe(
    'bi bi-exclamation-triangle me-1',
  )
})

function fixtureRow(
  node: (id: string) => HTMLElement,
  key: string,
): HTMLElement {
  return node('profile-identities-list').querySelector(
    `[data-identity-key="${key}"]`,
  )!
}

/**
 * Let the answers the page awaits arrive, and draw what they changed.
 *
 * @param fixture The page under test.
 */
async function settle(fixture: ComponentFixture<unknown>): Promise<void> {
  await fixture.whenStable()
  fixture.detectChanges()
}

it('adds a phone through two steps and releases its listeners', async () => {
  const { dispatch, fill, fixture, listeners, node, stopFlows } = setup()
  node('profile-sign-in-add').click()
  await settle(fixture)
  node('profile-sign-in-choose-phone').click()
  fixture.detectChanges()
  fill('profile-add-sms-phone', '+15551234567')
  node('profile-add-sms-request').click()
  await settle(fixture)
  fill('profile-add-sms-code', '123456')
  node('profile-add-sms-confirm').click()
  await settle(fixture)
  expect(dispatch).toHaveBeenLastCalledWith('profile_add_sms_confirm', {
    code: '123456',
  })
  expect(node('profile-sign-in-add-modal')).toBeNull()
  fixture.destroy()
  stopFlows()
  expect(listeners.size).toBe(0)
})

it('asks before discarding a session code step with no draft and clears a code after a new frame', async () => {
  const { emit, fill, fixture, node } = setup()
  emit(
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
  node('profile-sign-in-add').click()
  await settle(fixture)
  expect(node('profile-add-sms-code')).not.toBeNull()
  fill('profile-add-sms-code', '123456')
  emit(
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
  fixture.detectChanges()
  expect((node('profile-add-sms-code') as HTMLInputElement).value).toBe('')
  node('profile-sign-in-add-cancel').click()
  fixture.detectChanges()
  expect(document.querySelector('[data-id="modal-confirm"]')).not.toBeNull()
})

it('opens at the confirmation step when the server asks, and shows the chooser once confirmed', async () => {
  const { dispatch, fill, fixture, node } = setup(PASSWORD_STEP)
  node('profile-sign-in-add').click()
  await settle(fixture)
  expect(dispatch).toHaveBeenCalledWith(
    'hilos_step_up_start',
    { operation: 'add_sign_in_method' },
    expect.anything(),
  )
  expect(
    (fixture.nativeElement as HTMLElement).querySelector('.modal-title')
      ?.textContent,
  ).toBe("Confirm it's you")
  expect(node('profile-sign-in-choose-phone')).toBeNull()
  expect(node('profile-sign-in-add-back')).toBeNull()
  expect(node('profile-sign-in-add-step-up-confirm')).not.toBeNull()
  fill('step-up-password', 'secret')
  node('profile-sign-in-add-step-up').dispatchEvent(
    new Event('submit', { cancelable: true }),
  )
  await settle(fixture)
  expect(dispatch).toHaveBeenLastCalledWith('hilos_step_up_confirm', {
    operation: 'add_sign_in_method',
    method: 'password',
    code: '',
    backupCode: false,
    password: 'secret',
    passkey: null,
  })
  expect(node('profile-sign-in-choose-phone')).not.toBeNull()
  fixture.destroy()
})

it('warns a passkey-only account with a button per offered way and drops it when another way arrives', () => {
  const { node, setMethods } = setup(NO_STEP, PASSKEY_ONLY)
  const block = node('profile-sign-in-passkey-only')
  expect(block.textContent).toContain('Only your passkeys can sign you in')
  expect(
    node('profile-sign-in-passkey-only-password').textContent?.trim(),
  ).toBe('Add a password')
  expect(node('profile-sign-in-passkey-only-phone').textContent?.trim()).toBe(
    'Add a phone',
  )
  expect(
    node('profile-sign-in-passkey-only-link-oauth:github').textContent?.trim(),
  ).toBe('Link GitHub')
  expect(block.querySelectorAll('button')).toHaveLength(3)
  expect(node('profile-sign-in-passkey-only-none')).toBeNull()
  setMethods(
    resolveHilosProfileSignInMethods(
      [
        {
          id: 3,
          type: 'passkey',
          provider: null,
          identifier: 'opaque',
          verified: true,
        },
        {
          id: 4,
          type: 'sms',
          provider: null,
          identifier: '+15551234567',
          verified: true,
        },
      ],
      [],
    ),
  )
  expect(node('profile-sign-in-passkey-only')).toBeNull()
})

it('keeps the warning without buttons when nothing but a passkey is on', () => {
  const { node } = setup(NO_STEP, PASSKEY_ONLY, [
    { key: 'passkey', name: null },
  ])
  expect(
    node('profile-sign-in-passkey-only').querySelectorAll('button'),
  ).toHaveLength(0)
  expect(node('profile-sign-in-passkey-only-none').textContent?.trim()).toBe(
    'No other way to sign in is available here.',
  )
})

it('opens the dialog straight at the pressed way, and leaves a switched-off method out of the chooser', async () => {
  const { fixture, node } = setup(NO_STEP, PASSKEY_ONLY, [
    { key: 'password', name: null },
  ])
  expect(node('profile-sign-in-passkey-only-phone')).toBeNull()
  node('profile-sign-in-passkey-only-password').click()
  await settle(fixture)
  expect(node('profile-add-password-email')).not.toBeNull()
  expect(node('profile-sign-in-choose-password')).toBeNull()
  node('profile-sign-in-add-back').click()
  fixture.detectChanges()
  expect(node('profile-sign-in-choose-password')).not.toBeNull()
  expect(node('profile-sign-in-choose-phone')).toBeNull()
})

it.each(CODE_WAYS)(
  'draws the $way send block on the code step and empties the code before it asks for another one',
  async (way) => {
    // The send gate of the code request has reopened: Send again is allowed.
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(1_900_000_001_000)
    const { dispatch, fill, fixture, node } = setup()
    node('profile-sign-in-add').click()
    await settle(fixture)
    node(way.choose).click()
    fixture.detectChanges()
    fill(way.address, way.value)
    node(way.submit).click()
    await settle(fixture)
    fill(way.code, '123456')

    expect(node(way.send)).not.toBeNull()
    expect(
      (fixture.nativeElement as HTMLElement).querySelector('[inert] [data-id]'),
    ).toBeNull()
    const again = node(`${way.send}-again`) as HTMLButtonElement
    expect(again.disabled).toBe(false)
    const answer = dispatch.getMockImplementation()!
    const codeAtSend: string[] = []
    dispatch.mockImplementation((action: string) => {
      if (action === way.action) {
        // Draw the page as the request leaves: its code field is empty already.
        fixture.detectChanges()
        codeAtSend.push((node(way.code) as HTMLInputElement).value)
      }
      return answer(action)
    })
    again.click()
    await settle(fixture)
    expect(codeAtSend).toEqual([''])
    expect(dispatch).toHaveBeenLastCalledWith(
      way.action,
      way.payload,
      expect.anything(),
    )
    fixture.destroy()
  },
)

/**
 * A dispatch answer that stays in flight until the test settles it.
 *
 * @returns The answer to hand back from dispatch, and how to settle it.
 */
function held(): {
  answer: {
    loading: ReturnType<typeof createSignal<boolean>>
    done: Promise<{ reply: object }>
  }
  resolve: (value: { reply: object }) => void
  reject: (error: unknown) => void
} {
  let resolve!: (value: { reply: object }) => void
  let reject!: (error: unknown) => void
  const done = new Promise<{ reply: object }>((settle, refuse) => {
    resolve = settle
    reject = refuse
  })

  return { answer: { loading: createSignal(false), done }, resolve, reject }
}

/**
 * Let the answers the page awaits arrive under a fake clock, and draw what they
 * changed.
 *
 * @param fixture The page under test.
 */
async function flush(fixture: ComponentFixture<unknown>): Promise<void> {
  for (let tick = 0; tick < 10; tick += 1) await Promise.resolve()
  fixture.detectChanges()
}

/**
 * Let every answer the page awaits arrive on the real clock, however long its
 * chain, and draw what it changed.
 *
 * @param fixture The page under test.
 */
async function drain(fixture: ComponentFixture<unknown>): Promise<void> {
  await new Promise((resolve) => setTimeout(resolve, 0))
  fixture.detectChanges()
}

/**
 * Move the fake clock and draw what it changed.
 *
 * @param fixture The page under test.
 * @param ms How far to move the clock.
 */
function wait(fixture: ComponentFixture<unknown>, ms: number): void {
  vi.advanceTimersByTime(ms)
  fixture.detectChanges()
}

/** The delayed spinner of a LoadingButton, if it shows. */
function spinnerOf(button: HTMLElement): Element | null {
  return button.querySelector('[data-id="loading-button-spinner"]')
}

// In a group the pressed button carries the flight: it is locked at once and
// keeps its look until its one spinner comes; the others are off natively from
// the same moment, by a flight that is not theirs (HIL-1768).
describe('profile sign-in page locks a pressed button at once and the others by its flight', () => {
  afterEach(() => {
    delete (globalThis as { PublicKeyCredential?: unknown }).PublicKeyCredential
    delete (navigator as { credentials?: unknown }).credentials
  })

  it.each(['profile-sign-in-passkey-only-password', 'profile-sign-in-add'])(
    'keeps %s live-looking while the dialog opens and turns the others off at once',
    async (pressedId) => {
      vi.useFakeTimers()
      const { dispatch, fixture, node } = setup(NO_STEP, PASSKEY_ONLY)
      const opening = held()
      dispatch.mockImplementationOnce(() => opening.answer)
      const pressed = node(pressedId) as HTMLButtonElement
      const others = [
        'profile-sign-in-passkey-only-password',
        'profile-sign-in-passkey-only-phone',
        'profile-sign-in-passkey-only-link-oauth:github',
        'profile-sign-in-add',
      ]
        .filter((id) => id !== pressedId)
        .map((id) => node(id) as HTMLButtonElement)
      const starts = () =>
        dispatch.mock.calls.filter(
          ([action]) => action === 'hilos_step_up_start',
        )

      pressed.click()
      fixture.detectChanges()
      expect(starts()).toHaveLength(1)
      expect(pressed.getAttribute('aria-busy')).toBe('true')
      expect(pressed.getAttribute('aria-disabled')).toBe('true')
      expect(pressed.disabled).toBe(false)
      expect(others.map((button) => button.disabled)).toEqual([
        true,
        true,
        true,
      ])
      for (const button of others)
        expect(button.hasAttribute('aria-busy')).toBe(false)

      pressed.click()
      for (const button of others) button.click()
      fixture.detectChanges()
      expect(starts()).toHaveLength(1)

      wait(fixture, 299)
      expect(spinnerOf(pressed)).toBeNull()
      expect(pressed.classList.contains('disabled')).toBe(false)
      wait(fixture, 1)
      expect(spinnerOf(pressed)).not.toBeNull()
      expect(pressed.classList.contains('disabled')).toBe(true)
      expect(pressed.disabled).toBe(false)
      for (const button of others) expect(spinnerOf(button)).toBeNull()

      opening.resolve({ reply: NO_STEP })
      await flush(fixture)
      expect(node('profile-sign-in-add-modal')).not.toBeNull()
      expect(starts()).toHaveLength(1)
      fixture.destroy()
    },
  )

  it('keeps a pressed passkey live-looking while the provider beside it goes off at once', async () => {
    ;(globalThis as { PublicKeyCredential?: unknown }).PublicKeyCredential =
      class {}
    Object.defineProperty(navigator, 'credentials', {
      configurable: true,
      value: { create: vi.fn(), get: vi.fn() },
    })
    const { dispatch, fixture, node } = setup(NO_STEP, MAGIC_LINK, [
      ...OFFERED,
      { key: 'passkey', name: null },
    ])
    node('profile-sign-in-add').click()
    await settle(fixture)
    const options = held()
    dispatch.mockImplementationOnce(() => options.answer)
    const passkey = node('profile-passkey-add') as HTMLButtonElement
    const provider = node(
      'profile-oauth-link-oauth:github',
    ) as HTMLButtonElement
    const requests = () =>
      dispatch.mock.calls.filter(
        ([action]) => action === AUTH_ACTION_PASSKEY_REGISTER_OPTIONS,
      )
    expect(provider.disabled).toBe(false)

    passkey.click()
    fixture.detectChanges()
    expect(requests()).toHaveLength(1)
    expect(passkey.getAttribute('aria-busy')).toBe('true')
    expect(passkey.getAttribute('aria-disabled')).toBe('true')
    expect(passkey.disabled).toBe(false)
    expect(provider.disabled).toBe(true)
    expect(provider.hasAttribute('aria-busy')).toBe(false)

    passkey.click()
    provider.click()
    fixture.detectChanges()
    expect(requests()).toHaveLength(1)
    expect(
      dispatch.mock.calls.filter(
        ([action]) => action !== 'hilos_step_up_start',
      ),
    ).toHaveLength(1)

    options.reject(
      new ActionError(
        AUTH_ACTION_PASSKEY_REGISTER_OPTIONS,
        'fail',
        'Passkeys are off.',
      ),
    )
    await drain(fixture)
    expect(passkey.hasAttribute('aria-busy')).toBe(false)
    expect(passkey.hasAttribute('aria-disabled')).toBe(false)
    expect(provider.disabled).toBe(false)
    fixture.destroy()
  })

  it('locks Remove at once by its own flight, keeps Cancel off and sends once', async () => {
    vi.useFakeTimers()
    const { dispatch, fixture, node } = setup(NO_STEP, LINK_AND_PASSKEY)
    node('identity-unlink').click()
    fixture.detectChanges()
    const removal = held()
    dispatch.mockImplementationOnce(() => removal.answer)
    const remove = node('identity-unlink-yes') as HTMLButtonElement
    const cancel = node('identity-unlink-cancel') as HTMLButtonElement
    const sends = () =>
      dispatch.mock.calls.filter(
        ([action]) => action === PROFILE_UNLINK_IDENTITY_ACTION,
      )

    remove.click()
    fixture.detectChanges()
    expect(sends()).toHaveLength(1)
    expect(remove.getAttribute('aria-busy')).toBe('true')
    expect(remove.getAttribute('aria-disabled')).toBe('true')
    expect(remove.disabled).toBe(false)
    expect(cancel.disabled).toBe(true)

    remove.click()
    wait(fixture, 299)
    expect(sends()).toHaveLength(1)
    expect(spinnerOf(remove)).toBeNull()
    expect(remove.classList.contains('disabled')).toBe(false)
    wait(fixture, 1)
    expect(spinnerOf(remove)).not.toBeNull()
    expect(remove.disabled).toBe(false)

    removal.resolve({ reply: {} })
    await flush(fixture)
    expect(node('profile-unlink-modal')).toBeNull()
    expect(sends()).toHaveLength(1)
    fixture.destroy()
  })

  it('locks the confirmation of the step by its own flight, not natively', async () => {
    const { dispatch, fill, fixture, node } = setup(PASSWORD_STEP)
    node('profile-sign-in-add').click()
    await settle(fixture)
    fill('step-up-password', 'secret')
    const confirmation = held()
    dispatch.mockImplementationOnce(() => confirmation.answer)
    const confirm = node(
      'profile-sign-in-add-step-up-confirm',
    ) as HTMLButtonElement
    const confirms = () =>
      dispatch.mock.calls.filter(
        ([action]) => action === 'hilos_step_up_confirm',
      )
    const submitStep = () =>
      node('profile-sign-in-add-step-up').dispatchEvent(
        new Event('submit', { cancelable: true }),
      )

    submitStep()
    fixture.detectChanges()
    expect(confirms()).toHaveLength(1)
    expect(confirm.getAttribute('aria-busy')).toBe('true')
    expect(confirm.getAttribute('aria-disabled')).toBe('true')
    expect(confirm.disabled).toBe(false)

    confirm.click()
    submitStep()
    fixture.detectChanges()
    expect(confirms()).toHaveLength(1)

    confirmation.resolve({ reply: {} })
    await drain(fixture)
    expect(node('profile-sign-in-choose-phone')).not.toBeNull()
    fixture.destroy()
  })

  it('turns Save off natively while the form is unfit, and only locks it by its own flight', async () => {
    const { dispatch, fill, fixture, node } = setup(NO_STEP, MAGIC_LINK)
    node('profile-sign-in-add').click()
    await settle(fixture)
    node('profile-sign-in-choose-password').click()
    fixture.detectChanges()
    const save = node('profile-add-password-save') as HTMLButtonElement
    expect(save.disabled).toBe(true)
    expect(save.hasAttribute('aria-busy')).toBe(false)
    fill('profile-add-password-new', 'new-secret')
    fill('profile-add-password-confirm', 'new-secret')
    expect(save.disabled).toBe(false)
    const setting = held()
    dispatch.mockImplementationOnce(() => setting.answer)
    const sends = () =>
      dispatch.mock.calls.filter(
        ([action]) => action === 'profile_set_password',
      )

    save.click()
    fixture.detectChanges()
    expect(sends()).toHaveLength(1)
    expect(save.getAttribute('aria-busy')).toBe('true')
    expect(save.getAttribute('aria-disabled')).toBe('true')
    expect(save.disabled).toBe(false)
    expect(
      (node('profile-sign-in-add-back') as HTMLButtonElement).disabled,
    ).toBe(true)

    save.click()
    fixture.detectChanges()
    expect(sends()).toHaveLength(1)
    fixture.destroy()
  })
})
