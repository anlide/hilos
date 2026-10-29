// @vitest-environment jsdom
// The real modal disables an ancestor fieldset; jsdom implements :disabled
// inheritance, which its focus trap uses to skip the locked email field.
import {
  ActionLifecycle,
  DEFAULT_SPINNER_DELAY_MS,
  type HilosConnection,
} from '@hilos/core/base'
import {
  createStatusSubscription,
  STATUS_SUBSCRIPTION_COPY as COPY,
} from '@hilos/core/status'
import { DOMWrapper, mount } from '@vue/test-utils'
import { defineComponent, h, nextTick } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import HilosStatusSubscriptionModal from './HilosStatusSubscriptionModal.vue'

const token = 'ab'.repeat(32)
const cleanup: Array<() => void> = []
const body = () => new DOMWrapper(document.body)
const byId = (id: string) => body().get(`[data-id="${id}"]`)

function harness(fragment = '') {
  window.history.replaceState({}, '', `/status${fragment}`)
  const listeners = new Map<string, Set<(data: unknown) => void>>()
  const emit = (event: string, data?: unknown) => {
    for (const listener of [...(listeners.get(event) ?? [])]) listener(data)
  }
  const send = vi.fn<
    (action: string, data: unknown, requestId?: string) => boolean
  >(() => true)
  const connection = {
    state: 'connected',
    protectedMode: { active: false },
    on(event: string, listener: (data: unknown) => void) {
      const bucket = listeners.get(event) ?? new Set()
      listeners.set(event, bucket)
      bucket.add(listener)
      return () => bucket.delete(listener)
    },
    sendAction: send,
  }
  const controller = createStatusSubscription({
    connection: connection as unknown as HilosConnection,
    actions: new ActionLifecycle(connection as unknown as HilosConnection, {
      timeoutMs: 1000,
      deferredLoadingMs: 30,
    }),
  })
  const wrapper = mount(
    defineComponent({
      setup: () => () =>
        h('div', [
          h(
            'button',
            { 'data-id': 'opener', onClick: () => controller.open() },
            'Subscribe to updates',
          ),
          h(HilosStatusSubscriptionModal, { controller }),
        ]),
    }),
    { attachTo: document.body },
  )
  cleanup.push(() => {
    wrapper.unmount()
    controller.dispose()
    emit('state', 'disconnected')
  })
  return {
    wrapper,
    controller,
    send,
    emit,
    reply(reply: unknown, index = send.mock.calls.length - 1) {
      const [action, , requestId] = send.mock.calls[index]!
      emit('actionSuccess', { action, requestId, reply })
    },
    async open() {
      controller.start()
      ;(byId('opener').element as HTMLButtonElement).focus()
      await byId('opener').trigger('click')
      await settle()
    },
    freeze(active: boolean) {
      connection.protectedMode.active = active
      emit('protectedMode', connection.protectedMode)
    },
    connect(connected: boolean) {
      connection.state = connected ? 'connected' : 'disconnected'
      emit('state', connection.state)
    },
  }
}

async function settle(): Promise<void> {
  await vi.advanceTimersByTimeAsync(0)
  await nextTick()
}

beforeEach(() => vi.useFakeTimers())
afterEach(() => {
  for (const release of cleanup.splice(0).reverse()) release()
  vi.useRealTimers()
  vi.restoreAllMocks()
  document.body.innerHTML = ''
  document.body.classList.remove('modal-open')
})

