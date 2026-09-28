// Covers what the React security page view owns (HIL-1138): connecting an app
// is a protected operation, so the modal opens on the server's word - at the
// confirmation step for the first app, at the name when no step is needed - and
// a second app is still asked for a code from the connected one after the name.
import { StrictMode } from 'react'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { afterEach, expect, it, vi } from 'vitest'
import {
  createSignal,
  ScopeManager,
  type HilosRouter,
  type HilosSecondFactorContext,
  type ProjectSignal,
} from '@hilos/core'
import { HilosProfileSecurityPage } from '../src/profile/HilosProfileSecurityPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

afterEach(cleanup)

/** A section with no app connected yet, as the group frame carries it. */
const SECTION_OFF = {
  authenticators: [],
  backupCodesLeft: 0,
  backupCodesTotal: 0,
  required: false,
  resetWait: {
    days: 8,
    pendingDays: null,
    pendingFrom: null,
    defaultDays: 8,
    minDays: 1,
    maxDays: 30,
  },
  reset: null,
}

/** The section with one app connected. */
const SECTION_ON = {
  ...SECTION_OFF,
  authenticators: [{ id: 1, label: 'Phone', createdAt: 0, lastUsedAt: null }],
  backupCodesLeft: 10,
  backupCodesTotal: 10,
}

/** The replies the server gives, by action name. */
const REPLIES: Record<string, unknown> = {
  hilos_step_up_start: { required: false, purpose: 'add an authenticator app' },
  profile_second_factor_enroll_start: {
    authenticatorId: 3,
    secret: 'JBSWY3DPEHPK3PXP',
    otpauthUri: 'otpauth://totp/Hilos:a@b.test?secret=JBSWY3DPEHPK3PXP',
  },
}

function setup(
  replies: Record<string, unknown> = REPLIES,
  section: object = SECTION_OFF,
) {
  const listeners = new Set<(signal: ProjectSignal) => void>()
  const dispatch = vi.fn((action: string) => ({
    requestId: action,
    loading: createSignal(false),
    done: Promise.resolve({ reply: replies[action] }),
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
  } as unknown as HilosSecondFactorContext
  const router = {
    pageIdentity: createSignal(undefined),
    currentRoute: createSignal({
      page: 'hilos_profile_security',
      params: {},
      admin: false,
    }),
  } as unknown as HilosRouter
  render(
    <StrictMode>
      <HilosRouterContext.Provider value={router}>
        <HilosProfileSecurityPage context={context} />
      </HilosRouterContext.Provider>
    </StrictMode>,
  )
  act(() => {
    for (const listener of [...listeners])
      listener({
        kind: 'project',
        type: 'hilos_second_factor_state',
        data: section,
      } as unknown as ProjectSignal)
  })
  const node = (id: string) =>
    document.querySelector(`[data-id="${id}"]`) as HTMLElement | null
  return { dispatch, node }
}

it('asks the server before the modal opens and starts at the name when no step is needed', async () => {
  const { dispatch, node } = setup()
  await act(async () => {
    fireEvent.click(node('profile-2fa-add')!)
  })
  expect(dispatch).toHaveBeenCalledWith(
    'hilos_step_up_start',
    { operation: 'add_authenticator_app' },
    expect.anything(),
  )
  expect(node('profile-2fa-enroll-label')).not.toBeNull()
  expect(node('step-up')).toBeNull()
})

it('opens at the confirmation step for the first app and moves to the name once confirmed', async () => {
  const { dispatch, node } = setup({
    ...REPLIES,
    hilos_step_up_start: {
      required: true,
      purpose: 'add an authenticator app',
      method: 'password',
    },
  })
  await act(async () => {
    fireEvent.click(node('profile-2fa-add')!)
  })
  expect(document.querySelector('.modal-title')?.textContent).toBe(
    "Confirm it's you",
  )
  expect(node('profile-2fa-enroll-label')).toBeNull()
  fireEvent.change(node('step-up-password')!, { target: { value: 'secret' } })
  await act(async () => {
    fireEvent.submit(node('profile-2fa-enroll')!)
  })
  expect(dispatch).toHaveBeenLastCalledWith('hilos_step_up_confirm', {
    operation: 'add_authenticator_app',
    method: 'password',
    code: '',
    backupCode: false,
    password: 'secret',
    passkey: null,
  })
  expect(node('profile-2fa-enroll-label')).not.toBeNull()
  expect(document.querySelector('.modal-title')?.textContent).toBe(
    'Add an authenticator app',
  )
})

it('asks a second app for a code from the connected one after the name, as before', async () => {
  const { dispatch, node } = setup(REPLIES, SECTION_ON)
  await act(async () => {
    fireEvent.click(node('profile-2fa-add')!)
  })
  fireEvent.change(node('profile-2fa-enroll-label')!, {
    target: { value: 'Work phone' },
  })
  await act(async () => {
    fireEvent.submit(node('profile-2fa-enroll')!)
  })
  expect(node('profile-2fa-proof')).not.toBeNull()
  expect(dispatch).toHaveBeenCalledTimes(1)
})
