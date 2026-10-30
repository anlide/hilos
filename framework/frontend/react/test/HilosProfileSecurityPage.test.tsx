// Covers what the React security page view owns (HIL-1138): connecting an app
// is a protected operation, so the modal opens on the server's word - at the
// confirmation step for the first app, at the name when no step is needed - and
// a second app is still asked for a code from the connected one after the name.
// While the confirmation or the next opening is out the submit is locked at once
// and keeps its look until its spinner comes, and a repeat sends nothing; an
// empty code or codes not marked saved turn it off at once (HIL-1767). Each
// security window also locks its body and close paths for the request itself
// (HIL-1764).
// Turned off by the administrator (HIL-1303), the page is one plate, beside a
// removal that stands, and an open window closes when it turns off.
import { StrictMode } from 'react'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import { afterEach, expect, it, vi } from 'vitest'
import {
  ActionError,
  createSignal,
  ScopeManager,
  type HilosSecondFactorContext,
  type ProjectSignal,
} from '@hilos/core'
import { profileSectionFixture } from '../../core/test/profile/profileSectionFixture.js'
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
  const router = profileSectionFixture(context.scopes, 'hilos_profile_security')
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

/** A reply the test holds out until it settles or refuses it. */
function held(): {
  reply: Promise<unknown>
  settle: (reply: unknown) => void
  refuse: (action: string, reason: string) => void
} {
  let settle!: (reply: unknown) => void
  let refuse!: (action: string, reason: string) => void
  const reply = new Promise<unknown>((resolve, reject) => {
    settle = resolve
    refuse = (action, reason) => reject(new ActionError(action, 'fail', reason))
  })

  return { reply, settle, refuse }
}

function bodyFieldset(): HTMLFieldSetElement | null {
  return document.querySelector<HTMLFieldSetElement>(
    '[data-id="modal"] .modal-body > fieldset',
  )
}

function expectModalHeld(node: (id: string) => HTMLElement | null): void {
  const modal = node('modal')!
  const cancel = document.querySelector<HTMLButtonElement>(
    '[data-id="modal"] .modal-footer .btn-secondary',
  )!
  expect(bodyFieldset()?.disabled).toBe(true)
  expect(cancel.matches(':disabled')).toBe(true)
  expect(node('modal-close')?.matches(':disabled')).toBe(true)
  for (const close of [
    () => fireEvent.click(cancel),
    () => fireEvent.click(node('modal-close')!),
    () => fireEvent.keyDown(modal, { key: 'Escape' }),
    () => fireEvent.click(modal),
  ]) {
    close()
    expect(node('modal')).toBe(modal)
    expect(node('modal-confirm')).toBeNull()
  }
}

