import { enableAutoUnmount, mount } from '@vue/test-utils'
import {
  bindAdminAccess,
  createAppPageRouter,
  createSignal,
  HilosPages,
  pageIdentity,
  ScopeManager,
  type HilosRouter,
  type PageRouteMatch,
} from '@hilos/core/base'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'

import {
  SECURITY_OVERVIEW_DATA,
  type HilosSecurityOverview,
} from '@hilos/core/admin/security'
import HilosSecurityPage from './HilosSecurityPage.vue'

const overview: HilosSecurityOverview = {
  twoFactor: { enabled: true, required: 'admins' },
  stepUp: { enabledCount: 3, totalCount: 7 },
  oauth: {
    configuredCount: 1,
    totalCount: 3,
    unconfigured: [
      { providerKey: 'oauth:google', label: 'Google', missingFields: 2 },
      {
        providerKey: 'oauth:custom',
        label: 'Custom provider',
        missingFields: 1,
      },
    ],
  },
  signInMethods: {
    enabledCount: 4,
    totalCount: 6,
    unready: [{ methodKey: 'sms', label: 'SMS code' }],
  },
  impersonation: { allowed: true, scope: 'act' },
}

import { hilosRouterKey } from '../../hilosRouterKey.js'

enableAutoUnmount(afterEach)
const releases: Array<() => void> = []
afterEach(() => {
  for (const release of releases.splice(0)) release()
})

const tabs = [
  { page: HilosPages.SECURITY, label: 'Overview' },
  { page: HilosPages.SECURITY_2FA, label: 'Two-factor' },
  {
    page: HilosPages.SECURITY_STEP_UP,
    label: 'Operations that ask for confirmation',
  },
  { page: HilosPages.SECURITY_OAUTH, label: 'OAuth providers' },
  { page: HilosPages.SECURITY_SIGN_IN_METHODS, label: 'Sign-in methods' },
  { page: HilosPages.SECURITY_IMPERSONATION, label: 'Impersonation' },
]
const paths = [
  '/hilos/security',
  '/hilos/security/2fa',
  '/hilos/security/step-up',
  '/hilos/security/oauth',
  '/hilos/security/sign-in-methods',
  '/hilos/security/impersonation',
]
const sectionLead =
  'Sign-in methods, two-factor authentication and OAuth login providers.'

