// The sign-in methods page (HIL-427): the switch reads the live enabled set the
// session scope holds, not the row the table drew, and a click dispatches the
// one-method switch as a tracked action — a refusal puts the clicked box back to
// what the set still says. The passkey policy switch under the table (HIL-1105) is
// drawn only beside a passkey row, follows the live value rather than the click,
// and waits while a method write is in flight.
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import { afterEach, describe, expect, it } from 'vitest'
import {
  ActionError,
  HilosPages,
  ScopeManager,
  createSignal,
  hilosToasts,
} from '@hilos/core'
import type {
  ActionHandle,
  ActionLifecycle,
  ActionResult,
  HilosConnection,
  HilosRouter,
  PageRouteMatch,
} from '@hilos/core'

import HilosSecuritySignInMethodsPage from './HilosSecuritySignInMethodsPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'

function router(): HilosRouter {
  return {
    currentRoute: createSignal<PageRouteMatch>({
      page: HilosPages.SECURITY_SIGN_IN_METHODS,
      params: {},
      admin: true,
    }),
    currentPath: createSignal(''),
    currentTitle: createSignal(''),
    pageError: createSignal(null),
    pageLoading: createSignal(false),
    pageIdentity: createSignal(undefined),
    dashboardSections: createSignal(undefined),
    resolvePath: () => undefined,
    clearPageError: () => {},
    denyCurrentPage: () => {},
    awaitPageAnswer: () => {},
    navigate: () => {},
    replacePath: () => {},
    start: () => {},
    stop: () => {},
  }
}

/** A connection stub that hands the page a window of the methods table. */
function makeConnection(): {
  connection: HilosConnection
  pushWindow: (slots: Record<string, unknown>[]) => void
} {
  const windowListeners: ((signal: { data: unknown }) => void)[] = []
  const connection = {
    on(event: string, listener: (signal: never) => void): () => void {
      if (event === 'tableWindow') {
        windowListeners.push(
          listener as unknown as (signal: { data: unknown }) => void,
        )
      }

      return () => {}
    },
    registerTableWindow(): void {},
    unregisterTableWindow(): void {},
    tableWindowDescriptors: () => ({}),
    sendTableViewport(): void {},
    sendTableRendered(): void {},
  } as unknown as HilosConnection

  return {
    connection,
    pushWindow(slots: Record<string, unknown>[]): void {
      for (const listener of windowListeners) {
        listener({
          data: {
            page: HilosPages.SECURITY_SIGN_IN_METHODS,
            tableKey: 'hilosSecuritySignInMethods',
            rows: slots.map((method) => ({
              rowKey: String(method.methodKey),
              slots: { method },
            })),
            totalCount: slots.length,
          },
        })
      }
    },
  }
}

/** One dispatched action, held open so the test decides the backend's answer. */
interface Dispatched {
  action: string
  payload: Record<string, unknown>
  refuse: (error: ActionError) => void
}

/** An action lifecycle that records what was dispatched and leaves it open. */
function makeActions(): {
  actions: ActionLifecycle
  dispatched: Dispatched[]
} {
  const dispatched: Dispatched[] = []
  const actions = {
    dispatch(action: string, payload: Record<string, unknown>): ActionHandle {
      let refuse: (error: ActionError) => void = () => {}
      const done = new Promise<ActionResult>((_resolve, reject) => {
        refuse = reject
      })
      dispatched.push({ action, payload, refuse })

      return {
        requestId: String(dispatched.length),
        loading: createSignal(false),
        done,
      }
    },
  } as unknown as ActionLifecycle

  return { actions, dispatched }
}

/**
 * The page scope the window is normalized into, and the session scope holding the
 * live set the way a handshake puts it there: password and sms are on.
 */
function makeScopes(): ScopeManager {
  const scopes = new ScopeManager()
  scopes.openPage(HilosPages.SECURITY_SIGN_IN_METHODS)
  scopes.session.data.set('authMethods', [
    { key: 'password', name: null },
    { key: 'sms', name: null },
  ])

  return scopes
}

/**
 * Two rows whose own `enabled` says the opposite of the live set: a switch drawn
 * from the row would show sms off and passkey on.
 */
const METHOD_ROWS: Record<string, unknown>[] = [
  {
    methodKey: 'sms',
    label: 'SMS code',
    enabled: false,
    ready: true,
    providerKey: null,
  },
  {
    methodKey: 'passkey',
    label: 'Passkey',
    enabled: true,
    ready: true,
    providerKey: null,
  },
]

/** The passkey policy switch under the table. */
const PASSKEY_POLICY_SWITCH = '[data-id="hilos-sign-in-passkey-unproven"]'

const mounted: ReturnType<typeof mount>[] = []

afterEach(() => {
  for (const wrapper of mounted.splice(0)) {
    wrapper.unmount()
  }
  hilosToasts.clear()
})

function mountPage(
  connection: HilosConnection,
  scopes: ScopeManager,
  actions: ActionLifecycle = makeActions().actions,
) {
  const wrapper = mount(HilosSecuritySignInMethodsPage, {
    props: { context: { connection, scopes, actions } },
    global: { provide: { [hilosRouterKey as symbol]: router() } },
  })
  mounted.push(wrapper)

  return wrapper
}

