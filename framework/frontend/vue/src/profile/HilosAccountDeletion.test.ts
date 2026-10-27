// Covers what the account deletion view owns rather than the core flow
// (HIL-302): the zone turning into the warning and back from the person's
// state, the refusal drawn in the room kept above the buttons, the code field
// taking the focus on step 2, the send block of step 2 asking for another
// code, and the window held with its body locked while a step is out, its Your
// data link with it (HIL-1763).
import {
  ActionError,
  applyServerTime,
  bindProfileFlows,
  createSignal,
  ScopeManager,
  type ActionHandle,
  type ActionLifecycle,
  type HilosConnection,
  type HilosRouter,
  type ProjectSignal,
} from '@hilos/core/base'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'

import HilosAccountDeletion from './HilosAccountDeletion.vue'
import { hilosRouterKey } from '../hilosRouterKey.js'

// The modal teleports to <body>, so assertions query the document.
afterEach(() => {
  vi.restoreAllMocks()
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
 * @param hold Actions whose reply stays in flight until the test settles or refuses it.
 */
function zoneWorld(answers: Record<string, unknown>, hold: string[] = []) {
  const listeners: Array<(signal: ProjectSignal) => void> = []
  const sent: string[] = []
  const navigated: string[] = []
  const dispatched: Array<{ action: string; payload?: unknown }> = []
  const held: Array<{ settle(): void; refuse(reason: string): void }> = []
  const sendFlows = (flows: unknown[]) => {
    for (const listener of listeners) {
      listener({
        kind: 'project',
        type: 'hilos_profile_flows',
        data: { flows },
      } as unknown as ProjectSignal)
    }
  }
  const connection = {
    on(event: string, listener: (payload: never) => void): () => void {
      if (event === 'projectSignal') {
        listeners.push(listener as (signal: ProjectSignal) => void)
      }

      return () => undefined
    },
  } as unknown as HilosConnection
  bindProfileFlows(connection)
  sendFlows([])
  const actions = {
    dispatch(action: string, payload?: unknown): ActionHandle {
      sent.push(action)
      dispatched.push({ action, payload })
      const answer = answers[action] ?? []
      // What the server tells the session when the action lands, before it answers.
      const tell = (): void => {
        if (
          action === 'hilos_account_deletion_code' &&
          typeof answer !== 'string' &&
          (answer as { expiresAt?: number | null }).expiresAt != null
        ) {
          sendFlows([
            {
              operation: 'delete_account',
              step: 'code_sent',
              address: 'me@example.test',
              target: null,
            },
          ])
        }
        if (
          action === 'hilos_account_deletion_start' ||
          action === 'hilos_profile_flow_cancel'
        )
          sendFlows([])
      }
      if (hold.includes(action)) {
        return {
          requestId: action,
          loading: createSignal(false),
          done: new Promise((resolve, reject) =>
            held.push({
              settle: () => {
                tell()
                resolve({ reply: answer })
              },
              refuse: (reason) =>
                reject(new ActionError(action, 'fail', reason)),
            }),
          ),
        } as unknown as ActionHandle
      }
      tell()

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

  const wrapper = mount(HilosAccountDeletion, {
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
    wrapper,
    sent,
    navigated,
    dispatched,
    held,
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

// The fieldset the modal stands its body in; a request in flight disables it.
function bodyLock(): HTMLFieldSetElement | null {
  return document.querySelector('[data-id="modal"] .modal-body > fieldset')
}

// Every way a person closes the window: the ×, Cancel, Escape and the backdrop.
function tryClosing(): void {
  byId('modal-close').click()
  byId('account-deletion-cancel').click()
  byId('modal').dispatchEvent(
    new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
  )
  byId('modal').click()
}

/** Type into a field as a person does. */
function typeInto(id: string, value: string): void {
  const field = byId(id) as HTMLInputElement
  field.value = value
  field.dispatchEvent(new Event('input'))
}

describe('HilosAccountDeletion', () => {
  it('bounds the countdown by the delivered request and keeps ticking through new state', async () => {
    vi.useFakeTimers({ toFake: ['Date', 'setInterval', 'clearInterval'] })
    const requestedAt = 1_800_000_000_000
    vi.setSystemTime(requestedAt)
    applyServerTime(requestedAt - 300)
    const world = zoneWorld({})
    try {
      world.state({ deletion: null })
      await nextTick()
      expect(vi.getTimerCount()).toBe(0)

      world.state({
        deletion: {
          requestedAt,
          effectiveAt: requestedAt + 30 * DAY,
        },
      })
      await nextTick()
      expect(byId('account-deletion-scheduled').textContent).toContain(
        '30 days left',
      )
      expect(vi.getTimerCount()).toBe(1)

      vi.setSystemTime(requestedAt + DAY)
      await vi.advanceTimersByTimeAsync(60_000)
      expect(byId('account-deletion-scheduled').textContent).toContain(
        '29 days left',
      )

      world.state({ deletion: null })
      await nextTick()
      expect(find('account-deletion-scheduled')).toBeNull()
      expect(find('account-deletion-open')).not.toBeNull()
      expect(vi.getTimerCount()).toBe(0)

      world.state({
        deletion: {
          requestedAt: Date.now(),
          effectiveAt: Date.now() + 7 * DAY,
        },
      })
      await nextTick()
      expect(byId('account-deletion-scheduled').textContent).toContain(
        '7 days left',
      )
      expect(vi.getTimerCount()).toBe(1)
    } finally {
      world.wrapper.unmount()
      const timersLeft = vi.getTimerCount()
      applyServerTime(Date.now())
      vi.useRealTimers()
      expect(timersLeft).toBe(0)
    }
  })

  it('starts the zone clock when a deletion appears', async () => {
    let now = 1_700_000_000_000
    vi.spyOn(Date, 'now').mockImplementation(() => now)
    const world = zoneWorld({})
    world.state({ deletion: null })
    await nextTick()
    now += 50_000
    world.state({
      deletion: {
        requestedAt: now,
        effectiveAt: now + 30 * DAY,
      },
    })
    await nextTick()
    expect(byId('account-deletion-scheduled').textContent).toContain(
      '30 days left',
    )
  })

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
      hilos_account_deletion_code: SENT_RESEND_OPEN,
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

  it('holds the window and locks its body while a step is out, and frees it after a refusal', async () => {
    const world = zoneWorld(
      {
        hilos_step_up_start: {
          required: false,
          purpose: 'delete your account',
        },
        hilos_account_deletion_open: {
          graceDays: 30,
          channel: 'email',
          destination: 'me@example.test',
        },
        hilos_account_deletion_code: SENT_RESEND_OPEN,
      },
      ['hilos_account_deletion_code', 'hilos_account_deletion_start'],
    )
    world.state({ deletion: null })
    await nextTick()
    byId('account-deletion-open').click()
    await flushPromises()
    const body = bodyLock()
    const cancel = byId('account-deletion-cancel') as HTMLButtonElement
    const close = byId('modal-close') as HTMLButtonElement
    expect(body?.contains(byId('account-deletion-modal'))).toBe(true)
    expect(body?.disabled).toBe(false)

    // Step 1 sends the code: held, refused with its sentence, then let through.
    byId('account-deletion-continue').click()
    await flushPromises()
    expect(body?.disabled).toBe(true)
    expect(cancel.disabled).toBe(true)
    expect(close.disabled).toBe(true)
    world.held[0]!.refuse('Too many codes have been sent to this address.')
    await flushPromises()
    expect(body?.disabled).toBe(false)
    expect(cancel.disabled).toBe(false)
    expect(byId('account-deletion-error').textContent).toContain(
      'Too many codes',
    )
    byId('account-deletion-continue').click()
    await flushPromises()
    world.held[1]!.settle()
    await flushPromises()

    // Step 2 starts the deletion: the code stays locked until the answer.
    typeInto('account-deletion-code', '000000')
    await nextTick()
    const field = byId('account-deletion-code') as HTMLInputElement
    expect(bodyLock()).toBe(body)
    expect(body?.contains(field)).toBe(true)
    byId('account-deletion-start').click()
    await flushPromises()
    expect(world.held).toHaveLength(3)
    expect(body?.disabled).toBe(true)
    expect(cancel.disabled).toBe(true)
    expect(close.disabled).toBe(true)
    tryClosing()
    await flushPromises()
    expect(find('modal')).not.toBeNull()
    expect(find('modal-confirm')).toBeNull()
    expect(world.sent).not.toContain('hilos_profile_flow_cancel')

    world.held[2]!.refuse('That code is not right')
    await flushPromises()
    expect(byId('account-deletion-code')).toBe(field)
    expect(field.value).toBe('000000')
    expect(body?.disabled).toBe(false)
    expect(cancel.disabled).toBe(false)
    expect(close.disabled).toBe(false)
    expect(byId('account-deletion-error').textContent).toContain(
      'That code is not right',
    )
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

it('holds the Your data link while step 1 is out, and lets it leave once the answer is in', async () => {
  const world = zoneWorld(
    {
      hilos_step_up_start: { required: false, purpose: 'delete your account' },
      hilos_account_deletion_open: {
        graceDays: 30,
        channel: 'email',
        destination: 'me@example.test',
      },
    },
    ['hilos_account_deletion_code'],
  )
  world.state({ deletion: null })
  await nextTick()
  byId('account-deletion-open').click()
  await flushPromises()
  const link = byId('account-deletion-data-link').querySelector('a')!
  expect(link.hasAttribute('aria-disabled')).toBe(false)
  expect(link.hasAttribute('tabindex')).toBe(false)

  byId('account-deletion-continue').click()
  await flushPromises()
  expect(world.held).toHaveLength(1)
  expect(link.getAttribute('aria-disabled')).toBe('true')
  expect(link.getAttribute('tabindex')).toBe('-1')
  const click = new MouseEvent('click', {
    bubbles: true,
    cancelable: true,
    button: 0,
  })
  link.dispatchEvent(click)
  await flushPromises()
  expect(click.defaultPrevented).toBe(true)
  expect(world.navigated).toEqual([])
  expect(find('account-deletion-modal')).not.toBeNull()

  world.held[0]!.refuse('Too many codes have been sent to this address.')
  await flushPromises()
  expect(link.hasAttribute('aria-disabled')).toBe(false)
  expect(link.hasAttribute('tabindex')).toBe(false)
  link.click()
  await flushPromises()
  expect(world.navigated).toEqual(['/profile/data'])
  expect(find('account-deletion-modal')).toBeNull()
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
