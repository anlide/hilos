import {
  createHilosNotificationPreferencesStore,
  createHilosPushSubscriptionStore,
  NOTIFICATION_ACTION_CHANNEL_SET,
  type HilosConnection,
  type HilosPushEnvironment,
} from '@hilos/core'
import {
  act,
  cleanup,
  fireEvent,
  render,
  screen,
  waitFor,
} from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { HilosNotificationPreferences } from '../src/HilosNotificationPreferences.js'

afterEach(() => cleanup())

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
      },
      { channel: 'sms', label: 'SMS', allowed: false, hasAddress: false },
    ],
    mandatoryNote,
  })
  return store
}

function byId(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

// The switch's description: the element its aria-describedby names.
function describedBy(toggle: HTMLElement): HTMLElement | null {
  const id = toggle.getAttribute('aria-describedby')

  return id === null ? null : document.getElementById(id)
}

// A fake browser push surface with no subscription yet, so the push store
// reflects a known, enabled and unsubscribed device.
function fakePushEnvironment(): HilosPushEnvironment {
  return {
    isSupported: () => true,
    permission: () => 'default',
    userAgent: () => 'FakeAgent/1.0',
    requestPermission: async () => 'granted',
    getSubscription: async () => null,
    subscribe: async () => ({
      endpoint: 'https://push.example/abc',
      p256dh: 'p256dh-key',
      auth: 'auth-secret',
    }),
    unsubscribe: async () => {},
  }
}

// A section with an ordinary channel beside the web-push channel, whose config
// carries the VAPID public key that marks it as the device toggle.
function storeWithPush() {
  const store = createHilosNotificationPreferencesStore()
  store.applySection({
    channels: [
      {
        channel: 'email',
        label: 'Email',
        allowed: true,
        hasAddress: true,
        address: 'you@example.com',
      },
      {
        channel: 'push',
        label: 'Push',
        allowed: true,
        hasAddress: true,
        config: { vapid_public: 'vapid-public-key' },
      },
    ],
    mandatoryNote: false,
  })
  return store
}

// Every id is unique, and every measuring twin is hidden, inert and carries no
// id, data-id or interactive element of its own.
function expectUniqueIdsAndInertTwins(): void {
  const ids = [...document.querySelectorAll('[id]')].map((node) => node.id)
  expect(new Set(ids).size).toBe(ids.length)
  for (const twin of document.querySelectorAll('[inert]')) {
    expect(twin.getAttribute('aria-hidden')).toBe('true')
    expect(twin.hasAttribute('id')).toBe(false)
    expect(twin.hasAttribute('data-id')).toBe(false)
    expect(twin.querySelector('[id], [data-id], input, a, button')).toBeNull()
  }
}

describe('HilosNotificationPreferences', () => {
  it('shows the public address as the description of a switch named by its channel', () => {
    const { connection } = fakeConnection()
    render(
      <HilosNotificationPreferences
        connection={connection}
        store={storeWith()}
      />,
    )

    expect(
      screen.getByRole('heading', { level: 2, name: 'Delivery channels' }),
    ).not.toBeNull()
    const toggle = screen.getByRole('switch', { name: 'Email' })
    expect(toggle).toBe(byId('hilos-notification-preference-toggle-email'))
    expect(describedBy(toggle)?.textContent).toBe('you@example.com')
    const subtitle = byId(
      'hilos-notification-preference-subtitle-email',
    ) as HTMLElement
    expect(subtitle.contains(describedBy(toggle))).toBe(true)
    const icon = byId('hilos-notification-preference-icon-email') as HTMLElement
    expect(icon.getAttribute('aria-hidden')).toBe('true')
    // The shown address is text, never a link to the address section.
    expect(byId('hilos-notification-preference-address-email')).toBeNull()
    expect(byId('hilos-notification-preference-hint-email')).toBeNull()
    expectUniqueIdsAndInertTwins()
  })

  it('keeps an HTML-like address as text', () => {
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
          address,
        },
      ],
      mandatoryNote: false,
    })
    render(
      <HilosNotificationPreferences connection={connection} store={store} />,
    )

    const toggle = screen.getByRole('switch', { name: 'Project channel' })
    expect(describedBy(toggle)?.textContent).toBe(address)
    expect(document.querySelector('img')).toBeNull()
  })

  it.each([null, undefined])(
    'keeps an addressable channel enabled without a no-address hint when its address is %s',
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
      render(
        <HilosNotificationPreferences connection={connection} store={store} />,
      )

      const toggle = screen.getByRole('switch', {
        name: 'Other',
      }) as HTMLInputElement
      expect(toggle.disabled).toBe(false)
      expect(describedBy(toggle)?.textContent?.trim()).toBe('')
      expect(byId('hilos-notification-preference-hint-other')).toBeNull()
      expect(
        byId('hilos-notification-preference-icon-other')?.getAttribute(
          'aria-hidden',
        ),
      ).toBe('true')
    },
  )

  it('renders a switch per channel and disables a no-address channel with a hint', () => {
    const { connection } = fakeConnection()
    render(
      <HilosNotificationPreferences
        connection={connection}
        store={storeWith()}
      />,
    )

    const email = byId(
      'hilos-notification-preference-toggle-email',
    ) as HTMLInputElement
    expect(email.checked).toBe(true)
    expect(email.disabled).toBe(false)

    const sms = byId(
      'hilos-notification-preference-toggle-sms',
    ) as HTMLInputElement
    expect(sms.disabled).toBe(true)
    const hint = byId('hilos-notification-preference-hint-sms') as HTMLElement
    expect(hint).not.toBeNull()
    // The disabled switch points at its hint for assistive tech.
    expect(sms.getAttribute('aria-describedby')).toBe(hint.getAttribute('id'))
  })

  it('toggles a channel by sending the action and marks the row pending (no optimistic update)', () => {
    const { connection, sendAction } = fakeConnection()
    const store = storeWith()
    render(
      <HilosNotificationPreferences connection={connection} store={store} />,
    )

    fireEvent.click(
      byId('hilos-notification-preference-toggle-email') as Element,
    )

    expect(sendAction).toHaveBeenCalledWith(NOTIFICATION_ACTION_CHANNEL_SET, {
      channel: 'email',
      enabled: false,
    })
    // The row is pending until the changed signal fans back; state is untouched.
    expect(store.pending.get().has('email')).toBe(true)
    expect(store.channels.get()[0].allowed).toBe(true)
    expect(
      byId('hilos-notification-preference-toggle-email')?.getAttribute(
        'aria-busy',
      ),
    ).toBe('true')
  })

  it('clears the pending row when the send never leaves', () => {
    const { connection } = fakeConnection(false)
    const store = storeWith()
    render(
      <HilosNotificationPreferences connection={connection} store={store} />,
    )

    fireEvent.click(
      byId('hilos-notification-preference-toggle-email') as Element,
    )

    expect(store.pending.get().has('email')).toBe(false)
  })

  it('shows the mandatory note only when the section declares one', () => {
    const { connection } = fakeConnection()

    const without = render(
      <HilosNotificationPreferences
        connection={connection}
        store={storeWith(false)}
      />,
    )
    expect(byId('hilos-notification-preferences-mandatory')).toBeNull()
    without.unmount()

    render(
      <HilosNotificationPreferences
        connection={connection}
        store={storeWith(true)}
      />,
    )
    expect(byId('hilos-notification-preferences-mandatory')).not.toBeNull()
  })

  it('shows the empty state when there are no channels', () => {
    const { connection } = fakeConnection()
    render(
      <HilosNotificationPreferences
        connection={connection}
        store={createHilosNotificationPreferencesStore()}
      />,
    )

    expect(byId('hilos-notification-preferences-empty')).not.toBeNull()
  })

  it('links the no-address hint to the section the page names, and keeps it plain text otherwise', () => {
    const { connection } = fakeConnection()
    const plain = render(
      <HilosNotificationPreferences
        connection={connection}
        store={storeWith()}
      />,
    )
    expect(byId('hilos-notification-preference-hint-sms')?.textContent).toBe(
      'Add an address in your profile to enable this channel.',
    )
    plain.unmount()

    render(
      <HilosNotificationPreferences
        connection={connection}
        store={storeWith()}
        addressSection={{
          page: 'hilos_profile_sign_in',
          label: 'Ways to sign in',
        }}
        addressTo="/profile/sign-in"
      />,
    )
    expect(byId('hilos-notification-preference-hint-sms')?.textContent).toBe(
      'Add an address in Ways to sign in to enable this channel.',
    )
    const link = byId('hilos-notification-preference-address-sms')
    expect(link?.getAttribute('href')).toBe('/profile/sign-in')
    expect(link?.textContent).toBe('Ways to sign in')
    const hint = byId('hilos-notification-preference-hint-sms') as HTMLElement
    expect(hint.contains(link)).toBe(true)
    const sms = screen.getByRole('switch', { name: 'SMS' }) as HTMLInputElement
    expect(sms.disabled).toBe(true)
    expect(describedBy(sms)).toBe(hint)
    expect(screen.getAllByRole('link')).toHaveLength(1)
    expectUniqueIdsAndInertTwins()
  })

  it('follows the changed map and a new section without losing the address', () => {
    const { connection } = fakeConnection()
    const store = storeWith()
    render(
      <HilosNotificationPreferences connection={connection} store={store} />,
    )

    const toggle = screen.getByRole('switch', {
      name: 'Email',
    }) as HTMLInputElement
    // The clicker marks the row pending before sending; the click itself is
    // covered above, here only the changed signal's answer matters.
    act(() => {
      store.markPending('email')
    })
    expect(toggle.checked).toBe(true)
    expect(toggle.getAttribute('aria-busy')).toBe('true')
    expect(toggle.disabled).toBe(true)

    act(() => {
      store.applyChangedMap({ email: false })
    })
    expect(toggle.checked).toBe(false)
    expect(toggle.getAttribute('aria-busy')).toBeNull()
    expect(toggle.disabled).toBe(false)
    expect(describedBy(toggle)?.textContent).toBe('you@example.com')

    act(() => {
      store.applySection({
        channels: [
          {
            channel: 'email',
            label: 'Email',
            allowed: true,
            hasAddress: true,
            address: 'new@example.com',
          },
        ],
        mandatoryNote: false,
      })
    })
    const next = screen.getByRole('switch', { name: 'Email' })
    expect((next as HTMLInputElement).checked).toBe(true)
    expect(describedBy(next)?.textContent).toBe('new@example.com')
  })

  it('mounts the push channel as one row with its own device switch', async () => {
    const { connection } = fakeConnection()
    const pushStore = createHilosPushSubscriptionStore(fakePushEnvironment())
    await pushStore.refresh()
    render(
      <HilosNotificationPreferences
        connection={connection}
        store={storeWithPush()}
        pushStore={pushStore}
      />,
    )

    const push = screen.getByRole('switch', {
      name: 'Push',
    }) as HTMLInputElement
    await waitFor(() => expect(push.getAttribute('aria-busy')).toBe('false'))
    expect(push).toBe(byId('hilos-notification-preference-toggle-push'))
    expect(
      document.querySelectorAll(
        '[data-id="hilos-notification-preference-toggle-push"]',
      ),
    ).toHaveLength(1)
    // The device state drives the switch, not the server's allowed flag.
    expect(push.checked).toBe(false)
    expect(push.disabled).toBe(false)
    expect(describedBy(push)?.textContent).toBe(
      'This browser is not subscribed',
    )
    const subtitle = byId(
      'hilos-notification-preference-subtitle-push',
    ) as HTMLElement
    expect(subtitle.contains(describedBy(push))).toBe(true)
    expect(
      byId('hilos-notification-preference-icon-push')?.getAttribute(
        'aria-hidden',
      ),
    ).toBe('true')
    expect(screen.getAllByRole('switch')).toHaveLength(2)
    expectUniqueIdsAndInertTwins()
  })

  it('keeps ids unique across several sections', async () => {
    const pushStore = createHilosPushSubscriptionStore(fakePushEnvironment())
    await pushStore.refresh()
    render(
      <>
        <HilosNotificationPreferences
          connection={fakeConnection().connection}
          store={storeWithPush()}
          pushStore={pushStore}
        />
        <HilosNotificationPreferences
          connection={fakeConnection().connection}
          store={storeWith()}
          addressSection={{
            page: 'hilos_profile_sign_in',
            label: 'Ways to sign in',
          }}
          addressTo="/profile/sign-in"
        />
      </>,
    )
    await waitFor(() =>
      expect(
        byId('hilos-notification-preference-toggle-push')?.getAttribute(
          'aria-busy',
        ),
      ).toBe('false'),
    )

    expectUniqueIdsAndInertTwins()
    for (const toggle of screen.getAllByRole('switch')) {
      const description = describedBy(toggle)
      expect(description).not.toBeNull()
      const row = toggle.closest(
        '[data-id^="hilos-notification-preference-"]:not([data-id^="hilos-notification-preference-toggle-"])',
      )
      expect(row?.contains(description)).toBe(true)
    }
    for (const section of screen.getAllByRole('region', {
      name: 'Delivery channels',
    })) {
      expect(section.getAttribute('aria-labelledby')).not.toBeNull()
    }
  })
})
