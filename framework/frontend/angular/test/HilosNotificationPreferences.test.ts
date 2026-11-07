// Covers the Angular profile delivery channels (HIL-1658): every ordinary
// channel as a row whose switch is named by the channel and described by its
// public address or the no-address hint (HIL-1166), the tracked, non-optimistic
// toggle, the push channel mounted as its device row, and that the section reads,
// calls and lets go of the store its input really holds.
import { Component, signal } from '@angular/core'
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  createHilosNotificationPreferencesStore,
  createHilosPushSubscriptionStore,
  hilosNotificationPreferences,
  hilosPushSubscription,
  NOTIFICATION_ACTION_CHANNEL_SET,
  type HilosConnection,
  type HilosNotificationChannelState,
  type HilosNotificationPreferencesStore,
  type HilosPageCrumb,
  type HilosPushEnvironment,
  type HilosPushSubscriptionStore,
} from '@hilos/core/base'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { HilosNotificationPreferences } from '../src/HilosNotificationPreferences.js'

afterEach(() => {
  hilosNotificationPreferences.clear()
  TestBed.resetTestingModule()
  document.body.innerHTML = ''
  vi.restoreAllMocks()
})

const ADDRESS_SECTION: HilosPageCrumb = {
  page: 'hilos_profile_sign_in',
  label: 'Ways to sign in',
}

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

function storeWithChannels(
  channels: HilosNotificationChannelState[],
  mandatoryNote = false,
): HilosNotificationPreferencesStore {
  const store = createHilosNotificationPreferencesStore()
  store.applySection({ channels, mandatoryNote })

  return store
}

