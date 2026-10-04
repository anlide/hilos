// Covers what the account deletion view owns rather than the core flow
// (HIL-302): the zone turning into the warning and back from the person's
// state, the refusal drawn in the room kept above the buttons, the code field
// taking the focus on step 2, and the send block of step 2 asking for another
// code.
import {
  ActionError,
  createSignal,
  ScopeManager,
  type ActionHandle,
  type ActionLifecycle,
  type HilosConnection,
  type HilosRouter,
  type ProjectSignal,
} from '@hilos/core'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'
import { nextTick } from 'vue'

import HilosAccountDeletion from './HilosAccountDeletion.vue'
import { hilosRouterKey } from '../hilosRouterKey.js'

// The modal teleports to <body>, so assertions query the document.
afterEach(() => {
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
})

enableAutoUnmount(afterEach)

/** One day, in ms. */
const DAY = 86_400_000

/** A code request's answer whose resend gate is already open. */
const SENT_RESEND_OPEN = {
  sent: true,
  resendAt: Date.now() - 60_000,
  expiresAt: Date.now() + 600_000,
}

/**
 * A mounted zone over an action lifecycle that answers by name — a reply, or a
 * refusal with the server's sentence.
 *
 * @param answers What each action name answers; a string is a refusal.
 */
function zoneWorld(answers: Record<string, unknown>) {
  const listeners: Array<(signal: ProjectSignal) => void> = []
  const sent: string[] = []
  const navigated: string[] = []
  const dispatched: Array<{ action: string; payload?: unknown }> = []
  const connection = {
    on(event: string, listener: (payload: never) => void): () => void {
      if (event === 'projectSignal') {
        listeners.push(listener as (signal: ProjectSignal) => void)
      }

      return () => undefined
    },
  } as unknown as HilosConnection
  const actions = {
    dispatch(action: string, payload?: unknown): ActionHandle {
      sent.push(action)
      dispatched.push({ action, payload })
      const answer = answers[action] ?? []

      return {
        requestId: action,
        loading: createSignal(false),
        done:
          typeof answer === 'string'
            ? Promise.reject(new ActionError(action, 'fail', answer))
            : Promise.resolve({ reply: answer }),
      } as unknown as ActionHandle
    },
  } as unknown as ActionLifecycle

  mount(HilosAccountDeletion, {
    attachTo: document.body,
    global: {
      provide: {
        [hilosRouterKey as symbol]: {
          currentPath: createSignal('/profile'),
          navigate: (path: string) => navigated.push(path),
        } as unknown as HilosRouter,
      },
    },
    props: { context: { connection, scopes: new ScopeManager(), actions } },
  })

  return {
    sent,
    navigated,
    dispatched,
    state(data: unknown): void {
      for (const listener of listeners) {
        listener({
          kind: 'project',
          type: 'hilos_account_deletion_state',
          data,
        } as unknown as ProjectSignal)
      }
    },
  }
}

/** The element carrying a data-id, or null. */
function find(id: string): HTMLElement | null {
  return document.querySelector<HTMLElement>(`[data-id="${id}"]`)
}

/** The element carrying a data-id, which must be there. */
function byId(id: string): HTMLElement {
  const found = find(id)
  if (found === null) {
    throw new Error(`no [data-id="${id}"] in the document`)
  }

  return found
}

