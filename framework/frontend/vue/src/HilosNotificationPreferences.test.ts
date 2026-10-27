import {
  createHilosNotificationPreferencesStore,
  NOTIFICATION_ACTION_CHANNEL_SET,
  type HilosConnection,
} from '@hilos/core/base'
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

import HilosNotificationPreferences from './HilosNotificationPreferences.vue'

// A minimal connection stub: the section only ever calls sendAction on it.
function fakeConnection(sendResult = true): {
  connection: HilosConnection
  sendAction: ReturnType<typeof vi.fn>
} {
  const sendAction = vi.fn().mockReturnValue(sendResult)
  return {
    connection: { sendAction } as unknown as HilosConnection,
    sendAction,
  }
}

function storeWith(mandatoryNote = false) {
  const store = createHilosNotificationPreferencesStore()
  store.applySection({
    channels: [
      {
        channel: 'email',
        label: 'Email',
        allowed: true,
        hasAddress: true,
        address: 'you@example.com',
        icon: 'bi-envelope',
      },
      { channel: 'sms', label: 'SMS', allowed: false, hasAddress: false },
    ],
    mandatoryNote,
  })
  return store
}

describe('HilosNotificationPreferences', () => {
  it('renders metadata in the middle of the row and names the switch on the right', () => {
    const { connection } = fakeConnection()
    const wrapper = mount(HilosNotificationPreferences, {
      props: { connection, store: storeWith() },
    })
    expect(wrapper.get('h2').text()).toBe('Delivery channels')
    const row = wrapper.get('[data-id="hilos-notification-preference-email"]')
    expect(row.classes()).toEqual(
      expect.arrayContaining([
        'd-flex',
        'align-items-center',
        'gap-3',
        'py-3',
        'border-bottom',
      ]),
    )
    const icon = row.get('[data-id="hilos-notification-preference-icon-email"]')
    expect(icon.classes()).toContain('bi-envelope')
    expect(icon.classes()).toContain('flex-shrink-0')
    expect(icon.attributes('aria-hidden')).toBe('true')
    const middle = row.get('.flex-grow-1')
    expect(middle.classes()).toContain('overflow-hidden')
    expect(middle.get('.fw-semibold').text()).toBe('Email')
    const subtitle = middle.get(
      '[data-id="hilos-notification-preference-subtitle-email"]',
    )
    expect(subtitle.classes()).toContain('text-break')
    const toggle = row.get('input')
    expect(toggle.attributes('aria-label')).toBe('Email')
    expect(
      subtitle.get(`[id="${toggle.attributes('aria-describedby')}"]`).text(),
    ).toBe('you@example.com')
    expect(row.get('.text-nowrap').find('input').element).toBe(toggle.element)
    expect(row.get('.text-nowrap').classes()).toContain('flex-shrink-0')
    expect(row.find('label').exists()).toBe(false)
    expect(
      row
        .find('[data-id="hilos-notification-preference-address-email"]')
        .exists(),
    ).toBe(false)
    for (const twin of row.findAll('[inert]')) {
      expect(twin.attributes('aria-hidden')).toBe('true')
      expect(twin.find('[id], [data-id], input, a, button').exists()).toBe(
        false,
      )
    }
  })

  it('uses a custom channel icon and treats the address as text', () => {
    const { connection } = fakeConnection()
    const store = createHilosNotificationPreferencesStore()
    const address = '<img src=x onerror="alert(1)">'
    store.applySection({
      channels: [
        {
          channel: 'project',
          label: 'Project channel',
          allowed: true,
          hasAddress: true,
          icon: 'bi-chat-dots',
          address,
        },
      ],
      mandatoryNote: false,
    })
    const wrapper = mount(HilosNotificationPreferences, {
      props: { connection, store },
    })
    expect(
      wrapper
        .get('[data-id="hilos-notification-preference-icon-project"]')
        .classes(),
    ).toContain('bi-chat-dots')
    expect(wrapper.get('[id$="-subtitle"]').text()).toBe(address)
    expect(wrapper.find('img').exists()).toBe(false)
  })

  it.each([null, undefined])(
    'reserves an empty subtitle without disabling an addressable channel (%s)',
    (address) => {
      const { connection } = fakeConnection()
      const store = createHilosNotificationPreferencesStore()
      store.applySection({
        channels: [
          {
            channel: 'other',
            label: 'Other',
            allowed: false,
            hasAddress: true,
            address,
          },
        ],
        mandatoryNote: false,
      })
      const wrapper = mount(HilosNotificationPreferences, {
        props: { connection, store },
      })
      expect(
        wrapper
          .get('[data-id="hilos-notification-preference-icon-other"]')
          .classes(),
      ).toContain('bi-bell')
      const toggle = wrapper.get('input')
      expect(toggle.attributes('disabled')).toBeUndefined()
      expect(
        wrapper.get(`[id="${toggle.attributes('aria-describedby')}"]`).text(),
      ).toBe('')
      expect(
        wrapper
          .get('[data-id="hilos-notification-preference-subtitle-other"]')
          .classes(),
      ).toContain('hilos-stack')
      expect(
        wrapper
          .find('[data-id="hilos-notification-preference-hint-other"]')
          .exists(),
      ).toBe(false)
    },
  )

  it('renders a switch per channel and disables a no-address channel with a hint', () => {
    const { connection } = fakeConnection()
    const wrapper = mount(HilosNotificationPreferences, {
      props: { connection, store: storeWith() },
    })

    const email = wrapper.find(
      '[data-id="hilos-notification-preference-toggle-email"]',
    )
    expect((email.element as HTMLInputElement).checked).toBe(true)
    expect(email.attributes('disabled')).toBeUndefined()

    const sms = wrapper.find(
      '[data-id="hilos-notification-preference-toggle-sms"]',
    )
    expect(sms.attributes('disabled')).toBeDefined()
    const hint = wrapper.find(
      '[data-id="hilos-notification-preference-hint-sms"]',
    )
    expect(hint.exists()).toBe(true)
    // The disabled switch points at its hint for assistive tech.
    expect(sms.attributes('aria-describedby')).toBe(hint.attributes('id'))
  })

  it('toggles a channel by sending the action and marks the row pending (no optimistic update)', async () => {
    const { connection, sendAction } = fakeConnection()
    const store = storeWith()
    const wrapper = mount(HilosNotificationPreferences, {
      props: { connection, store },
    })

    await wrapper
      .find('[data-id="hilos-notification-preference-toggle-email"]')
      .trigger('click')

    expect(sendAction).toHaveBeenCalledWith(NOTIFICATION_ACTION_CHANNEL_SET, {
      channel: 'email',
      enabled: false,
    })
    // The row is pending until the changed signal fans back; state is untouched.
    expect(store.pending.get().has('email')).toBe(true)
    expect(store.channels.get()[0].allowed).toBe(true)
    const email = wrapper.find(
      '[data-id="hilos-notification-preference-toggle-email"]',
    )
    expect((email.element as HTMLInputElement).checked).toBe(true)
    expect(email.attributes('aria-busy')).toBe('true')
  })

  it('clears the pending row when the send never leaves', async () => {
    const { connection } = fakeConnection(false)
    const store = storeWith()
    const wrapper = mount(HilosNotificationPreferences, {
      props: { connection, store },
    })

    await wrapper
      .find('[data-id="hilos-notification-preference-toggle-email"]')
      .trigger('click')

    expect(store.pending.get().has('email')).toBe(false)
  })

  it('shows the mandatory note only when the section declares one', () => {
    const { connection } = fakeConnection()

    const without = mount(HilosNotificationPreferences, {
      props: { connection, store: storeWith(false) },
    })
    expect(
      without
        .find('[data-id="hilos-notification-preferences-mandatory"]')
        .exists(),
    ).toBe(false)

    const withNote = mount(HilosNotificationPreferences, {
      props: { connection, store: storeWith(true) },
    })
    expect(
      withNote
        .find('[data-id="hilos-notification-preferences-mandatory"]')
        .exists(),
    ).toBe(true)
  })

  it('shows the empty state when there are no channels', () => {
    const { connection } = fakeConnection()
    const wrapper = mount(HilosNotificationPreferences, {
      props: { connection, store: createHilosNotificationPreferencesStore() },
    })

    expect(
      wrapper.find('[data-id="hilos-notification-preferences-empty"]').exists(),
    ).toBe(true)
  })

  it('links the no-address hint to the section the page names, and keeps it plain text otherwise', () => {
    const { connection } = fakeConnection()
    const plain = mount(HilosNotificationPreferences, {
      props: { connection, store: storeWith() },
    })
    expect(
      plain.get('[data-id="hilos-notification-preference-hint-sms"]').text(),
    ).toBe('Add an address in your profile to enable this channel.')

    const linked = mount(HilosNotificationPreferences, {
      props: {
        connection,
        store: storeWith(),
        addressSection: {
          page: 'hilos_profile_sign_in',
          label: 'Ways to sign in',
        },
        addressTo: '/profile/sign-in',
      },
    })
    const hint = linked.get(
      '[data-id="hilos-notification-preference-hint-sms"]',
    )
    expect(hint.text()).toBe(
      'Add an address in Ways to sign in to enable this channel.',
    )
    const link = hint.get(
      '[data-id="hilos-notification-preference-address-sms"]',
    )
    expect(link.attributes('href')).toBe('/profile/sign-in')
    expect(link.text()).toBe('Ways to sign in')
    const middle = linked.get(
      '[data-id="hilos-notification-preference-sms"] .flex-grow-1',
    )
    expect(middle.find('a').element).toBe(link.element)
    expect(
      linked
        .get('[data-id="hilos-notification-preference-toggle-sms"]')
        .attributes('aria-describedby'),
    ).toBe(hint.attributes('id'))
    expect(linked.findAll('a')).toHaveLength(1)
  })
})
