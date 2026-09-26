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
it('adds a password under StrictMode and removes every listener on unmount', async () => {
  const listeners = new Set<(signal: ProjectSignal) => void>()
  const dispatch = vi.fn(() => ({
    loading: createSignal(false),
    done: Promise.resolve({}),
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
  fireEvent.click(node('profile-sign-in-add'))
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
    currentPassword: '',
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
