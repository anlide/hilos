// Covers what the Angular security page view owns (HIL-1138): connecting an app
// is a protected operation, so the modal opens on the server's word - at the
// confirmation step for the first app, at the name when no step is needed - and
// a second app is still asked for a code from the connected one after the name.
// While the opening, the confirmation or the next opening is out the button that
// sent it is locked at once and keeps its look until its spinner comes, and a
// repeat sends nothing; an empty code or codes not marked saved turn the submit
// off at once (HIL-1768).
// Turned off by the administrator (HIL-1303), the page is one plate, beside a
// removal that stands, and an open window closes when it turns off.
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  createSignal,
  ScopeManager,
  type HilosSecondFactorContext,
  type ProjectSignal,
} from '@hilos/core'
import { afterEach, expect, it, vi } from 'vitest'
import { profileSectionFixture } from '../../core/test/profile/profileSectionFixture.js'
import { HilosProfileSecurityPage } from '../src/profile/HilosProfileSecurityPage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

afterEach(() => {
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
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: router }],
  })
  const fixture = TestBed.createComponent(HilosProfileSecurityPage)
  fixture.componentRef.setInput('context', context)
  fixture.detectChanges()
  const push = (next: object): void => {
    for (const listener of [...listeners])
      listener({
        kind: 'project',
        type: 'hilos_second_factor_state',
        data: next,
      } as unknown as ProjectSignal)
    fixture.detectChanges()
  }
  push(section)
  const node = (id: string) =>
    (fixture.nativeElement as HTMLElement).querySelector<HTMLElement>(
      `[data-id="${id}"]`,
    )
  const fill = (id: string, value: string) => {
    const input = node(id) as HTMLInputElement
    input.value = value
    input.dispatchEvent(new Event('input', { bubbles: true }))
    fixture.detectChanges()
  }
  return { dispatch, fill, fixture, node, push }
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
 * Let the fake clock run and draw what the timers decided.
 *
 * @param fixture The page under test.
 * @param ms How far the clock moves.
 */
function wait(fixture: ComponentFixture<unknown>, ms: number): void {
  vi.advanceTimersByTime(ms)
  fixture.detectChanges()
}

/** A reply the test holds out until it settles it. */
function held(): { reply: Promise<unknown>; settle: (reply: unknown) => void } {
  let settle!: (reply: unknown) => void
  const reply = new Promise<unknown>((resolve) => {
    settle = resolve
  })

  return { reply, settle }
}

/**
 * What a button tells a sighted person and a screen reader now.
 *
 * @param button The button to read.
 */
