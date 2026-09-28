// Covers what the profile's security page view owns rather than the core store
// (HIL-494): a form sent again while its action is out dispatches nothing, the
// step a modal moves to takes the focus, and backup codes shown once ask before
// the modal closes. Connecting an app is a protected operation (HIL-1138): the
// modal opens on the server's word, at the confirmation step or at the name.
import {
  createSignal,
  ScopeManager,
  type ActionHandle,
  type ActionLifecycle,
  type HilosConnection,
  type ProjectSignal,
} from '@hilos/core'
import {
  enableAutoUnmount,
  flushPromises,
  mount,
  type VueWrapper,
} from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'
import { nextTick } from 'vue'

import HilosProfileSecurityPage from './HilosProfileSecurityPage.vue'

// The modals teleport to <body>, so assertions query the document.
afterEach(() => {
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
})

enableAutoUnmount(afterEach)

/** A section with no app connected yet, as the group frame carries it. */
const SECTION_OFF = {
  authenticators: [],
  backupCodesLeft: 0,
  backupCodesTotal: 0,
  required: false,
  resetWait: {
    days: 8,
    pendingDays: null,
    pendingFrom: null,
    defaultDays: 8,
    minDays: 1,
    maxDays: 30,
  },
  reset: null,
}

/** The section with one app connected, as the group frame carries it. */
const SECTION_ON = {
  ...SECTION_OFF,
  authenticators: [{ id: 1, label: 'Phone', createdAt: 0, lastUsedAt: null }],
  backupCodesLeft: 10,
  backupCodesTotal: 10,
}

/** The replies the server gives, by action name. */
const REPLIES: Record<string, unknown> = {
  hilos_step_up_start: { required: false, purpose: 'add an authenticator app' },
  profile_second_factor_enroll_start: {
    authenticatorId: 3,
    secret: 'JBSWY3DPEHPK3PXP',
    otpauthUri: 'otpauth://totp/Hilos:a@b.test?secret=JBSWY3DPEHPK3PXP',
  },
  profile_second_factor_enroll_confirm: { backupCodes: ['abcde-fghjk'] },
}

/**
 * A mounted page whose section says no app is connected, over an action
 * lifecycle that records every dispatch and answers from {@link REPLIES}.
 *
 * @param replies The server's answers by action name.
 * @param section The section the group frame carries.
 */
function pageWorld(
  replies: Record<string, unknown> = REPLIES,
  section: object = SECTION_OFF,
): { dispatched: string[]; wrapper: VueWrapper } {
  const dispatched: string[] = []
  const listeners: Array<(signal: ProjectSignal) => void> = []
  const connection = {
    on(event: string, listener: (payload: never) => void): () => void {
      if (event === 'projectSignal') {
        listeners.push(listener as (signal: ProjectSignal) => void)
      }

      return () => undefined
    },
  } as unknown as HilosConnection
  const actions = {
    dispatch(action: string): ActionHandle {
      dispatched.push(action)

      return {
        requestId: `req-${dispatched.length}`,
        loading: createSignal(false),
        done: Promise.resolve({ reply: replies[action] }),
      } as unknown as ActionHandle
    },
  } as unknown as ActionLifecycle

  const wrapper = mount(HilosProfileSecurityPage, {
    attachTo: document.body,
    global: { stubs: { HilosPageHeading: true } },
    props: { context: { connection, scopes: new ScopeManager(), actions } },
  })
  for (const listener of listeners) {
    listener({
      kind: 'project',
      type: 'hilos_second_factor_state',
      data: section,
    } as unknown as ProjectSignal)
  }

  return { dispatched, wrapper }
}

/** The element carrying a data-id, wherever the modal put it. */
function byId(id: string): HTMLElement {
  const found = document.querySelector<HTMLElement>(`[data-id="${id}"]`)
  if (found === null) {
    throw new Error(`no [data-id="${id}"] in the document`)
  }

  return found
}

/** Type into a field the way a person does. */
function typeInto(id: string, value: string): void {
  const field = byId(id) as HTMLInputElement
  field.value = value
  field.dispatchEvent(new Event('input'))
}

