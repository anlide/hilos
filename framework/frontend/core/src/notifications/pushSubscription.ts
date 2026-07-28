// Push-subscription client: the framework-owned reactive store behind the profile
// "Push on this device" toggle (HIL-199). Web push opt-in is per-device browser
// state — unlike the per-user channel preference (notificationPreferences.ts), it
// is not a shared value fanned across a user's connections but the truth the local
// browser's PushManager holds, so this store reflects getSubscription() rather than
// a server snapshot and neither the subscribe nor the unsubscribe action fans a
// signal. The store is SDK-agnostic and drives one shared thin toggle per SDK; all
// the browser surface (service-worker registration, Notification permission, the
// PushManager) sits behind an injected HilosPushEnvironment so the store is pure,
// testable logic and the same code runs in every SDK. The VAPID application-server
// public key it subscribes with arrives as the push channel row's `config` in the
// profile notification section (notificationPreferences.ts); the view passes it to
// enable(). The delivery agent signs every send with the matching private key
// (env-only, server-side), so the browser must subscribe with exactly this key.
import {
  createSignal,
  type ReadonlySignal,
  type WritableSignal,
} from '../state/signal.js'

/**
 * Client→server action registering the acting device's push subscription, mounted
 * on the profile page (PHP `PushSubscriptionAction::SUBSCRIBE`). Payload is the
 * browser endpoint, its two encryption keys, and the device user agent; the acting
 * user is resolved server-side from the connection, never carried.
 */
export const PUSH_ACTION_SUBSCRIBE = 'push_subscribe'

/**
 * Client→server action removing a device's push subscription (PHP
 * `PushSubscriptionAction::UNSUBSCRIBE`). Payload is the endpoint; the row is
 * deleted server-side.
 */
export const PUSH_ACTION_UNSUBSCRIBE = 'push_unsubscribe'

/**
 * The device's push permission state, or `unsupported` when the browser lacks the
 * service-worker / PushManager / Notification APIs. `denied` is terminal — the
 * browser will not re-prompt — so the toggle shows a blocked hint rather than
 * retrying.
 */
export type HilosPushPermission =
  | 'unsupported'
  | 'default'
  | 'granted'
  | 'denied'

/**
 * A browser push subscription reduced to the wire fields the subscribe action
 * carries: the endpoint URL and the two base64url encryption keys (p256dh public
 * key and auth secret) the delivery agent needs to encrypt a payload for it.
 */
export interface HilosPushSubscriptionSnapshot {
  /** The browser push endpoint URL. */
  readonly endpoint: string
  /** The client public key (base64url). */
  readonly p256dh: string
  /** The client auth secret (base64url). */
  readonly auth: string
}

/**
 * The browser surface the push store drives, injected so the store stays pure,
 * testable logic. The default {@link createBrowserPushEnvironment} binds it to the
 * real service worker, Notification permission, and PushManager; a test passes a
 * fake.
 */
export interface HilosPushEnvironment {
  /** Whether this browser has the service-worker, PushManager, and Notification APIs. */
  isSupported(): boolean
  /** The current permission state without prompting. */
  permission(): HilosPushPermission
  /** The subscribing device's user agent, or null when unavailable. */
  userAgent(): string | null
  /**
   * Prompt for notification permission (a user gesture must be in progress).
   *
   * @returns The resulting permission state.
   */
  requestPermission(): Promise<HilosPushPermission>
  /**
   * Read this device's current push subscription, if any.
   *
   * @returns The subscription snapshot, or null when the device is not subscribed.
   */
  getSubscription(): Promise<HilosPushSubscriptionSnapshot | null>
  /**
   * Subscribe this device to push with the given application-server public key.
   *
   * @param applicationServerKey The VAPID public key (base64url) to subscribe with.
   * @returns The new subscription's snapshot.
   */
  subscribe(
    applicationServerKey: string,
  ): Promise<HilosPushSubscriptionSnapshot>
  /** Unsubscribe this device's current push subscription, if any. */
  unsubscribe(): Promise<void>
}

/**
 * The minimal connection surface the push store sends its opt-in/out actions over —
 * structurally satisfied by a full `HilosConnection`, so a test passes a stub.
 */
export interface HilosPushActionSender {
  /**
   * Send a client→server action.
   *
   * @param action The action name.
   * @param data The action payload.
   * @returns True when the action left over a live connection.
   */
  sendAction(action: string, data: unknown): boolean
}

