import { TestBed, type ComponentFixture } from '@angular/core/testing'
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

/** The methods the session offers: on, ready, in button order. */
const OFFERED = [
  { key: 'password', name: null },
  { key: 'sms', name: null },
  { key: 'oauth:github', name: 'GitHub' },
]
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
  methods = resolveHilosProfileSignInMethods([], []),
  offered: readonly object[] = OFFERED,
) {
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
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: router }],
  })
  const fixture = TestBed.createComponent(HilosProfileSignInPage)
  fixture.componentRef.setInput('context', context)
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
  return {
    dispatch,
    fill,
    fixture,
    listeners,
    node,
    setMethods: (next: readonly HilosProfileSignInMethod[]) => {
      setMethods(context.scopes, next)
      fixture.detectChanges()
    },
  }
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

it('warns a passkey-only account with a button per offered way and drops it when another way arrives', () => {
  const { node, setMethods } = setup(NO_STEP, PASSKEY_ONLY)
  const block = node('profile-sign-in-passkey-only')
  expect(block.textContent).toContain('Only your passkeys can sign you in')
  expect(
    node('profile-sign-in-passkey-only-password').textContent?.trim(),
  ).toBe('Add a password')
  expect(node('profile-sign-in-passkey-only-phone').textContent?.trim()).toBe(
    'Add a phone',
  )
  expect(
    node('profile-sign-in-passkey-only-link-oauth:github').textContent?.trim(),
  ).toBe('Link GitHub')
  expect(block.querySelectorAll('button')).toHaveLength(3)
  expect(node('profile-sign-in-passkey-only-none')).toBeNull()
  setMethods(
    resolveHilosProfileSignInMethods(
      [
        {
          id: 3,
          type: 'passkey',
          provider: null,
          identifier: 'opaque',
          verified: true,
        },
        {
          id: 4,
          type: 'sms',
          provider: null,
          identifier: '+15551234567',
          verified: true,
        },
      ],
      [],
    ),
  )
  expect(node('profile-sign-in-passkey-only')).toBeNull()
})

it('keeps the warning without buttons when nothing but a passkey is on', () => {
  const { node } = setup(NO_STEP, PASSKEY_ONLY, [
    { key: 'passkey', name: null },
  ])
  expect(
    node('profile-sign-in-passkey-only').querySelectorAll('button'),
  ).toHaveLength(0)
  expect(node('profile-sign-in-passkey-only-none').textContent?.trim()).toBe(
    'No other way to sign in is available here.',
  )
})

it('opens the dialog straight at the pressed way, and leaves a switched-off method out of the chooser', async () => {
  const { fixture, node } = setup(NO_STEP, PASSKEY_ONLY, [
    { key: 'password', name: null },
  ])
  expect(node('profile-sign-in-passkey-only-phone')).toBeNull()
  node('profile-sign-in-passkey-only-password').click()
  await settle(fixture)
  expect(node('profile-add-password-email')).not.toBeNull()
  expect(node('profile-sign-in-choose-password')).toBeNull()
  node('profile-sign-in-add-back').click()
  fixture.detectChanges()
  expect(node('profile-sign-in-choose-password')).not.toBeNull()
  expect(node('profile-sign-in-choose-phone')).toBeNull()
})
