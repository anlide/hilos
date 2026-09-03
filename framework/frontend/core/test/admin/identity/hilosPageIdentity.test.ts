import { describe, expect, it } from 'vitest'

import {
  dashboardSections,
  pageIdentity,
} from '../../../src/admin/identity/hilosPageIdentity.js'
import { ScopeManager } from '../../../src/state/ScopeManager.js'

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