/** The reactive push-subscription state the profile device toggle renders. */
export interface HilosPushSubscriptionStore {
  /** Whether this browser can do web push at all; the toggle hides when false. */
  readonly supported: ReadonlySignal<boolean>
  /** The device's permission state; `denied` shows a blocked hint, not a retry. */
  readonly permission: ReadonlySignal<HilosPushPermission>
  /** Whether this device currently holds a push subscription (reflects the browser). */
  readonly subscribed: ReadonlySignal<boolean>
  /** Whether an opt-in/out is mid-flight, so the toggle shows a loader and disables. */
  readonly busy: ReadonlySignal<boolean>
  /**
   * Read the live browser state into the store (support, permission, subscription).
   * The view calls this on mount so the toggle reflects the device's real state.
   */
  refresh(): Promise<void>
  /**
   * Opt this device in: prompt for permission, subscribe with the VAPID public key,
   * and register the subscription server-side. A denied prompt or an empty key
   * leaves the device unsubscribed; if the register action never leaves (no live
   * connection) the just-created browser subscription is rolled back so browser and
   * server stay consistent.
   *
   * @param connection The connection the subscribe action is sent over.
   * @param vapidPublicKey The push channel's VAPID public key (base64url) from the section config.
   * @returns True when the device is subscribed and the server was told.
   */
  enable(
    connection: HilosPushActionSender,
    vapidPublicKey: string,
  ): Promise<boolean>
  /**
   * Opt this device out: unsubscribe the browser and tell the server to drop the
   * row. The browser is unsubscribed even if the action does not leave — a stale
   * server row then self-heals when its next send returns gone.
   *
   * @param connection The connection the unsubscribe action is sent over.
   * @returns True once the device is unsubscribed.
   */
  disable(connection: HilosPushActionSender): Promise<boolean>
  /** Reset to defaults (section unmount, sign-out); does not touch the browser. */
  clear(): void
}

/**
 * Create an independent push-subscription store over a browser environment.
 *
 * Applications use the shared {@link hilosPushSubscription}; this factory exists so
 * a test passes a fake {@link HilosPushEnvironment} and a second window gets its own
 * state. Signals start at safe defaults and are populated by {@link
 * HilosPushSubscriptionStore.refresh}, so constructing the store never touches the
 * browser (import-safe in a non-browser environment).
 *
 * @param environment The browser surface to drive; defaults to the real browser.
 * @returns A fresh store.
 */
export function createHilosPushSubscriptionStore(
  environment: HilosPushEnvironment = createBrowserPushEnvironment(),
): HilosPushSubscriptionStore {
  const supported = createSignal(false)
  const permission: WritableSignal<HilosPushPermission> =
    createSignal<HilosPushPermission>('default')
  const subscribed = createSignal(false)
  const busy = createSignal(false)

  return {
    supported,
    permission,
    subscribed,
    busy,
    async refresh() {
      if (!environment.isSupported()) {
        supported.set(false)
        permission.set('unsupported')
        subscribed.set(false)

        return
      }
      supported.set(true)
      permission.set(environment.permission())
      subscribed.set((await environment.getSubscription()) !== null)
    },
    async enable(connection, vapidPublicKey) {
      if (!environment.isSupported()) {
        supported.set(false)
        permission.set('unsupported')

        return false
      }
      if (vapidPublicKey === '') {
        return false
      }
      busy.set(true)
      try {
        const granted = await environment.requestPermission()
        permission.set(granted)
        if (granted !== 'granted') {
          return false
        }
        const snapshot = await environment.subscribe(vapidPublicKey)
        const sent = connection.sendAction(PUSH_ACTION_SUBSCRIBE, {
          endpoint: snapshot.endpoint,
          p256dh: snapshot.p256dh,
          auth: snapshot.auth,
          userAgent: environment.userAgent(),
        })
        if (!sent) {
          await environment.unsubscribe()
          subscribed.set(false)

          return false
        }
        subscribed.set(true)

        return true
      } finally {
        busy.set(false)
      }
    },
    async disable(connection) {
      if (!environment.isSupported()) {
        return false
      }
      busy.set(true)
      try {
        const current = await environment.getSubscription()
        await environment.unsubscribe()
        subscribed.set(false)
        if (current !== null) {
          connection.sendAction(PUSH_ACTION_UNSUBSCRIBE, {
            endpoint: current.endpoint,
          })
        }

        return true
      } finally {
        busy.set(false)
      }
    },
    clear() {
      supported.set(false)
      permission.set('default')
      subscribed.set(false)
      busy.set(false)
    },
  }
}

/** Default service-worker path the browser environment registers and scopes at `/`. */
const DEFAULT_SERVICE_WORKER_PATH = '/sw.js'

