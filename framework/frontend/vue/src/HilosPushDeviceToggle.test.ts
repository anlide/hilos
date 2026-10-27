import {
  createHilosNotificationPreferencesStore,
  createHilosPushSubscriptionStore,
  type HilosConnection,
  type HilosPushEnvironment,
  type HilosPushPermission,
  type HilosPushSubscriptionSnapshot,
} from '@hilos/core/base'
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

import HilosNotificationPreferences from './HilosNotificationPreferences.vue'
import HilosPushDeviceToggle from './HilosPushDeviceToggle.vue'

// A subscription snapshot the fake browser reports as this device's current one.
const SNAPSHOT: HilosPushSubscriptionSnapshot = {
  endpoint: 'https://push.example/abc',
  p256dh: 'p256dh-key',
  auth: 'auth-secret',
}

interface FakeEnvironmentOptions {
  supported?: boolean
  permission?: HilosPushPermission
  existing?: HilosPushSubscriptionSnapshot | null
}

// A fake browser push surface so the store reflects a known device state; the
// component test only cares about what the store exposes, not the browser dance.
function fakeEnvironment(
  options: FakeEnvironmentOptions = {},
): HilosPushEnvironment {
  return {
    isSupported: () => options.supported ?? true,
    permission: () => options.permission ?? 'default',
    userAgent: () => 'FakeAgent/1.0',
    requestPermission: async () => 'granted',
    getSubscription: async () => options.existing ?? null,
    subscribe: async () => SNAPSHOT,
    unsubscribe: async () => {},
  }
}

// A minimal connection stub: the toggle only ever hands it to the store.
function fakeConnection(): HilosConnection {
  return {
    sendAction: vi.fn().mockReturnValue(true),
  } as unknown as HilosConnection
}

// A store already reflecting the given device state, so the first render is
// deterministic; the component's on-mount refresh re-reads the same values.
async function storeReflecting(options: FakeEnvironmentOptions = {}) {
  const store = createHilosPushSubscriptionStore(fakeEnvironment(options))
  await store.refresh()

  return store
}

function mountToggle(
  store: Awaited<ReturnType<typeof storeReflecting>>,
  connection: HilosConnection,
  channelRow = false,
) {
  return mount(HilosPushDeviceToggle, {
    props: {
      connection,
      channel: 'push',
      label: 'Push on this device',
      vapidPublicKey: 'vapid-public-key',
      store,
      ...(channelRow ? { channelRow } : {}),
    },
  })
}

