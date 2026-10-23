// Covers what the React security page view owns (HIL-1138): connecting an app
// is a protected operation, so the modal opens on the server's word - at the
// confirmation step for the first app, at the name when no step is needed - and
// a second app is still asked for a code from the connected one after the name.
// While the confirmation or the next opening is out the submit is locked at once
// and keeps its look until its spinner comes, and a repeat sends nothing; an
// empty code or codes not marked saved turn it off at once (HIL-1767).
// Turned off by the administrator (HIL-1303), the page is one plate, beside a
// removal that stands, and an open window closes when it turns off.
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

afterEach(() => {
  cleanup()
  vi.useRealTimers()
})

/** A section with no app connected yet, as the group frame carries it. */
const SECTION_OFF = {
  enabled: true,
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
  const dispatch = vi.fn((action: string) => {
    const reply = replies[action]

    return {
      requestId: action,
      loading: createSignal(false),
      // A reply given as a promise is held until it settles.
      done:
        reply instanceof Promise
          ? reply.then((settled: unknown) => ({ reply: settled }))
          : Promise.resolve({ reply }),
    }
  })
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
  const push = (next: object): void =>
    act(() => {
      for (const listener of [...listeners])
        listener({
          kind: 'project',
          type: 'hilos_second_factor_state',
          data: next,
        } as unknown as ProjectSignal)
    })
  push(section)
  const node = (id: string) =>
    document.querySelector(`[data-id="${id}"]`) as HTMLElement | null
  return { dispatch, node, push }
}

/** A reply the test holds out until it settles it. */
function held(): { reply: Promise<unknown>; settle: (reply: unknown) => void } {
  let settle!: (reply: unknown) => void
  const reply = new Promise<unknown>((resolve) => {
    settle = resolve
  })

  return { reply, settle }
}

// What a button tells a sighted person and a screen reader now.
function buttonState(button: HTMLElement | null) {
  return {
    nativeDisabled: (button as HTMLButtonElement).disabled,
    disabledLook: button!.classList.contains('disabled'),
    spinner:
      button!.querySelector('[data-id="loading-button-spinner"]') !== null,
    ariaBusy: button!.getAttribute('aria-busy'),
    ariaDisabled: button!.getAttribute('aria-disabled'),
  }
}

const RESTING = {
  nativeDisabled: false,
  disabledLook: false,
  spinner: false,
  ariaBusy: null,
  ariaDisabled: null,
}
const OFF = { ...RESTING, nativeDisabled: true, ariaDisabled: 'true' }
const LOCKED = { ...RESTING, ariaBusy: 'true', ariaDisabled: 'true' }
const SPINNING = { ...LOCKED, disabledLook: true, spinner: true }