/** Options for {@link createBrowserPushEnvironment}. */
export interface BrowserPushEnvironmentOptions {
  /** The service-worker script path to register; defaults to `/sw.js` (scope `/`). */
  readonly serviceWorkerPath?: string
}

/**
 * Bind a {@link HilosPushEnvironment} to the real browser.
 *
 * Registers the service worker lazily (once, cached) and reads permission and
 * subscriptions through its PushManager. All global access is inside the methods,
 * so constructing the environment is side-effect free and import-safe; a method
 * called in a non-supporting browser is guarded by {@link HilosPushEnvironment.isSupported}.
 *
 * @param options The service-worker path override.
 * @returns A browser-backed push environment.
 */
export function createBrowserPushEnvironment(
  options: BrowserPushEnvironmentOptions = {},
): HilosPushEnvironment {
  const serviceWorkerPath =
    options.serviceWorkerPath ?? DEFAULT_SERVICE_WORKER_PATH
  let registration: Promise<ServiceWorkerRegistration> | null = null

  function ensureRegistration(): Promise<ServiceWorkerRegistration> {
    if (registration === null) {
      registration = navigator.serviceWorker.register(serviceWorkerPath)
    }

    return registration
  }

  function toSnapshot(
    subscription: PushSubscription | null,
  ): HilosPushSubscriptionSnapshot | null {
    if (subscription === null) {
      return null
    }

    return {
      endpoint: subscription.endpoint,
      p256dh: encodeKey(subscription.getKey('p256dh')),
      auth: encodeKey(subscription.getKey('auth')),
    }
  }

  return {
    isSupported() {
      return (
        typeof navigator !== 'undefined' &&
        'serviceWorker' in navigator &&
        typeof window !== 'undefined' &&
        'PushManager' in window &&
        'Notification' in window
      )
    },
    permission() {
      if (!this.isSupported()) {
        return 'unsupported'
      }

      return Notification.permission as HilosPushPermission
    },
    userAgent() {
      return typeof navigator === 'undefined' ? null : navigator.userAgent
    },
    async requestPermission() {
      return (await Notification.requestPermission()) as HilosPushPermission
    },
    async getSubscription() {
      const active = await ensureRegistration()

      return toSnapshot(await active.pushManager.getSubscription())
    },
    async subscribe(applicationServerKey) {
      const active = await ensureRegistration()
      const subscription = await active.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: decodeApplicationServerKey(applicationServerKey),
      })
      const snapshot = toSnapshot(subscription)
      if (snapshot === null) {
        // The PushManager just returned a subscription; it always has keys.
        throw new Error('push subscription is missing its encryption keys')
      }

      return snapshot
    },
    async unsubscribe() {
      const active = await ensureRegistration()
      const subscription = await active.pushManager.getSubscription()
      await subscription?.unsubscribe()
    },
  }
}

/**
 * Encode a subscription key (a raw ArrayBuffer) as base64url, the form the backend
 * decodes.
 *
 * @param key The raw key bytes, or null when the browser omitted it.
 * @returns The base64url string, or empty when the key is absent.
 */
function encodeKey(key: ArrayBuffer | null): string {
  if (key === null) {
    return ''
  }
  const bytes = new Uint8Array(key)
  let binary = ''
  for (const byte of bytes) {
    binary += String.fromCharCode(byte)
  }

  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
}

/**
 * Decode a base64url VAPID public key into the byte view PushManager.subscribe
 * wants as its application-server key.
 *
 * @param base64url The VAPID public key (base64url).
 * @returns The key bytes over a plain ArrayBuffer (the application-server key form).
 */
function decodeApplicationServerKey(
  base64url: string,
): Uint8Array<ArrayBuffer> {
  const padding = '='.repeat((4 - (base64url.length % 4)) % 4)
  const base64 = (base64url + padding).replace(/-/g, '+').replace(/_/g, '/')
  const binary = atob(base64)
  const bytes = new Uint8Array(new ArrayBuffer(binary.length))
  for (let i = 0; i < binary.length; i += 1) {
    bytes[i] = binary.charCodeAt(i)
  }

  return bytes
}

/**
 * The application-wide push-subscription store.
 *
 * One per loaded SDK: the profile device toggle renders it and drives it against the
 * real browser. A test that needs isolation builds its own with {@link
 * createHilosPushSubscriptionStore} and a fake environment.
 */
export const hilosPushSubscription: HilosPushSubscriptionStore =
  createHilosPushSubscriptionStore()
