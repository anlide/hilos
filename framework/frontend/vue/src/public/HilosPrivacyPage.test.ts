import { flushPromises, mount } from '@vue/test-utils'
import {
  ActionError,
  browserValue,
  createSignal,
  type ActionHandle,
  type ActionLifecycle,
  type HilosConnection,
} from '@hilos/core'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import HilosPrivacyPage from './HilosPrivacyPage.vue'

// The modal teleports to <body>, so its assertions query the document rather
// than the wrapper — the same as HilosModal's own test.
afterEach(() => {
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
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

beforeEach(() => {
  sessionStorage.clear()
  localStorage.clear()
})

function mountPage(
  connection: HilosConnection,
  actions: ActionLifecycle,
): ReturnType<typeof mount> {
  return mount(HilosPrivacyPage, { props: { connection, actions } })
}

/**
 * Open the confirmation and take its dangerous answer, settling the reply.
 *
 * The settle is the point: the block becomes the outcome only once the server
 * half has answered, so a test that read it a tick after the click would read the
 * offer it is still showing.
 *
 * @param wrapper The mounted page to click inside.
 */
async function erase(wrapper: ReturnType<typeof mount>): Promise<void> {
  await wrapper.find('[data-id="privacy-erase"]').trigger('click')
  document
    .querySelector<HTMLButtonElement>('[data-id="privacy-erase-confirm"]')
    ?.click()
  await flushPromises()
}

describe('HilosPrivacyPage', () => {
  it('offers the erase without asking anything of the browser first', () => {
    const wrapper = mountPage(
      fakeConnection(),
      fakeActions(Promise.resolve({})),
    )

    expect(wrapper.find('[data-id="privacy-erase"]').exists()).toBe(true)
    expect(wrapper.find('[data-id="privacy-erase-done"]').exists()).toBe(false)
    // Never disabled, in any state: the browser half runs offline just as well.
    expect(
      wrapper.find('[data-id="privacy-erase"]').attributes('disabled'),
    ).toBeUndefined()
  })

  it('names every registry entry in the confirmation, the project’s beside the framework’s', async () => {
    const wrapper = mount(HilosPrivacyPage, {
      props: {
        connection: fakeConnection(),
        actions: fakeActions(Promise.resolve({})),
        values: [
          browserValue({
            store: 'local',
            key: 'demo.theme',
            label: 'The theme this browser was last set to',
          }),
        ],
      },
    })
    await wrapper.find('[data-id="privacy-erase"]').trigger('click')

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

    const wrapper = mountPage(connection, actions)
    await erase(wrapper)

    expect(sessionStorage.getItem('hilos.oauth.provider')).toBeNull()
    expect(localStorage.getItem('hilos.protectedMode.hint')).toBeNull()
    expect(order).toEqual(['forget', 'dispatch'])
  })

  it('becomes the outcome, naming what went and that the account did not', async () => {
    const wrapper = mountPage(
      fakeConnection(),
      fakeActions(Promise.resolve({})),
    )
    await erase(wrapper)

    const done = wrapper.find('[data-id="privacy-erase-done"]')
    expect(done.exists()).toBe(true)
    expect(wrapper.find('[data-id="privacy-erase"]').exists()).toBe(false)
    expect(done.text()).toContain('a new one begun')
    expect(done.text()).toContain('not account deletion')
    expect(wrapper.find('[data-id="privacy-erase-partial"]').exists()).toBe(
      false,
    )
  })

  it('tells the truth in parts when the server half refused', async () => {
    const wrapper = mountPage(
      fakeConnection(),
      fakeActions(
        Promise.reject(
          new ActionError('hilos_browser_erase', 'fail', 'Session is gone'),
        ),
      ),
    )
    await erase(wrapper)

    const partial = wrapper.find('[data-id="privacy-erase-partial"]')
    expect(partial.exists()).toBe(true)
    expect(partial.text()).toContain('Session is gone')
    // What the browser half did stands: the outcome still names it.
    expect(wrapper.find('[data-id="privacy-erase-done"]').text()).toContain(
      'were erased',
    )
  })
})