// An email channel with its public address beside an SMS channel without one.
function emailAndSms(mandatoryNote = false): HilosNotificationPreferencesStore {
  return storeWithChannels(
    [
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
  )
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

async function pushStoreReflectingDevice(): Promise<HilosPushSubscriptionStore> {
  const store = createHilosPushSubscriptionStore(fakePushEnvironment())
  await store.refresh()

  return store
}

// An ordinary channel beside the web-push channel, whose config carries the
// VAPID public key that marks it as the device row.
function emailAndPush(): HilosNotificationPreferencesStore {
  return storeWithChannels([
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
  ])
}

interface MountOptions {
  connection?: HilosConnection
  pushStore?: HilosPushSubscriptionStore
  addressTo?: string
}

// Every input — the custom store included — goes in through setInput before
// the first change detection, as a parent template binds it.
async function mount(
  store: HilosNotificationPreferencesStore,
  options: MountOptions = {},
): Promise<ComponentFixture<HilosNotificationPreferences>> {
  const fixture = TestBed.createComponent(HilosNotificationPreferences)
  fixture.componentRef.setInput(
    'connection',
    options.connection ?? fakeConnection().connection,
  )
  fixture.componentRef.setInput('store', store)
  if (options.pushStore !== undefined) {
    fixture.componentRef.setInput('pushStore', options.pushStore)
  }
  if (options.addressTo !== undefined) {
    fixture.componentRef.setInput('addressSection', ADDRESS_SECTION)
    fixture.componentRef.setInput('addressTo', options.addressTo)
  }
  fixture.detectChanges()
  await settle(fixture)

  return fixture
}

async function settle(fixture: ComponentFixture<unknown>): Promise<void> {
  await fixture.whenStable()
  fixture.detectChanges()
}

function rootOf(fixture: ComponentFixture<unknown>): HTMLElement {
  return fixture.nativeElement as HTMLElement
}

function byId(root: ParentNode, id: string): HTMLElement | null {
  return root.querySelector<HTMLElement>(`[data-id="${id}"]`)
}

function switchesIn(root: ParentNode): HTMLInputElement[] {
  return [...root.querySelectorAll<HTMLInputElement>('input[role="switch"]')]
}

// The switch of one channel, found by the hook on its checkbox.
function toggleOf(root: ParentNode, channel: string): HTMLInputElement {
  const toggle = byId(root, `hilos-notification-preference-toggle-${channel}`)
  expect(toggle).not.toBeNull()

  return toggle as HTMLInputElement
}

// The switch's accessible name: its aria-label, or the label pointing at it.
function nameOf(root: ParentNode, toggle: HTMLInputElement): string | null {
  return (
    toggle.getAttribute('aria-label') ??
    root.querySelector(`label[for="${toggle.id}"]`)?.textContent?.trim() ??
    null
  )
}

// The switch's description: the element its aria-describedby names.
function describedBy(
  root: ParentNode,
  toggle: HTMLInputElement,
): HTMLElement | null {
  const id = toggle.getAttribute('aria-describedby')

  return id === null ? null : root.querySelector<HTMLElement>(`[id="${id}"]`)
}

function text(node: Element | null | undefined): string | undefined {
  return node?.textContent?.replace(/\s+/g, ' ').trim()
}

// Every id is unique, and every measuring twin is hidden, inert and carries no
// id, data-id or interactive element of its own.
function expectUniqueIdsAndInertTwins(root: ParentNode): void {
  const ids = [...root.querySelectorAll('[id]')].map((node) => node.id)
  expect(new Set(ids).size).toBe(ids.length)
  for (const twin of root.querySelectorAll('[inert]')) {
    expect(twin.getAttribute('aria-hidden')).toBe('true')
    expect(twin.hasAttribute('id')).toBe(false)
    expect(twin.hasAttribute('data-id')).toBe(false)
    expect(twin.querySelector('[id], [data-id], input, a, button')).toBeNull()
  }
}

/** Two sections bound from one template, as two parent surfaces mount them. */
@Component({
  selector: 'test-preferences-two-sections-host',
  imports: [HilosNotificationPreferences],
  template: `
    <hilos-notification-preferences
      [connection]="connection"
      [store]="first()"
      [pushStore]="pushStore()"
    />
    <hilos-notification-preferences
      [connection]="connection"
      [store]="second()"
      [addressSection]="addressSection"
      addressTo="/profile/sign-in"
    />
  `,
})
class TwoSectionsHost {
  readonly first = signal<HilosNotificationPreferencesStore>(
    hilosNotificationPreferences,
  )
  readonly second = signal<HilosNotificationPreferencesStore>(
    hilosNotificationPreferences,
  )
  readonly pushStore = signal<HilosPushSubscriptionStore>(hilosPushSubscription)
  protected readonly connection = fakeConnection().connection
  protected readonly addressSection = ADDRESS_SECTION
}

describe('HilosNotificationPreferences (Angular)', () => {
  describe('a channel row', () => {
    it('shows the public address as the description of a switch named by its channel', async () => {
      const root = rootOf(await mount(emailAndSms()))

      const heading = root.querySelector('h2')
      expect(text(heading)).toBe('Delivery channels')
      expect(
        root
          .querySelector('[data-id="hilos-notification-preferences"]')
          ?.getAttribute('aria-labelledby'),
      ).toBe(heading?.id)
      const toggle = toggleOf(root, 'email')
      expect(nameOf(root, toggle)).toBe('Email')
      expect(toggle.checked).toBe(true)
      expect(toggle.disabled).toBe(false)
      const description = describedBy(root, toggle)
      expect(text(description)).toBe('you@example.com')
      expect(
        byId(root, 'hilos-notification-preference-subtitle-email')?.contains(
          description,
        ),
      ).toBe(true)
      expect(
        byId(root, 'hilos-notification-preference-icon-email')?.getAttribute(
          'aria-hidden',
        ),
      ).toBe('true')
      // The shown address is text, never a link and never the no-address hint.
      expect(
        byId(root, 'hilos-notification-preference-address-email'),
      ).toBeNull()
      expect(byId(root, 'hilos-notification-preference-hint-email')).toBeNull()
      // One real switch per channel.
      expect(switchesIn(root)).toHaveLength(2)
      expectUniqueIdsAndInertTwins(root)
    })

    it('keeps an HTML-like address as text', async () => {
      const address = '<img src=x onerror="alert(1)">'
      const root = rootOf(
        await mount(
          storeWithChannels([
            {
              channel: 'project',
              label: 'Project channel',
              allowed: true,
              hasAddress: true,
              address,
            },
          ]),
        ),
      )

      const toggle = toggleOf(root, 'project')
      expect(describedBy(root, toggle)?.textContent?.trim()).toBe(address)
      expect(root.querySelector('img')).toBeNull()
    })

    it.each([null, undefined])(
      'keeps an addressable channel enabled without a no-address hint when its address is %s',
      async (address) => {
        const root = rootOf(
          await mount(
            storeWithChannels([
              {
                channel: 'other',
                label: 'Other',
                allowed: false,
                hasAddress: true,
                address,
              },
            ]),
          ),
        )

        const toggle = toggleOf(root, 'other')
        expect(nameOf(root, toggle)).toBe('Other')
        expect(toggle.disabled).toBe(false)
        const description = describedBy(root, toggle)
        expect(description).not.toBeNull()
        expect(text(description)).toBe('')
        expect(
          byId(root, 'hilos-notification-preference-hint-other'),
        ).toBeNull()
        expect(
          byId(root, 'hilos-notification-preference-icon-other')?.getAttribute(
            'aria-hidden',
          ),
        ).toBe('true')
      },
    )

    it('blocks a channel without an address and keeps the no-address hint plain text when the page names no section', async () => {
      const root = rootOf(await mount(emailAndSms()))

      const sms = toggleOf(root, 'sms')
      expect(nameOf(root, sms)).toBe('SMS')
      expect(sms.disabled).toBe(true)
      const hint = byId(root, 'hilos-notification-preference-hint-sms')
      expect(text(hint)).toBe(
        'Add an address in your profile to enable this channel.',
      )
      expect(describedBy(root, sms)).toBe(hint)
      expect(byId(root, 'hilos-notification-preference-address-sms')).toBeNull()
      expect(root.querySelector('a')).toBeNull()
    })

    it('links the no-address hint to the section the page names', async () => {
      const root = rootOf(
        await mount(emailAndSms(), { addressTo: '/profile/sign-in' }),
      )

      const sms = toggleOf(root, 'sms')
      expect(sms.disabled).toBe(true)
      const hint = byId(root, 'hilos-notification-preference-hint-sms')
      expect(text(hint)).toBe(
        'Add an address in Ways to sign in to enable this channel.',
      )
      expect(describedBy(root, sms)).toBe(hint)
      const link = byId(root, 'hilos-notification-preference-address-sms')
      expect(link?.getAttribute('href')).toBe('/profile/sign-in')
      expect(link?.textContent).toBe('Ways to sign in')
      expect(hint?.contains(link)).toBe(true)
      expect(root.querySelectorAll('a')).toHaveLength(1)
      expectUniqueIdsAndInertTwins(root)
    })
  })

  describe('toggling', () => {
    it('sends the known action and keeps the confirmed position until the changed map answers', async () => {
      const { connection, sendAction } = fakeConnection()
      const store = emailAndSms()
      const fixture = await mount(store, { connection })
      const root = rootOf(fixture)
      const toggle = toggleOf(root, 'email')

      toggle.click()
      fixture.detectChanges()

      expect(sendAction).toHaveBeenCalledWith(NOTIFICATION_ACTION_CHANNEL_SET, {
        channel: 'email',
        enabled: false,
      })
      expect(store.pending.get().has('email')).toBe(true)
      // Not optimistic: the switch stays where the server last put it, busy.
      expect(toggle.checked).toBe(true)
      expect(toggle.getAttribute('aria-busy')).toBe('true')
      expect(toggle.disabled).toBe(true)

      store.applyChangedMap({ email: false })
      fixture.detectChanges()

      expect(toggle.checked).toBe(false)
      expect(toggle.getAttribute('aria-busy')).toBeNull()
      expect(toggle.disabled).toBe(false)
      // The changed map moves only `allowed`; the row keeps its address.
      expect(text(describedBy(root, toggle))).toBe('you@example.com')
      expect(store.channels.get()[0]).toMatchObject({
        address: 'you@example.com',
      })
    })

    it('settles the pending row when the send never leaves', async () => {
      const { connection } = fakeConnection(false)
      const store = emailAndSms()
      const fixture = await mount(store, { connection })
      const toggle = toggleOf(rootOf(fixture), 'email')

      toggle.click()
      fixture.detectChanges()

      expect(store.pending.get().has('email')).toBe(false)
      expect(toggle.checked).toBe(true)
      expect(toggle.disabled).toBe(false)
      expect(toggle.getAttribute('aria-busy')).toBeNull()
    })

    it('shows the address a new section brings', async () => {
      const store = emailAndSms()
      const fixture = await mount(store)
      const root = rootOf(fixture)

      store.applySection({
        channels: [
          {
            channel: 'email',
            label: 'Email',
            allowed: false,
            hasAddress: true,
            address: 'new@example.com',
          },
        ],
        mandatoryNote: false,
      })
      fixture.detectChanges()

      const toggle = toggleOf(root, 'email')
      expect(toggle.checked).toBe(false)
      expect(text(describedBy(root, toggle))).toBe('new@example.com')
      expect(switchesIn(root)).toHaveLength(1)
    })
  })

  describe('the section', () => {
    it('shows the mandatory note only when the section declares one', async () => {
      expect(
        byId(
          rootOf(await mount(emailAndSms(false))),
          'hilos-notification-preferences-mandatory',
        ),
      ).toBeNull()
      expect(
        byId(
          rootOf(await mount(emailAndSms(true))),
          'hilos-notification-preferences-mandatory',
        ),
      ).not.toBeNull()
    })

    it('shows the empty state when there are no channels', async () => {
      const root = rootOf(
        await mount(createHilosNotificationPreferencesStore()),
      )

      expect(byId(root, 'hilos-notification-preferences-empty')).not.toBeNull()
      expect(switchesIn(root)).toHaveLength(0)
    })

    it('mounts the push channel as one device row observing the passed push store', async () => {
      const { connection } = fakeConnection()
      const pushStore = await pushStoreReflectingDevice()
      const observePermission = vi.spyOn(pushStore, 'observePermission')
      const enable = vi.spyOn(pushStore, 'enable').mockResolvedValue(true)
      const sharedObservePermission = vi.spyOn(
        hilosPushSubscription,
        'observePermission',
      )
      const fixture = await mount(emailAndPush(), { connection, pushStore })
      const root = rootOf(fixture)

      expect(observePermission).toHaveBeenCalledTimes(1)
      expect(sharedObservePermission).not.toHaveBeenCalled()
      expect(
        root.querySelectorAll(
          '[data-id="hilos-notification-preference-toggle-push"]',
        ),
      ).toHaveLength(1)
      expect(
        root.querySelectorAll('[data-id="hilos-notification-preference-push"]'),
      ).toHaveLength(1)
      const push = toggleOf(root, 'push')
      expect(nameOf(root, push)).toBe('Push')
      // The device state drives the switch, not the server's allowed flag.
      expect(push.checked).toBe(false)
      expect(push.disabled).toBe(false)
      const description = describedBy(root, push)
      expect(text(description)).toBe('This browser is not subscribed')
      expect(
        byId(root, 'hilos-notification-preference-subtitle-push')?.contains(
          description,
        ),
      ).toBe(true)
      expect(
        byId(root, 'hilos-notification-preference-icon-push')?.getAttribute(
          'aria-hidden',
        ),
      ).toBe('true')
      expect(switchesIn(root)).toHaveLength(2)
      expectUniqueIdsAndInertTwins(root)

      push.click()
      expect(enable).toHaveBeenCalledWith(connection, 'vapid-public-key')
    })

    it('keeps ids unique and every description in its own row across two sections', async () => {
      const fixture = TestBed.createComponent(TwoSectionsHost)
      const first = emailAndPush()
      const second = emailAndSms()
      fixture.componentInstance.first.set(first)
      fixture.componentInstance.second.set(second)
      fixture.componentInstance.pushStore.set(await pushStoreReflectingDevice())
      fixture.detectChanges()
      await settle(fixture)
      const root = rootOf(fixture)

      const toggles = switchesIn(root)
      expect(toggles.map((toggle) => nameOf(root, toggle))).toEqual([
        'Email',
        'Push',
        'Email',
        'SMS',
      ])
      expectUniqueIdsAndInertTwins(root)
      for (const toggle of toggles) {
        const description = describedBy(root, toggle)
        expect(description).not.toBeNull()
        expect(
          toggle
            .closest(
              '[data-id^="hilos-notification-preference-"]:not([data-id^="hilos-notification-preference-toggle-"])',
            )
            ?.contains(description),
        ).toBe(true)
      }

      // One section's store moves only that section.
      first.applyChangedMap({ email: false })
      fixture.detectChanges()
      expect(toggles[0].checked).toBe(false)
      expect(toggles[2].checked).toBe(true)
    })
  })

  describe('the passed store', () => {
    it('renders and calls the store set before the first render, not the shared one', async () => {
      hilosNotificationPreferences.applySection({
        channels: [
          {
            channel: 'shared',
            label: 'Shared',
            allowed: true,
            hasAddress: true,
          },
        ],
        mandatoryNote: true,
      })
      const sharedMark = vi.spyOn(hilosNotificationPreferences, 'markPending')
      const store = emailAndSms()
      const mark = vi.spyOn(store, 'markPending')
      const fixture = await mount(store)
      const root = rootOf(fixture)

      expect(byId(root, 'hilos-notification-preference-shared')).toBeNull()
      expect(byId(root, 'hilos-notification-preferences-mandatory')).toBeNull()
      expect(switchesIn(root).map((toggle) => nameOf(root, toggle))).toEqual([
        'Email',
        'SMS',
      ])
      toggleOf(root, 'email').click()
      expect(mark).toHaveBeenCalledWith('email')
      expect(sharedMark).not.toHaveBeenCalled()
    })

    it('leaves a replaced store and lets go of the store when destroyed', async () => {
      const a = emailAndSms()
      const b = storeWithChannels([
        {
          channel: 'email',
          label: 'Email',
          allowed: false,
          hasAddress: true,
          address: 'b@example.com',
        },
      ])
      const fixture = await mount(a)
      const root = rootOf(fixture)

      fixture.componentRef.setInput('store', b)
      await settle(fixture)
      expect(toggleOf(root, 'email').checked).toBe(false)
      expect(byId(root, 'hilos-notification-preference-sms')).toBeNull()
      // applySection only writes the store's signals, so a read of the old
      // store's channels can only come from a subscription still alive.
      const readA = vi.spyOn(a.channels, 'get')
      a.applySection({ channels: [], mandatoryNote: true })
      b.applyChangedMap({ email: true })
      fixture.detectChanges()
      expect(readA).not.toHaveBeenCalled()
      expect(toggleOf(root, 'email').checked).toBe(true)
      expect(byId(root, 'hilos-notification-preferences-mandatory')).toBeNull()
      const markB = vi.spyOn(b, 'markPending')
      const markA = vi.spyOn(a, 'markPending')
      toggleOf(root, 'email').click()
      expect(markB).toHaveBeenCalledWith('email')
      expect(markA).not.toHaveBeenCalled()

      fixture.destroy()
      const readB = vi.spyOn(b.channels, 'get')
      b.applySection({ channels: [], mandatoryNote: false })
      expect(readB).not.toHaveBeenCalled()
    })
  })
})
