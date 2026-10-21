import { hilosAuthGateKey } from '@hilos/vue/base'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { nextTick } from 'vue'

import Main from './Main.vue'
import type { SelfConnection } from './types/SelfConnection.js'

// Keep the real signal bridge, refusal row and modal. Only the page's external
// inputs and upload boundary are doubles; no bootstrap or socket is started.
const inputs = await vi.hoisted(async () => {
  const { createSignal } =
    await vi.importActual<typeof import('@hilos/core/base')>('@hilos/core/base')
  return {
    error: createSignal<string | null>(null),
    selfConnection: createSignal<SelfConnection | undefined>(undefined),
    userId: createSignal<number | null>(1),
    userName: createSignal('Reader'),
    participants: createSignal([]),
    bots: createSignal([]),
    events: createSignal([]),
    upload: {
      ready: createSignal<readonly string[]>([]),
      busy: createSignal(false),
      setDisabled: vi.fn(),
      dispose: vi.fn(),
    },
  }
})

vi.mock('@hilos/core/base', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@hilos/core/base')>()),
  createHilosUploadField: () => inputs.upload,
}))
vi.mock('../../bootstrap/connection.js', () => ({
  connection: { state: 'connected', on: () => vi.fn() },
}))
vi.mock('../../bootstrap/session.js', () => ({
  currentUserId: inputs.userId,
  currentUserName: inputs.userName,
}))
vi.mock('./mainPage.js', () => ({
  EVENT_MESSAGE_SENT: 'message_sent',
  mainParticipants: inputs.participants,
  mainBots: inputs.bots,
  mainEvents: inputs.events,
  selfConnection: inputs.selfConnection,
}))
vi.mock('./mainActions.js', () => ({
  MESSAGE_RATE_LIMIT_SECONDS: 10,
  messageError: inputs.error,
  sendChatMessage: vi.fn(),
}))

enableAutoUnmount(afterEach)
afterEach(() => {
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
})
beforeEach(() => {
  inputs.error.set(null)
  inputs.selfConnection.set(undefined)
  vi.clearAllMocks()
})

function setup() {
  return mount(Main, {
    attachTo: document.body,
    global: {
      provide: { [hilosAuthGateKey]: { requireAuth: vi.fn() } },
      stubs: {
        HilosUploadDrop: { template: '<div><slot /></div>' },
        HilosUploadFiles: { template: '<div />' },
        HilosUploadPicker: { template: '<button type="button" />' },
      },
    },
  })
}

function byId(id: string): HTMLElement {
  const element = document.querySelector<HTMLElement>(`[data-id="${id}"]`)
  if (!element) throw new Error(`Missing ${id}`)
  return element
}

async function settle(): Promise<void> {
  await nextTick()
  await flushPromises()
}

