// Covers what the profile's security page view owns rather than the core store
// (HIL-494): a form sent again while its action is out dispatches nothing, the
// step a modal moves to takes the focus, and backup codes shown once ask before
// the modal closes.
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

/** The replies the server gives, by action name. */
const REPLIES: Record<string, unknown> = {
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
 */
function pageWorld(): { dispatched: string[]; wrapper: VueWrapper } {
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
        done: Promise.resolve({ reply: REPLIES[action] }),
      } as unknown as ActionHandle
    },
  } as unknown as ActionLifecycle

  const wrapper = mount(HilosProfileSecurityPage, {
    attachTo: document.body,
    props: { context: { connection, scopes: new ScopeManager(), actions } },
  })
  for (const listener of listeners) {
    listener({
      kind: 'project',
      type: 'hilos_second_factor_state',
      data: SECTION_OFF,
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
  await nextTick()
  typeInto('profile-2fa-enroll-label', 'Work phone')
}

describe('HilosProfileSecurityPage', () => {
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
