import { StrictMode } from 'react'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
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
  type HilosRouter,
  type ProjectSignal,
} from '@hilos/core'
import { HilosProfileSignInPage } from '../src/profile/HilosProfileSignInPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

afterEach(cleanup)

/** The server's answer to the add-a-way-in confirmation start. */
const NO_STEP = { required: false, purpose: 'add a way to sign in' }
const PASSWORD_STEP = {
  required: true,
  purpose: 'add a way to sign in',
  method: 'password',
}

/** The answer to a code request: the code is out, and another may follow at once. */
const SENT = {
  sent: true,
  resendAt: Date.now() - 1_000,
  expiresAt: Date.now() + 600_000,
}
const CODE_REQUESTS = [
  'profile_add_sms_request',
  'profile_add_password_request',
]

/** The methods the session offers: on, ready, in button order. */
const OFFERED = [
  { key: 'password', name: null },
  { key: 'sms', name: null },
  { key: 'oauth:github', name: 'GitHub' },
]
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

it('renders each profile method with its glyph and the normalized subtitle', () => {
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
    const row = document.querySelector(
      `[data-identity-key="${key}"]`,
    ) as HTMLElement
    expect(row.querySelector('i')?.className.split(' ')).toEqual([
      'bi',
      glyph,
      'fs-5',
      'text-body-secondary',
      'flex-shrink-0',
    ])
    expect(row.querySelector('[data-id="identity-type"]')?.textContent).toBe(
      title,
    )
    expect(
      row.querySelector('[data-id="identity-identifier"]')?.textContent,
    ).toBe(subtitle)
    expect(row.querySelector('[data-id="identity-verified"]')).toBeNull()
    expect(row.querySelector('[data-id="identity-unverified"]')).toBeNull()
  }
  const passkey = document.querySelector('[data-identity-key="17"]')!
  expect(passkey.querySelector('i')?.className).toContain('bi-fingerprint')
  expect(
    passkey.querySelector('[data-id="identity-passkey-added"]')?.textContent,
  ).toBe('Passkey')
  const add = node('profile-sign-in-add')
  expect(add.querySelector('i')?.className).toBe('bi bi-plus-lg me-1')
  expect(add.querySelector('i')?.getAttribute('aria-hidden')).toBe('true')
  expect(add.textContent).toContain('Add a way to sign in')
})