describe('HilosPushDeviceToggle', () => {
  it('reflects an existing device subscription as a checked, enabled switch', async () => {
    const store = await storeReflecting({
      permission: 'granted',
      existing: SNAPSHOT,
    })
    const wrapper = mountToggle(store, fakeConnection())
    await flushPromises()

    const toggle = wrapper.find(
      '[data-id="hilos-notification-preference-toggle-push"]',
    )
    expect((toggle.element as HTMLInputElement).checked).toBe(true)
    expect(toggle.attributes('disabled')).toBeUndefined()
    expect(
      wrapper
        .find('[data-id="hilos-notification-preference-hint-push"]')
        .exists(),
    ).toBe(false)
    expect(wrapper.find('.form-check-label').text()).toBe('Push on this device')
    expect(
      wrapper
        .find('[data-id="hilos-notification-preference-icon-push"]')
        .exists(),
    ).toBe(false)
    expect(
      wrapper
        .find('[data-id="hilos-notification-preference-subtitle-push"]')
        .exists(),
    ).toBe(false)
    expect(wrapper.findAll('input')).toHaveLength(1)
    expect(toggle.attributes('aria-describedby')).toBeUndefined()
  })

  it('disables the switch with a hint when the browser does not support push', async () => {
    const store = await storeReflecting({ supported: false })
    const wrapper = mountToggle(store, fakeConnection())
    await flushPromises()

    const toggle = wrapper.find(
      '[data-id="hilos-notification-preference-toggle-push"]',
    )
    expect(toggle.attributes('disabled')).toBeDefined()
    const hint = wrapper.find(
      '[data-id="hilos-notification-preference-hint-push"]',
    )
    expect(hint.text()).toContain('not supported')
    // The disabled switch points at its hint for assistive tech.
    expect(toggle.attributes('aria-describedby')).toBe(hint.attributes('id'))
  })

  it('disables the switch with a blocked hint when permission is denied', async () => {
    const store = await storeReflecting({ permission: 'denied' })
    const wrapper = mountToggle(store, fakeConnection())
    await flushPromises()

    const toggle = wrapper.find(
      '[data-id="hilos-notification-preference-toggle-push"]',
    )
    expect(toggle.attributes('disabled')).toBeDefined()
    const hint = wrapper.find(
      '[data-id="hilos-notification-preference-hint-push"]',
    )
    expect(hint.text()).toContain('blocked')
    expect(toggle.attributes('aria-describedby')).toBe(hint.attributes('id'))
  })

  it('subscribes this device when the switch is turned on', async () => {
    const store = await storeReflecting({ existing: null })
    const enable = vi.spyOn(store, 'enable').mockResolvedValue(true)
    const connection = fakeConnection()
    const wrapper = mountToggle(store, connection)
    await flushPromises()

    await wrapper
      .find('[data-id="hilos-notification-preference-toggle-push"]')
      .setValue(true)

    expect(enable).toHaveBeenCalledWith(connection, 'vapid-public-key')
  })

  it('unsubscribes this device when the switch is turned off', async () => {
    const store = await storeReflecting({
      permission: 'granted',
      existing: SNAPSHOT,
    })
    const disable = vi.spyOn(store, 'disable').mockResolvedValue(true)
    const connection = fakeConnection()
    const wrapper = mountToggle(store, connection)
    await flushPromises()

    await wrapper
      .find('[data-id="hilos-notification-preference-toggle-push"]')
      .setValue(false)

    expect(disable).toHaveBeenCalledWith(connection)
  })

  it.each([
    {
      name: 'subscribed',
      options: { permission: 'granted', existing: SNAPSHOT },
      checked: true,
      disabled: false,
      text: 'This browser is subscribed',
    },
    {
      name: 'unsubscribed',
      options: { existing: null },
      checked: false,
      disabled: false,
      text: 'This browser is not subscribed',
    },
    {
      name: 'unsupported',
      options: { supported: false },
      checked: false,
      disabled: true,
      text: 'Push notifications are not supported on this device.',
    },
    {
      name: 'denied',
      options: { permission: 'denied' },
      checked: false,
      disabled: true,
      text: 'Push notifications are blocked in your browser settings.',
    },
  ] satisfies {
    name: string
    options: FakeEnvironmentOptions
    checked: boolean
    disabled: boolean
    text: string
  }[])(
    'renders the $name device as a channel row with one named switch',
    async ({ options, checked, disabled, text }) => {
      const store = await storeReflecting(options)
      const wrapper = mountToggle(store, fakeConnection(), true)
      await flushPromises()

      const root = wrapper.get('[data-id="hilos-notification-preference-push"]')
      expect(root.classes()).toEqual(
        expect.arrayContaining([
          'd-flex',
          'align-items-center',
          'gap-3',
          'py-3',
          'border-bottom',
        ]),
      )
      expect(
        wrapper.findAll('[data-id="hilos-notification-preference-push"]'),
      ).toHaveLength(1)
      expect(wrapper.findAll('input')).toHaveLength(1)
      const toggle = wrapper.get('input')
      expect((toggle.element as HTMLInputElement).checked).toBe(checked)
      expect((toggle.element as HTMLInputElement).disabled).toBe(disabled)
      expect(toggle.attributes('aria-busy')).toBe('false')
      const label = wrapper.get('label')
      expect(label.text()).toBe('Push on this device')
      expect(label.attributes('for')).toBe(toggle.attributes('id'))
      const middle = root.get('.flex-grow-1')
      expect(middle.classes()).toContain('overflow-hidden')
      expect(middle.get('label').element).toBe(label.element)
      expect(
        middle.get(`[id="${toggle.attributes('aria-describedby')}"]`).text(),
      ).toBe(text)
      const subtitle = middle.get(
        '[data-id="hilos-notification-preference-subtitle-push"]',
      )
      expect(subtitle.classes()).toEqual(
        expect.arrayContaining([
          'hilos-stack',
          'text-break',
          'text-body-secondary',
        ]),
      )
      const icon = wrapper.get(
        '[data-id="hilos-notification-preference-icon-push"]',
      )
      expect(icon.classes()).toContain('bi-broadcast')
      expect(icon.attributes('aria-hidden')).toBe('true')
      const right = root.get('.text-nowrap')
      expect(right.classes()).toContain('flex-shrink-0')
      expect(right.get('input').element).toBe(toggle.element)
      expect(right.find('label').exists()).toBe(false)
      expect(right.get('.hilos-stack [inert]').classes()).toContain(
        'spinner-border',
      )
      const hint = wrapper.find(
        '[data-id="hilos-notification-preference-hint-push"]',
      )
      expect(hint.exists()).toBe(disabled)
      if (disabled) {
        expect(toggle.attributes('aria-describedby')).toBe(
          hint.attributes('id'),
        )
      }
      const ids = wrapper.findAll('[id]').map((node) => node.attributes('id'))
      expect(new Set(ids).size).toBe(ids.length)
      for (const twin of wrapper.findAll('[inert]')) {
        expect(twin.attributes('aria-hidden')).toBe('true')
        expect(twin.attributes('id')).toBeUndefined()
        expect(twin.attributes('data-id')).toBeUndefined()
        expect(twin.find('[id], [data-id], input, a, button').exists()).toBe(
          false,
        )
      }
    },
  )

  it.each([false, true])(
    'keeps the local status and loader slot while a row mutation is busy (subscribed=%s)',
    async (existing) => {
      let finish!: () => void
      const wait = new Promise<void>((resolve) => {
        finish = resolve
      })
      const environment = fakeEnvironment({
        permission: 'granted',
        existing: existing ? SNAPSHOT : null,
      })
      environment.subscribe = async () => {
        await wait
        return SNAPSHOT
      }
      environment.unsubscribe = async () => {
        await wait
      }
      const store = createHilosPushSubscriptionStore(environment)
      await store.refresh()
      const mutation = vi.spyOn(store, existing ? 'disable' : 'enable')
      const wrapper = mountToggle(store, fakeConnection(), true)
      await flushPromises()
      const toggle = wrapper.get('input')
      const description = toggle.attributes('aria-describedby')
      const status = existing
        ? 'This browser is subscribed'
        : 'This browser is not subscribed'
      const slot = wrapper.get('.text-nowrap .hilos-stack').element
      await toggle.setValue(!existing)
      await flushPromises()

      expect(store.busy.get()).toBe(true)
      expect(toggle.attributes('aria-busy')).toBe('true')
      expect(toggle.attributes('disabled')).toBeDefined()
      expect((toggle.element as HTMLInputElement).checked).toBe(existing)
      expect(toggle.attributes('aria-describedby')).toBe(description)
      expect(wrapper.get(`[id="${description}"]`).text()).toBe(status)
      expect(
        wrapper
          .get('[data-id="hilos-notification-preference-pending-push"]')
          .text(),
      ).toBe('Saving…')
      expect(wrapper.get('.text-nowrap .hilos-stack').element).toBe(slot)
      expect(wrapper.findAll('input')).toHaveLength(1)
      finish()
      await mutation.mock.results[0].value
      await flushPromises()
      expect(store.busy.get()).toBe(false)
      expect((toggle.element as HTMLInputElement).checked).toBe(!existing)
      expect(wrapper.get(`[id="${description}"]`).text()).toBe(
        existing
          ? 'This browser is not subscribed'
          : 'This browser is subscribed',
      )
      expect(
        wrapper
          .find('[data-id="hilos-notification-preference-pending-push"]')
          .exists(),
      ).toBe(false)
      expect(wrapper.get('.text-nowrap .hilos-stack').element).toBe(slot)
      wrapper.unmount()
    },
  )

  it('mounts the push channel row through Preferences using config and browser state', async () => {
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
          channel: 'web',
          label: 'Project push',
          allowed: false,
          hasAddress: false,
          icon: 'bi-phone',
          config: { vapid_public: 'project-vapid-key' },
        },
        {
          channel: 'other',
          label: 'Other',
          allowed: false,
          hasAddress: false,
          config: {},
        },
      ],
      mandatoryNote: false,
    })
    const pushStore = await storeReflecting({
      permission: 'granted',
      existing: SNAPSHOT,
    })
    const connection = fakeConnection()
    const disable = vi.spyOn(pushStore, 'disable').mockResolvedValue(true)
    const wrapper = mount(HilosNotificationPreferences, {
      props: { connection, store, pushStore },
    })
    await flushPromises()

    const row = wrapper.get('[data-id="hilos-notification-preference-web"]')
    const toggle = row.get('input')
    expect((toggle.element as HTMLInputElement).checked).toBe(true)
    expect(toggle.attributes('disabled')).toBeUndefined()
    expect(
      row.get('[data-id="hilos-notification-preference-icon-web"]').classes(),
    ).toContain('bi-phone')
    expect(row.get('label').text()).toBe('Project push')
    expect(
      row.get(`[id="${toggle.attributes('aria-describedby')}"]`).text(),
    ).toBe('This browser is subscribed')
    expect(
      wrapper
        .get('[data-id="hilos-notification-preference-toggle-other"]')
        .attributes('disabled'),
    ).toBeDefined()
    const ids = wrapper.findAll('[id]').map((node) => node.attributes('id'))
    expect(new Set(ids).size).toBe(ids.length)
    expect(wrapper.findAll('input')).toHaveLength(3)
    expect(wrapper.findAll('label')).toHaveLength(1)
    await toggle.setValue(false)
    expect(disable).toHaveBeenCalledWith(connection)
    expect(connection.sendAction).not.toHaveBeenCalled()
    expect(store.pending.get().size).toBe(0)
  })
})