// Advance the fake clock and let the view render what the timers decided.
function wait(ms: number): void {
  act(() => {
    vi.advanceTimersByTime(ms)
  })
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

it('keeps Confirm live-looking while the confirmation is out, dims it only with its spinner and sends it once', async () => {
  vi.useFakeTimers()
  const answer = held()
  const { dispatch, node } = setup({
    ...REPLIES,
    hilos_step_up_start: {
      required: true,
      purpose: 'add an authenticator app',
      method: 'password',
    },
    hilos_step_up_confirm: answer.reply,
  })
  await act(async () => {
    fireEvent.click(node('profile-2fa-add')!)
  })
  const confirms = () =>
    dispatch.mock.calls.filter(([action]) => action === 'hilos_step_up_confirm')
  fireEvent.change(node('step-up-password')!, { target: { value: 'secret' } })
  await act(async () => {
    fireEvent.submit(node('profile-2fa-enroll')!)
  })
  const confirm = node('profile-2fa-enroll-submit')
  expect(confirms()).toHaveLength(1)
  expect(buttonState(confirm)).toEqual(LOCKED)

  wait(299)
  expect(buttonState(confirm)).toEqual(LOCKED)
  await act(async () => {
    fireEvent.submit(node('profile-2fa-enroll')!)
    fireEvent.click(confirm!)
  })
  expect(confirms()).toHaveLength(1)
  wait(1)
  expect(buttonState(confirm)).toEqual(SPINNING)

  await act(async () => {
    answer.settle(undefined)
  })
  expect(node('profile-2fa-enroll-label')).not.toBeNull()
  expect(buttonState(node('profile-2fa-enroll-submit'))).toEqual(RESTING)
})

it('keeps Connect another live-looking while the next opening is out, dims it only with its spinner and asks once', async () => {
  vi.useFakeTimers()
  const replies: Record<string, unknown> = {
    ...REPLIES,
    profile_second_factor_enroll_confirm: {},
  }
  const { dispatch, node } = setup(replies)
  await act(async () => {
    fireEvent.click(node('profile-2fa-add')!)
  })
  await act(async () => {
    fireEvent.submit(node('profile-2fa-enroll')!)
  })
  fireEvent.change(node('profile-2fa-enroll-code')!, {
    target: { value: '123456' },
  })
  await act(async () => {
    fireEvent.submit(node('profile-2fa-enroll')!)
  })
  const more = node('profile-2fa-enroll-submit')
  expect(more?.textContent?.trim()).toBe('Connect another')
  const openings = () =>
    dispatch.mock.calls.filter(([action]) => action === 'hilos_step_up_start')
  expect(openings()).toHaveLength(1)

  const answer = held()
  replies.hilos_step_up_start = answer.reply
  await act(async () => {
    fireEvent.click(more!)
  })
  expect(openings()).toHaveLength(2)
  expect(buttonState(more)).toEqual(LOCKED)

  wait(299)
  expect(buttonState(more)).toEqual(LOCKED)
  await act(async () => {
    fireEvent.click(more!)
    fireEvent.submit(node('profile-2fa-enroll')!)
  })
  expect(openings()).toHaveLength(2)
  wait(1)
  expect(buttonState(more)).toEqual(SPINNING)

  await act(async () => {
    answer.settle({ required: false, purpose: 'add an authenticator app' })
  })
  expect(node('profile-2fa-enroll-label')).not.toBeNull()
  expect(buttonState(node('profile-2fa-enroll-submit'))).toEqual(RESTING)
})

it('turns the submit off at once for an empty code and for codes not marked saved', async () => {
  const { dispatch, node } = setup(
    {
      ...REPLIES,
      profile_second_factor_enroll_confirm: {
        backupCodes: ['1111-2222', '3333-4444'],
      },
    },
    SECTION_OFF,
  )
  await act(async () => {
    fireEvent.click(node('profile-2fa-add')!)
  })
  await act(async () => {
    fireEvent.submit(node('profile-2fa-enroll')!)
  })
  expect(buttonState(node('profile-2fa-enroll-submit'))).toEqual(OFF)
  await act(async () => {
    fireEvent.submit(node('profile-2fa-enroll')!)
  })
  expect(
    dispatch.mock.calls.filter(
      ([action]) => action === 'profile_second_factor_enroll_confirm',
    ),
  ).toHaveLength(0)

  fireEvent.change(node('profile-2fa-enroll-code')!, {
    target: { value: '123456' },
  })
  expect(buttonState(node('profile-2fa-enroll-submit'))).toEqual(RESTING)
  await act(async () => {
    fireEvent.submit(node('profile-2fa-enroll')!)
  })
  expect(node('backup-codes')).not.toBeNull()
  expect(buttonState(node('profile-2fa-enroll-submit'))).toEqual(OFF)

  fireEvent.click(node('backup-codes-saved')!)
  expect(buttonState(node('profile-2fa-enroll-submit'))).toEqual(RESTING)
})

it('turns the submit off at once for an empty code from the connected app', async () => {
  const { node } = setup(REPLIES, SECTION_ON)
  await act(async () => {
    fireEvent.click(node('profile-2fa-add')!)
  })
  await act(async () => {
    fireEvent.submit(node('profile-2fa-enroll')!)
  })
  expect(node('profile-2fa-proof')).not.toBeNull()
  expect(buttonState(node('profile-2fa-enroll-submit'))).toEqual(OFF)

  fireEvent.change(node('profile-2fa-proof')!, { target: { value: '654321' } })
  expect(buttonState(node('profile-2fa-enroll-submit'))).toEqual(RESTING)
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

it('switches between the empty paragraph and the apps list when factors change', () => {
  const { node, push } = setup(REPLIES, SECTION_OFF)

  const off = node('profile-2fa-off')
  expect(off).not.toBeNull()
  expect(off?.tagName).toBe('P')
  expect(off?.textContent?.replace(/\s+/g, ' ').trim()).toBe(
    'Two-step verification is off. Connect an authenticator app to turn it on.',
  )
  expect(off?.closest('ul')).toBeNull()
  expect(off?.closest('.list-group')).toBeNull()
  expect(node('profile-2fa-apps')).toBeNull()
  expect(node('profile-2fa-codes-left')).toBeNull()
  expect(node('profile-2fa-add')).not.toBeNull()

  push(SECTION_ON)

  expect(node('profile-2fa-off')).toBeNull()
  const apps = node('profile-2fa-apps')
  expect(apps).not.toBeNull()
  expect(apps?.tagName).toBe('UL')
  expect(node('profile-2fa-app-1')).not.toBeNull()
  expect(node('profile-2fa-codes-left')).not.toBeNull()
  expect(node('profile-2fa-add')).not.toBeNull()

  push(SECTION_OFF)

  const offRestored = node('profile-2fa-off')
  expect(offRestored).not.toBeNull()
  expect(offRestored?.tagName).toBe('P')
  expect(offRestored?.textContent?.replace(/\s+/g, ' ').trim()).toBe(
    'Two-step verification is off. Connect an authenticator app to turn it on.',
  )
  expect(offRestored?.closest('ul')).toBeNull()
  expect(offRestored?.closest('.list-group')).toBeNull()
  expect(node('profile-2fa-apps')).toBeNull()
  expect(node('profile-2fa-codes-left')).toBeNull()
  expect(node('profile-2fa-add')).not.toBeNull()
})

it('is one plate while turned off, saying the apps are kept, with no apps, codes, wait or buttons', () => {
  const { node } = setup(REPLIES, { ...SECTION_ON, enabled: false })

  expect(node('hilos-2fa-turned-off')?.textContent).toBe(
    'Your administrator turned two-step verification off. Your apps and backup codes are kept and will work again when it is turned back on.',
  )
  for (const id of [
    'profile-2fa-apps',
    'profile-2fa-off',
    'profile-2fa-codes-left',
    'profile-2fa-add',
    'profile-2fa-wait',
    'profile-2fa-reset-request',
  ]) {
    expect(node(id)).toBeNull()
  }
})

it('says only the first sentence while turned off with no app connected', () => {
  const { node } = setup(REPLIES, { ...SECTION_OFF, enabled: false })

  expect(node('hilos-2fa-turned-off')?.textContent).toBe(
    'Your administrator turned two-step verification off.',
  )
  for (const id of [
    'profile-2fa-apps',
    'profile-2fa-off',
    'profile-2fa-codes-left',
    'profile-2fa-add',
    'profile-2fa-wait',
    'profile-2fa-reset-request',
  ]) {
    expect(node(id)).toBeNull()
  }
})

it('keeps a removal that stands beside the plate, with its date and Cancel', () => {
  const { node } = setup(REPLIES, {
    ...SECTION_ON,
    enabled: false,
    reset: { requestedAt: 0, effectiveAt: 86_400_000 },
  })

  expect(node('hilos-2fa-turned-off')).not.toBeNull()
  expect(node('profile-2fa-reset-pending')?.textContent).toContain(
    'Two-step verification will be removed on',
  )
  expect(node('profile-2fa-reset-cancel')).not.toBeNull()
})

it('closes an open window when it turns off, and is the whole page again when it turns back on', () => {
  const { node, push } = setup(REPLIES, SECTION_ON)
  act(() => {
    fireEvent.click(node('profile-2fa-codes-show')!)
  })
  expect(node('profile-2fa-proof')).not.toBeNull()

  push({ ...SECTION_ON, enabled: false })

  expect(node('modal')).toBeNull()
  expect(node('hilos-2fa-turned-off')).not.toBeNull()

  push(SECTION_ON)
  expect(node('hilos-2fa-turned-off')).toBeNull()
  expect(node('profile-2fa-apps')).not.toBeNull()
})
