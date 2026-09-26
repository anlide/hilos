import {
  ScopeManager,
  hilosNotificationPreferences,
  type HilosConnection,
} from '@hilos/core'
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import HilosProfileNotificationsPage from './HilosProfileNotificationsPage.vue'

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
      props: { context: { connection, scopes } },
      global: { stubs: { HilosPageHeading: true } },
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
})