/** Send a form the way Enter does: past its buttons. */
function submit(id: string): void {
  byId(id).dispatchEvent(new Event('submit', { cancelable: true }))
}

/** Open "Add an app" and name the app. */
async function openEnrolment(wrapper: VueWrapper): Promise<void> {
  await wrapper.find('[data-id="profile-2fa-add"]').trigger('click')
  await flushPromises()
  await nextTick()
  typeInto('profile-2fa-enroll-label', 'Work phone')
}

describe('HilosProfileSecurityPage', () => {
  it('asks the server before the modal opens and starts at the name when no step is needed', async () => {
    const { dispatched, wrapper } = pageWorld()
    await nextTick()
    await wrapper.find('[data-id="profile-2fa-add"]').trigger('click')
    expect(dispatched).toEqual(['hilos_step_up_start'])
    await flushPromises()
    await nextTick()

    expect(byId('profile-2fa-enroll-label')).toBeTruthy()
    expect(document.querySelector('[data-id="step-up"]')).toBeNull()
  })

  it('opens at the confirmation step for the first app and moves to the name once confirmed', async () => {
    const { dispatched, wrapper } = pageWorld({
      ...REPLIES,
      hilos_step_up_start: {
        required: true,
        purpose: 'add an authenticator app',
        method: 'password',
      },
    })
    await nextTick()
    await wrapper.find('[data-id="profile-2fa-add"]').trigger('click')
    await flushPromises()
    await nextTick()
    expect(document.querySelector('.modal-title')?.textContent).toBe(
      "Confirm it's you",
    )
    expect(
      document.querySelector('[data-id="profile-2fa-enroll-label"]'),
    ).toBeNull()

    typeInto('step-up-password', 'secret')
    submit('profile-2fa-enroll')
    await flushPromises()
    await nextTick()

    expect(dispatched.at(-1)).toBe('hilos_step_up_confirm')
    expect(byId('profile-2fa-enroll-label')).toBeTruthy()
    expect(document.querySelector('.modal-title')?.textContent).toBe(
      'Add an authenticator app',
    )
  })

  it('asks a second app for a code from the connected one after the name, as before', async () => {
    const { dispatched, wrapper } = pageWorld(REPLIES, SECTION_ON)
    await nextTick()
    await openEnrolment(wrapper)
    submit('profile-2fa-enroll')
    await nextTick()

    expect(byId('profile-2fa-proof')).toBeTruthy()
    expect(dispatched).toEqual(['hilos_step_up_start'])
  })

  it('starts one enrolment however often Enter sends the form', async () => {
    const { dispatched, wrapper } = pageWorld()
    await nextTick()
    await openEnrolment(wrapper)

    submit('profile-2fa-enroll')
    submit('profile-2fa-enroll')
    await flushPromises()

    expect(
      dispatched.filter((action) => action.endsWith('_enroll_start')),
    ).toHaveLength(1)
  })

  it('puts the focus on the field of the step the modal moves to', async () => {
    const { wrapper } = pageWorld()
    await nextTick()
    await openEnrolment(wrapper)

    submit('profile-2fa-enroll')
    await flushPromises()
    await nextTick()

    expect(document.activeElement?.getAttribute('data-id')).toBe(
      'profile-2fa-enroll-code',
    )
  })

  it('asks before closing on codes not yet marked saved', async () => {
    const { dispatched, wrapper } = pageWorld()
    await nextTick()
    await openEnrolment(wrapper)
    submit('profile-2fa-enroll')
    await flushPromises()
    typeInto('profile-2fa-enroll-code', '123456')
    submit('profile-2fa-enroll')
    await flushPromises()
    expect(dispatched.at(-1)).toBe('profile_second_factor_enroll_confirm')

    // Enter on the codes step does not pass the "I have saved" tick either.
    submit('profile-2fa-enroll')
    await nextTick()
    expect(byId('backup-codes-list')).toBeTruthy()

    byId('modal-close').click()
    await nextTick()

    expect(byId('modal-confirm')).toBeTruthy()
    expect(byId('backup-codes-list')).toBeTruthy()
  })
})
