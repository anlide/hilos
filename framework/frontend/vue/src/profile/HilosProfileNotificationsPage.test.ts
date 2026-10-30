import {
  ActionLifecycle,
  createSignal,
  ScopeManager,
  hilosNotificationPreferences,
  type HilosConnection,
  type HilosRouter,
} from '@hilos/core/base'
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { hilosRouterKey } from '../hilosRouterKey.js'
import { profileSectionFixture } from '../../../core/test/profile/profileSectionFixture.js'
import HilosProfileNotificationsPage from './HilosProfileNotificationsPage.vue'

const actions = new ActionLifecycle({
  sendAction: () => true,
  on: () => () => {},
})

describe('profile notifications page', () => {
  it('renders channels from the subscription and releases them on unmount', async () => {
    const scopes = new ScopeManager()
    scopes
      .openPage('hilos_profile_notifications')
      .data.set('notificationPreferences', {
        channels: [
          {
            channel: 'email',
            label: 'Email',
            allowed: true,
            hasAddress: false,
          },
        ],
        mandatoryNote: true,
      })
    const connection = { on: () => () => {} } as unknown as HilosConnection
    const wrapper = mount(HilosProfileNotificationsPage, {
      props: { context: { connection, scopes, actions } },
      global: {
        provide: {
          [hilosRouterKey as symbol]: {
            ...profileSectionFixture(scopes, 'hilos_profile_notifications'),
            resolvePath: () => undefined,
          },
        },
      },
    })
    await flushPromises()
    expect(wrapper.get('h2').text()).toBe('Delivery channels')
    expect(
      wrapper
        .get('[data-id="hilos-notification-preference-toggle-email"]')
        .attributes('disabled'),
    ).toBeDefined()
    expect(wrapper.text()).toContain('Security messages are always delivered.')
    wrapper.unmount()
    expect(hilosNotificationPreferences.channels.get()).toEqual([])
  })

  it('links the no-address hint to the section the answer names, through the router', async () => {
    const scopes = new ScopeManager()
    const page = scopes.openPage('hilos_profile_notifications')
    page.data.set('notificationPreferences', {
      channels: [
        { channel: 'email', label: 'Email', allowed: true, hasAddress: false },
      ],
      mandatoryNote: false,
    })
    page.data.set('addressSection', {
      page: 'hilos_profile_sign_in',
      label: 'Ways to sign in',
      lead: '',
    })
    const connection = { on: () => () => {} } as unknown as HilosConnection
    const router = {
      ...profileSectionFixture(scopes, 'hilos_profile_notifications'),
      currentPath: createSignal('/profile/notifications'),
      resolvePath: (key: string) =>
        key === 'hilos_profile_sign_in' ? '/profile/sign-in' : undefined,
      navigate: () => {},
    } as unknown as HilosRouter
    const linked = mount(HilosProfileNotificationsPage, {
      props: { context: { connection, scopes, actions } },
      global: {
        provide: { [hilosRouterKey as symbol]: router },
      },
    })
    await flushPromises()
    expect(
      linked
        .get('[data-id="hilos-notification-preference-address-email"]')
        .attributes('href'),
    ).toBe('/profile/sign-in')
    linked.unmount()

    const plain = mount(HilosProfileNotificationsPage, {
      props: { context: { connection, scopes, actions } },
      global: {
        provide: {
          [hilosRouterKey as symbol]: {
            ...profileSectionFixture(scopes, 'hilos_profile_notifications'),
            resolvePath: () => undefined,
          },
        },
      },
    })
    await flushPromises()
    expect(
      plain
        .find('[data-id="hilos-notification-preference-address-email"]')
        .exists(),
    ).toBe(false)
    expect(
      plain.get('[data-id="hilos-notification-preference-hint-email"]').text(),
    ).toBe('Add an address in your profile to enable this channel.')
    plain.unmount()
  })
})