it('uses provider glyphs in the live chooser and its inert twin', async () => {
  const { node } = setup(NO_STEP, MAGIC_LINK, [
    ...OFFERED,
    { key: 'oauth:google', name: 'Google' },
    { key: 'oauth:other', name: 'Other' },
  ])
  await act(async () => fireEvent.click(node('profile-sign-in-add')))
  const twin = document.querySelector(
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
      expect(choice.querySelector('i')?.className.split(' ')).toEqual([
        'bi',
        glyph,
        'fs-5',
      ])
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

function setup(
  opening: object = NO_STEP,
  methods = MAGIC_LINK,
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
            : CODE_REQUESTS.includes(action)
              ? { reply: SENT }
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
  const router = {
    pageIdentity: createSignal(undefined),
    currentRoute: createSignal({
      page: 'hilos_profile_sign_in',
      params: {},
      admin: false,
    }),
  } as unknown as HilosRouter
  const page = (
    <StrictMode>
      <HilosRouterContext.Provider value={router}>
        <HilosProfileSignInPage context={context} />
      </HilosRouterContext.Provider>
    </StrictMode>
  )
  const { unmount: unmountPage } = render(page)
  const node = (id: string) =>
    document.querySelector(`[data-id="${id}"]`) as HTMLElement
  return {
    dispatch,
    listeners,
    emit,
    node,
    unmount: () => {
      unmountPage()
      stopFlows()
    },
    setMethods: (next: readonly HilosProfileSignInMethod[]) =>
      act(() => setMethods(context.scopes, next)),
  }
}

it('adds a password under StrictMode and removes every listener on unmount', async () => {
  const { dispatch, listeners, node, unmount } = setup()
  await act(async () => {
    fireEvent.click(node('profile-sign-in-add'))
  })
  fireEvent.click(node('profile-sign-in-choose-password'))
  fireEvent.change(node('profile-add-password-new'), {
    target: { value: 'new-secret' },
  })
  fireEvent.change(node('profile-add-password-confirm'), {
    target: { value: 'new-secret' },
  })
  await act(async () => {
    fireEvent.click(node('profile-add-password-save'))
  })
  expect(dispatch).toHaveBeenCalledWith('profile_set_password', {
    newPassword: 'new-secret',
  })
  act(() => {
    for (const listener of [...listeners])
      listener({
        kind: 'project',
        type: 'profile_password_updated',
        data: { mode: 'added' },
      } as ProjectSignal)
  })
  expect(node('profile-sign-in-add-modal')).toBeNull()
  unmount()
  expect(listeners.size).toBe(0)
})

it('opens at the confirmation step when the server asks, and shows the chooser once confirmed', async () => {
  const { dispatch, node } = setup(PASSWORD_STEP)
  await act(async () => {
    fireEvent.click(node('profile-sign-in-add'))
  })
  expect(dispatch).toHaveBeenCalledWith(
    'hilos_step_up_start',
    { operation: 'add_sign_in_method' },
    expect.anything(),
  )
  expect(document.querySelector('.modal-title')?.textContent).toBe(
    "Confirm it's you",
  )
  expect(node('profile-sign-in-choose-phone')).toBeNull()
  expect(node('profile-sign-in-add-back')).toBeNull()
  expect(node('profile-sign-in-add-step-up-confirm')).not.toBeNull()
  fireEvent.change(node('step-up-password'), { target: { value: 'secret' } })
  await act(async () => {
    fireEvent.submit(node('profile-sign-in-add-step-up'))
  })
  expect(dispatch).toHaveBeenLastCalledWith('hilos_step_up_confirm', {
    operation: 'add_sign_in_method',
    method: 'password',
    code: '',
    backupCode: false,
    password: 'secret',
    passkey: null,
  })
  expect(node('profile-sign-in-choose-phone')).not.toBeNull()
  expect(document.querySelector('.modal-title')?.textContent).toBe(
    'Add a way to sign in',
  )
})

it('asks before discarding a session code step with no draft and clears a code after a new frame', async () => {
  const { emit, node } = setup()
  act(() =>
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
    ),
  )
  await act(async () => fireEvent.click(node('profile-sign-in-add')))
  expect(node('profile-add-sms-code')).not.toBeNull()
  fireEvent.change(node('profile-add-sms-code'), {
    target: { value: '123456' },
  })
  act(() =>
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
    ),
  )
  expect((node('profile-add-sms-code') as HTMLInputElement).value).toBe('')
  fireEvent.click(node('profile-sign-in-add-cancel'))
  expect(node('modal-confirm')).not.toBeNull()
})

it('warns a passkey-only account with a button per offered way and drops it when another way arrives', () => {
  const { node, setMethods } = setup(NO_STEP, PASSKEY_ONLY)
  const block = node('profile-sign-in-passkey-only')
  expect(block.textContent).toContain('Only your passkeys can sign you in')
  expect(node('profile-sign-in-passkey-only-password').textContent).toBe(
    'Add a password',
  )
  expect(node('profile-sign-in-passkey-only-phone').textContent).toBe(
    'Add a phone',
  )
  expect(
    node('profile-sign-in-passkey-only-link-oauth:github').textContent,
  ).toBe('Link GitHub')
  expect(block.querySelectorAll('button')).toHaveLength(3)
  expect(node('profile-sign-in-passkey-only-none')).toBeNull()
  setMethods([...PASSKEY_ONLY, ...MAGIC_LINK])
  expect(node('profile-sign-in-passkey-only')).toBeNull()
})

it('keeps the warning without buttons when nothing but a passkey is on', () => {
  const { node } = setup(NO_STEP, PASSKEY_ONLY, [
    { key: 'passkey', name: null },
  ])
  expect(
    node('profile-sign-in-passkey-only').querySelectorAll('button'),
  ).toHaveLength(0)
  expect(node('profile-sign-in-passkey-only-none').textContent).toBe(
    'No other way to sign in is available here.',
  )
})

it('opens the dialog straight at the pressed way, and leaves a switched-off method out of the chooser', async () => {
  const { node } = setup(NO_STEP, PASSKEY_ONLY, [
    { key: 'password', name: null },
  ])
  expect(node('profile-sign-in-passkey-only-phone')).toBeNull()
  await act(async () => {
    fireEvent.click(node('profile-sign-in-passkey-only-password'))
  })
  expect(node('profile-add-password-email')).not.toBeNull()
  expect(node('profile-sign-in-choose-password')).toBeNull()
  fireEvent.click(node('profile-sign-in-add-back'))
  expect(node('profile-sign-in-choose-password')).not.toBeNull()
  expect(node('profile-sign-in-choose-phone')).toBeNull()
})

it.each([
  {
    way: 'phone',
    methods: MAGIC_LINK,
    choose: 'profile-sign-in-choose-phone',
    address: 'profile-add-sms-phone',
    value: '+48600000000',
    request: 'profile-add-sms-request',
    send: 'profile-add-sms-send',
    code: 'profile-add-sms-code',
    action: 'profile_add_sms_request',
    payload: { phone: '+48600000000' },
  },
  {
    way: 'password',
    methods: PASSKEY_ONLY,
    choose: 'profile-sign-in-choose-password',
    address: 'profile-add-password-email',
    value: 'b@example.test',
    request: 'profile-add-password-request',
    send: 'profile-add-password-send',
    code: 'profile-add-password-code',
    action: 'profile_add_password_request',
    payload: { email: 'b@example.test' },
  },
])(
  'empties the code and asks for another one from the $way send block',
  async (row) => {
    const { dispatch, node } = setup(NO_STEP, row.methods)
    await act(async () => {
      fireEvent.click(node('profile-sign-in-add'))
    })
    fireEvent.click(node(row.choose))
    fireEvent.change(node(row.address), {
      target: { value: row.value },
    })
    await act(async () => {
      fireEvent.click(node(row.request))
    })

    expect(node(row.send)).not.toBeNull()
    expect(document.querySelectorAll('[inert] [data-id]')).toHaveLength(0)
    fireEvent.change(node(row.code), { target: { value: '123456' } })
    const again = node(`${row.send}-again`) as HTMLButtonElement
    expect(again.disabled).toBe(false)
    await act(async () => {
      fireEvent.click(again)
    })
    expect((node(row.code) as HTMLInputElement).value).toBe('')
    expect(
      dispatch.mock.calls.filter(([action]) => action === row.action),
    ).toHaveLength(2)
    expect(dispatch).toHaveBeenLastCalledWith(
      row.action,
      row.payload,
      expect.anything(),
    )
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

/** The delayed spinner of a LoadingButton, if it shows. */
function spinnerOf(button: HTMLElement): Element | null {
  return button.querySelector('[data-id="loading-button-spinner"]')
}

// In a group the pressed button carries the flight: it is locked at once and
// keeps its look until its one spinner comes; the others are off natively from
// the same moment, by a flight that is not theirs (HIL-1767).
describe('profile sign-in page locks a pressed button at once and the others by its flight', () => {
  afterEach(() => {
    vi.useRealTimers()
    delete (globalThis as { PublicKeyCredential?: unknown }).PublicKeyCredential
    delete (navigator as { credentials?: unknown }).credentials
  })

  it.each(['profile-sign-in-passkey-only-password', 'profile-sign-in-add'])(
    'keeps %s live-looking while the dialog opens and turns the others off at once',
    async (pressedId) => {
      vi.useFakeTimers()
      const { dispatch, node } = setup(NO_STEP, PASSKEY_ONLY)
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

      await act(async () => {
        fireEvent.click(pressed)
      })
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

      await act(async () => {
        fireEvent.click(pressed)
        for (const button of others) fireEvent.click(button)
      })
      expect(starts()).toHaveLength(1)

      await act(async () => {
        await vi.advanceTimersByTimeAsync(299)
      })
      expect(spinnerOf(pressed)).toBeNull()
      expect(pressed.classList.contains('disabled')).toBe(false)
      await act(async () => {
        await vi.advanceTimersByTimeAsync(1)
      })
      expect(spinnerOf(pressed)).not.toBeNull()
      expect(pressed.classList.contains('disabled')).toBe(true)
      expect(pressed.disabled).toBe(false)
      for (const button of others) expect(spinnerOf(button)).toBeNull()

      await act(async () => {
        opening.resolve({ reply: NO_STEP })
      })
      expect(node('profile-sign-in-add-modal')).not.toBeNull()
      expect(starts()).toHaveLength(1)
    },
  )

  it('keeps a pressed passkey live-looking while the provider beside it goes off at once', async () => {
    ;(globalThis as { PublicKeyCredential?: unknown }).PublicKeyCredential =
      class {}
    Object.defineProperty(navigator, 'credentials', {
      configurable: true,
      value: { create: vi.fn(), get: vi.fn() },
    })
    const { dispatch, node } = setup(NO_STEP, MAGIC_LINK, [
      ...OFFERED,
      { key: 'passkey', name: null },
    ])
    await act(async () => {
      fireEvent.click(node('profile-sign-in-add'))
    })
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

    await act(async () => {
      fireEvent.click(passkey)
    })
    expect(requests()).toHaveLength(1)
    expect(passkey.getAttribute('aria-busy')).toBe('true')
    expect(passkey.getAttribute('aria-disabled')).toBe('true')
    expect(passkey.disabled).toBe(false)
    expect(provider.disabled).toBe(true)
    expect(provider.hasAttribute('aria-busy')).toBe(false)

    await act(async () => {
      fireEvent.click(passkey)
      fireEvent.click(provider)
    })
    expect(requests()).toHaveLength(1)
    expect(
      dispatch.mock.calls.filter(
        ([action]) => action !== 'hilos_step_up_start',
      ),
    ).toHaveLength(1)

    await act(async () => {
      options.reject(
        new ActionError(
          AUTH_ACTION_PASSKEY_REGISTER_OPTIONS,
          'fail',
          'Passkeys are off.',
        ),
      )
    })
    expect(passkey.hasAttribute('aria-busy')).toBe(false)
    expect(passkey.hasAttribute('aria-disabled')).toBe(false)
    expect(provider.disabled).toBe(false)
  })

  it('locks Remove at once by its own flight, keeps Cancel off and sends once', async () => {
    vi.useFakeTimers()
    const { dispatch, node } = setup(NO_STEP, LINK_AND_PASSKEY)
    fireEvent.click(
      document.querySelector('[data-id="identity-unlink"]') as HTMLElement,
    )
    const removal = held()
    dispatch.mockImplementationOnce(() => removal.answer)
    const remove = node('identity-unlink-yes') as HTMLButtonElement
    const cancel = node('identity-unlink-cancel') as HTMLButtonElement
    const sends = () =>
      dispatch.mock.calls.filter(
        ([action]) => action === PROFILE_UNLINK_IDENTITY_ACTION,
      )

    await act(async () => {
      fireEvent.click(remove)
    })
    expect(sends()).toHaveLength(1)
    expect(remove.getAttribute('aria-busy')).toBe('true')
    expect(remove.getAttribute('aria-disabled')).toBe('true')
    expect(remove.disabled).toBe(false)
    expect(cancel.disabled).toBe(true)

    await act(async () => {
      fireEvent.click(remove)
      await vi.advanceTimersByTimeAsync(299)
    })
    expect(sends()).toHaveLength(1)
    expect(spinnerOf(remove)).toBeNull()
    expect(remove.classList.contains('disabled')).toBe(false)
    await act(async () => {
      await vi.advanceTimersByTimeAsync(1)
    })
    expect(spinnerOf(remove)).not.toBeNull()
    expect(remove.disabled).toBe(false)

    await act(async () => {
      removal.resolve({ reply: {} })
    })
    expect(node('profile-unlink-modal')).toBeNull()
  })

  it('locks the confirmation of the step by its own flight, not natively', async () => {
    const { dispatch, node } = setup(PASSWORD_STEP)
    await act(async () => {
      fireEvent.click(node('profile-sign-in-add'))
    })
    fireEvent.change(node('step-up-password'), { target: { value: 'secret' } })
    const confirmation = held()
    dispatch.mockImplementationOnce(() => confirmation.answer)
    const confirm = node(
      'profile-sign-in-add-step-up-confirm',
    ) as HTMLButtonElement
    const confirms = () =>
      dispatch.mock.calls.filter(
        ([action]) => action === 'hilos_step_up_confirm',
      )

    await act(async () => {
      fireEvent.submit(node('profile-sign-in-add-step-up'))
    })
    expect(confirms()).toHaveLength(1)
    expect(confirm.getAttribute('aria-busy')).toBe('true')
    expect(confirm.getAttribute('aria-disabled')).toBe('true')
    expect(confirm.disabled).toBe(false)

    await act(async () => {
      fireEvent.click(confirm)
      fireEvent.submit(node('profile-sign-in-add-step-up'))
    })
    expect(confirms()).toHaveLength(1)

    await act(async () => {
      confirmation.resolve({ reply: {} })
    })
    expect(node('profile-sign-in-choose-phone')).not.toBeNull()
  })

  it('turns Save off natively while the form is unfit, and only locks it by its own flight', async () => {
    const { dispatch, node } = setup()
    await act(async () => {
      fireEvent.click(node('profile-sign-in-add'))
    })
    fireEvent.click(node('profile-sign-in-choose-password'))
    const save = node('profile-add-password-save') as HTMLButtonElement
    expect(save.disabled).toBe(true)
    expect(save.hasAttribute('aria-busy')).toBe(false)
    fireEvent.change(node('profile-add-password-new'), {
      target: { value: 'new-secret' },
    })
    fireEvent.change(node('profile-add-password-confirm'), {
      target: { value: 'new-secret' },
    })
    expect(save.disabled).toBe(false)
    const setting = held()
    dispatch.mockImplementationOnce(() => setting.answer)
    const sends = () =>
      dispatch.mock.calls.filter(
        ([action]) => action === 'profile_set_password',
      )

    await act(async () => {
      fireEvent.click(save)
    })
    expect(sends()).toHaveLength(1)
    expect(save.getAttribute('aria-busy')).toBe('true')
    expect(save.getAttribute('aria-disabled')).toBe('true')
    expect(save.disabled).toBe(false)
    expect(
      (node('profile-sign-in-add-back') as HTMLButtonElement).disabled,
    ).toBe(true)

    await act(async () => {
      fireEvent.click(save)
    })
    expect(sends()).toHaveLength(1)
  })
})