function buttonState(button: HTMLElement) {
  return {
    nativeDisabled: (button as HTMLButtonElement).disabled,
    disabledLook: button.classList.contains('disabled'),
    spinner:
      button.querySelector('[data-id="loading-button-spinner"]') !== null,
    ariaBusy: button.getAttribute('aria-busy'),
    ariaDisabled: button.getAttribute('aria-disabled'),
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

/**
 * Send the enrolment form the way Enter does: past its buttons.
 *
 * @param node The page's element lookup.
 */
function submitEnrolment(node: (id: string) => HTMLElement | null): void {
  node('profile-2fa-enroll')!.dispatchEvent(
    new Event('submit', { cancelable: true }),
  )
}

it('asks the server before the modal opens and starts at the name when no step is needed', async () => {
  const { dispatch, fixture, node } = setup()
  node('profile-2fa-add')!.click()
  await settle(fixture)
  expect(dispatch).toHaveBeenCalledWith(
    'hilos_step_up_start',
    { operation: 'add_authenticator_app' },
    expect.anything(),
  )
  expect(node('profile-2fa-enroll-label')).not.toBeNull()
  expect(node('step-up')).toBeNull()
  fixture.destroy()
})

it('opens at the confirmation step for the first app and moves to the name once confirmed', async () => {
  const { dispatch, fill, fixture, node } = setup({
    ...REPLIES,
    hilos_step_up_start: {
      required: true,
      purpose: 'add an authenticator app',
      method: 'password',
    },
  })
  node('profile-2fa-add')!.click()
  await settle(fixture)
  expect(
    (fixture.nativeElement as HTMLElement).querySelector('.modal-title')
      ?.textContent,
  ).toBe("Confirm it's you")
  expect(node('profile-2fa-enroll-label')).toBeNull()
  fill('step-up-password', 'secret')
  submitEnrolment(node)
  await settle(fixture)
  expect(dispatch).toHaveBeenLastCalledWith('hilos_step_up_confirm', {
    operation: 'add_authenticator_app',
    method: 'password',
    code: '',
    backupCode: false,
    password: 'secret',
    passkey: null,
  })
  expect(node('profile-2fa-enroll-label')).not.toBeNull()
  fixture.destroy()
})

it('keeps Add an authenticator app live-looking while it asks, dims it only with its spinner and asks once', async () => {
  vi.useFakeTimers()
  const answer = held()
  const { dispatch, fixture, node } = setup({
    ...REPLIES,
    hilos_step_up_start: answer.reply,
  })
  const openings = () =>
    dispatch.mock.calls.filter(([action]) => action === 'hilos_step_up_start')
  const add = node('profile-2fa-add')!
  add.click()
  await flush(fixture)
  expect(openings()).toHaveLength(1)
  expect(buttonState(add)).toEqual(LOCKED)

  wait(fixture, 299)
  expect(buttonState(add)).toEqual(LOCKED)
  add.click()
  await flush(fixture)
  expect(openings()).toHaveLength(1)
  wait(fixture, 1)
  expect(buttonState(add)).toEqual(SPINNING)

  answer.settle({ required: false, purpose: 'add an authenticator app' })
  await flush(fixture)
  expect(node('profile-2fa-enroll-label')).not.toBeNull()
  expect(buttonState(add)).toEqual(RESTING)
  fixture.destroy()
})

it('keeps Confirm live-looking while the confirmation is out, dims it only with its spinner and sends it once', async () => {
  vi.useFakeTimers()
  const answer = held()
  const { dispatch, fill, fixture, node } = setup({
    ...REPLIES,
    hilos_step_up_start: {
      required: true,
      purpose: 'add an authenticator app',
      method: 'password',
    },
    hilos_step_up_confirm: answer.reply,
  })
  node('profile-2fa-add')!.click()
  await flush(fixture)
  const confirms = () =>
    dispatch.mock.calls.filter(([action]) => action === 'hilos_step_up_confirm')
  fill('step-up-password', 'secret')
  submitEnrolment(node)
  await flush(fixture)
  const confirm = node('profile-2fa-enroll-submit')!
  expect(confirms()).toHaveLength(1)
  expect(buttonState(confirm)).toEqual(LOCKED)

  wait(fixture, 299)
  expect(buttonState(confirm)).toEqual(LOCKED)
  submitEnrolment(node)
  confirm.click()
  await flush(fixture)
  expect(confirms()).toHaveLength(1)
  wait(fixture, 1)
  expect(buttonState(confirm)).toEqual(SPINNING)

  answer.settle(undefined)
  await flush(fixture)
  expect(node('profile-2fa-enroll-label')).not.toBeNull()
  expect(buttonState(node('profile-2fa-enroll-submit')!)).toEqual(RESTING)
  fixture.destroy()
})

it('keeps Connect another live-looking while the next opening is out, dims it only with its spinner and asks once', async () => {
  vi.useFakeTimers()
  const replies: Record<string, unknown> = {
    ...REPLIES,
    profile_second_factor_enroll_confirm: {},
  }
  const { dispatch, fill, fixture, node } = setup(replies)
  node('profile-2fa-add')!.click()
  await flush(fixture)
  submitEnrolment(node)
  await flush(fixture)
  fill('profile-2fa-enroll-code', '123456')
  submitEnrolment(node)
  await flush(fixture)
  const more = node('profile-2fa-enroll-submit')!
  expect(more.textContent?.trim()).toBe('Connect another')
  const openings = () =>
    dispatch.mock.calls.filter(([action]) => action === 'hilos_step_up_start')
  expect(openings()).toHaveLength(1)

  const answer = held()
  replies['hilos_step_up_start'] = answer.reply
  more.click()
  await flush(fixture)
  expect(openings()).toHaveLength(2)
  expect(buttonState(more)).toEqual(LOCKED)

  wait(fixture, 299)
  expect(buttonState(more)).toEqual(LOCKED)
  more.click()
  submitEnrolment(node)
  await flush(fixture)
  expect(openings()).toHaveLength(2)
  wait(fixture, 1)
  expect(buttonState(more)).toEqual(SPINNING)

  answer.settle({ required: false, purpose: 'add an authenticator app' })
  await flush(fixture)
  expect(node('profile-2fa-enroll-label')).not.toBeNull()
  expect(buttonState(node('profile-2fa-enroll-submit')!)).toEqual(RESTING)
  fixture.destroy()
})

it('turns the submit off at once for an empty code and for codes not marked saved', async () => {
  const { dispatch, fill, fixture, node } = setup(
    {
      ...REPLIES,
      profile_second_factor_enroll_confirm: {
        backupCodes: ['1111-2222', '3333-4444'],
      },
    },
    SECTION_OFF,
  )
  node('profile-2fa-add')!.click()
  await settle(fixture)
  submitEnrolment(node)
  await settle(fixture)
  expect(buttonState(node('profile-2fa-enroll-submit')!)).toEqual(OFF)
  submitEnrolment(node)
  await settle(fixture)
  expect(
    dispatch.mock.calls.filter(
      ([action]) => action === 'profile_second_factor_enroll_confirm',
    ),
  ).toHaveLength(0)

  fill('profile-2fa-enroll-code', '123456')
  expect(buttonState(node('profile-2fa-enroll-submit')!)).toEqual(RESTING)
  submitEnrolment(node)
  await settle(fixture)
  expect(node('backup-codes')).not.toBeNull()
  expect(buttonState(node('profile-2fa-enroll-submit')!)).toEqual(OFF)

  node('backup-codes-saved')!.click()
  fixture.detectChanges()
  expect(buttonState(node('profile-2fa-enroll-submit')!)).toEqual(RESTING)
  fixture.destroy()
})

it('turns the submit off at once for an empty code from the connected app', async () => {
  const { fill, fixture, node } = setup(REPLIES, SECTION_ON)
  node('profile-2fa-add')!.click()
  await settle(fixture)
  submitEnrolment(node)
  await settle(fixture)
  expect(node('profile-2fa-proof')).not.toBeNull()
  expect(buttonState(node('profile-2fa-enroll-submit')!)).toEqual(OFF)

  fill('profile-2fa-proof', '654321')
  expect(buttonState(node('profile-2fa-enroll-submit')!)).toEqual(RESTING)
  fixture.destroy()
})

it('asks a second app for a code from the connected one after the name, as before', async () => {
  const { dispatch, fill, fixture, node } = setup(REPLIES, SECTION_ON)
  node('profile-2fa-add')!.click()
  await settle(fixture)
  fill('profile-2fa-enroll-label', 'Work phone')
  submitEnrolment(node)
  await settle(fixture)
  expect(node('profile-2fa-proof')).not.toBeNull()
  expect(dispatch).toHaveBeenCalledTimes(1)
  fixture.destroy()
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

  expect(node('hilos-2fa-turned-off')?.textContent?.trim()).toBe(
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

  expect(node('hilos-2fa-turned-off')?.textContent?.trim()).toBe(
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
  const { fixture, node, push } = setup(REPLIES, SECTION_ON)
  node('profile-2fa-codes-show')!.click()
  fixture.detectChanges()
  expect(node('profile-2fa-proof')).not.toBeNull()

  push({ ...SECTION_ON, enabled: false })

  expect(node('modal')).toBeNull()
  expect(node('hilos-2fa-turned-off')).not.toBeNull()

  push(SECTION_ON)
  expect(node('hilos-2fa-turned-off')).toBeNull()
  expect(node('profile-2fa-apps')).not.toBeNull()
})
