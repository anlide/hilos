// Covers the Angular profile root (HIL-1169): Name with Change only for the
// project's rename, Email only with a verified address, and a row per catalog
// section in its order with the summaries it knows.
import { TestBed } from '@angular/core/testing'
import {
  createSignal,
  HILOS_PROFILE_IDENTITIES_LIST,
  HilosPages,
  resolveHilosProfileSignInMethods,
  ScopeManager,
  type HilosProfileBinding,
  type HilosProfilePageContext,
  type HilosProfileSignInMethod,
  type HilosRouter,
} from '@hilos/core'
import { expect, it, vi } from 'vitest'
import { HilosProfilePage } from '../src/profile/HilosProfilePage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

/** A router on the profile root whose sections all have an address. */
function router(withSignIn = false): HilosRouter {
  return {
    pageIdentity: createSignal({
      label: 'Profile',
      lead: '',
      breadcrumb: [{ page: HilosPages.PROFILE, label: 'Profile' }],
      children: [
        ...(withSignIn
          ? [
              {
                page: HilosPages.PROFILE_SIGN_IN,
                label: 'Ways to sign in',
                lead: '',
                icon: null,
              },
            ]
          : []),
        {
          page: HilosPages.PROFILE_SECURITY,
          label: 'Security',
          lead: '',
          icon: null,
        },
        {
          page: HilosPages.PROFILE_DATA,
          label: 'Your data',
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
    navigate: () => {},
  } as unknown as HilosRouter
}

/** Set the framework identity list in the profile's page scope. */
function setMethods(
  scopes: ScopeManager,
  methods: readonly HilosProfileSignInMethod[],
): void {
  const page = scopes.page() ?? scopes.openPage(HilosPages.PROFILE)
  const identities = methods.map((method) => {
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
  binding: HilosProfileBinding,
  signedIn = true,
  withSignIn = false,
  initialMethods?: readonly HilosProfileSignInMethod[],
) {
  const scopes = new ScopeManager()
  if (signedIn) scopes.session.data.set('currentUser', { type: 'user', id: 1 })
  if (initialMethods) setMethods(scopes, initialMethods)
  const context = {
    actions: {
      dispatch: vi.fn(() => ({
        loading: createSignal(false),
        done: Promise.resolve({}),
      })),
    },
    connection: { on: () => () => {} },
    scopes,
  } as unknown as HilosProfilePageContext
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: router(withSignIn) }],
  })
  const fixture = TestBed.createComponent(HilosProfilePage)
  fixture.componentRef.setInput('context', context)
  fixture.componentRef.setInput('binding', binding)
  fixture.detectChanges()
  const root = fixture.nativeElement as HTMLElement
  const node = (id: string) =>
    root.querySelector<HTMLElement>(`[data-id="${id}"]`)
  return {
    fixture,
    scopes,
    node,
    root,
    setMethods: (next: readonly HilosProfileSignInMethod[]) => {
      setMethods(scopes, next)
      fixture.detectChanges()
    },
  }
}

it('offers the photo button only after the profile page enables photos', () => {
  const { fixture, node, scopes } = setup(nameOnly())
  expect(node('profile-photo-open')).toBeNull()
  scopes.openPage(HilosPages.PROFILE).data.set('profilePhoto', true)
  fixture.detectChanges()
  expect(node('profile-photo-open')?.getAttribute('aria-label')).toBe(
    'Change your photo',
  )
  fixture.destroy()
})

function nameOnly(): HilosProfileBinding {
  return {
    name: createSignal('Ann Lee'),
    rename: null,
    sessionCount: null,
    deviceCount: null,
  }
}

it('shows the name without Change and the sections in catalog order when the project hands no rename', () => {
  const { node, root } = setup(nameOnly())

  expect(node('profile-identity-name')?.textContent?.trim()).toBe('Ann Lee')
  expect(node('profile-edit')).toBeNull()
  expect(node('profile-email')).toBeNull()
  expect(
    [...root.querySelectorAll('[data-id="profile-section"] .fw-semibold')].map(
      (label) => label.textContent,
    ),
  ).toEqual(['Security', 'Your data'])
  expect(node('profile-security-summary')?.textContent?.trim()).toBe(
    'Two-step verification is off',
  )
  expect(node('profile-data-open')?.getAttribute('href')).toBe(
    `/${HilosPages.PROFILE_DATA}`,
  )
})

it("offers Change for the project's rename and the Email row for a verified address", () => {
  const { node } = setup(
    {
      ...nameOnly(),
      rename: {
        send: () => false,
        refusal: createSignal(null),
        clearRefusal: () => {},
        stepUpOperation: 'change_name',
        minLength: 2,
        maxLength: 64,
      },
    },
    true,
    false,
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
  )

  expect(node('profile-edit')).not.toBeNull()
  expect(node('profile-email')?.textContent?.trim()).toBe(
    'ann@example.test · verified',
  )
  expect(node('profile-email-change')).not.toBeNull()
})

it('draws only the placeholder while nobody is signed in', () => {
  const { node } = setup(nameOnly(), false)

  expect(node('profile-loading')).not.toBeNull()
  expect(node('profile-view')).toBeNull()
})

it('warns under the sign-in row while device keys are the only way in, live', () => {
  const methods = resolveHilosProfileSignInMethods(
    [
      {
        id: 2,
        type: 'passkey',
        provider: null,
        identifier: 'opaque',
        verified: true,
      },
    ],
    [],
  )
  const { node, setMethods } = setup(nameOnly(), true, true, methods)

  expect(node('profile-sign-in-passkey-only-line')?.textContent?.trim()).toBe(
    'Only passkeys can sign you in: access cannot be restored automatically',
  )
  setMethods(
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
  )
  expect(node('profile-sign-in-passkey-only-line')).toBeNull()
})
