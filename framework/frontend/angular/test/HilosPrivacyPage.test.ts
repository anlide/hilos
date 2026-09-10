// The Angular peer of vue/src/public/HilosPrivacyPage.test.ts and
// react/test/HilosPrivacyPage.test.tsx: the same cases by the same names, so a
// drift between the three view layers shows up as one of them failing rather
// than as a page nobody compared. What is Angular's own is the mount (TestBed),
// the fact that a frame is read by running change detection, and that the modal
// renders in place rather than through a portal — one root covers the page and
// its dialog alike.
import { TestBed, type ComponentFixture } from '@angular/core/testing'
import {
  ActionError,
  browserValue,
  createSignal,
  type ActionHandle,
  type ActionLifecycle,
  type HilosBrowserValue,
  type HilosConnection,
} from '@hilos/core'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { HilosPrivacyPage } from '../src/public/HilosPrivacyPage.js'

beforeEach(() => {
  sessionStorage.clear()
  localStorage.clear()
})

/** A handle whose reply is already settled, so a click resolves within the tick. */
function handle(done: Promise<{ message?: string }>): ActionHandle {
  return { requestId: '1', loading: createSignal(false), done }
}

/** The connection the page reads a cookie name from and drops a pass on. */
function fakeConnection(): HilosConnection {
  return {
    sessionCookieName: 'hilos_session_token',
    forgetProtectedModePass: vi.fn(),
  } as unknown as HilosConnection
}

/** A lifecycle that records the dispatch and answers it however the test says. */
function fakeActions(done: Promise<{ message?: string }>): ActionLifecycle {
  return {
    dispatch: vi.fn(() => handle(done)),
  } as unknown as ActionLifecycle
}

/**
 * Mount the page with the connection and lifecycle a project hands it.
 *
 * @param connection The connection the erase acts on.
 * @param actions The lifecycle the erase is dispatched on.
 * @param values The project's own declarations, if it has any.
 * @returns The mounted fixture, already rendered once.
 */
function mountPage(
  connection: HilosConnection,
  actions: ActionLifecycle,
  values: readonly HilosBrowserValue[] = [],
): ComponentFixture<HilosPrivacyPage> {
  const fixture = TestBed.createComponent(HilosPrivacyPage)
  fixture.componentRef.setInput('connection', connection)
  fixture.componentRef.setInput('actions', actions)
  fixture.componentRef.setInput('values', values)
  fixture.detectChanges()

  return fixture
}

/**
 * Find a node of the mounted page by its stable test id.
 *
 * @param fixture The mounted page to look inside.
 * @param id The `data-id` the page renders on the node.
 * @returns The node, or null when this state does not render it.
 */
function byId(
  fixture: ComponentFixture<HilosPrivacyPage>,
  id: string,
): HTMLElement | null {
  return (fixture.nativeElement as HTMLElement).querySelector(
    `[data-id="${id}"]`,
  )
}

/**
 * Open the confirmation and take its dangerous answer, settling the reply.
 *
 * @param fixture The mounted page to click inside.
 */
async function erase(
  fixture: ComponentFixture<HilosPrivacyPage>,
): Promise<void> {
  byId(fixture, 'privacy-erase')?.click()
  fixture.detectChanges()
  byId(fixture, 'privacy-erase-confirm')?.click()
  await fixture.whenStable()
  fixture.detectChanges()
}

describe('HilosPrivacyPage', () => {
  it('offers the erase without asking anything of the browser first', () => {
    const fixture = mountPage(
      fakeConnection(),
      fakeActions(Promise.resolve({})),
    )

    expect(byId(fixture, 'privacy-erase')).not.toBeNull()
    expect(byId(fixture, 'privacy-erase-done')).toBeNull()
    // Never disabled, in any state: the browser half runs offline just as well.
    expect((byId(fixture, 'privacy-erase') as HTMLButtonElement).disabled).toBe(
      false,
    )
  })

  it('names every registry entry in the confirmation, the project’s beside the framework’s', () => {
    const fixture = mountPage(
      fakeConnection(),
      fakeActions(Promise.resolve({})),
      [
        browserValue({
          store: 'local',
          key: 'demo.theme',
          label: 'The theme this browser was last set to',
        }),
      ],
    )
    byId(fixture, 'privacy-erase')?.click()
    fixture.detectChanges()

    const listed = [
      ...(byId(fixture, 'privacy-erase-list') as HTMLElement).querySelectorAll(
        'li',
      ),
    ].map((item) => item.textContent)

    expect(listed).toHaveLength(5)
    expect(listed[4]).toBe('The theme this browser was last set to')
  })

  it('sweeps the browser and drops the live pass before the action goes out', async () => {
    const connection = fakeConnection()
    const order: string[] = []
    const actions = {
      dispatch: vi.fn(() => {
        order.push('dispatch')

        return handle(Promise.resolve({}))
      }),
    } as unknown as ActionLifecycle
    vi.mocked(connection.forgetProtectedModePass).mockImplementation(() => {
      order.push('forget')
    })
    sessionStorage.setItem('hilos.oauth.provider', 'github')
    localStorage.setItem('hilos.protectedMode.hint', '1')

    const fixture = mountPage(connection, actions)
    await erase(fixture)

    expect(sessionStorage.getItem('hilos.oauth.provider')).toBeNull()
    expect(localStorage.getItem('hilos.protectedMode.hint')).toBeNull()
    expect(order).toEqual(['forget', 'dispatch'])
  })

  it('becomes the outcome, naming what went and that the account did not', async () => {
    const fixture = mountPage(
      fakeConnection(),
      fakeActions(Promise.resolve({})),
    )
    await erase(fixture)

    const done = byId(fixture, 'privacy-erase-done')
    expect(done).not.toBeNull()
    expect(byId(fixture, 'privacy-erase')).toBeNull()
    expect(done?.textContent).toContain('a new one begun')
    expect(done?.textContent).toContain('not account deletion')
    expect(byId(fixture, 'privacy-erase-partial')).toBeNull()
  })

  it('tells the truth in parts when the server half refused', async () => {
    const fixture = mountPage(
      fakeConnection(),
      fakeActions(
        Promise.reject(
          new ActionError('hilos_browser_erase', 'fail', 'Session is gone'),
        ),
      ),
    )
    await erase(fixture)

    expect(byId(fixture, 'privacy-erase-partial')?.textContent).toContain(
      'Session is gone',
    )
    // What the browser half did stands: the outcome still names it.
    expect(byId(fixture, 'privacy-erase-done')?.textContent).toContain(
      'were erased',
    )
  })
})
