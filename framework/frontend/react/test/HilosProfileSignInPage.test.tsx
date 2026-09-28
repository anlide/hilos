import { StrictMode } from 'react'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { afterEach, expect, it, vi } from 'vitest'
import {
  createSignal,
  ScopeManager,
  resolveHilosProfileSignInMethods,
  type HilosAuthContext,
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

function setup(opening: object = NO_STEP) {
  const listeners = new Set<(signal: ProjectSignal) => void>()
  const dispatch = vi.fn((action: string) => ({
    loading: createSignal(false),
    done: Promise.resolve(
      action === 'hilos_step_up_start' ? { reply: opening } : {},
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
    termsPath: '/terms',
    privacyPath: '/privacy',
  } as unknown as HilosAuthContext
  const router = {
    pageIdentity: createSignal(undefined),
    currentRoute: createSignal({
      page: 'hilos_profile_sign_in',
      params: {},
      admin: false,
    }),
  } as unknown as HilosRouter
  const methods = resolveHilosProfileSignInMethods(
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
  const { unmount } = render(
    <StrictMode>
      <HilosRouterContext.Provider value={router}>
        <HilosProfileSignInPage context={context} methods={methods} />
      </HilosRouterContext.Provider>
    </StrictMode>,
  )
  const node = (id: string) =>
    document.querySelector(`[data-id="${id}"]`) as HTMLElement
  return { dispatch, listeners, node, unmount }
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