describe('Main composer refusal', () => {
  it.each([null, ''])('reserves a silent empty slot for %j', async (error) => {
    const wrapper = setup()
    const live = byId('message-error-live')
    const slot = byId('message-error-slot')
    inputs.error.set(error)
    await settle()

    const idle = byId('message-error-idle')
    expect(idle.classList.contains('invisible')).toBe(true)
    expect(idle.getAttribute('aria-hidden')).toBe('true')
    expect(idle.querySelector('button')).toBeNull()
    expect(wrapper.find('[data-id="message-error"]').exists()).toBe(false)
    expect(wrapper.find('[data-id="message-error-details"]').exists()).toBe(
      false,
    )
    expect(live.textContent?.trim()).toBe('')
    expect(live.classList.contains('visually-hidden')).toBe(true)
    expect(live.getAttribute('role')).toBe('alert')
    expect(live.getAttribute('aria-live')).toBe('assertive')
    expect(live.getAttribute('aria-atomic')).toBe('true')
    expect(live.parentElement).toBe(byId('composer').parentElement)
    expect(slot.contains(live)).toBe(false)
    expect(slot.querySelector('[role], [aria-live]')).toBeNull()
    expect(slot.hasAttribute('role')).toBe(false)
    expect(slot.hasAttribute('aria-live')).toBe(false)
    expect(byId('message-error-live')).toBe(live)
    expect(byId('message-error-slot')).toBe(slot)
  })

  it('keeps one voice and slot through arrival, details, clearing and another refusal', async () => {
    const wrapper = setup()
    const live = byId('message-error-live')
    const slot = byId('message-error-slot')
    const reason = 'Only the owner of this conversation may send a message.'
    inputs.error.set(reason)
    await settle()

    const row = byId('message-error')
    expect(row.querySelector('.text-truncate')?.textContent).toBe(reason)
    expect(live.textContent?.trim()).toBe(reason)
    expect(slot.querySelector('[role], [aria-live]')).toBeNull()
    expect(slot.hasAttribute('role')).toBe(false)
    expect(slot.hasAttribute('aria-live')).toBe(false)
    expect(row.hasAttribute('role')).toBe(false)
    expect(row.hasAttribute('aria-live')).toBe(false)
    const attachments = byId('attachments')
    expect(slot.nextElementSibling).toBe(attachments)
    expect(
      row.compareDocumentPosition(attachments) &
        Node.DOCUMENT_POSITION_FOLLOWING,
    ).toBeTruthy()
    expect(wrapper.findAll('[role="alert"]')).toHaveLength(1)

    await wrapper.get('[data-id="message-error-details"]').trigger('click')
    await settle()
    expect(
      document.querySelector('[role="dialog"]')?.getAttribute('aria-label'),
    ).toBe('Error details')
    expect(byId('message-error-full').textContent).toBe(reason)

    inputs.error.set(null)
    await settle()
    expect(document.querySelector('[data-id="message-error-full"]')).toBeNull()
    expect(byId('message-error-idle').getAttribute('aria-hidden')).toBe('true')
    expect(live.textContent?.trim()).toBe('')
    expect(byId('message-error-live')).toBe(live)
    expect(byId('message-error-slot')).toBe(slot)

    inputs.error.set('Try again later.')
    await settle()
    expect(
      byId('message-error').querySelector('.text-truncate')?.textContent,
    ).toBe('Try again later.')
    expect(live.textContent?.trim()).toBe('Try again later.')
    expect(byId('message-error-live')).toBe(live)
    expect(byId('message-error-slot')).toBe(slot)
  })

  it.each(['checking', 'rejected', 'unavailable'] as const)(
    'suppresses the row, details and voice under the %s banner, then restores the refusal',
    async (phase) => {
      const wrapper = setup()
      const live = byId('message-error-live')
      const slot = byId('message-error-slot')
      const reason = 'The server declined this submission.'
      inputs.error.set(reason)
      await settle()
      await wrapper.get('[data-id="message-error-details"]').trigger('click')
      await settle()
      expect(byId('message-error-full').textContent).toBe(reason)

      inputs.selfConnection.set({
        messageRateLimitSecondsRemaining: 0,
        moderation: { phase, text: 'Draft', reason: 'Moderation reason' },
      })
      await settle()
      expect(wrapper.find('[data-id="moderation-banner"]').exists()).toBe(true)
      expect(wrapper.find('[data-id="message-error"]').exists()).toBe(false)
      expect(wrapper.find('[data-id="message-error-details"]').exists()).toBe(
        false,
      )
      expect(byId('message-error-idle').getAttribute('aria-hidden')).toBe(
        'true',
      )
      expect(
        document.querySelector('[data-id="message-error-full"]'),
      ).toBeNull()
      expect(live.textContent?.trim()).toBe('')
      expect(byId('message-error-live')).toBe(live)
      expect(byId('message-error-slot')).toBe(slot)

      inputs.selfConnection.set({
        messageRateLimitSecondsRemaining: 0,
        moderation: null,
      })
      await settle()
      expect(wrapper.find('[data-id="moderation-banner"]').exists()).toBe(false)
      expect(
        byId('message-error').querySelector('.text-truncate')?.textContent,
      ).toBe(reason)
      expect(live.textContent?.trim()).toBe(reason)
      expect(
        document.querySelector('[data-id="message-error-full"]'),
      ).toBeNull()
      expect(byId('message-error-live')).toBe(live)
      expect(byId('message-error-slot')).toBe(slot)
    },
  )

  it('keeps a long server sentence whole inside the truncated line and details', async () => {
    const wrapper = setup()
    const reason =
      'This conversation cannot accept your message until its owner completes the pending review. '.repeat(
        8,
      )
    inputs.error.set(reason)
    await settle()
    expect(
      byId('message-error').querySelector('.text-truncate')?.textContent,
    ).toBe(reason)
    expect(byId('message-error-live').textContent?.trim()).toBe(reason.trim())
    await wrapper.get('[data-id="message-error-details"]').trigger('click')
    await settle()
    expect(byId('message-error-full').textContent).toBe(reason)
    byId('message-error-close').click()
    await settle()
    expect(document.querySelector('[data-id="message-error-full"]')).toBeNull()
  })
})
