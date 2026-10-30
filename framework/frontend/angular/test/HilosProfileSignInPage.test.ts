import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  ActionError,
  AUTH_ACTION_PASSKEY_REGISTER_OPTIONS,
  bindProfileFlows,
  bindTimeZoneState,
  cancelOAuthTrip,
  createConnectionInteractionState,
  createSignal,
  HILOS_PROFILE_IDENTITIES_LIST,
  PROFILE_UNLINK_IDENTITY_ACTION,
  profileFlowsSchema,
  ScopeManager,
  SIGNAL_PROFILE_FLOWS,
  resolveHilosProfileSignInMethods,
  type HilosAuthContext,
  type HilosConnection,
  type HilosProfileSignInMethod,
  type ProjectSignal,
} from '@hilos/core'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { profileSectionFixture } from '../../core/test/profile/profileSectionFixture.js'
import { HilosProfileSignInPage } from '../src/profile/HilosProfileSignInPage.js'
import { HILOS_CONNECTION_INTERACTION } from '../src/hilosConnectionInteraction.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

let releaseTimeZone: (() => void) | undefined

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
const MAGIC_LINK = resolveHilosProfileSignInMethods(
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
const LINK_AND_PASSKEY = [...MAGIC_LINK, ...PASSKEY_ONLY]
const PROFILE_METHODS = resolveHilosProfileSignInMethods(
  [
    {
      id: 10,
      type: 'password',
      provider: null,
      identifier: 'a@example.test',
      verified: true,
    },
    {
      id: 11,
      type: 'sms',
      provider: null,
      identifier: '+15551234567',
      verified: true,
    },
    {
      id: 12,
      type: 'sms',
      provider: null,
      identifier: '+15557654321',
      verified: false,
    },
    {
      id: 13,
      type: 'magic_link',
      provider: null,
      identifier: 'b@example.test',
      verified: true,
    },
    {
      id: 14,
      type: 'oauth',
      provider: 'oauth:github',
      identifier: 'octocat',
      verified: true,
    },
    {
      id: 15,
      type: 'oauth',
      provider: 'oauth:google',
      identifier: 'c@example.test',
      verified: true,
    },
    {
      id: 16,
      type: 'oauth',
      provider: 'oauth:other',
      identifier: 'other-account',
      verified: false,
    },
    {
      id: 17,
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
    upsertIdentity(scopes, method)
    return ref
  })
  page.lists.upsert(HILOS_PROFILE_IDENTITIES_LIST, 1, {
    identities,
    passkeyCredentials: [],
  })
}

/**
 * Upsert one normalized identity, leaving its page-list references untouched.
 *
 * @param scopes The scope manager containing the profile page.
 * @param method The normalized identity to write.
 */
function upsertIdentity(
  scopes: ScopeManager,
  method: HilosProfileSignInMethod,
): void {
  const id = Number(method.key)
  const page = scopes.page() ?? scopes.openPage('hilos_profile_sign_in')
  page.entities.upsert(
    { type: 'identities', id },
    {
      id,
      type: method.type,
      provider: method.provider,
      identifier: method.identifier,
      verified: method.verified,
      passwordChangedAt: method.passwordChangedAt,
      providerName: method.providerName,
    },
  )
}

function setup(
  opening: object = NO_STEP,
  methods = resolveHilosProfileSignInMethods([], []),
  offered: readonly object[] = OFFERED,
) {
  const listeners = new Set<(signal: ProjectSignal) => void>()
  let frameCount = 0
  const emit = (type: string, data: unknown) => {
    frameCount += 1
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
  let scopeUpdates = 0
  const stopFlows = bindProfileFlows(context.connection as HilosConnection)
  emit(SIGNAL_PROFILE_FLOWS, profileFlowsSchema.parse({ flows: [] }))
  context.scopes.session.data.set('authMethods', offered)
  scopeUpdates += 1
  setMethods(context.scopes, methods)
  const router = profileSectionFixture(context.scopes, 'hilos_profile_sign_in')
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
    context,
    frameCount: () => frameCount,
    identityList: () =>
      context.scopes.pageListSignal(HILOS_PROFILE_IDENTITIES_LIST).get(),
    identityFields: (id: number) =>
      context.scopes.entitySignal({ type: 'identities', id }).get()?.fields,
    scopeUpdateCount: () => scopeUpdates,
    updateIdentity: (method: HilosProfileSignInMethod) => {
      upsertIdentity(context.scopes, method)
      scopeUpdates += 1
      fixture.detectChanges()
    },
    setMethods: (next: readonly HilosProfileSignInMethod[]) => {
      setMethods(context.scopes, next)
      scopeUpdates += 1
      fixture.detectChanges()
    },
  }
}

afterEach(() => {
  releaseTimeZone?.()
  releaseTimeZone = undefined
  vi.restoreAllMocks()
  vi.useRealTimers()
})

it('renders each profile method with its glyph and normalized subtitle', () => {
  const { node } = setup(NO_STEP, PROFILE_METHODS, [
    ...OFFERED,
    { key: 'oauth:google', name: 'Google' },
    { key: 'oauth:other', name: 'Other' },
  ])
  const rows = [
    ['10', 'bi-lock', 'Password', 'Set'],
    ['11', 'bi-phone', 'Phone', '+1 555 123 4567 · verified'],
    ['12', 'bi-phone', 'Phone', '+1 555 765 4321'],
    ['13', 'bi-envelope', 'Email link', 'b@example.test'],
    ['14', 'bi-github', 'GitHub', 'Linked'],
    ['15', 'bi-google', 'Google', 'Linked'],
    ['16', 'bi-box-arrow-in-right', 'Other', 'Linked'],
  ] as const
  for (const [key, glyph, title, subtitle] of rows) {
    const row = fixtureRow(node, key)
    expect(Array.from(row.querySelector('i')!.classList).sort()).toEqual(
      ['bi', glyph, 'fs-5', 'text-body-secondary', 'flex-shrink-0'].sort(),
    )
    expect(
      row.querySelector('[data-id="identity-type"]')?.textContent?.trim(),
    ).toBe(title)
    expect(
      row.querySelector('[data-id="identity-identifier"]')?.textContent?.trim(),
    ).toBe(subtitle)
    expect(row.querySelector('[data-id="identity-verified"]')).toBeNull()
    expect(row.querySelector('[data-id="identity-unverified"]')).toBeNull()
  }
  const passkey = fixtureRow(node, '17')
  expect(passkey.querySelector('i')?.className).toContain('bi-fingerprint')
  expect(
    passkey
      .querySelector('[data-id="identity-passkey-added"]')
      ?.textContent?.trim(),
  ).toBe('Passkey')
  const add = node('profile-sign-in-add')
  expect(add.querySelector('i')?.className).toBe('bi bi-plus-lg me-1')
  expect(add.querySelector('i')?.getAttribute('aria-hidden')).toBe('true')
  expect(add.textContent).toContain('Add a way to sign in')
})

it('keeps an unknown method subtitle on its normalized identifier', () => {
  const [unknown] = resolveHilosProfileSignInMethods(
    [
      {
        id: 18,
        type: 'external_method',
        provider: null,
        identifier: 'opaque-reference',
        verified: false,
      },
    ],
    [],
  )
  const { node } = setup(NO_STEP, [unknown!])
  const row = fixtureRow(node, '18')

  expect(
    row.querySelector('[data-id="identity-type"]')?.textContent?.trim(),
  ).toBe('external_method')
  expect(
    row.querySelector('[data-id="identity-identifier"]')?.textContent?.trim(),
  ).toBe('opaque-reference')
})

it('redraws a password subtitle from a live identity entity update', async () => {
  const resolved = new Intl.DateTimeFormat().resolvedOptions()
  vi.spyOn(Intl.DateTimeFormat.prototype, 'resolvedOptions').mockImplementation(
    () => ({ ...resolved, timeZone: 'Europe/Warsaw' }),
  )

  const [password] = resolveHilosProfileSignInMethods(
    [
      {
        id: 1,
        type: 'password',
        identifier: 'a@example.test',
        verified: true,
        provider: null,
      },
    ],
    [],
  )
  const world = setup(NO_STEP, [password!])
  releaseTimeZone = bindTimeZoneState(world.context.connection)
  await flush(world.fixture)
  const row = fixtureRow(world.node, '1')
  const rowElement = row
  const subtitle = row.querySelector('[data-id="identity-identifier"]')!
  const subtitleElement = subtitle
  const identityList = world.identityList()
  const scopeUpdates = world.scopeUpdateCount()
  const frames = world.frameCount()
  const dispatches = world.dispatch.mock.calls.length

  expect(password?.passwordChangedAt).toBeNull()
  expect(subtitle.textContent?.trim()).toBe('Set')
  world.updateIdentity({ ...password!, passwordChangedAt: 1767227400000 })
  await flush(world.fixture)

  expect(fixtureRow(world.node, '1')).toBe(rowElement)
  expect(row.querySelector('[data-id="identity-identifier"]')).toBe(
    subtitleElement,
  )
  expect(subtitle.textContent?.trim()).toBe(
    'Set · last changed Jan 1, 2026, 1:30:00 AM',
  )
  expect(world.identityList()).toBe(identityList)
  expect(world.scopeUpdateCount()).toBe(scopeUpdates + 1)
  expect(world.frameCount()).toBe(frames)
  expect(world.dispatch).toHaveBeenCalledTimes(dispatches)
})

it('redraws OAuth with the trimmed provider-reported name', async () => {
  const [oauth] = resolveHilosProfileSignInMethods(
    [
      {
        id: 5,
        type: 'oauth',
        identifier: 'github:provider-subject',
        verified: true,
        provider: 'oauth:github',
      },
    ],
    [],
  )
  const world = setup(NO_STEP, [oauth!])
  const row = fixtureRow(world.node, '5')
  const rowElement = row
  const subtitle = row.querySelector('[data-id="identity-identifier"]')!
  const subtitleElement = subtitle
  const identityList = world.identityList()
  const scopeUpdates = world.scopeUpdateCount()
  const frames = world.frameCount()
  const dispatches = world.dispatch.mock.calls.length

  expect(oauth?.providerName).toBeNull()
  expect(oauth?.identifier).toBe('github:provider-subject')
  expect(subtitle.textContent?.trim()).toBe('Linked')
  world.updateIdentity({ ...oauth!, providerName: '  Octocat  ' })
  await flush(world.fixture)

  expect(fixtureRow(world.node, '5')).toBe(rowElement)
  expect(row.querySelector('[data-id="identity-identifier"]')).toBe(
    subtitleElement,
  )
  expect(subtitle.textContent?.trim()).toBe('Octocat')
  expect(world.identityList()).toBe(identityList)
  expect(world.scopeUpdateCount()).toBe(scopeUpdates + 1)
  expect(world.frameCount()).toBe(frames)
  expect(world.dispatch).toHaveBeenCalledTimes(dispatches)
})

it('reformats a password subtitle when only the reader time zone changes', async () => {
  let browserZone = 'Asia/Tokyo'
  const resolved = new Intl.DateTimeFormat().resolvedOptions()
  vi.spyOn(Intl.DateTimeFormat.prototype, 'resolvedOptions').mockImplementation(
    () => ({ ...resolved, timeZone: browserZone }),
  )

  const [password] = resolveHilosProfileSignInMethods(
    [
      {
        id: 1,
        type: 'password',
        identifier: 'a@example.test',
        verified: true,
        provider: null,
        passwordChangedAt: 1767227400000,
      },
    ],
    [],
  )
  const world = setup(NO_STEP, [password!])
  releaseTimeZone = bindTimeZoneState(world.context.connection)
  await flush(world.fixture)
  const row = fixtureRow(world.node, '1')
  const rowElement = row
  const subtitle = row.querySelector('[data-id="identity-identifier"]')!
  const subtitleElement = subtitle
  const identityList = world.identityList()
  const identityFields = world.identityFields(1)
  const scopeUpdates = world.scopeUpdateCount()
  const frames = world.frameCount()
  const dispatches = world.dispatch.mock.calls.length

  expect(subtitle.textContent?.trim()).toBe(
    'Set · last changed Jan 1, 2026, 9:30:00 AM',
  )
  browserZone = 'America/New_York'
  window.dispatchEvent(new Event('focus'))
  await flush(world.fixture)

  expect(fixtureRow(world.node, '1')).toBe(rowElement)
  expect(row.querySelector('[data-id="identity-identifier"]')).toBe(
    subtitleElement,
  )
  expect(subtitle.textContent?.trim()).toBe(
    'Set · last changed Dec 31, 2025, 7:30:00 PM',
  )
  expect(world.identityList()).toBe(identityList)
  expect(world.identityFields(1)).toBe(identityFields)
  expect(world.scopeUpdateCount()).toBe(scopeUpdates)
  expect(world.frameCount()).toBe(frames)
  expect(world.dispatch).toHaveBeenCalledTimes(dispatches)
})

it('uses provider glyphs in the live chooser and its inert twin', async () => {
  const { fixture, node } = setup(NO_STEP, MAGIC_LINK, [
    ...OFFERED,
    { key: 'oauth:google', name: 'Google' },
    { key: 'oauth:other', name: 'Other' },
  ])
  node('profile-sign-in-add').click()
  await settle(fixture)
  const twin = (fixture.nativeElement as HTMLElement).querySelector(
    '[data-id="profile-sign-in-add-modal"] .invisible[inert].d-flex',
  )!
  for (const [provider, glyph] of [
    ['github', 'bi-github'],
    ['google', 'bi-google'],
    ['other', 'bi-box-arrow-in-right'],
  ]) {
    const live = node(`profile-oauth-link-oauth:${provider}`)
    const copy = Array.from(twin.querySelectorAll('span.btn')).find((choice) =>
      choice.textContent?.includes(
        provider === 'github'
          ? 'Continue with GitHub'
          : provider === 'google'
            ? 'Continue with Google'
            : 'Continue with Other',
      ),
    )!
    for (const choice of [live, copy]) {
      expect(Array.from(choice.querySelector('i')!.classList).sort()).toEqual(
        ['bi', glyph, 'fs-5'].sort(),
      )
      expect(choice.querySelector('i')?.getAttribute('aria-hidden')).toBe(
        'true',
      )
    }
  }
})

it('keeps Change available and explains why the sole password cannot be removed', () => {
  const { node, setMethods } = setup()
  setMethods(PROFILE_METHODS.slice(0, 1))
  const change = node('profile-password-change') as HTMLButtonElement
  const remove = node('identity-unlink') as HTMLButtonElement
  expect(change.disabled).toBe(false)
  expect(remove.disabled).toBe(true)
  expect(node('identity-unlink-blocked').textContent).toContain(
    'You cannot remove your only login method',
  )
  expect(node('identity-unlink-blocked').querySelector('i')?.className).toBe(
    'bi bi-exclamation-triangle me-1',
  )
})

function fixtureRow(
  node: (id: string) => HTMLElement,
  key: string,
): HTMLElement {
  return node('profile-identities-list').querySelector(
    `[data-identity-key="${key}"]`,
  )!
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

/**
 * A dispatch answer that stays in flight until the test settles it.
 *
 * @returns The answer to hand back from dispatch, and how to settle it.
 */
function held(): {
  answer: {
    loading: ReturnType<typeof createSignal<boolean>>
    done: Promise<{ reply: object }>
  }
  resolve: (value: { reply: object }) => void
  reject: (error: unknown) => void
} {
  let resolve!: (value: { reply: object }) => void
  let reject!: (error: unknown) => void
  const done = new Promise<{ reply: object }>((settle, refuse) => {
    resolve = settle
    reject = refuse
  })

  return { answer: { loading: createSignal(false), done }, resolve, reject }
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
 * Let every answer the page awaits arrive on the real clock, however long its
 * chain, and draw what it changed.
 *
 * @param fixture The page under test.
 */
async function drain(fixture: ComponentFixture<unknown>): Promise<void> {
  await new Promise((resolve) => setTimeout(resolve, 0))
  fixture.detectChanges()
}

/**
 * Move the fake clock and draw what it changed.
 *
 * @param fixture The page under test.
 * @param ms How far to move the clock.
 */
function wait(fixture: ComponentFixture<unknown>, ms: number): void {
  vi.advanceTimersByTime(ms)
  fixture.detectChanges()
}

/** The delayed spinner of a LoadingButton, if it shows. */
function spinnerOf(button: HTMLElement): Element | null {
  return button.querySelector('[data-id="loading-button-spinner"]')
}

// In a group the pressed button carries the flight: it is locked at once and
// keeps its look until its one spinner comes; the others are off natively from
// the same moment, by a flight that is not theirs (HIL-1768).
describe('profile sign-in page locks a pressed button at once and the others by its flight', () => {
  afterEach(() => {
    delete (globalThis as { PublicKeyCredential?: unknown }).PublicKeyCredential
    delete (navigator as { credentials?: unknown }).credentials
  })

  it.each(['profile-sign-in-passkey-only-password', 'profile-sign-in-add'])(
    'keeps %s live-looking while the dialog opens and turns the others off at once',
    async (pressedId) => {
      vi.useFakeTimers()
      const { dispatch, fixture, node } = setup(NO_STEP, PASSKEY_ONLY)
      const opening = held()
      dispatch.mockImplementationOnce(() => opening.answer)
      const pressed = node(pressedId) as HTMLButtonElement
      const others = [
        'profile-sign-in-passkey-only-password',
        'profile-sign-in-passkey-only-phone',
        'profile-sign-in-passkey-only-link-oauth:github',
        'profile-sign-in-add',
      ]
        .filter((id) => id !== pressedId)
        .map((id) => node(id) as HTMLButtonElement)
      const starts = () =>
        dispatch.mock.calls.filter(
          ([action]) => action === 'hilos_step_up_start',
        )

      pressed.click()
      fixture.detectChanges()
      expect(starts()).toHaveLength(1)
      expect(pressed.getAttribute('aria-busy')).toBe('true')
      expect(pressed.getAttribute('aria-disabled')).toBe('true')
      expect(pressed.disabled).toBe(false)
      expect(others.map((button) => button.disabled)).toEqual([
        true,
        true,
        true,
      ])
      for (const button of others)
        expect(button.hasAttribute('aria-busy')).toBe(false)

      pressed.click()
      for (const button of others) button.click()
      fixture.detectChanges()
      expect(starts()).toHaveLength(1)

      wait(fixture, 299)
      expect(spinnerOf(pressed)).toBeNull()
      expect(pressed.classList.contains('disabled')).toBe(false)
      wait(fixture, 1)
      expect(spinnerOf(pressed)).not.toBeNull()
      expect(pressed.classList.contains('disabled')).toBe(true)
      expect(pressed.disabled).toBe(false)
      for (const button of others) expect(spinnerOf(button)).toBeNull()

      opening.resolve({ reply: NO_STEP })
      await flush(fixture)
      expect(node('profile-sign-in-add-modal')).not.toBeNull()
      expect(starts()).toHaveLength(1)
      fixture.destroy()
    },
  )

  it('keeps a pressed passkey live-looking while the provider beside it goes off at once', async () => {
    ;(globalThis as { PublicKeyCredential?: unknown }).PublicKeyCredential =
      class {}
    Object.defineProperty(navigator, 'credentials', {
      configurable: true,
      value: { create: vi.fn(), get: vi.fn() },
    })
    const { dispatch, fixture, node } = setup(NO_STEP, MAGIC_LINK, [
      ...OFFERED,
      { key: 'passkey', name: null },
    ])
    node('profile-sign-in-add').click()
    await settle(fixture)
    const options = held()
    dispatch.mockImplementationOnce(() => options.answer)
    const passkey = node('profile-passkey-add') as HTMLButtonElement
    const provider = node(
      'profile-oauth-link-oauth:github',
    ) as HTMLButtonElement
    const requests = () =>
      dispatch.mock.calls.filter(
        ([action]) => action === AUTH_ACTION_PASSKEY_REGISTER_OPTIONS,
      )
    expect(provider.disabled).toBe(false)

    passkey.click()
    fixture.detectChanges()
    expect(requests()).toHaveLength(1)
    expect(passkey.getAttribute('aria-busy')).toBe('true')
    expect(passkey.getAttribute('aria-disabled')).toBe('true')
    expect(passkey.disabled).toBe(false)
    expect(provider.disabled).toBe(true)
    expect(provider.hasAttribute('aria-busy')).toBe(false)

    passkey.click()
    provider.click()
    fixture.detectChanges()
    expect(requests()).toHaveLength(1)
    expect(
      dispatch.mock.calls.filter(
        ([action]) => action !== 'hilos_step_up_start',
      ),
    ).toHaveLength(1)

    options.reject(
      new ActionError(
        AUTH_ACTION_PASSKEY_REGISTER_OPTIONS,
        'fail',
        'Passkeys are off.',
      ),
    )
    await drain(fixture)
    expect(passkey.hasAttribute('aria-busy')).toBe(false)
    expect(passkey.hasAttribute('aria-disabled')).toBe(false)
    expect(provider.disabled).toBe(false)
    fixture.destroy()
  })

  it('locks Remove at once by its own flight, keeps Cancel off and sends once', async () => {
    vi.useFakeTimers()
    const { dispatch, fixture, node } = setup(NO_STEP, LINK_AND_PASSKEY)
    node('identity-unlink').click()
    fixture.detectChanges()
    const removal = held()
    dispatch.mockImplementationOnce(() => removal.answer)
    const remove = node('identity-unlink-yes') as HTMLButtonElement
    const cancel = node('identity-unlink-cancel') as HTMLButtonElement
    const sends = () =>
      dispatch.mock.calls.filter(
        ([action]) => action === PROFILE_UNLINK_IDENTITY_ACTION,
      )

    remove.click()
    fixture.detectChanges()
    expect(sends()).toHaveLength(1)
    expect(remove.getAttribute('aria-busy')).toBe('true')
    expect(remove.getAttribute('aria-disabled')).toBe('true')
    expect(remove.disabled).toBe(false)
    expect(cancel.disabled).toBe(true)

    remove.click()
    wait(fixture, 299)
    expect(sends()).toHaveLength(1)
    expect(spinnerOf(remove)).toBeNull()
    expect(remove.classList.contains('disabled')).toBe(false)
    wait(fixture, 1)
    expect(spinnerOf(remove)).not.toBeNull()
    expect(remove.disabled).toBe(false)

    removal.resolve({ reply: {} })
    await flush(fixture)
    expect(node('profile-unlink-modal')).toBeNull()
    expect(sends()).toHaveLength(1)
    fixture.destroy()
  })

  it('locks the confirmation of the step by its own flight, not natively', async () => {
    const { dispatch, fill, fixture, node } = setup(PASSWORD_STEP)
    node('profile-sign-in-add').click()
    await settle(fixture)
    fill('step-up-password', 'secret')
    const confirmation = held()
    dispatch.mockImplementationOnce(() => confirmation.answer)
    const confirm = node(
      'profile-sign-in-add-step-up-confirm',
    ) as HTMLButtonElement
    const confirms = () =>
      dispatch.mock.calls.filter(
        ([action]) => action === 'hilos_step_up_confirm',
      )
    const submitStep = () =>
      node('profile-sign-in-add-step-up').dispatchEvent(
        new Event('submit', { cancelable: true }),
      )

    submitStep()
    fixture.detectChanges()
    expect(confirms()).toHaveLength(1)
    expect(confirm.getAttribute('aria-busy')).toBe('true')
    expect(confirm.getAttribute('aria-disabled')).toBe('true')
    expect(confirm.disabled).toBe(false)

    confirm.click()
    submitStep()
    fixture.detectChanges()
    expect(confirms()).toHaveLength(1)

    confirmation.resolve({ reply: {} })
    await drain(fixture)
    expect(node('profile-sign-in-choose-phone')).not.toBeNull()
    fixture.destroy()
  })

  it('turns Save off natively while the form is unfit, and only locks it by its own flight', async () => {
    const { dispatch, fill, fixture, node } = setup(NO_STEP, MAGIC_LINK)
    node('profile-sign-in-add').click()
    await settle(fixture)
    node('profile-sign-in-choose-password').click()
    fixture.detectChanges()
    const save = node('profile-add-password-save') as HTMLButtonElement
    expect(save.disabled).toBe(true)
    expect(save.hasAttribute('aria-busy')).toBe(false)
    fill('profile-add-password-new', 'new-secret')
    fill('profile-add-password-confirm', 'new-secret')
    expect(save.disabled).toBe(false)
    const setting = held()
    dispatch.mockImplementationOnce(() => setting.answer)
    const sends = () =>
      dispatch.mock.calls.filter(
        ([action]) => action === 'profile_set_password',
      )

    save.click()
    fixture.detectChanges()
    expect(sends()).toHaveLength(1)
    expect(save.getAttribute('aria-busy')).toBe('true')
    expect(save.getAttribute('aria-disabled')).toBe('true')
    expect(save.disabled).toBe(false)
    expect(
      (node('profile-sign-in-add-back') as HTMLButtonElement).disabled,
    ).toBe(true)

    save.click()
    fixture.detectChanges()
    expect(sends()).toHaveLength(1)
    fixture.destroy()
  })
})

/** Try each close gesture while the add dialog must stay on its current step. */
function tryClosingAdd(
  fixture: ComponentFixture<unknown>,
  node: (id: string) => HTMLElement,
): void {
  for (const close of [
    () => node('modal-close').click(),
    () => node('profile-sign-in-add-cancel').click(),
    () =>
      node('modal').dispatchEvent(
        new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
      ),
    () => node('modal').click(),
  ]) {
    close()
    fixture.detectChanges()
    expect(node('profile-sign-in-add-modal')).not.toBeNull()
    expect(node('modal-confirm')).toBeNull()
    expect(node('modal-close').matches(':disabled')).toBe(true)
    expect(node('profile-sign-in-add-cancel').matches(':disabled')).toBe(true)
  }
}

describe('profile sign-in add modal busy body', () => {
  afterEach(() => {
    cancelOAuthTrip()
    vi.clearAllTimers()
    vi.restoreAllMocks()
    delete (globalThis as { PublicKeyCredential?: unknown }).PublicKeyCredential
    delete (navigator as { credentials?: unknown }).credentials
  })

  it.each([
    {
      way: 'provider',
      pressedId: 'profile-oauth-link-oauth:github',
      otherId: 'profile-passkey-add',
      action: 'hilos_link_oauth_start',
    },
    {
      way: 'passkey',
      pressedId: 'profile-passkey-add',
      otherId: 'profile-oauth-link-oauth:github',
      action: AUTH_ACTION_PASSKEY_REGISTER_OPTIONS,
    },
  ])(
    'holds the $way chooser and keeps its sending button focused through 0/299/300 ms',
    async ({ pressedId, otherId, action }) => {
      ;(globalThis as { PublicKeyCredential?: unknown }).PublicKeyCredential =
        class {}
      Object.defineProperty(navigator, 'credentials', {
        configurable: true,
        value: { create: vi.fn(), get: vi.fn() },
      })
      vi.spyOn(window, 'open').mockReturnValue({
        closed: false,
        close: vi.fn(),
        location: { replace: vi.fn() },
      } as unknown as Window)
      const { dispatch, fixture, node } = setup(NO_STEP, MAGIC_LINK, [
        ...OFFERED,
        { key: 'oauth:google', name: 'Google' },
        { key: 'passkey', name: null },
      ])
      node('profile-sign-in-add').click()
      await settle(fixture)
      const pending = held()
      dispatch.mockImplementationOnce(() => pending.answer)
      const pressed = node(pressedId) as HTMLButtonElement
      const others = [
        otherId,
        'profile-oauth-link-oauth:google',
        'profile-sign-in-choose-password',
        'profile-sign-in-choose-phone',
      ].map((id) => node(id) as HTMLButtonElement)
      const chooser = pressed.parentElement
      const body = node('profile-sign-in-add-modal')
      const live = node('profile-sign-in-add-live')
      const room = node('profile-sign-in-add-error-slot')
      const label = pressed.firstElementChild as HTMLElement
      const content = label.innerHTML
      const sends = () => dispatch.mock.calls.filter(([key]) => key === action)
      pressed.focus()
      vi.useFakeTimers()
      pressed.click()
      fixture.detectChanges()
      expect(sends()).toHaveLength(1)
      const sent = dispatch.mock.calls.length

      for (const elapsed of [0, 299, 1]) {
        wait(fixture, elapsed)
        expect(node(pressedId)).toBe(pressed)
        expect(pressed.parentElement).toBe(chooser)
        expect(node('profile-sign-in-add-modal')).toBe(body)
        expect(node('profile-sign-in-add-live')).toBe(live)
        expect(node('profile-sign-in-add-error-slot')).toBe(room)
        expect(document.activeElement).toBe(pressed)
        expect(pressed.matches(':disabled')).toBe(false)
        expect(pressed.getAttribute('aria-busy')).toBe('true')
        expect(pressed.getAttribute('aria-disabled')).toBe('true')
        expect(spinnerOf(pressed) !== null).toBe(elapsed === 1)
        expect(
          pressed.querySelectorAll('[data-id="loading-button-spinner"]'),
        ).toHaveLength(elapsed === 1 ? 1 : 0)
        expect(pressed.classList.contains('disabled')).toBe(elapsed === 1)
        expect(pressed.firstElementChild).toBe(label)
        expect(label.innerHTML).toBe(content)
        expect(label.classList.contains('invisible')).toBe(elapsed === 1)
        for (const other of others) {
          expect(other.matches(':disabled')).toBe(true)
          expect(other.hasAttribute('aria-busy')).toBe(false)
          expect(spinnerOf(other)).toBeNull()
          other.click()
        }
        pressed.click()
        tryClosingAdd(fixture, node)
        expect(dispatch).toHaveBeenCalledTimes(sent)
      }
      expect(body.querySelectorAll('[inert] [data-id]')).toHaveLength(0)
      expect(body.closest('[inert], [aria-hidden="true"]')).toBeNull()
      expect(live.getAttribute('role')).toBe('alert')

      pending.reject(
        new ActionError(action, 'fail', 'This way is unavailable.'),
      )
      await flush(fixture)
      expect(node(pressedId)).toBe(pressed)
      expect(document.activeElement).toBe(pressed)
      expect(pressed.firstElementChild).toBe(label)
      expect(label.innerHTML).toBe(content)
      expect(label.classList.contains('invisible')).toBe(false)
      expect(pressed.hasAttribute('aria-busy')).toBe(false)
      for (const other of others) expect(other.matches(':disabled')).toBe(false)
      expect(node('profile-sign-in-add-error').textContent).toContain(
        'This way is unavailable.',
      )
      expect(node('profile-sign-in-add-live')).toBe(live)
      expect(node('profile-sign-in-add-error-slot')).toBe(room)
      expect(node('modal-confirm')).toBeNull()
      expect(node('profile-sign-in-add-cancel').matches(':disabled')).toBe(
        false,
      )
      expect(dispatch).toHaveBeenCalledTimes(sent)

      const retry = held()
      dispatch.mockImplementationOnce(() => retry.answer)
      pressed.click()
      fixture.detectChanges()
      expect(sends()).toHaveLength(2)
      retry.reject(new ActionError(action, 'fail', 'Try another way.'))
      await flush(fixture)
      expect(node('profile-sign-in-add-error').textContent).toContain(
        'Try another way.',
      )
      node('profile-sign-in-add-cancel').click()
      fixture.detectChanges()
      expect(node('profile-sign-in-add-modal')).toBeNull()
      fixture.destroy()
    },
  )

  it('keeps the password draft and nodes through refusal and closes on the successful retry signal', async () => {
    const { dispatch, emit, fill, fixture, node } = setup(NO_STEP, MAGIC_LINK)
    node('profile-sign-in-add').click()
    await settle(fixture)
    node('profile-sign-in-choose-password').click()
    fixture.detectChanges()
    const password = node('profile-add-password-new') as HTMLInputElement
    const confirm = node('profile-add-password-confirm') as HTMLInputElement
    const fields = password.closest('fieldset')
    const save = node('profile-add-password-save') as HTMLButtonElement
    const back = node('profile-sign-in-add-back') as HTMLButtonElement
    const live = node('profile-sign-in-add-live')
    const room = node('profile-add-password-error-slot')
    fill('profile-add-password-new', 'new-secret')
    fill('profile-add-password-confirm', 'new-secret')
    expect(document.activeElement).toBe(password)
    const pending = held()
    dispatch.mockImplementationOnce(() => pending.answer)
    save.click()
    fixture.detectChanges()
    expect(password.matches(':disabled')).toBe(true)
    expect(confirm.matches(':disabled')).toBe(true)
    expect(save.matches(':disabled')).toBe(false)
    expect(save.getAttribute('aria-busy')).toBe('true')
    expect(back.matches(':disabled')).toBe(true)
    save.click()
    password.form!.dispatchEvent(new Event('submit', { cancelable: true }))
    back.click()
    tryClosingAdd(fixture, node)
    const sends = () =>
      dispatch.mock.calls.filter(([key]) => key === 'profile_set_password')
    expect(sends()).toHaveLength(1)
    pending.reject(
      new ActionError(
        'profile_set_password',
        'fail',
        'Choose another password.',
      ),
    )
    await flush(fixture)
    expect(node('profile-add-password-new')).toBe(password)
    expect(node('profile-add-password-confirm')).toBe(confirm)
    expect(password.closest('fieldset')).toBe(fields)
    expect([password.value, confirm.value]).toEqual([
      'new-secret',
      'new-secret',
    ])
    expect(password.matches(':disabled')).toBe(false)
    expect(confirm.matches(':disabled')).toBe(false)
    expect(back.matches(':disabled')).toBe(false)
    expect(node('modal-close').matches(':disabled')).toBe(false)
    expect(node('profile-add-password-error').textContent).toContain(
      'Choose another password.',
    )
    expect(node('profile-sign-in-add-live')).toBe(live)
    expect(node('profile-add-password-error-slot')).toBe(room)
    expect(node('modal-confirm')).toBeNull()
    fill('profile-add-password-confirm', '')
    expect(save.matches(':disabled')).toBe(true)
    password.form!.dispatchEvent(new Event('submit', { cancelable: true }))
    expect(sends()).toHaveLength(1)
    fill('profile-add-password-confirm', 'new-secret')
    save.click()
    await flush(fixture)
    expect(sends()).toHaveLength(2)
    expect(node('profile-sign-in-add-modal')).not.toBeNull()
    expect(password.matches(':disabled')).toBe(true)
    emit('profile_password_updated', { mode: 'added' })
    fixture.detectChanges()
    expect(node('profile-sign-in-add-modal')).toBeNull()
    fixture.destroy()
  })

  it.each(CODE_WAYS)(
    'holds the $way request and its code fields, keeping refused drafts retryable',
    async ({ choose, address, value, submit, action, code }) => {
      const { dispatch, emit, fill, fixture, node } = setup()
      node('profile-sign-in-add').click()
      await settle(fixture)
      node(choose).click()
      fixture.detectChanges()
      const input = node(address) as HTMLInputElement
      const fields = input.closest('fieldset')
      fill(address, value)
      const pending = held()
      dispatch.mockImplementationOnce(() => pending.answer)
      node(submit).click()
      fixture.detectChanges()
      expect(input.matches(':disabled')).toBe(true)
      expect(node('profile-sign-in-add-back').matches(':disabled')).toBe(true)
      input.form!.dispatchEvent(new Event('submit', { cancelable: true }))
      tryClosingAdd(fixture, node)
      const sends = () => dispatch.mock.calls.filter(([key]) => key === action)
      expect(sends()).toHaveLength(1)
      pending.reject(
        new ActionError(action, 'fail', 'This address cannot get codes.'),
      )
      await flush(fixture)
      expect(node(address)).toBe(input)
      expect(input.closest('fieldset')).toBe(fields)
      expect(input.value).toBe(value)
      expect(input.matches(':disabled')).toBe(false)
      expect(node('profile-sign-in-add-back').matches(':disabled')).toBe(false)
      expect(node('profile-sign-in-add-live').textContent).toContain(
        'This address cannot get codes.',
      )
      node(submit).click()
      await flush(fixture)
      expect(sends()).toHaveLength(2)
      const proof = node(code) as HTMLInputElement
      const inputs = Array.from(proof.form!.querySelectorAll('input'))
      fill(code, '123456')
      const phone = action === 'profile_add_sms_request'
      if (!phone) {
        fill('profile-add-password-new', 'new-secret')
        fill('profile-add-password-confirm', 'new-secret')
      }
      const drafts = inputs.map((field) => field.value)
      const confirmation = held()
      dispatch.mockImplementationOnce(() => confirmation.answer)
      proof.form!.dispatchEvent(new Event('submit', { cancelable: true }))
      fixture.detectChanges()
      for (const field of inputs) expect(field.matches(':disabled')).toBe(true)
      tryClosingAdd(fixture, node)
      confirmation.reject(
        new ActionError(
          phone ? 'profile_add_sms_confirm' : 'profile_add_password_confirm',
          'fail',
          'That code is not right.',
        ),
      )
      await flush(fixture)
      expect(Array.from(proof.form!.querySelectorAll('input'))).toEqual(inputs)
      expect(inputs.map((field) => field.value)).toEqual(drafts)
      for (const field of inputs) expect(field.matches(':disabled')).toBe(false)
      proof.form!.dispatchEvent(new Event('submit', { cancelable: true }))
      await flush(fixture)
      if (!phone) {
        emit('profile_password_updated', { mode: 'added' })
        fixture.detectChanges()
      }
      expect(node('profile-sign-in-add-modal')).toBeNull()
      fixture.destroy()
    },
  )

  it('holds step-up confirmation and preserves its refused proof for retry', async () => {
    const { dispatch, fill, fixture, node } = setup(PASSWORD_STEP)
    node('profile-sign-in-add').click()
    await settle(fixture)
    const proof = node('step-up-password') as HTMLInputElement
    const fields = proof.closest('fieldset')
    fill('step-up-password', 'secret')
    const pending = held()
    dispatch.mockImplementationOnce(() => pending.answer)
    proof.form!.dispatchEvent(new Event('submit', { cancelable: true }))
    fixture.detectChanges()
    expect(proof.matches(':disabled')).toBe(true)
    expect(
      node('profile-sign-in-add-step-up-confirm').matches(':disabled'),
    ).toBe(false)
    proof.form!.dispatchEvent(new Event('submit', { cancelable: true }))
    node('profile-sign-in-add-step-up-confirm').click()
    tryClosingAdd(fixture, node)
    const confirms = () =>
      dispatch.mock.calls.filter(([key]) => key === 'hilos_step_up_confirm')
    expect(confirms()).toHaveLength(1)
    pending.reject(
      new ActionError(
        'hilos_step_up_confirm',
        'fail',
        'That password is not right.',
      ),
    )
    await flush(fixture)
    expect(node('step-up-password')).toBe(proof)
    expect(proof.closest('fieldset')).toBe(fields)
    expect(proof.value).toBe('secret')
    expect(proof.matches(':disabled')).toBe(false)
    expect(node('step-up-error').textContent).toContain(
      'That password is not right.',
    )
    expect(node('profile-sign-in-add-live').textContent?.trim()).toBe(
      'That password is not right.',
    )
    expect(node('modal-confirm')).toBeNull()
    expect(node('profile-sign-in-add-cancel').matches(':disabled')).toBe(false)
    proof.form!.dispatchEvent(new Event('submit', { cancelable: true }))
    await flush(fixture)
    expect(confirms()).toHaveLength(2)
    expect(node('profile-sign-in-choose-phone')).not.toBeNull()
    node('profile-sign-in-add-cancel').click()
    fixture.detectChanges()
    expect(node('profile-sign-in-add-modal')).toBeNull()
    fixture.destroy()
  })

  it('holds the modal for a step-up resend independently of the main request and retains the cooldown', async () => {
    const opening = {
      required: true,
      purpose: 'add a way to sign in',
      method: 'email_code',
      destination: 'a@example.test',
      send: { sent: true, resendAt: Date.now() - 60_000, expiresAt: null },
    }
    const { dispatch, fixture, node } = setup(opening)
    node('profile-sign-in-add').click()
    await settle(fixture)
    const code = node('step-up-code') as HTMLInputElement
    const fields = code.closest('fieldset')
    const resend = node('step-up-send-again') as HTMLButtonElement
    expect(resend.matches(':disabled')).toBe(false)
    const pending = held()
    dispatch.mockImplementationOnce(() => pending.answer)
    resend.click()
    fixture.detectChanges()
    expect(code.matches(':disabled')).toBe(true)
    resend.click()
    code.form!.dispatchEvent(new Event('submit', { cancelable: true }))
    tryClosingAdd(fixture, node)
    expect(dispatch).toHaveBeenCalledTimes(2)
    pending.resolve({
      reply: {
        ...opening,
        send: { ...opening.send, resendAt: Date.now() + 60_000 },
      },
    })
    await flush(fixture)
    expect(node('step-up-code')).toBe(code)
    expect(code.closest('fieldset')).toBe(fields)
    expect(code.matches(':disabled')).toBe(false)
    expect(resend.matches(':disabled')).toBe(true)
    expect(node('profile-sign-in-add-cancel').matches(':disabled')).toBe(false)
    expect(node('modal-close').matches(':disabled')).toBe(false)
    expect(node('modal-confirm')).toBeNull()
    expect(dispatch).toHaveBeenCalledTimes(2)
    fixture.destroy()
  })

  it('keeps custom fields and submitting buttons under the connection lock after busy clears', async () => {
    const connection = (state: string) =>
      ({ state, on: () => () => {} }) as unknown as HilosConnection
    const interaction = createConnectionInteractionState(
      connection('connected'),
    )
    TestBed.configureTestingModule({
      providers: [
        {
          provide: HILOS_CONNECTION_INTERACTION,
          useValue: createSignal(interaction),
        },
      ],
    })
    const { dispatch, fill, fixture, node } = setup()
    fixture.componentRef.onDestroy(() => interaction.dispose())
    node('profile-sign-in-add').click()
    await settle(fixture)
    node('profile-sign-in-choose-phone').click()
    fixture.detectChanges()
    const phone = node('profile-add-sms-phone') as HTMLInputElement
    const fields = phone.closest('fieldset')
    const send = node('profile-add-sms-request')
    fill('profile-add-sms-phone', '+15551234567')
    const pending = held()
    dispatch.mockImplementationOnce(() => pending.answer)
    send.click()
    fixture.detectChanges()
    expect(send.matches(':disabled')).toBe(false)
    interaction.setConnection(connection('reconnecting'))
    fixture.detectChanges()
    expect(send.matches(':disabled')).toBe(true)
    pending.reject(
      new ActionError(
        'profile_add_sms_request',
        'fail',
        'Try this number again.',
      ),
    )
    await flush(fixture)
    expect(node('profile-add-sms-phone')).toBe(phone)
    expect(phone.closest('fieldset')).toBe(fields)
    expect(phone.value).toBe('+15551234567')
    expect(phone.matches(':disabled')).toBe(true)
    expect(send.matches(':disabled')).toBe(true)
    expect(send.hasAttribute('aria-busy')).toBe(false)
    tryClosingAdd(fixture, node)
    interaction.setConnection(connection('connected'))
    fixture.detectChanges()
    expect(phone.matches(':disabled')).toBe(false)
    expect(send.matches(':disabled')).toBe(false)
    expect(node('profile-sign-in-add-modal')).not.toBeNull()
    expect(node('modal-confirm')).toBeNull()
    expect(
      dispatch.mock.calls.filter(([key]) => key === 'profile_add_sms_request'),
    ).toHaveLength(1)
    expect(node('profile-add-sms-error').textContent).toContain(
      'Try this number again.',
    )
    fixture.destroy()
  })
})
