// The Angular peer of react/test/HilosSecuritySignInMethodsPage.test.tsx and
// vue/src/admin/security/HilosSecuritySignInMethodsPage.test.ts (HIL-427): the
// switch reads the live enabled set the session scope holds, not the row the table
// drew, and a click dispatches the one-method switch as a tracked action — a
// refusal puts the clicked box back to what the set still says.
//
// The world below — the connection, the action lifecycle and the row on the wire —
// is the React peer's. What is Angular's own is the mount (TestBed) and the fact
// that a frame is read by running change detection rather than by awaiting a tick;
// and here the refusal matters more than in React, because a property binding
// whose value did not change never writes the box back by itself.
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  ActionError,
  createSignal,
  HilosPages,
  hilosToasts,
  ScopeManager,
  type ActionHandle,
  type ActionLifecycle,
  type ActionResult,
  type HilosConnection,
  type HilosRouter,
  type PageRouteMatch,
} from '@hilos/core'
import { afterEach, describe, expect, it } from 'vitest'

import { HilosSecuritySignInMethodsPage } from '../src/admin/security/HilosSecuritySignInMethodsPage.js'
import { HILOS_ROUTER } from '../src/hilosRouterToken.js'

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
  } as unknown as HilosRouter
}

/**
 * A connection stub that hands the page a window of the methods table.
 *
 * @returns The connection plus the push a case drives it with.
 */
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

/**
 * An action lifecycle that records what was dispatched and leaves it open.
 *
 * @returns The lifecycle to mount with, and the log the assertions read.
 */
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
 *
 * @returns The scope manager to mount with.
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
 * Mount the screen with the router an app provides it with. `context` is a
 * required input, so it holds a value before the first change detection reads it.
 *
 * @param connection The connection the window arrives over.
 * @param scopes The scope manager holding the page scope and the live set.
 * @param actions The action lifecycle a dispatch travels through.
 * @returns The mounted fixture.
 */
function mountPage(
  connection: HilosConnection,
  scopes: ScopeManager,
  actions: ActionLifecycle = makeActions().actions,
): ComponentFixture<HilosSecuritySignInMethodsPage> {
  TestBed.configureTestingModule({
    providers: [{ provide: HILOS_ROUTER, useValue: router() }],
  })

  const fixture = TestBed.createComponent(HilosSecuritySignInMethodsPage)
  fixture.componentRef.setInput('context', { connection, scopes, actions })
  fixture.detectChanges()

  return fixture
}

/**
 * Every switch drawn for a method: the table on a wide screen and the card on a
 * narrow one both carry it.
 *
 * @param fixture The mounted screen to look inside.
 * @param methodKey The method the switch is for.
 * @returns The switches, in document order.
 */
function switchesOf(
  fixture: ComponentFixture<HilosSecuritySignInMethodsPage>,
  methodKey: string,
): HTMLInputElement[] {
  return Array.from(
    (fixture.nativeElement as HTMLElement).querySelectorAll<HTMLInputElement>(
      `[data-id="hilos-sign-in-method-enabled-${methodKey}"]`,
    ),
  )
}

/**
 * Let the microtasks a settled action resolves through run out, then render.
 *
 * @param fixture The mounted screen to flush the render of.
 */
async function settled(
  fixture: ComponentFixture<HilosSecuritySignInMethodsPage>,
): Promise<void> {
  for (let tick = 0; tick < 10; tick += 1) {
    await Promise.resolve()
  }
  fixture.detectChanges()
}

describe('HilosSecuritySignInMethodsPage', () => {
  afterEach(() => hilosToasts.clear())

  it('draws each switch from the live set, not from the row', () => {
    const { connection, pushWindow } = makeConnection()
    const scopes = makeScopes()
    const fixture = mountPage(connection, scopes)

    pushWindow(METHOD_ROWS)
    fixture.detectChanges()

    const smsSwitches = switchesOf(fixture, 'sms')
    expect(smsSwitches).toHaveLength(2)
    expect(new Set(smsSwitches.map((box) => box.id)).size).toBe(2)
    for (const box of smsSwitches) {
      expect(box.checked).toBe(true)
    }
    for (const box of switchesOf(fixture, 'passkey')) {
      expect(box.checked).toBe(false)
    }

    // The set moves, whichever door moved it: the switches follow it.
    scopes.session.data.set('authMethods', [{ key: 'passkey', name: null }])
    fixture.detectChanges()

    for (const box of switchesOf(fixture, 'sms')) {
      expect(box.checked).toBe(false)
    }
    for (const box of switchesOf(fixture, 'passkey')) {
      expect(box.checked).toBe(true)
    }
  })

  it('dispatches the one-method switch and stays on the set when refused', async () => {
    const { connection, pushWindow } = makeConnection()
    const { actions, dispatched } = makeActions()
    const fixture = mountPage(connection, makeScopes(), actions)

    pushWindow(METHOD_ROWS)
    fixture.detectChanges()
    const [smsSwitch] = switchesOf(fixture, 'sms')
    smsSwitch?.click()
    fixture.detectChanges()

    expect(dispatched).toMatchObject([
      {
        action: 'security_sign_in_method_set',
        payload: { methodKey: 'sms', enabled: false },
      },
    ])
    // In flight: every switch waits for the answer.
    for (const box of switchesOf(fixture, 'passkey')) {
      expect(box.disabled).toBe(true)
    }
    for (const box of switchesOf(fixture, 'sms')) {
      expect(box.checked).toBe(true)
    }

    dispatched[0]?.refuse(
      new ActionError(
        'security_sign_in_method_set',
        'fail',
        'At least one sign-in method must stay on.',
      ),
    )
    await settled(fixture)

    expect(smsSwitch?.checked).toBe(true)
    expect(smsSwitch?.disabled).toBe(false)
  })
})