describe('public Status subscription with real modal, form error and LoadingButton', () => {
  it('owns no start or teardown, labels the server-validated email and traps and returns focus', async () => {
    const h = harness()
    const start = vi.spyOn(h.controller, 'start')
    const dispose = vi.spyOn(h.controller, 'dispose')
    await settle()
    expect(start).not.toHaveBeenCalled()
    expect(body().find('[data-id="modal"]').exists()).toBe(false)
    await h.open()
    const modal = byId('modal')
    const email = byId('status-subscription-email')
    expect(modal.attributes('role')).toBe('dialog')
    expect(modal.attributes('aria-modal')).toBe('true')
    expect(modal.attributes('aria-label')).toBe(COPY.subscribe)
    expect(document.activeElement).toBe(email.element)
    expect(email.attributes('type')).toBe('email')
    expect(email.attributes('autocomplete')).toBe('email')
    expect(email.attributes('name')).toBe('email')
    expect(modal.get('label').attributes('for')).toBe(email.attributes('id'))
    expect(modal.get('label').text()).toBe('Email address')
    expect(byId('status-subscription-form').attributes()).toHaveProperty(
      'novalidate',
    )
    for (const id of email.attributes('aria-describedby')!.split(' ')) {
      expect(document.getElementById(id)).not.toBeNull()
    }
    expect(modal.text()).toContain('You do not need an account.')
    expect(modal.text()).toContain('ignore it.')
    expect(modal.text()).not.toContain('scheduled')
    expect(
      (byId('status-subscription-submit').element as HTMLButtonElement)
        .disabled,
    ).toBe(true)
    await byId('status-subscription-form').trigger('submit')
    expect(h.send).not.toHaveBeenCalled()
    await email.setValue('reader@example.net')
    const submit = byId('status-subscription-submit')
    ;(submit.element as HTMLButtonElement).focus()
    await submit.trigger('keydown', { key: 'Tab' })
    expect(document.activeElement).toBe(byId('modal-close').element)
    await byId('modal-close').trigger('keydown', { key: 'Tab', shiftKey: true })
    expect(document.activeElement).toBe(submit.element)
    await modal.trigger('keydown', { key: 'Escape' })
    await settle()
    expect(body().find('[data-id="modal"]').exists()).toBe(false)
    expect(document.activeElement).toBe(byId('opener').element)
    expect(h.send).not.toHaveBeenCalled()
    h.wrapper.unmount()
    expect(dispose).not.toHaveBeenCalled()
  })

  it('locks one native submit immediately, retains the field and label, blocks every close and delays its own spinner once', async () => {
    const h = harness()
    await h.open()
    const field = byId('status-subscription-email')
    await field.setValue('Reader@Example.net')
    const form = byId('status-subscription-form')
    const fieldset = form.element.closest('fieldset')!
    const submit = byId('status-subscription-submit')
    const label = submit.get('span').element
    ;(submit.element as HTMLButtonElement).click()
    await settle()
    expect(h.send).toHaveBeenCalledExactlyOnceWith(
      'status_subscribe',
      { email: 'reader@example.net' },
      '1',
    )
    expect(submit.attributes('aria-busy')).toBe('true')
    expect(submit.attributes('aria-disabled')).toBe('true')
    expect(submit.attributes('disabled')).toBeUndefined()
    expect(field.element.matches(':disabled')).toBe(true)
    expect((fieldset as HTMLFieldSetElement).disabled).toBe(true)
    expect(byId('status-subscription-email').element).toBe(field.element)
    expect((field.element as HTMLInputElement).value).toBe('Reader@Example.net')
    expect(field.element.closest('[aria-hidden], [inert]')).toBeNull()
    expect((byId('modal-close').element as HTMLButtonElement).disabled).toBe(
      true,
    )
    expect(
      (byId('status-subscription-cancel').element as HTMLButtonElement)
        .disabled,
    ).toBe(true)
    await submit.trigger('click')
    await form.trigger('submit')
    await byId('modal').trigger('keydown', { key: 'Escape' })
    await byId('modal').trigger('click')
    h.controller.close()
    expect(body().find('[data-id="modal"]').exists()).toBe(true)
    expect(h.send).toHaveBeenCalledTimes(1)
    await vi.advanceTimersByTimeAsync(31)
    expect(h.controller.loading.get()).toBe(true)
    expect(submit.find('[data-id="loading-button-spinner"]').exists()).toBe(
      false,
    )
    await vi.advanceTimersByTimeAsync(DEFAULT_SPINNER_DELAY_MS - 31)
    expect(submit.find('[data-id="loading-button-spinner"]').exists()).toBe(
      true,
    )
    expect(submit.get('span').element).toBe(label)
    expect(submit.get('span').classes()).toContain('invisible')
    expect(submit.text()).toContain('Subscribe')
    h.reply({ status: 'check_inbox' })
    await settle()
    expect(body().find('[data-id="modal"]').exists()).toBe(false)
    expect(h.controller.success.get()).toBe(COPY.checkInbox)
    expect(document.activeElement).toBe(byId('opener').element)
  })

  it('keeps fast replies free of a spinner and does not validate an email in the view', async () => {
    const h = harness()
    await h.open()
    await byId('status-subscription-email').setValue('not-an-email')
    await byId('status-subscription-form').trigger('submit')
    expect(h.send.mock.calls[0]?.[1]).toEqual({ email: 'not-an-email' })
    await vi.advanceTimersByTimeAsync(50)
    expect(body().find('[data-id="loading-button-spinner"]').exists()).toBe(
      false,
    )
    h.reply({ status: 'check_inbox' })
    await settle()
    await vi.advanceTimersByTimeAsync(DEFAULT_SPINNER_DELAY_MS)
    expect(body().find('[data-id="loading-button-spinner"]').exists()).toBe(
      false,
    )
    expect(h.controller.success.get()).toBe(COPY.checkInbox)
  })

  it('shows backend refusal words through the reserved error slot and keeps its ARIA association and draft', async () => {
    const h = harness()
    await h.open()
    const field = byId('status-subscription-email')
    const errorSlot = byId('status-subscription-error-slot').element
    const idleClasses = byId('status-subscription-error-idle').classes()
    await field.setValue('reader@example.net')
    await byId('status-subscription-form').trigger('submit')
    h.emit('actionError', {
      action: 'status_subscribe',
      requestId: '1',
      reason: 'Please enter a valid email address.',
      errorDetail: `Internal detail ${token}`,
      errorType: 'PrivateClass',
    })
    await settle()
    expect(byId('status-subscription-error-slot').element).toBe(errorSlot)
    expect(byId('status-subscription-error-slot').attributes('role')).toBe(
      'alert',
    )
    expect(byId('status-subscription-error').text()).toBe(
      'Please enter a valid email address.',
    )
    expect(byId('status-subscription-error').classes()).toEqual(
      idleClasses.filter((name) => name !== 'invisible'),
    )
    expect(field.attributes('aria-invalid')).toBe('true')
    expect(field.element.matches(':disabled')).toBe(false)
    expect((field.element as HTMLInputElement).value).toBe('reader@example.net')
    expect(body().text()).not.toContain(token)
    expect(body().text()).not.toContain('PrivateClass')
    await byId('status-subscription-error-details').trigger('click')
    await settle()
    expect(byId('status-subscription-error-full').text()).toBe(
      'Please enter a valid email address.',
    )
    expect(h.controller.success.get()).toBeNull()
  })

  it.each(['timeout', 'disconnect', 'expired', 'malformed'])(
    'keeps a %s confirmation honest without a window, success, automatic retry or late resurrection',
    async (outcome) => {
      const h = harness(
        `#status-confirm=${outcome === 'malformed' ? 'bad' : token}`,
      )
      h.controller.start()
      await settle()
      expect(window.location.hash).toBe('')
      expect(body().find('[data-id="modal"]').exists()).toBe(false)
      if (outcome === 'timeout') await vi.advanceTimersByTimeAsync(1001)
      else if (outcome === 'disconnect') h.connect(false)
      else if (outcome === 'expired')
        h.emit('actionError', {
          action: 'status_subscription_confirm',
          requestId: '1',
          reason: 'This confirmation link has expired.',
          errorDetail: token,
        })
      await settle()
      expect(h.controller.error.get()).toBe(
        outcome === 'malformed'
          ? COPY.invalidLink
          : outcome === 'expired'
            ? 'This confirmation link has expired.'
            : COPY.unconfirmed,
      )
      expect(h.controller.success.get()).toBeNull()
      expect(body().text()).not.toContain(token)
      h.connect(true)
      h.freeze(true)
      h.freeze(false)
      if (outcome !== 'malformed') h.reply({ status: 'subscribed' })
      await settle()
      expect(h.send).toHaveBeenCalledTimes(outcome === 'malformed' ? 0 : 1)
      expect(h.controller.success.get()).toBeNull()
      expect(body().find('[data-id="modal"]').exists()).toBe(false)
    },
  )

  it('previews only the mask and Keep never deletes; only an explicit submit unsubscribes after recovery', async () => {
    const keep = harness(`#status-unsubscribe=${token}`)
    keep.controller.start()
    await settle()
    expect(keep.send).toHaveBeenCalledExactlyOnceWith(
      'status_unsubscribe_preview',
      { token },
      '1',
    )
    expect(body().find('[data-id="status-subscription-submit"]').exists()).toBe(
      false,
    )
    expect(document.activeElement).toBe(byId('modal').element)
    const address = byId('status-subscription-address').element
    const reservedAction = byId('modal').get('.modal-footer > span')
    expect(reservedAction.classes()).toEqual(['btn', 'btn-danger', 'invisible'])
    expect(reservedAction.attributes('aria-hidden')).toBe('true')
    expect(reservedAction.attributes()).toHaveProperty('inert')
    expect(reservedAction.attributes('data-id')).toBeUndefined()
    keep.reply({ maskedEmail: 'r***@example.net' })
    await settle()
    expect(byId('status-subscription-address').element).toBe(address)
    expect(byId('status-subscription-address').text()).toBe(
      'Stop sending status updates to r***@example.net?',
    )
    expect(body().find('input').exists()).toBe(false)
    expect(body().text()).not.toContain('reader@example.net')
    expect(body().text()).not.toContain(token)
    await byId('status-subscription-cancel').trigger('click')
    expect(keep.send).toHaveBeenCalledTimes(1)
    expect(keep.controller.success.get()).toBeNull()
    const explicit = harness(`#status-unsubscribe=${token}`)
    explicit.controller.start()
    explicit.reply({ maskedEmail: 'r***@example.net' })
    await settle()
    explicit.freeze(true)
    await settle()
    expect(
      (byId('status-subscription-submit').element as HTMLButtonElement)
        .disabled,
    ).toBe(true)
    await byId('status-subscription-form').trigger('submit')
    explicit.freeze(false)
    explicit.connect(false)
    await settle()
    await byId('status-subscription-form').trigger('submit')
    expect(explicit.send).toHaveBeenCalledTimes(1)
    explicit.connect(true)
    await settle()
    await byId('status-subscription-form').trigger('submit')
    expect(explicit.send.mock.calls[1]).toEqual([
      'status_unsubscribe',
      { token },
      '2',
    ])
    expect(explicit.controller.success.get()).toBeNull()
    explicit.reply({ status: 'unsubscribed' })
    await settle()
    expect(body().find('[data-id="modal"]').exists()).toBe(false)
    expect(explicit.controller.success.get()).toBe(COPY.unsubscribed)
  })
})
