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

import HilosSecurityPageShell from './HilosSecurityPageShell.vue'
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

function harness(page: string = HilosPages.SECURITY_2FA, viewer = false) {
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
  const route = createSignal<PageRouteMatch>({ page, params: {}, admin: true })
  const navigation: HilosRouter = {
    currentRoute: route,
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
  }
  const view = mount(HilosSecurityPageShell, {
    props: { page },
    attachTo: document.body,
    slots: { default: '<div data-id="body">Page content</div>' },
    global: { provide: { [hilosRouterKey as symbol]: navigation } },
  })
  return { scopes, scope, navigation, route, view, answer }
}

describe('HilosSecurityPageShell', () => {
  it.each(tabs)(
    'uses the shared row and current key on $page',
    async ({ page, label }) => {
      const { view, answer, navigation } = harness(page)
      expect(view.find('[data-id="hilos-page-head-skeleton"]').exists()).toBe(
        true,
      )
      expect(view.find('p.small.mb-3').exists()).toBe(false)
      answer()
      await nextTick()

      expect(view.findAll('h1').map((heading) => heading.text())).toEqual([
        'Security Center',
      ])
      expect(
        view
          .get('i.bi-shield-lock')
          .element.closest('[aria-hidden]')
          ?.getAttribute('aria-hidden'),
      ).toBe('true')
      const crumbs = view.get('nav[aria-label="Breadcrumb"]')
      expect(crumbs.findAll('li').map((crumb) => crumb.text())).toEqual([
        'Hilos',
        'Security Center',
      ])
      expect(crumbs.get('a').attributes('href')).toBe('/hilos')
      const row = view.get('nav[aria-label="Security Center pages"]')
      expect(row.findAll('a').map((link) => link.text())).toEqual(
        tabs.map((tab) => tab.label),
      )
      expect(row.findAll('a').map((link) => link.attributes('href'))).toEqual(
        paths,
      )
      expect(
        row.findAll('[aria-current="page"]').map((link) => link.text()),
      ).toEqual([label])
      expect(row.get('ul').classes()).toEqual(
        expect.arrayContaining(['flex-nowrap', 'overflow-x-auto']),
      )
      for (const link of row.findAll('a')) {
        expect(link.classes()).toContain('text-nowrap')
        await link.trigger('click', { button: 0 })
        expect(navigation.navigate).toHaveBeenLastCalledWith(
          link.attributes('href'),
        )
      }
      expect(view.find('[data-id="hilos-admin-children"]').exists()).toBe(false)
      expect(view.findAll('table')).toHaveLength(0)
      const lead = view.find('p.small.mb-3')
      expect(lead.exists()).toBe(page !== HilosPages.SECURITY)
      if (lead.exists()) {
        expect(lead.text()).toBe('This tab policy.')
        const content = view.get('[data-id="hilos-admin-page"]').element
        expect(
          row.element.compareDocumentPosition(lead.element) &
            Node.DOCUMENT_POSITION_FOLLOWING,
        ).toBe(Node.DOCUMENT_POSITION_FOLLOWING)
        expect(Array.from(content.children).indexOf(lead.element)).toBeLessThan(
          Array.from(content.children).indexOf(
            view.get('[data-id="body"]').element,
          ),
        )
      }
    },
  )

  it('does not duplicate the legacy lead or suppress legacy child cards', async () => {
    const { view, scope, answer } = harness()
    answer()
    scope.data.set('pageHeadOwner', undefined)
    scope.data.set('pageChildren', [
      {
        page: HilosPages.SECURITY_STEP_UP,
        label: 'Operations',
        lead: 'Open operations.',
      },
    ])
    await nextTick()
    expect(view.findAll('h1').map((heading) => heading.text())).toEqual([
      'Two-factor',
    ])
    expect(
      view.findAll('p').filter((line) => line.text() === 'This tab policy.'),
    ).toHaveLength(1)
    expect(view.find('p.small.mb-3').exists()).toBe(false)
    expect(
      view.get('[data-id="hilos-admin-children"] a').attributes('href'),
    ).toBe('/hilos/security/step-up')
    expect(view.find('[data-id="hilos-page-tabs"]').exists()).toBe(false)
  })

  it('removes the tab lead for a foreign route, owner, or pending answer', async () => {
    const { view, scopes, scope, route, answer } = harness()
    answer()
    await nextTick()
    expect(view.get('p.small.mb-3').text()).toBe('This tab policy.')
    route.set({ page: HilosPages.SECURITY_OAUTH, params: {}, admin: true })
    await nextTick()
    expect(view.find('p.small.mb-3').exists()).toBe(false)
    route.set({ page: HilosPages.SECURITY_2FA, params: {}, admin: true })
    scope.data.set('pageHeadOwner', {
      page: HilosPages.I18N,
      label: 'Internationalization',
      lead: 'Foreign owner.',
      icon: 'bi-translate',
    })
    await nextTick()
    expect(view.find('p.small.mb-3').exists()).toBe(false)
    answer()
    await nextTick()
    scope.data.set('pageLead', 'Updated policy.')
    await nextTick()
    expect(view.get('p.small.mb-3').text()).toBe('Updated policy.')
    scopes.openPage(HilosPages.SECURITY_2FA)
    await nextTick()
    expect(view.find('p.small.mb-3').exists()).toBe(false)
    expect(view.find('[data-id="hilos-page-head-skeleton"]').exists()).toBe(
      true,
    )
  })
})