function expectModalFreed(node: (id: string) => HTMLElement | null): void {
  expect(bodyFieldset()?.disabled).toBe(false)
  expect(
    document
      .querySelector('[data-id="modal"] .modal-footer .btn-secondary')
      ?.matches(':disabled'),
  ).toBe(false)
  expect(node('modal-close')?.matches(':disabled')).toBe(false)
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

it('keeps the enrolment body held while confirmation is out and sends it once', async () => {
  vi.useFakeTimers()
  const answer = held()
  const replies = {
    ...REPLIES,
    hilos_step_up_start: {
      required: true,
      purpose: 'add an authenticator app',
      method: 'password',
    },
    hilos_step_up_confirm: answer.reply,
  }
  const { dispatch, node } = setup(replies)
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
  const password = node('step-up-password') as HTMLInputElement
  expect(confirms()).toHaveLength(1)
  expect(buttonState(confirm)).toEqual(LOCKED)
  expect(password.matches(':disabled')).toBe(true)
  expect(password.value).toBe('secret')
  expectModalHeld(node)
  expect(node('step-up-password')).toBe(password)

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
  expectModalFreed(node)
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
  expectModalHeld(node)

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
  expectModalFreed(node)
  expect(buttonState(node('profile-2fa-enroll-submit'))).toEqual(RESTING)
})

it('holds the enrollment form through a refusal and lets the same draft retry', async () => {
  const replies: Record<string, unknown> = { ...REPLIES }
  const answer = held()
  replies.profile_second_factor_enroll_start = answer.reply
  const { dispatch, node } = setup(replies)
  await act(async () => {
    fireEvent.click(node('profile-2fa-add')!)
  })
  const field = node('profile-2fa-enroll-label') as HTMLInputElement
  fireEvent.change(field, { target: { value: 'Work phone' } })
  const form = node('profile-2fa-enroll')!

  await act(async () => {
    fireEvent.submit(form)
  })

  const sends = () =>
    dispatch.mock.calls.filter(
      ([action]) => action === 'profile_second_factor_enroll_start',
    )
  expect(sends()).toHaveLength(1)
  expect(field.matches(':disabled')).toBe(true)
  expect(field.value).toBe('Work phone')
  expectModalHeld(node)
  expect(node('profile-2fa-enroll-label')).toBe(field)
  await act(async () => {
    fireEvent.submit(form)
    fireEvent.click(node('profile-2fa-enroll-submit')!)
  })
  expect(sends()).toHaveLength(1)

  await act(async () => {
    answer.refuse(
      'profile_second_factor_enroll_start',
      'Two-step verification is not available right now',
    )
  })
  expect(node('profile-2fa-enroll-label')).toBe(field)
  expect(field.matches(':disabled')).toBe(false)
  expect(field.value).toBe('Work phone')
  expectModalFreed(node)
  expect(node('hilos-action-error')?.textContent).toContain(
    'Two-step verification is not available right now',
  )

  replies.profile_second_factor_enroll_start =
    REPLIES.profile_second_factor_enroll_start
  await act(async () => {
    fireEvent.submit(form)
  })
  expect(node('profile-2fa-enroll-code')).not.toBeNull()
  expect(sends()).toHaveLength(2)
})

it('keeps the step-up proof and permits retry after refusal', async () => {
  const replies: Record<string, unknown> = {
    ...REPLIES,
    hilos_step_up_start: {
      required: true,
      purpose: 'add an authenticator app',
      method: 'password',
    },
  }
  const answer = held()
  replies.hilos_step_up_confirm = answer.reply
  const { dispatch, node } = setup(replies)
  await act(async () => {
    fireEvent.click(node('profile-2fa-add')!)
  })
  const password = node('step-up-password') as HTMLInputElement
  fireEvent.change(password, { target: { value: 'wrong' } })
  const form = node('profile-2fa-enroll')!

  await act(async () => {
    fireEvent.submit(form)
  })
  const confirms = () =>
    dispatch.mock.calls.filter(([action]) => action === 'hilos_step_up_confirm')
  expect(confirms()).toHaveLength(1)
  expect(password.matches(':disabled')).toBe(true)
  expectModalHeld(node)
  await act(async () => {
    fireEvent.submit(form)
    fireEvent.click(node('profile-2fa-enroll-submit')!)
  })
  expect(confirms()).toHaveLength(1)

  await act(async () => {
    answer.refuse('hilos_step_up_confirm', 'That password is not right')
  })
  expect(node('step-up-password')).toBe(password)
  expect(password.value).toBe('wrong')
  expect(password.matches(':disabled')).toBe(false)
  expectModalFreed(node)
  expect(node('step-up-error')?.textContent).toContain(
    'That password is not right',
  )

  replies.hilos_step_up_confirm = []
  fireEvent.change(password, { target: { value: 'secret' } })
  await act(async () => {
    fireEvent.submit(form)
  })
  expect(confirms()).toHaveLength(2)
  expect(node('profile-2fa-enroll-label')).not.toBeNull()
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
  fireEvent.click(node('backup-codes-saved')!)
  expect(buttonState(node('profile-2fa-enroll-submit'))).toEqual(OFF)

  const codes = node('backup-codes')
  fireEvent.click(node('modal-close')!)
  expect(node('modal-confirm')).not.toBeNull()
  expect(node('modal-confirm-cancel')?.textContent).toBe('Back to the codes')
  fireEvent.click(node('modal-confirm-cancel')!)
  expect(node('modal-confirm')).toBeNull()
  expect(node('backup-codes')).toBe(codes)
  expect((node('backup-codes-saved') as HTMLInputElement).checked).toBe(false)

  fireEvent.click(node('modal-close')!)
  fireEvent.click(node('modal-confirm-discard')!)
  expect(node('modal')).toBeNull()
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

it('asks before closing renewed backup codes that are not marked saved', async () => {
  const replies: Record<string, unknown> = {
    ...REPLIES,
    profile_second_factor_codes_show: {
      codes: [{ code: 'abcde-fghjk', used: false }],
    },
    profile_second_factor_codes_renew: {
      backupCodes: ['1111-2222', '3333-4444'],
    },
  }
  const { dispatch, node } = setup(replies, SECTION_ON)
  await act(async () => {
    fireEvent.click(node('profile-2fa-codes-show')!)
  })
  fireEvent.change(node('profile-2fa-proof')!, { target: { value: '123456' } })
  await act(async () => {
    fireEvent.click(node('profile-2fa-codes-submit')!)
  })
  expect(node('profile-2fa-codes-list')).not.toBeNull()
  await act(async () => {
    fireEvent.click(node('profile-2fa-codes-submit')!)
  })
  expect(node('profile-2fa-proof')).not.toBeNull()
  expect(buttonState(node('profile-2fa-codes-submit'))).toEqual(OFF)

  fireEvent.change(node('profile-2fa-proof')!, { target: { value: '654321' } })
  await act(async () => {
    fireEvent.submit(node('profile-2fa-codes')!)
  })
  expect(dispatch.mock.calls.map(([action]) => action)).toContain(
    'profile_second_factor_codes_renew',
  )
  expect(node('backup-codes')).not.toBeNull()
  expect(buttonState(node('profile-2fa-codes-submit'))).toEqual(OFF)

  const codes = node('backup-codes')
  fireEvent.click(node('modal-close')!)
  expect(node('modal-confirm')).not.toBeNull()
  expect(node('modal-confirm-cancel')?.textContent).toBe('Back to the codes')
  fireEvent.click(node('modal-confirm-cancel')!)
  expect(node('modal-confirm')).toBeNull()
  expect(node('backup-codes')).toBe(codes)
  expect((node('backup-codes-saved') as HTMLInputElement).checked).toBe(false)

  fireEvent.click(node('modal-close')!)
  fireEvent.click(node('modal-confirm-discard')!)
  expect(node('modal')).toBeNull()
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

const PENDING_ACTIONS = [
  {
    window: 'Backup codes',
    open: 'profile-2fa-codes-show',
    fields: [
      { id: 'profile-2fa-proof', value: '123456' },
      { id: 'profile-2fa-codes-backup', checked: true },
    ],
    action: 'profile_second_factor_codes_show',
    form: 'profile-2fa-codes',
    send: 'profile-2fa-codes-submit',
    reply: { codes: [{ code: 'abcde-fghjk', used: false }] },
    after: 'profile-2fa-codes-list',
  },
  {
    window: 'Remove',
    open: 'profile-2fa-remove-1',
    fields: [
      { id: 'profile-2fa-proof', value: '123456' },
      { id: 'profile-2fa-remove-backup', checked: true },
    ],
    action: 'profile_second_factor_remove',
    form: 'profile-2fa-remove',
    send: 'profile-2fa-remove-submit',
    reply: [],
    after: null,
  },
  {
    window: 'Wait before removal',
    open: 'profile-2fa-wait-edit',
    fields: [{ id: 'profile-2fa-wait-days', value: '12' }],
    action: 'profile_second_factor_reset_wait_set',
    form: 'profile-2fa-wait-form',
    send: 'profile-2fa-wait-save',
    reply: [],
    after: null,
  },
  {
    window: 'Request removal',
    open: 'profile-2fa-reset-request',
    fields: [],
    action: 'profile_second_factor_reset_request',
    form: null,
    send: 'profile-2fa-reset-submit',
    reply: [],
    after: null,
  },
] as const

it.each(PENDING_ACTIONS)(
  'holds the $window window through refusal and retry',
  async ({ open, fields, action, form, send, reply, after }) => {
    const replies: Record<string, unknown> = { ...REPLIES }
    const { dispatch, node } = setup(replies, SECTION_ON)
    const inputFor = (id: string): HTMLInputElement =>
      (node(id) ?? document.getElementById(id)) as HTMLInputElement
    await act(async () => {
      fireEvent.click(node(open)!)
    })
    const submit = node(send)!
    expect(submit.matches(':disabled')).toBe(fields.length > 0)

    const inputs = fields.map(({ id }) => inputFor(id))
    fields.forEach((field) => {
      const input = inputFor(field.id)
      if ('value' in field)
        fireEvent.change(input, { target: { value: field.value } })
      if ('checked' in field) fireEvent.click(input)
    })
    expect(submit.matches(':disabled')).toBe(false)
    const bodyForm = form === null ? null : node(form)!
    const answer = held()
    replies[action] = answer.reply

    await act(async () => {
      fireEvent.click(submit)
    })
    const sends = () => dispatch.mock.calls.filter(([sent]) => sent === action)
    expect(sends()).toHaveLength(1)
    expect(submit.getAttribute('aria-busy')).toBe('true')
    expect(submit.matches(':disabled')).toBe(false)
    inputs.forEach((input) => expect(input.matches(':disabled')).toBe(true))
    expectModalHeld(node)
    expect(node(send)).toBe(submit)
    if (bodyForm !== null) {
      expect(node(form!)).toBe(bodyForm)
      await act(async () => {
        fireEvent.submit(bodyForm)
        fireEvent.click(submit)
      })
    } else {
      await act(async () => {
        fireEvent.click(submit)
      })
    }
    expect(sends()).toHaveLength(1)

    await act(async () => {
      answer.refuse(action, 'The server is busy, try again')
    })
    expectModalFreed(node)
    inputs.forEach((input, index) => {
      const field = fields[index]!
      expect(inputFor(field.id)).toBe(input)
      expect(input.matches(':disabled')).toBe(false)
      if ('value' in field) expect(input.value).toBe(field.value)
      if ('checked' in field) expect(input.checked).toBe(field.checked)
    })
    if (bodyForm !== null) expect(node(form!)).toBe(bodyForm)
    expect(node('hilos-action-error')?.textContent).toContain(
      'The server is busy, try again',
    )

    replies[action] = reply
    await act(async () => {
      fireEvent.click(node(send)!)
    })
    if (after === null) {
      expect(node('modal')).toBeNull()
    } else {
      expect(node(after)).not.toBeNull()
    }
    expect(sends()).toHaveLength(2)
  },
)

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

it('closes a pending window when it turns off, then returns when turned back on', async () => {
  const replies: Record<string, unknown> = { ...REPLIES }
  const answer = held()
  replies.profile_second_factor_codes_show = answer.reply
  const { dispatch, node, push } = setup(replies, SECTION_ON)
  await act(async () => {
    fireEvent.click(node('profile-2fa-codes-show')!)
  })
  fireEvent.change(node('profile-2fa-proof')!, { target: { value: '123456' } })
  await act(async () => {
    fireEvent.click(node('profile-2fa-codes-submit')!)
  })
  expect(
    dispatch.mock.calls.filter(
      ([action]) => action === 'profile_second_factor_codes_show',
    ),
  ).toHaveLength(1)
  expectModalHeld(node)

  push({ ...SECTION_ON, enabled: false })

  expect(node('modal')).toBeNull()
  expect(node('hilos-2fa-turned-off')).not.toBeNull()
  await act(async () => {
    answer.settle({ codes: [] })
  })
  expect(node('modal')).toBeNull()

  push(SECTION_ON)
  expect(node('hilos-2fa-turned-off')).toBeNull()
  expect(node('profile-2fa-apps')).not.toBeNull()
})
