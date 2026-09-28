import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  createSignal,
  ScopeManager,
  resolveHilosProfileSignInMethods,
  type HilosAuthContext,
  type HilosRouter,
  type ProjectSignal,
} from '@hilos/core'
import { expect, it, vi } from 'vitest'
import { HilosProfileSignInPage } from '../src/profile/HilosProfileSignInPage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

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
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: router }],
  })
  const fixture = TestBed.createComponent(HilosProfileSignInPage)
  fixture.componentRef.setInput('context', context)
  fixture.componentRef.setInput(
    'methods',
    resolveHilosProfileSignInMethods([], []),
  )
  fixture.detectChanges()
  const node = (id: string) =>
    (fixture.nativeElement as HTMLElement).querySelector<HTMLElement>(
      `[data-id="${id}"]`,
    )!
  const fill = (id: string, value: string) => {
    const input = node(id) as HTMLInputElement
    input.value = value
    input.dispatchEvent(new Event('input', { bubbles: true }))
    fixture.detectChanges()
  }
  return { dispatch, fill, fixture, listeners, node }
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

it('adds a phone through two steps and releases its listeners', async () => {
  const { dispatch, fill, fixture, listeners, node } = setup()
  node('profile-sign-in-add').click()
  await settle(fixture)
  node('profile-sign-in-choose-phone').click()
  fixture.detectChanges()
  fill('profile-add-sms-phone', '+15551234567')
  node('profile-add-sms-request').click()
  await settle(fixture)
  fill('profile-add-sms-code', '123456')
  node('profile-add-sms-confirm').click()
  await settle(fixture)
  expect(dispatch).toHaveBeenLastCalledWith('profile_add_sms_confirm', {
    phone: '+15551234567',
    code: '123456',
  })
  expect(node('profile-sign-in-add-modal')).toBeNull()
  fixture.destroy()
  expect(listeners.size).toBe(0)
})

it('opens at the confirmation step when the server asks, and shows the chooser once confirmed', async () => {
  const { dispatch, fill, fixture, node } = setup(PASSWORD_STEP)
  node('profile-sign-in-add').click()
  await settle(fixture)
  expect(dispatch).toHaveBeenCalledWith(
    'hilos_step_up_start',
    { operation: 'add_sign_in_method' },
    expect.anything(),
  )
  expect(
    (fixture.nativeElement as HTMLElement).querySelector('.modal-title')
      ?.textContent,
  ).toBe("Confirm it's you")
  expect(node('profile-sign-in-choose-phone')).toBeNull()
  expect(node('profile-sign-in-add-back')).toBeNull()
  expect(node('profile-sign-in-add-step-up-confirm')).not.toBeNull()
  fill('step-up-password', 'secret')
  node('profile-sign-in-add-step-up').dispatchEvent(
    new Event('submit', { cancelable: true }),
  )
  await settle(fixture)
  expect(dispatch).toHaveBeenLastCalledWith('hilos_step_up_confirm', {
    operation: 'add_sign_in_method',
    method: 'password',
    code: '',
    backupCode: false,
    password: 'secret',
    passkey: null,
  })
  expect(node('profile-sign-in-choose-phone')).not.toBeNull()
  fixture.destroy()
})
