// Covers the React profile root (HIL-1169): Name with Change only for the
// project's rename, Email only with a verified address, a row per catalog
// section with its summary, and a name window that still closes on the name it
// sent after StrictMode ran its effects twice.
import { StrictMode } from 'react'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { afterEach, expect, it, vi } from 'vitest'
import {
  createSignal,
  HilosPages,
  resolveHilosProfileSignInMethods,
  ScopeManager,
  type HilosProfileBinding,
  type HilosProfilePageContext,
  type HilosProfileRename,
  type HilosRouter,
} from '@hilos/core'
import { HilosProfilePage } from '../src/profile/HilosProfilePage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

afterEach(cleanup)

/** A router on the profile root whose sections all have an address. */
const router = {
  pageIdentity: createSignal({
    label: 'Profile',
    lead: '',
    breadcrumb: [{ page: HilosPages.PROFILE, label: 'Profile' }],
    children: [
      {
        page: HilosPages.PROFILE_SIGN_IN,
        label: 'Ways to sign in',
        lead: '',
        icon: null,
      },
      {
        page: HilosPages.PROFILE_SECURITY,
        label: 'Security',
        lead: '',
        icon: null,
      },
    ],
  }),
  currentRoute: createSignal({
    page: HilosPages.PROFILE,
    params: {},
    admin: false,
  }),
  currentPath: createSignal('/profile'),
  resolvePath: (page: string) => `/${page}`,
} as unknown as HilosRouter

function setup(binding: HilosProfileBinding, signedIn = true) {
  const dispatch = vi.fn((action: string) => ({
    loading: createSignal(false),
    done: Promise.resolve(
      action === 'hilos_step_up_start'
        ? { reply: { required: false, purpose: 'change your name' } }
        : {},
    ),
  }))
  const scopes = new ScopeManager()
  if (signedIn) scopes.session.data.set('currentUser', { type: 'user', id: 1 })
  const context = {
    actions: { dispatch },
    connection: { on: () => () => {} },
    scopes,
  } as unknown as HilosProfilePageContext
  render(
    <StrictMode>
      <HilosRouterContext.Provider value={router}>
        <HilosProfilePage context={context} binding={binding} />
      </HilosRouterContext.Provider>
    </StrictMode>,
  )
  return (id: string) =>
    document.querySelector<HTMLElement>(`[data-id="${id}"]`)
}

function nameOnly(): HilosProfileBinding {
  return {
    name: createSignal('Ann Lee'),
    rename: null,
    signInMethods: null,
    sessionCount: null,
    deviceCount: null,
  }
}

it('shows the name without Change and no Email row when the project hands neither', () => {
  const node = setup(nameOnly())

  expect(node('profile-identity-name')?.textContent).toBe('Ann Lee')
  expect(node('profile-edit')).toBeNull()
  expect(node('profile-email')).toBeNull()
  expect(
    [...document.querySelectorAll('[data-id="profile-section"]')].map(
      (row) => row.querySelector('.fw-semibold')?.textContent,
    ),
  ).toEqual(['Ways to sign in', 'Security'])
  expect(node('profile-sign-in-summary')?.textContent).toBe('')
  expect(node('profile-security-summary')?.textContent).toBe(
    'Two-step verification is off',
  )
  expect(node('profile-security-open')?.getAttribute('href')).toBe(
    `/${HilosPages.PROFILE_SECURITY}`,
  )
})

it('shows the Email row for a verified address and the ways-in summary', () => {
  const node = setup({
    ...nameOnly(),
    signInMethods: createSignal(
      resolveHilosProfileSignInMethods(
        [
          {
            id: 1,
            type: 'password',
            provider: null,
            identifier: 'ann@example.test',
            verified: true,
          },
        ],
        [],
      ),
    ),
  })

  expect(node('profile-email')?.textContent).toBe('ann@example.test · verified')
  expect(node('profile-identity-email')?.textContent).toBe('ann@example.test')
  expect(node('profile-sign-in-summary')?.textContent).toBe('Password')
})

it('opens the name window under StrictMode and closes it on the name it sent', async () => {
  const name = createSignal('Ann Lee')
  const sent: string[] = []
  const rename: HilosProfileRename = {
    send(next) {
      sent.push(next)
      return true
    },
    refusal: createSignal(null),
    clearRefusal: () => {},
    stepUpOperation: 'change_name',
    minLength: 2,
    maxLength: 64,
  }
  const node = setup({ ...nameOnly(), name, rename })

  await act(async () => {
    fireEvent.click(node('profile-edit') as HTMLElement)
  })
  const input = node('profile-name-input') as HTMLInputElement
  expect(input.value).toBe('Ann Lee')
  await act(async () => {
    fireEvent.change(input, { target: { value: 'Bea' } })
  })
  await act(async () => {
    fireEvent.click(node('profile-rename-save') as HTMLElement)
  })
  expect(sent).toEqual(['Bea'])
  await act(async () => {
    name.set('Bea')
  })
  expect(node('profile-name-input')).toBeNull()
  expect(node('profile-name')?.textContent).toBe('Bea')
})

it('draws only the placeholder while nobody is signed in', () => {
  const node = setup(nameOnly(), false)

  expect(node('profile-loading')).not.toBeNull()
  expect(node('profile-view')).toBeNull()
})