describe('HilosAccountDeletion', () => {
  it('turns the zone into the warning and back as the state says', async () => {
    const world = zoneWorld({})
    world.state({ deletion: null })
    await nextTick()
    expect(find('account-deletion-open')).not.toBeNull()
    expect(find('account-deletion-scheduled')).toBeNull()

    world.state({
      deletion: {
        requestedAt: Date.now(),
        effectiveAt: Date.now() + 30 * DAY - 60_000,
      },
    })
    await nextTick()
    expect(find('account-deletion-open')).toBeNull()
    expect(byId('account-deletion-scheduled').textContent).toContain(
      '30 days left',
    )

    world.state({ deletion: null })
    await nextTick()
    expect(find('account-deletion-open')).not.toBeNull()
  })

  it('draws a refusal above the buttons and keeps the step', async () => {
    const world = zoneWorld({
      hilos_step_up_start: { required: false, purpose: 'delete your account' },
      hilos_account_deletion_open: {
        graceDays: 30,
        channel: 'email',
        destination: 'me@example.test',
      },
      hilos_account_deletion_code:
        'Too many codes have been sent to this address. Please try again later.',
    })
    world.state({ deletion: null })
    await nextTick()

    byId('account-deletion-open').click()
    await flushPromises()
    expect(byId('account-deletion-modal').textContent).toContain('30 days')
    byId('account-deletion-continue').click()
    await flushPromises()

    expect(byId('account-deletion-error').textContent).toContain(
      'Too many codes',
    )
    expect(find('account-deletion-continue')).not.toBeNull()
  })

  it('puts the focus on the code field on step 2', async () => {
    const world = zoneWorld({
      hilos_step_up_start: { required: false, purpose: 'delete your account' },
      hilos_account_deletion_open: {
        graceDays: 30,
        channel: 'email',
        destination: 'me@example.test',
      },
    })
    world.state({ deletion: null })
    await nextTick()

    byId('account-deletion-open').click()
    await flushPromises()
    byId('account-deletion-continue').click()
    await flushPromises()
    await nextTick()

    expect(byId('account-deletion-modal').textContent).toContain(
      'me@example.test',
    )
    expect(document.activeElement).toBe(byId('account-deletion-code'))
  })

  it('draws the send block on step 2 and sends the code again from it', async () => {
    const world = zoneWorld({
      hilos_step_up_start: { required: false, purpose: 'delete your account' },
      hilos_account_deletion_open: {
        graceDays: 30,
        channel: 'email',
        destination: 'me@example.test',
      },
      hilos_account_deletion_code: SENT_RESEND_OPEN,
    })
    world.state({ deletion: null })
    await nextTick()

    byId('account-deletion-open').click()
    await flushPromises()
    byId('account-deletion-continue').click()
    await flushPromises()
    expect(byId('account-deletion-send')).toBeDefined()
    byId('account-deletion-send-again').click()
    await flushPromises()

    expect(
      world.sent.filter((action) => action === 'hilos_account_deletion_code'),
    ).toHaveLength(2)
  })
})

it('leaves the explanation for Your data without scheduling deletion', async () => {
  const world = zoneWorld({
    hilos_step_up_start: { required: false, purpose: 'delete your account' },
    hilos_account_deletion_open: {
      graceDays: 30,
      channel: 'email',
      destination: 'me@example.test',
    },
  })
  world.state({ deletion: null })
  await nextTick()
  byId('account-deletion-open').click()
  await flushPromises()
  const link = byId('account-deletion-data-link').querySelector('a')
  expect(link?.getAttribute('href')).toBe('/profile/data')
  link?.click()
  await flushPromises()
  expect(world.navigated).toEqual(['/profile/data'])
  expect(find('account-deletion-modal')).toBeNull()
  expect(world.sent).toEqual([
    'hilos_step_up_start',
    'hilos_account_deletion_open',
  ])
})

it('confirms step-up via form submit when credential is typed, ignores empty submit, and binds confirm button to form', async () => {
  const world = zoneWorld({
    hilos_step_up_start: {
      required: true,
      purpose: 'delete your account',
      method: 'password',
    },
  })
  world.state({ deletion: null })
  await nextTick()

  byId('account-deletion-open').click()
  await flushPromises()

  const form = byId('account-deletion-step-up')
  const confirm = byId('account-deletion-confirm')

  expect(confirm.getAttribute('type')).toBe('submit')
  expect(confirm.getAttribute('form')).toBe(form.id)

  form.dispatchEvent(new Event('submit', { cancelable: true }))
  await flushPromises()
  expect(
    world.dispatched.some((item) => item.action === 'hilos_step_up_confirm'),
  ).toBe(false)

  const input = byId('step-up-password') as HTMLInputElement
  input.value = 'secret'
  input.dispatchEvent(new Event('input'))
  form.dispatchEvent(new Event('submit', { cancelable: true }))
  await flushPromises()

  const call = world.dispatched.find(
    (item) => item.action === 'hilos_step_up_confirm',
  )
  expect(call).toBeDefined()
  expect(call?.payload).toMatchObject({
    password: 'secret',
  })
})