function harness(viewer = false) {
  const page = HilosPages.SECURITY
  const scopes = new ScopeManager()
  const scope = scopes.openPage(page)
  scopes.session.data.set('adminViewMode', viewer)
  if (!viewer) {
    const user = { type: 'users', id: '1' }
    scopes.session.entities.upsert(user, { admin: true })
    scopes.session.data.set('currentUser', user)
  }
  releases.push(bindAdminAccess(scopes))
  const routes = createAppPageRouter({})
  const navigation: HilosRouter = {
    currentRoute: createSignal<PageRouteMatch>({
      page,
      params: {},
      admin: true,
    }),
    currentPath: createSignal(routes.path(page, {}) ?? '/hilos/security'),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: pageIdentity(scopes),
    dashboardSections: createSignal(undefined),
    resolvePath: (key, params) => routes.path(key, params),
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    onLeave: () => () => {},
    navigate: vi.fn(),
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
  const answer = (): void => {
    scope.data.set(
      'pageLabel',
      page === HilosPages.SECURITY ? 'Security Center' : 'Two-factor',
    )
    scope.data.set(
      'pageLead',
      page === HilosPages.SECURITY ? sectionLead : 'This tab policy.',
    )
    scope.data.set('pageHeadOwner', {
      page: HilosPages.SECURITY,
      label: 'Security Center',
      lead: sectionLead,
      icon: 'bi-shield-lock',
    })
    scope.data.set('pageBreadcrumb', [
      { page: HilosPages.DASHBOARD, label: 'Hilos' },
      { page: HilosPages.SECURITY, label: 'Security Center' },
    ])
    scope.data.set('pageTabs', tabs)
    scope.data.set('pageChildren', [])
    scope.data.set(SECURITY_OVERVIEW_DATA, overview)
  }
  const view = mount(HilosSecurityPage, {
    props: { context: { scopes } },
    attachTo: document.body,
    global: { provide: { [hilosRouterKey as symbol]: navigation } },
  })
  return { scopes, scope, navigation, view, answer }
}

describe('HilosSecurityPage', () => {
  it.each([false, true])(
    'draws five read-only tiles under the real head (viewer=%s)',
    async (viewer) => {
      const { view, answer } = harness(viewer)
      answer()
      await nextTick()
      expect(view.findAll('h1').map((heading) => heading.text())).toEqual([
        'Security Center',
      ])
      expect(view.get('[data-id="hilos-page-head-block"] p').text()).toBe(
        sectionLead,
      )
      const row = view.get('nav[aria-label="Security Center pages"]')
      expect(row.findAll('a').map((link) => link.attributes('href'))).toEqual(
        paths,
      )
      expect(row.get('[aria-current="page"]').text()).toBe('Overview')
      expect(view.find('p.small.mb-3').exists()).toBe(false)
      expect(view.find('[data-id="hilos-admin-children"]').exists()).toBe(false)
      expect(view.find('[data-id="hilos-admin-empty"]').exists()).toBe(false)
      const body = view.get('[data-id="security-overview"]')
      expect(body.get('.row').classes()).toEqual([
        'row',
        'row-cols-1',
        'row-cols-sm-2',
        'row-cols-lg-3',
        'g-3',
        'mb-2',
      ])
      expect(body.findAll('section.border.rounded-3.p-3.h-100')).toHaveLength(5)
      expect(body.findAll('h2').map((heading) => heading.text())).toEqual([
        'Two-factor',
        'Operations that ask for confirmation',
        'OAuth providers',
        'Sign-in methods',
        'Impersonation',
      ])
      expect(body.findAll('a,button,input,table')).toHaveLength(0)
      expect(body.findAll('.fs-4').map((value) => value.text())).toEqual([
        'Administrators',
        '3 of 7',
        '1 of 3',
        '4 of 6',
        'Allowed',
      ])
      expect(
        body.get('[data-id="security-overview-impersonation"]').text(),
      ).toContain('View and act')
      const warnings = body.findAll('.text-warning-emphasis')
      expect(warnings.map((warning) => warning.text())).toEqual([
        'Google: 2 required field(s) not set',
        'Custom provider: 1 required field(s) not set',
        'SMS code has nothing to send with',
      ])
      for (const warning of warnings)
        expect(
          warning.get('i.bi-exclamation-triangle').attributes('aria-hidden'),
        ).toBe('true')
      for (const region of body.findAll('[role="region"]')) {
        expect(region.classes()).toEqual(
          expect.arrayContaining(['overflow-x-scroll', 'text-nowrap']),
        )
        expect(region.attributes('tabindex')).toBe('0')
      }
    },
  )

  it('waits for a complete valid first answer and drops data from a page left behind', async () => {
    const { view, scope, scopes, answer } = harness()
    expect(view.find('[data-id="hilos-page-head-skeleton"]').exists()).toBe(
      true,
    )
    const body = view.get('[data-id="security-overview"]')
    expect(body.find('[data-id="hilos-skeleton"]').exists()).toBe(true)
    expect(body.text()).not.toContain('0 of 0')
    answer()
    await nextTick()
    expect(body.findAll('section')).toHaveLength(5)
    scope.data.set(SECURITY_OVERVIEW_DATA, { twoFactor: overview.twoFactor })
    await nextTick()
    expect(body.find('[data-id="hilos-skeleton"]').exists()).toBe(true)
    expect(body.text()).not.toContain('3 of 7')
    answer()
    await nextTick()
    scopes.openPage(HilosPages.SECURITY_OAUTH)
    await nextTick()
    expect(body.find('[data-id="hilos-skeleton"]').exists()).toBe(true)
    expect(body.findAll('section')).toHaveLength(0)
  })

  it('updates all five summaries live, including real zeroes and neutral disabled two-factor', async () => {
    const { view, scope, answer } = harness()
    answer()
    await nextTick()
    scope.data.set(SECURITY_OVERVIEW_DATA, {
      twoFactor: { enabled: false, required: 'everyone' },
      stepUp: { enabledCount: 0, totalCount: 0 },
      oauth: { configuredCount: 0, totalCount: 0, unconfigured: [] },
      signInMethods: { enabledCount: 0, totalCount: 0, unready: [] },
      impersonation: { allowed: false, scope: 'view' },
    })
    await nextTick()
    const body = view.get('[data-id="security-overview"]')
    expect(body.findAll('.fs-4').map((value) => value.text())).toEqual([
      'Disabled',
      '0 of 0',
      '0 of 0',
      '0 of 0',
      'Disabled',
    ])
    expect(body.findAll('.text-warning-emphasis')).toHaveLength(0)
    expect(body.findAll('[aria-hidden="true"].invisible')).toHaveLength(2)
    const factor = body.get('[data-id="security-overview-two-factor"]')
    expect(factor.text()).toContain(
      "No code is requested at sign-in; people's apps and codes are preserved.",
    )
    expect(factor.text()).not.toContain('Everyone')
    expect(factor.find('i').exists()).toBe(false)
    expect(
      body.get('[data-id="security-overview-impersonation"]').text(),
    ).not.toContain('View only')
    scope.data.set(SECURITY_OVERVIEW_DATA, {
      ...overview,
      twoFactor: { enabled: true, required: 'none' },
      impersonation: { allowed: true, scope: 'view' },
    })
    await nextTick()
    expect(factor.get('.fs-4').text()).toBe('Nobody')
    expect(
      body.get('[data-id="security-overview-impersonation"]').text(),
    ).toContain('View only')
  })

  it('keeps hidden policies, tallies and readiness out of text and attributes', async () => {
    const { view, scope, answer } = harness(true)
    answer()
    const hidden = { _hidden: true }
    scope.data.set(SECURITY_OVERVIEW_DATA, {
      ...overview,
      twoFactor: { enabled: hidden, required: hidden },
      stepUp: { enabledCount: hidden, totalCount: 7 },
      signInMethods: { enabledCount: hidden, totalCount: 6, unready: hidden },
      impersonation: { allowed: hidden, scope: hidden },
    })
    await nextTick()
    const body = view.get('[data-id="security-overview"]')
    expect(body.findAll('[data-id="hilos-hidden"]')).toHaveLength(5)
    expect(body.get('[data-id="security-overview-step-up"]').text()).toContain(
      'Hidden of 7',
    )
    expect(
      body
        .get('[data-id="security-overview-sign-in-methods"]')
        .find('[role="region"]')
        .exists(),
    ).toBe(false)
    for (const word of [
      'Disabled',
      'Allowed',
      'Nobody',
      'Administrators',
      'Everyone',
      'View only',
      'View and act',
      'SMS code',
      'nothing to send with',
      '3 of 7',
      '4 of 6',
    ]) {
      expect(body.html()).not.toContain(word)
    }
    // Public provider readiness is still available to the viewer.
    expect(body.get('[data-id="security-overview-oauth"]').text()).toContain(
      'Google: 2 required field(s) not set',
    )
  })

  it('never describes a permitted switch using its separately hidden policy', async () => {
    const { view, scope, answer } = harness(true)
    answer()
    scope.data.set(SECURITY_OVERVIEW_DATA, {
      ...overview,
      twoFactor: { enabled: true, required: { _hidden: true } },
      impersonation: { allowed: true, scope: { _hidden: true } },
    })
    await nextTick()
    const factor = view.get('[data-id="security-overview-two-factor"]')
    expect(factor.get('[data-id="hilos-hidden"]').text()).toBe('Hidden')
    expect(factor.html()).not.toMatch(
      /Nobody|Required;|Disabled|Administrators/,
    )
    const impersonation = view.get(
      '[data-id="security-overview-impersonation"]',
    )
    expect(impersonation.text()).toContain('Allowed')
    expect(impersonation.get('[data-id="hilos-hidden"]').text()).toBe('Hidden')
    expect(impersonation.html()).not.toMatch(/View only|View and act/)
  })
})
