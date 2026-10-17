// Covers what the Angular security page view owns (HIL-1138): connecting an app
// is a protected operation, so the modal opens on the server's word - at the
// confirmation step for the first app, at the name when no step is needed - and
// a second app is still asked for a code from the connected one after the name.
// Turned off by the administrator (HIL-1303), the page is one plate, beside a
// removal that stands, and an open window closes when it turns off.
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  createSignal,
  ScopeManager,
  type HilosRouter,
  type HilosSecondFactorContext,
  type ProjectSignal,
} from '@hilos/core'
import { expect, it, vi } from 'vitest'
import { HilosProfileSecurityPage } from '../src/profile/HilosProfileSecurityPage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

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

it('is one plate while turned off, saying the apps are kept, with no apps, codes, wait or buttons', () => {
  const { node } = setup(REPLIES, { ...SECTION_ON, enabled: false })

  expect(node('hilos-2fa-turned-off')?.textContent?.trim()).toBe(
    'Your administrator turned two-step verification off. Your apps and backup codes are kept and will work again when it is turned back on.',
  )
  for (const id of [
    'profile-2fa-apps',
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
