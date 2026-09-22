// The sign-in methods page (HIL-427): the switch reads the live enabled set the
// session scope holds, not the row the table drew, and a click dispatches the
// one-method switch as a tracked action — a refusal leaves the switch on what the
// set still says.
import { afterEach, describe, expect, it } from 'vitest'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
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

import { HilosSecuritySignInMethodsPage } from '../src/admin/security/HilosSecuritySignInMethodsPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'

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
      act(() => {
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
      })
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

/**
 * Every switch drawn for a method: the table on a wide screen and the card on a
 * narrow one both carry it.
 */
function switchesOf(
  container: HTMLElement,
  methodKey: string,
): HTMLInputElement[] {
  return Array.from(
    container.querySelectorAll<HTMLInputElement>(
      `[data-id="hilos-sign-in-method-enabled-${methodKey}"]`,
    ),
  )
}

/** Wait out the microtasks a settled action resolves through. */
async function settled(): Promise<void> {
  await act(async () => {
    await Promise.resolve()
  })
}

describe('HilosSecuritySignInMethodsPage', () => {
  afterEach(() => {
    cleanup()
    hilosToasts.clear()
  })

  it('draws each switch from the live set, not from the row', () => {
    const { connection, pushWindow } = makeConnection()
    const scopes = makeScopes()
    const { container } = render(
      <HilosRouterContext.Provider value={router()}>
        <HilosSecuritySignInMethodsPage
          context={{ connection, scopes, actions: makeActions().actions }}
        />
      </HilosRouterContext.Provider>,
    )

    pushWindow(METHOD_ROWS)

    const smsSwitches = switchesOf(container, 'sms')
    expect(smsSwitches).toHaveLength(2)
    expect(new Set(smsSwitches.map((box) => box.id)).size).toBe(2)
    for (const box of smsSwitches) {
      expect(box.checked).toBe(true)
    }
    for (const box of switchesOf(container, 'passkey')) {
      expect(box.checked).toBe(false)
    }

    // The set moves, whichever door moved it: the switches follow it.
    act(() => {
      scopes.session.data.set('authMethods', [{ key: 'passkey', name: null }])
    })

    for (const box of switchesOf(container, 'sms')) {
      expect(box.checked).toBe(false)
    }
    for (const box of switchesOf(container, 'passkey')) {
      expect(box.checked).toBe(true)
    }
  })

  it('dispatches the one-method switch and stays on the set when refused', async () => {
    const { connection, pushWindow } = makeConnection()
    const { actions, dispatched } = makeActions()
    const { container } = render(
      <HilosRouterContext.Provider value={router()}>
        <HilosSecuritySignInMethodsPage
          context={{ connection, scopes: makeScopes(), actions }}
        />
      </HilosRouterContext.Provider>,
    )

    pushWindow(METHOD_ROWS)
    const [smsSwitch] = switchesOf(container, 'sms')
    fireEvent.click(smsSwitch as HTMLInputElement)

    expect(dispatched).toMatchObject([
      {
        action: 'security_sign_in_method_set',
        payload: { methodKey: 'sms', enabled: false },
      },
    ])
    // In flight: every switch waits for the answer.
    for (const box of switchesOf(container, 'passkey')) {
      expect(box.disabled).toBe(true)
    }

    dispatched[0]?.refuse(
      new ActionError(
        'security_sign_in_method_set',
        'fail',
        'At least one sign-in method must stay on.',
      ),
    )
    await settled()

    for (const box of switchesOf(container, 'sms')) {
      expect(box.checked).toBe(true)
      expect(box.disabled).toBe(false)
    }
  })
})
