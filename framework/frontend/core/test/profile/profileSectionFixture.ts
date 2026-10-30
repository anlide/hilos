import { pageIdentity } from '../../src/admin/identity/hilosPageIdentity.js'
import { createAppPageRouter } from '../../src/routing/appPageRouter.js'
import { type HilosRouter } from '../../src/routing/HilosRouter.js'
import { type ScopeManager } from '../../src/state/ScopeManager.js'
import { createSignal } from '../../src/state/signal.js'

/** Seed the common profile answer around a section's existing body fixture. */
export function profileSectionFixture(
  scopes: ScopeManager,
  key: string,
): HilosRouter {
  const page = scopes.page() ?? scopes.openPage(key)
  page.data.set('pageLabel', 'Profile section')
  page.data.set('pageHeadOwner', {
    page: 'hilos_profile',
    label: 'Profile',
    lead: '',
  })
  page.data.set('pageBreadcrumb', [{ page: 'hilos_profile', label: 'Profile' }])
  page.data.set('pageChildren', [])
  page.data.set('pageTabs', [
    { page: 'hilos_profile', label: 'Overview' },
    { page: key, label: 'Current section' },
  ])
  page.data.set('profilePhoto', false)
  if (page.data.signal('secondFactor').get() === undefined)
    page.data.set('secondFactor', {
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
    })
  if (scopes.pageListSignal('profileIdentities').get().length === 0)
    page.lists.upsert('profileIdentities', 1, {
      identities: [],
      passkeyCredentials: [],
    })
  if (scopes.session.data.signal('currentUser').get() === undefined) {
    scopes.session.data.set('currentUser', { type: 'fixtureUsers', id: 1 })
    scopes.session.entities.upsert(
      { type: 'fixtureUsers', id: 1 },
      { id: 1, name: 'Ann Lee' },
    )
  }
  const routes = createAppPageRouter({})
  return {
    pageIdentity: pageIdentity(scopes),
    currentRoute: createSignal({ page: key, params: {}, admin: false }),
    currentPath: createSignal(routes.path(key) ?? '/profile'),
    resolvePath: (page: string, params?: Record<string, string>) =>
      routes.path(page, params),
    navigate: () => {},
  } as unknown as HilosRouter
}