/**
 * Every switch drawn for a method: the table on a wide screen and the card on a
 * narrow one both carry it.
 */
function switchesOf(
  wrapper: ReturnType<typeof mountPage>,
  methodKey: string,
): HTMLInputElement[] {
  return wrapper
    .findAll(`[data-id="hilos-sign-in-method-enabled-${methodKey}"]`)
    .map((node) => node.element as HTMLInputElement)
}

/** Wait out the microtasks a settled action resolves through. */
async function settled(): Promise<void> {
  await nextTick()
  await nextTick()
  await nextTick()
}

describe('HilosSecuritySignInMethodsPage', () => {
  it('draws each switch from the live set, not from the row', async () => {
    const { connection, pushWindow } = makeConnection()
    const scopes = makeScopes()
    const wrapper = mountPage(connection, scopes)

    pushWindow(METHOD_ROWS)
    await nextTick()

    const smsSwitches = switchesOf(wrapper, 'sms')
    expect(smsSwitches).toHaveLength(2)
    expect(new Set(smsSwitches.map((box) => box.id)).size).toBe(2)
    for (const box of smsSwitches) {
      expect(box.checked).toBe(true)
    }
    for (const box of switchesOf(wrapper, 'passkey')) {
      expect(box.checked).toBe(false)
    }

    // The set moves, whichever door moved it: the switches follow it.
    scopes.session.data.set('authMethods', [{ key: 'passkey', name: null }])
    await nextTick()

    for (const box of switchesOf(wrapper, 'sms')) {
      expect(box.checked).toBe(false)
    }
    for (const box of switchesOf(wrapper, 'passkey')) {
      expect(box.checked).toBe(true)
    }
  })

  it('dispatches the one-method switch and stays on the set when refused', async () => {
    const { connection, pushWindow } = makeConnection()
    const { actions, dispatched } = makeActions()
    const wrapper = mountPage(connection, makeScopes(), actions)

    pushWindow(METHOD_ROWS)
    await nextTick()
    await wrapper
      .find('[data-id="hilos-sign-in-method-enabled-sms"]')
      .trigger('click')

    expect(dispatched).toMatchObject([
      {
        action: 'security_sign_in_method_set',
        payload: { methodKey: 'sms', enabled: false },
      },
    ])
    // In flight: every switch waits for the answer.
    for (const box of switchesOf(wrapper, 'passkey')) {
      expect(box.disabled).toBe(true)
    }
    for (const box of switchesOf(wrapper, 'sms')) {
      expect(box.checked).toBe(true)
    }

    dispatched[0]?.refuse(
      new ActionError(
        'security_sign_in_method_set',
        'fail',
        'At least one sign-in method must stay on.',
      ),
    )
    await settled()

    const [smsSwitch] = switchesOf(wrapper, 'sms')
    expect(smsSwitch?.checked).toBe(true)
    expect(smsSwitch?.disabled).toBe(false)
  })

  it('draws no passkey policy switch without a passkey row (HIL-1105)', async () => {
    const { connection, pushWindow } = makeConnection()
    const wrapper = mountPage(connection, makeScopes())

    pushWindow(METHOD_ROWS.filter((row) => row.methodKey !== 'passkey'))
    await nextTick()

    expect(wrapper.find(PASSKEY_POLICY_SWITCH).exists()).toBe(false)
  })

  it('draws the passkey policy switch from the session and dispatches only the flag (HIL-1105)', async () => {
    const { connection, pushWindow } = makeConnection()
    const { actions, dispatched } = makeActions()
    const scopes = makeScopes()
    const wrapper = mountPage(connection, scopes, actions)

    pushWindow(METHOD_ROWS)
    await nextTick()

    const policy = (): HTMLInputElement =>
      wrapper.find(PASSKEY_POLICY_SWITCH).element as HTMLInputElement
    expect(policy().checked).toBe(false)

    scopes.session.data.set('passkeyAllowsUnproven', true)
    await nextTick()
    expect(policy().checked).toBe(true)

    await wrapper.find(PASSKEY_POLICY_SWITCH).trigger('click')

    expect(dispatched).toMatchObject([
      { action: 'security_passkey_unproven_set', payload: { allowed: false } },
    ])
    // Nothing optimistic: the switch stays on the live value until it moves.
    expect(policy().checked).toBe(true)
    for (const box of switchesOf(wrapper, 'sms')) {
      expect(box.disabled).toBe(true)
    }

    dispatched[0]?.refuse(
      new ActionError('security_passkey_unproven_set', 'fail', 'Refused.'),
    )
    await settled()

    expect(policy().checked).toBe(true)
    expect(policy().disabled).toBe(false)
  })

  it('holds the passkey policy switch while a method write is in flight (HIL-1105)', async () => {
    const { connection, pushWindow } = makeConnection()
    const { actions } = makeActions()
    const wrapper = mountPage(connection, makeScopes(), actions)

    pushWindow(METHOD_ROWS)
    await nextTick()
    await wrapper
      .find('[data-id="hilos-sign-in-method-enabled-sms"]')
      .trigger('click')

    expect(
      (wrapper.find(PASSKEY_POLICY_SWITCH).element as HTMLInputElement)
        .disabled,
    ).toBe(true)
  })
})
