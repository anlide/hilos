import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  bindProfileFlows,
  createSignal,
  HILOS_PROFILE_IDENTITIES_LIST,
  profileFlowsSchema,
  ScopeManager,
  SIGNAL_PROFILE_FLOWS,
  resolveHilosProfileSignInMethods,
  type HilosAuthContext,
  type HilosConnection,
  type HilosProfileSignInMethod,
  type HilosRouter,
  type ProjectSignal,
} from '@hilos/core'
import { afterEach, expect, it, vi } from 'vitest'
import { HilosProfileSignInPage } from '../src/profile/HilosProfileSignInPage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

/** The server's answer to the add-a-way-in confirmation start. */
const NO_STEP = { required: false, purpose: 'add a way to sign in' }
const PASSWORD_STEP = {
  required: true,
  purpose: 'add a way to sign in',
  method: 'password',
}

/** The two ways that ask for a code, and what each step of theirs is called. */
const CODE_WAYS = [
  {
    way: 'phone',
    choose: 'profile-sign-in-choose-phone',
    address: 'profile-add-sms-phone',
    value: '+15551234567',
    payload: { phone: '+15551234567' },
    submit: 'profile-add-sms-request',
    action: 'profile_add_sms_request',
    code: 'profile-add-sms-code',
    send: 'profile-add-sms-send',
  },
  {
    way: 'password',
    choose: 'profile-sign-in-choose-password',
    address: 'profile-add-password-email',
    value: 'me@example.test',
    payload: { email: 'me@example.test' },
    submit: 'profile-add-password-request',
    action: 'profile_add_password_request',
    code: 'profile-add-password-code',
    send: 'profile-add-password-send',
  },
]

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
  const emit = (type: string, data: unknown) => {
    for (const listener of listeners)
      listener({ kind: 'project', type, data } as ProjectSignal)
  }
  const dispatch = vi.fn(
    (action: string, payload?: { phone?: string; email?: string }) => {
      if (action === 'profile_add_sms_request' && payload?.phone)
        emit(
          SIGNAL_PROFILE_FLOWS,
          profileFlowsSchema.parse({
            flows: [
              {
                operation: 'add_sign_in_method',
                step: 'phone_sent',
                address: payload.phone,
                target: payload.phone,
              },
            ],
          }),
        )
      if (action === 'profile_add_password_request' && payload?.email)
        emit(
          SIGNAL_PROFILE_FLOWS,
          profileFlowsSchema.parse({
            flows: [
              {
                operation: 'add_sign_in_method',
                step: 'email_sent',
                address: payload.email,
                target: payload.email,
              },
            ],
          }),
        )
      if (
        action === 'profile_add_sms_confirm' ||
        action === 'hilos_profile_flow_cancel'
      )
        emit(SIGNAL_PROFILE_FLOWS, profileFlowsSchema.parse({ flows: [] }))
      return {
        loading: createSignal(false),
        done: Promise.resolve(
          action === 'hilos_step_up_start'
            ? { reply: opening }
            : action === 'profile_add_sms_request' ||
                action === 'profile_add_password_request'
              ? {
                  reply: {
                    sent: true,
                    resendAt: 1_900_000_000_000,
                    expiresAt: 1_900_000_600_000,
                  },
                }
              : {},
        ),
      }
    },
  )
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
  const stopFlows = bindProfileFlows(context.connection as HilosConnection)
  emit(SIGNAL_PROFILE_FLOWS, profileFlowsSchema.parse({ flows: [] }))
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
    emit,
    stopFlows,
    node,
    setMethods: (next: readonly HilosProfileSignInMethod[]) => {
      setMethods(context.scopes, next)
      fixture.detectChanges()
    },
  }
}

afterEach(() => {
  vi.useRealTimers()
})

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
  const { dispatch, fill, fixture, listeners, node, stopFlows } = setup()
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
    code: '123456',
  })
  expect(node('profile-sign-in-add-modal')).toBeNull()
  fixture.destroy()
  stopFlows()
  expect(listeners.size).toBe(0)
})

it('asks before discarding a session code step with no draft and clears a code after a new frame', async () => {
  const { emit, fill, fixture, node } = setup()
  emit(
    SIGNAL_PROFILE_FLOWS,
    profileFlowsSchema.parse({
      flows: [
        {
          operation: 'add_sign_in_method',
          step: 'phone_sent',
          address: '+15551234567',
          target: '+15551234567',
        },
      ],
    }),
  )
  node('profile-sign-in-add').click()
  await settle(fixture)
  expect(node('profile-add-sms-code')).not.toBeNull()
  fill('profile-add-sms-code', '123456')
  emit(
    SIGNAL_PROFILE_FLOWS,
    profileFlowsSchema.parse({
      flows: [
        {
          operation: 'add_sign_in_method',
          step: 'phone_sent',
          address: '+15559876543',
          target: '+15559876543',
        },
      ],
    }),
  )
  fixture.detectChanges()
  expect((node('profile-add-sms-code') as HTMLInputElement).value).toBe('')
  node('profile-sign-in-add-cancel').click()
  fixture.detectChanges()
  expect(document.querySelector('[data-id="modal-confirm"]')).not.toBeNull()
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

it.each(CODE_WAYS)(
  'draws the $way send block on the code step and empties the code before it asks for another one',
  async (way) => {
    // The send gate of the code request has reopened: Send again is allowed.
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(1_900_000_001_000)
    const { dispatch, fill, fixture, node } = setup()
    node('profile-sign-in-add').click()
    await settle(fixture)
    node(way.choose).click()
    fixture.detectChanges()
    fill(way.address, way.value)
    node(way.submit).click()
    await settle(fixture)
    fill(way.code, '123456')

    expect(node(way.send)).not.toBeNull()
    expect(
      (fixture.nativeElement as HTMLElement).querySelector('[inert] [data-id]'),
    ).toBeNull()
    const again = node(`${way.send}-again`) as HTMLButtonElement
    expect(again.disabled).toBe(false)
    const answer = dispatch.getMockImplementation()!
    const codeAtSend: string[] = []
    dispatch.mockImplementation((action: string) => {
      if (action === way.action) {
        // Draw the page as the request leaves: its code field is empty already.
        fixture.detectChanges()
        codeAtSend.push((node(way.code) as HTMLInputElement).value)
      }
      return answer(action)
    })
    again.click()
    await settle(fixture)
    expect(codeAtSend).toEqual([''])
    expect(dispatch).toHaveBeenLastCalledWith(
      way.action,
      way.payload,
      expect.anything(),
    )
    fixture.destroy()
  },
)
