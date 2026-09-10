import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { act, cleanup, fireEvent, render } from '@testing-library/react'
import {
  ActionError,
  browserValue,
  createSignal,
  type ActionHandle,
  type ActionLifecycle,
  type HilosConnection,
} from '@hilos/core'

import { HilosPrivacyPage } from '../src/public/HilosPrivacyPage.js'

// The React peer of vue/src/public/HilosPrivacyPage.test.ts: the same cases by
// the same names, so a drift between the two view layers shows up as one of them
// failing rather than as a page nobody compared. The modal portals to <body>, so
// its assertions query the document rather than the render.
afterEach(() => {
  cleanup()
  document.body.classList.remove('modal-open')
})

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

function byId(id: string): HTMLElement | null {
  return document.querySelector(`[data-id="${id}"]`)
}

/** Opens the confirmation and takes its dangerous answer, settling the reply. */
async function erase(): Promise<void> {
  fireEvent.click(byId('privacy-erase') as HTMLElement)
  await act(async () => {
    fireEvent.click(byId('privacy-erase-confirm') as HTMLElement)
  })
}

describe('HilosPrivacyPage', () => {
  it('offers the erase without asking anything of the browser first', () => {
    render(
      <HilosPrivacyPage
        connection={fakeConnection()}
        actions={fakeActions(Promise.resolve({}))}
      />,
    )

    expect(byId('privacy-erase')).not.toBeNull()
    expect(byId('privacy-erase-done')).toBeNull()
    // Never disabled, in any state: the browser half runs offline just as well.
    expect((byId('privacy-erase') as HTMLButtonElement).disabled).toBe(false)
  })

  it('names every registry entry in the confirmation, the project’s beside the framework’s', () => {
    render(
      <HilosPrivacyPage
        connection={fakeConnection()}
        actions={fakeActions(Promise.resolve({}))}
        values={[
          browserValue({
            store: 'local',
            key: 'demo.theme',
            label: 'The theme this browser was last set to',
          }),
        ]}
      />,
    )
    fireEvent.click(byId('privacy-erase') as HTMLElement)

    const listed = [
      ...document.querySelectorAll('[data-id="privacy-erase-list"] li'),
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

    render(<HilosPrivacyPage connection={connection} actions={actions} />)
    await erase()

    expect(sessionStorage.getItem('hilos.oauth.provider')).toBeNull()
    expect(localStorage.getItem('hilos.protectedMode.hint')).toBeNull()
    expect(order).toEqual(['forget', 'dispatch'])
  })

  it('becomes the outcome, naming what went and that the account did not', async () => {
    render(
      <HilosPrivacyPage
        connection={fakeConnection()}
        actions={fakeActions(Promise.resolve({}))}
      />,
    )
    await erase()

    const done = byId('privacy-erase-done')
    expect(done).not.toBeNull()
    expect(byId('privacy-erase')).toBeNull()
    expect(done?.textContent).toContain('a new one begun')
    expect(done?.textContent).toContain('not account deletion')
    expect(byId('privacy-erase-partial')).toBeNull()
  })

  it('tells the truth in parts when the server half refused', async () => {
    render(
      <HilosPrivacyPage
        connection={fakeConnection()}
        actions={fakeActions(
          Promise.reject(
            new ActionError('hilos_browser_erase', 'fail', 'Session is gone'),
          ),
        )}
      />,
    )
    await erase()

    expect(byId('privacy-erase-partial')?.textContent).toContain(
      'Session is gone',
    )
    // What the browser half did stands: the outcome still names it.
    expect(byId('privacy-erase-done')?.textContent).toContain('were erased')
  })
})
