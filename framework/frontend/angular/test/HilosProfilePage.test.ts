// Covers the Angular profile root (HIL-1169): Name with Change only for the
// project's rename, Email only with a verified address, and a row per catalog
// section in its order with the summaries it knows.
import { TestBed } from '@angular/core/testing'
import {
  createSignal,
  HilosPages,
  resolveHilosProfileSignInMethods,
  ScopeManager,
  type HilosProfileBinding,
  type HilosProfilePageContext,
  type HilosRouter,
} from '@hilos/core'
import { expect, it, vi } from 'vitest'
import { HilosProfilePage } from '../src/profile/HilosProfilePage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

/** A router on the profile root whose sections all have an address. */
function router(): HilosRouter {
  return {
    pageIdentity: createSignal({
      label: 'Profile',
      lead: '',
      breadcrumb: [{ page: HilosPages.PROFILE, label: 'Profile' }],
      children: [
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

function setup(binding: HilosProfileBinding, signedIn = true) {
  const scopes = new ScopeManager()
  if (signedIn) scopes.session.data.set('currentUser', { type: 'user', id: 1 })
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
    providers: [{ provide: HILOS_ROUTER, useValue: router() }],
  })
  const fixture = TestBed.createComponent(HilosProfilePage)
  fixture.componentRef.setInput('context', context)
  fixture.componentRef.setInput('binding', binding)
  fixture.detectChanges()
  const root = fixture.nativeElement as HTMLElement
  const node = (id: string) =>
    root.querySelector<HTMLElement>(`[data-id="${id}"]`)
  return { node, root }
}

function nameOnly(): HilosProfileBinding {
  return {
    name: createSignal('Ann Lee'),
    rename: null,
    signInMethods: null,
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
  const { node } = setup({
    ...nameOnly(),
    rename: {
      send: () => false,
      refusal: createSignal(null),
      clearRefusal: () => {},
      stepUpOperation: 'change_name',
      minLength: 2,
      maxLength: 64,
    },
    signInMethods: createSignal(
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
    ),
  })

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
