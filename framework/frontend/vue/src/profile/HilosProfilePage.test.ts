// Covers the profile root's view (HIL-1169): Name with Change only when the
// project handed its rename, Email only with a verified address, a row per
// catalog section in the catalog's order with its summary, and the placeholder
// while the name is not known yet.
import {
  createSignal,
  HILOS_PROFILE_IDENTITIES_LIST,
  HilosPages,
  resolveHilosProfileSignInMethods,
  ScopeManager,
  type ActionLifecycle,
  type HilosConnection,
  type HilosPageIdentity,
  type HilosProfileBinding,
  type HilosProfileRename,
  type HilosProfileSignInMethod,
  type HilosRouter,
  type PageRouteMatch,
} from '@hilos/core'
import { enableAutoUnmount, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'
import { nextTick } from 'vue'

import { hilosRouterKey } from '../hilosRouterKey.js'
import HilosProfilePage from './HilosProfilePage.vue'

afterEach(() => {
  document.body.innerHTML = ''
})
enableAutoUnmount(afterEach)

/** The root's identity: the profile's sections in catalog order. */
const IDENTITY: HilosPageIdentity = {
  label: 'Profile',
  lead: '',
  breadcrumb: [{ page: HilosPages.PROFILE, label: 'Profile' }],
  children: [
    {
      page: HilosPages.PROFILE_SIGN_IN,
      label: 'Ways to sign in',
      lead: '',
      icon: null,
    },
    {
      page: HilosPages.PROFILE_SESSIONS,
      label: 'Sessions',
      lead: '',
      icon: null,
    },
    {
      page: HilosPages.PROFILE_SECURITY,
      label: 'Security',
      lead: '',
      icon: null,
    },
  ],
}

/** A router on the profile root whose every section has an address. */
function router(): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.PROFILE,
      params: {},
      admin: false,
    }),
    currentPath: createSignal('/profile'),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal(IDENTITY),
    dashboardSections: createSignal(undefined),
    resolvePath: (page) => `/${page}`,
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
}

/** A rename that sends nothing; the page only needs to know it is there. */
const RENAME: HilosProfileRename = {
  send: () => false,
  refusal: createSignal(null),
  clearRefusal: () => {},
  stepUpOperation: 'change_name',
  minLength: 2,
  maxLength: 64,
}

/** A binding with the name only, as polls and tasks hand it. */
function nameOnly(name = 'Ann Lee'): HilosProfileBinding {
  return {
    name: createSignal(name),
    rename: null,
    sessionCount: null,
    deviceCount: null,
  }
}

/** Scopes whose session holds a signed-in person. */
function signedInScopes(): ScopeManager {
  const scopes = new ScopeManager()
  scopes.session.data.set('currentUser', { type: 'user', id: 1 })
  return scopes
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

function mountPage(
  binding: HilosProfileBinding,
  scopes: ScopeManager = signedInScopes(),
) {
  return mount(HilosProfilePage, {
    attachTo: document.body,
    global: {
      provide: { [hilosRouterKey as symbol]: router() },
      stubs: { HilosPageHeading: true, HilosAccountDeletion: true },
    },
    props: {
      context: {
        connection: { on: () => () => {} } as unknown as HilosConnection,
        scopes,
        actions: {} as ActionLifecycle,
      },
      binding,
    },
  })
}

describe('HilosProfilePage', () => {
  it('shows the name without Change and no Email row when the project hands neither', () => {
    const wrapper = mountPage(nameOnly())

    expect(wrapper.find('[data-id="profile-identity-name"]').text()).toBe(
      'Ann Lee',
    )
    expect(wrapper.find('[data-id="profile-name"]').text()).toBe('Ann Lee')
    expect(wrapper.find('[data-id="profile-edit"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="profile-email"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="profile-identity-email"]').exists()).toBe(
      false,
    )
  })

  it("offers Change for the project's rename and the Email row for a verified address", () => {
    const scopes = signedInScopes()
    setMethods(
      scopes,
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
    const wrapper = mountPage({ ...nameOnly(), rename: RENAME }, scopes)

    expect(wrapper.find('[data-id="profile-edit"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="profile-email"]').text()).toBe(
      'ann@example.test · verified',
    )
    expect(wrapper.find('[data-id="profile-identity-email"]').text()).toBe(
      'ann@example.test',
    )
    expect(wrapper.find('[data-id="profile-email-change"]').exists()).toBe(true)
  })

  it('draws a row per catalog section in its order with the summaries it knows', () => {
    const wrapper = mountPage({
      ...nameOnly(),
      sessionCount: createSignal(2),
    })

    const rows = wrapper.findAll('[data-id="profile-section"]')
    expect(rows.map((row) => row.text())).toEqual([
      expect.stringContaining('Ways to sign in'),
      expect.stringContaining('Sessions'),
      expect.stringContaining('Security'),
    ])
    expect(wrapper.find('[data-id="profile-sign-in-summary"]').text()).toBe(
      'No ways to sign in',
    )
    expect(wrapper.find('[data-id="profile-sessions-summary"]').text()).toBe(
      '2 active sign-ins',
    )
    expect(wrapper.find('[data-id="profile-security-summary"]').text()).toBe(
      'Two-step verification is off',
    )
    expect(
      wrapper.find('[data-id="profile-security-open"]').attributes('href'),
    ).toBe(`/${HilosPages.PROFILE_SECURITY}`)
  })

  it('draws only the placeholder while nobody is signed in', () => {
    const wrapper = mountPage(nameOnly(), new ScopeManager())

    expect(wrapper.find('[data-id="profile-loading"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="profile-view"]').exists()).toBe(false)
  })

  it('holds a placeholder until the name is known', () => {
    const wrapper = mountPage(nameOnly(''))

    expect(wrapper.find('[data-id="profile-loading"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="profile-identity"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="profile-detail"]').exists()).toBe(false)
  })

  it('warns under the sign-in row while device keys are the only way in, live', async () => {
    const methods = createSignal(
      resolveHilosProfileSignInMethods(
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
      ),
    )
    const scopes = signedInScopes()
    setMethods(scopes, methods.get())
    const wrapper = mountPage(nameOnly(), scopes)

    expect(
      wrapper.find('[data-id="profile-sign-in-passkey-only-line"]').text(),
    ).toBe(
      'Only passkeys can sign you in: access cannot be restored automatically',
    )
    setMethods(
      scopes,
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
    await nextTick()
    expect(
      wrapper.find('[data-id="profile-sign-in-passkey-only-line"]').exists(),
    ).toBe(false)
  })
})
