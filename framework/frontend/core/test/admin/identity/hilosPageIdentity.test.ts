import { describe, expect, it } from 'vitest'

import {
  dashboardSections,
  pageIdentity,
} from '../../../src/admin/identity/hilosPageIdentity.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'
import { bindPageInstanceName } from '../../../src/admin/identity/hilosPageInstanceName.js'
import { computedSignal, createSignal } from '../../../src/state/signal.js'

describe('pageIdentity', () => {
  it('reads nothing before a page is subscribed', () => {
    expect(pageIdentity(new ScopeManager()).get()).toBeUndefined()
  })

  it('reads nothing while the answer is still on the wire', () => {
    // The page scope opens on the subscribe, empty; that emptiness is what the
    // shell draws its skeleton for.
    const scopes = new ScopeManager()
    scopes.openPage('hilos_logs')

    expect(pageIdentity(scopes).get()).toBeUndefined()
  })

  it('reads the identity the page answered with', () => {
    const scopes = new ScopeManager()
    const scope = scopes.openPage('hilos_logs')
    const identity = pageIdentity(scopes)

    scope.data.set('pageLabel', 'Logs')
    scope.data.set('pageLead', 'Rotation stats, log keys, workers.')
    scope.data.set('pageBreadcrumb', [
      { page: 'hilos', label: 'Hilos' },
      { page: 'hilos_logs', label: 'Logs' },
    ])
    scope.data.set('pageChildren', [
      {
        page: 'hilos_logs_keys',
        label: 'By key',
        lead: 'Log volume grouped by log key.',
      },
    ])

    expect(identity.get()).toEqual({
      label: 'Logs',
      lead: 'Rotation stats, log keys, workers.',
      breadcrumb: [
        { page: 'hilos', label: 'Hilos' },
        { page: 'hilos_logs', label: 'Logs' },
      ],
      children: [
        {
          page: 'hilos_logs_keys',
          label: 'By key',
          lead: 'Log volume grouped by log key.',
          icon: null,
        },
      ],
    })
  })

  it('reads a leaf as an identity with no children', () => {
    const scopes = new ScopeManager()
    const scope = scopes.openPage('hilos_logs_keys')
    const identity = pageIdentity(scopes)
    scope.data.set('pageLabel', 'By key')
    scope.data.set('pageLead', 'Log volume grouped by log key.')
    scope.data.set('pageBreadcrumb', [])
    scope.data.set('pageChildren', [])

    expect(identity.get()?.children).toEqual([])
  })

  it('keeps the card icon the catalog gave it', () => {
    const scopes = new ScopeManager()
    const scope = scopes.openPage('hilos')
    const identity = pageIdentity(scopes)
    scope.data.set('pageLabel', 'Hilos')
    scope.data.set('pageChildren', [
      {
        page: 'hilos_users',
        label: 'Users',
        lead: 'Who is who.',
        icon: 'bi-people',
      },
    ])

    expect(identity.get()?.children[0].icon).toBe('bi-people')
  })

  it('switches to the new page on navigation, dropping the old name', () => {
    // A page scope is opened per subscription, so the previous screen's name
    // cannot outlive the screen — which is the whole reason there is no store.
    const scopes = new ScopeManager()
    const identity = pageIdentity(scopes)
    scopes.openPage('hilos_logs').data.set('pageLabel', 'Logs')

    expect(identity.get()?.label).toBe('Logs')

    const next = scopes.openPage('hilos_users')

    expect(identity.get()).toBeUndefined()

    next.data.set('pageLabel', 'Users')

    expect(identity.get()?.label).toBe('Users')
  })

  it('reads an answer without a catalog entry as no identity at all', () => {
    // The public footer pages and a project page outside the admin tree answer
    // with none of the identity keys; printing the raw page key instead would
    // leak an internal name onto the screen.
    const scopes = new ScopeManager()
    scopes.openPage('hilos_profile').data.set('greeting', 'hi')

    expect(pageIdentity(scopes).get()).toBeUndefined()
  })

  it('projects the owner without changing the subscribing tab or child cards', () => {
    const scopes = new ScopeManager()
    const scope = scopes.openPage('names')
    scope.data.set('pageLabel', 'Names')
    scope.data.set('pageLead', 'The subscribing tab lead')
    scope.data.set('pageHeadOwner', {
      page: 'country',
      label: 'Country',
      lead: 'Owner lead',
      icon: 'bi-flag',
    })
    scope.data.set('pageChildren', [
      { page: 'country', label: 'Country', lead: 'Child lead' },
    ])
    bindPageInstanceName(scopes, 'country', createSignal('Ireland'))
    expect(pageIdentity(scopes).get()).toEqual({
      label: 'Names',
      lead: 'The subscribing tab lead',
      breadcrumb: [],
      children: [
        { page: 'country', label: 'Country', lead: 'Child lead', icon: null },
      ],
      pageHeadOwner: {
        page: 'country',
        label: 'Ireland',
        lead: 'Owner lead',
        icon: 'bi-flag',
      },
    })
  })

  it.each([
    undefined,
    null,
    'Country',
    [],
    {},
    { page: 1, label: 'Country', lead: '' },
    { page: 'country', label: null, lead: '' },
    { page: 'country', label: 'Country' },
    { page: 'country', label: 'Country', lead: 1 },
  ])('omits a missing or malformed owner entirely: %j', (owner) => {
    const scopes = new ScopeManager()
    const scope = scopes.openPage('legacy')
    scope.data.set('pageLabel', 'Legacy')
    scope.data.set('pageHeadOwner', owner)
    expect(pageIdentity(scopes).get()).toEqual({
      label: 'Legacy',
      lead: '',
      breadcrumb: [],
      children: [],
    })
  })

  it('reads an absent owner icon as null', () => {
    const scopes = new ScopeManager()
    const scope = scopes.openPage('names')
    scope.data.set('pageLabel', 'Names')
    scope.data.set('pageHeadOwner', {
      page: 'country',
      label: 'Country',
      lead: '',
    })
    expect(pageIdentity(scopes).get()?.pageHeadOwner).toEqual({
      page: 'country',
      label: 'Country',
      lead: '',
      icon: null,
    })
  })

  it('uses only the permitted page name for a viewer, with no session fallback', () => {
    const scopes = new ScopeManager()
    scopes.session.data.set('instanceName', 'Hidden real name')
    scopes.user.data.set('instanceName', 'Another hidden name')
    const scope = scopes.openPage('entity_names')
    scope.data.set('pageLabel', 'Names')
    scope.data.set('pageBreadcrumb', [{ page: 'entity', label: 'Entity' }])
    scope.data.set('pageHeadOwner', {
      page: 'entity',
      label: 'Entity',
      lead: '',
    })
    scope.data.set('instanceName', 'Permitted alias')
    const name = scopes.pageDataSignal('instanceName')
    bindPageInstanceName(
      scopes,
      'entity',
      computedSignal(() => {
        const value = name.get()
        return typeof value === 'string' ? value : undefined
      }),
    )
    const identity = pageIdentity(scopes)
    expect(identity.get()?.breadcrumb[0].label).toBe('Permitted alias')
    expect(identity.get()?.pageHeadOwner?.label).toBe('Permitted alias')
    scope.data.set('instanceName', undefined)
    expect(identity.get()?.breadcrumb[0].label).toBe('Entity')
    expect(identity.get()?.pageHeadOwner?.label).toBe('Entity')
    scopes.dropPage()
    expect(identity.get()).toBeUndefined()
  })

  it('names only the bound entity key, including an intermediate repeated caption', () => {
    const scopes = new ScopeManager()
    const scope = scopes.openPage('child_names')
    scope.data.set('pageLabel', 'Names')
    scope.data.set('pageBreadcrumb', [
      { page: 'root', label: 'Root' },
      { page: 'parent', label: 'Entity' },
      { page: 'child', label: 'Entity' },
    ])
    scope.data.set('pageHeadOwner', {
      page: 'child',
      label: 'Entity',
      lead: '',
    })
    const identity = pageIdentity(scopes)
    expect(identity.get()?.breadcrumb.map((crumb) => crumb.label)).toEqual([
      'Root',
      'Entity',
      'Entity',
    ])
    const parent = createSignal<string | undefined>('Parent instance')
    bindPageInstanceName(scopes, 'parent', parent)
    expect(identity.get()?.breadcrumb).toEqual([
      { page: 'root', label: 'Root' },
      { page: 'parent', label: 'Parent instance' },
      { page: 'child', label: 'Entity' },
    ])
    expect(identity.get()?.pageHeadOwner?.label).toBe('Entity')
    const child = createSignal<string | undefined>('Child instance')
    bindPageInstanceName(scopes, 'child', child)
    child.set('Renamed')
    expect(identity.get()?.breadcrumb[2].label).toBe('Renamed')
    expect(identity.get()?.pageHeadOwner?.label).toBe('Renamed')
    parent.set('')
    child.set(undefined)
    expect(identity.get()?.breadcrumb.map((crumb) => crumb.label)).toEqual([
      'Root',
      '',
      'Entity',
    ])
    expect(identity.get()?.pageHeadOwner?.label).toBe('Entity')
    bindPageInstanceName(scopes, 'absent', createSignal('No synthetic crumb'))
    expect(identity.get()?.breadcrumb).toHaveLength(3)
  })

  it('reads the new scope after reset and drops the old name on an error', () => {
    const scopes = new ScopeManager()
    const identity = pageIdentity(scopes)
    const name = scopes.pageDataSignal('name')
    bindPageInstanceName(
      scopes,
      'country',
      computedSignal(() => {
        const value = name.get()
        return typeof value === 'string' ? value : undefined
      }),
    )
    const answer = (name: string) => {
      const scope = scopes.openPage('names')
      scope.data.set('pageLabel', 'Names')
      scope.data.set('name', name)
      scope.data.set('pageHeadOwner', {
        page: 'country',
        label: 'Country',
        lead: '',
      })
      scope.data.set('pageBreadcrumb', [{ page: 'country', label: 'Country' }])
      return scope
    }
    const first = answer('First')
    expect(identity.get()?.pageHeadOwner?.label).toBe('First')
    scopes.dropPage()
    expect(identity.get()).toBeUndefined()
    answer('Second')
    expect(identity.get()?.breadcrumb[0].label).toBe('Second')
    first.data.set('name', 'Old answer')
    expect(identity.get()?.pageHeadOwner?.label).toBe('Second')
    scopes.openPage('names')
    expect(identity.get()).toBeUndefined()
    scopes.openPage('unrelated').data.set('pageLabel', 'Other page')
    expect(identity.get()).toEqual({
      label: 'Other page',
      lead: '',
      breadcrumb: [],
      children: [],
    })
  })
})

describe('dashboardSections', () => {
  it('reads nothing on a page that is not the dashboard', () => {
    const scopes = new ScopeManager()
    scopes.openPage('hilos_logs').data.set('pageLabel', 'Logs')

    expect(dashboardSections(scopes).get()).toBeUndefined()
  })

  it('reads the groups and their cards in the order they arrived', () => {
    const scopes = new ScopeManager()
    const scope = scopes.openPage('hilos')
    const sections = dashboardSections(scopes)

    scope.data.set('dashboardSections', [
      {
        title: 'Access & identity',
        description: 'Users and the roles that grant them access.',
        items: [
          {
            page: 'hilos_users',
            label: 'Users',
            lead: 'Who is who.',
            icon: 'bi-people',
          },
        ],
      },
      {
        title: 'Chat administration',
        description: 'What this product adds to the panel.',
        items: [
          {
            page: 'admin_users',
            label: 'Users',
            lead: 'Chat users.',
            icon: 'bi-people',
          },
        ],
      },
    ])

    expect(sections.get()?.map((section) => section.title)).toEqual([
      'Access & identity',
      'Chat administration',
    ])
    expect(sections.get()?.[1].items[0].page).toBe('admin_users')
  })
})
