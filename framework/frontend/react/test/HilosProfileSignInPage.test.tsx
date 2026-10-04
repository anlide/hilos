import { StrictMode } from 'react'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { afterEach, expect, it, vi } from 'vitest'
import {
  createSignal,
  HILOS_PROFILE_IDENTITIES_LIST,
  ScopeManager,
  resolveHilosProfileSignInMethods,
  type HilosAuthContext,
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
  methods = MAGIC_LINK,
  offered: readonly object[] = OFFERED,
) {
  const listeners = new Set<(signal: ProjectSignal) => void>()
  const dispatch = vi.fn((action: string) => ({
    loading: createSignal(false),
    done: Promise.resolve(
      action === 'hilos_step_up_start'
        ? { reply: opening }
        : CODE_REQUESTS.includes(action)
          ? { reply: SENT }
          : {},
    ),
  }))
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
  const { unmount } = render(page)
  const node = (id: string) =>
    document.querySelector(`[data-id="${id}"]`) as HTMLElement
  return {
    dispatch,
    listeners,
    node,
    unmount,
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
