// The public Status subscription window in React over the real core controller,
// HilosModal, HilosFormError, LoadingButton and tracked action; only the wire is
// fake. The window owns no start or teardown: the test plays the page, which
// creates, starts and disposes the controller. Scenarios mirror
// framework/frontend/vue/src/public/HilosStatusSubscriptionModal.test.ts.
import { act, fireEvent } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { DEFAULT_SPINNER_DELAY_MS } from '@hilos/core/base'
import {
  STATUS_SUBSCRIPTION_COPY as COPY,
  type StatusSubscription,
} from '@hilos/core/status'

import { HilosStatusSubscriptionModal } from '../src/public/HilosStatusSubscriptionModal.js'
import { statusSubscriptionHarness } from './statusFixtures.js'

const token = 'ab'.repeat(32)
const cleanups: (() => void)[] = []
beforeEach(() => vi.useFakeTimers())
afterEach(() => {
  for (const cleanup of cleanups.splice(0).reverse()) act(cleanup)
  vi.useRealTimers()
  vi.restoreAllMocks()
})

function find(id: string): HTMLElement | null {
  return document.body.querySelector<HTMLElement>(`[data-id="${id}"]`)
}

function get(id: string): HTMLElement {
  const element = find(id)
  if (element === null) throw new Error(`Missing [data-id="${id}"]`)
  return element
}

function submitButton(): HTMLButtonElement {
  return get('status-subscription-submit') as HTMLButtonElement
}

function email(): HTMLInputElement {
  return get('status-subscription-email') as HTMLInputElement
}

function bodyText(): string {
  return document.body.textContent ?? ''
}

async function settled() {
  await act(async () => {
    await vi.advanceTimersByTimeAsync(0)
  })
}

async function advance(ms: number) {
  await act(async () => {
    await vi.advanceTimersByTimeAsync(ms)
  })
}

/** Submit the open form through its own submit event. */
async function submit() {
  fireEvent.submit(get('status-subscription-form'))
  await settled()
}

/**
 * What the public page draws around the window: the opener and the window
 * over the page-owned controller.
 *
 * @param props The page-owned subscription controller.
 */
function Surface({ controller }: { controller: StatusSubscription }) {
  return (
    <div>
      <button type="button" data-id="opener" onClick={() => controller.open()}>
        Subscribe to updates
      </button>
      <HilosStatusSubscriptionModal controller={controller} />
    </div>
  )
}

function harness(fragment = '', strict = false) {
  const h = statusSubscriptionHarness(fragment)
  cleanups.push(h.dispose)
  let view = h.mount(<Surface controller={h.controller} />, strict)
  let mounted = true
  const unmount = () => {
    if (!mounted) return
    mounted = false
    view.unmount()
  }
  cleanups.push(() => unmount())
  return {
    ...h,
    unmount,
    /**
     * Draw the window again over the same controller, as a page that
     * re-enters does.
     *
     * @param again Whether to wrap the new mount in StrictMode.
     */
    remount(again = strict) {
      unmount()
      view = h.mount(<Surface controller={h.controller} />, again)
      mounted = true
    },
    /** Start the controller as the page does, then press the opener. */
    async open() {
      act(() => h.controller.start())
      get('opener').focus()
      fireEvent.click(get('opener'))
      await settled()
    },
  }
}

describe('public Status subscription with real modal, form error and LoadingButton', () => {
  it('owns no start or teardown, labels the server-validated email and traps and returns focus', async () => {
    const h = harness()
    const start = vi.spyOn(h.controller, 'start')
    const dispose = vi.spyOn(h.controller, 'dispose')
    await settled()
    expect(start).not.toHaveBeenCalled()
    expect(find('modal')).toBeNull()
    await h.open()
    const modal = get('modal')
    const field = email()
    expect(modal.getAttribute('aria-label')).toBe(COPY.subscribe)
    expect(document.activeElement).toBe(field)
    expect(field.getAttribute('type')).toBe('email')
    expect(field.getAttribute('autocomplete')).toBe('email')
    expect(field.getAttribute('name')).toBe('email')
    const label = modal.querySelector('label')!
    expect(label.getAttribute('for')).toBe(field.id)
    expect(label.textContent).toBe('Email address')
    expect(get('status-subscription-form').hasAttribute('novalidate')).toBe(
      true,
    )
    for (const id of field.getAttribute('aria-describedby')!.split(' ')) {
      expect(document.getElementById(id)).not.toBeNull()
    }
    expect(modal.textContent).toContain('You do not need an account.')
    expect(modal.textContent).toContain('ignore it.')
    expect(modal.textContent).not.toContain('scheduled')
    expect(submitButton().disabled).toBe(true)
    await submit()
    expect(h.sent).toEqual([])
    fireEvent.change(field, { target: { value: 'reader@example.net' } })
    const button = submitButton()
    button.focus()
    fireEvent.keyDown(button, { key: 'Tab' })
    expect(document.activeElement).toBe(get('modal-close'))
    fireEvent.keyDown(get('modal-close'), { key: 'Tab', shiftKey: true })
    expect(document.activeElement).toBe(button)
    fireEvent.keyDown(modal, { key: 'Escape' })
    await settled()
    expect(find('modal')).toBeNull()
    expect(document.activeElement).toBe(get('opener'))
    expect(h.sent).toEqual([])
    h.unmount()
    expect(dispose).not.toHaveBeenCalled()
  })

  it('locks one native submit immediately, retains the field and label, blocks every close and delays its own spinner once', async () => {
    const h = harness()
    await h.open()
    const field = email()
    fireEvent.change(field, { target: { value: 'Reader@Example.net' } })
    const form = get('status-subscription-form')
    const fieldset = form.closest('fieldset')!
    const button = submitButton()
    const label = button.querySelector('span')!
    act(() => button.click())
    await settled()
    expect(h.sent).toEqual([
      {
        action: 'status_subscribe',
        data: { email: 'reader@example.net' },
        requestId: expect.any(String),
      },
    ])
    expect(button.getAttribute('aria-busy')).toBe('true')
    expect(button.getAttribute('aria-disabled')).toBe('true')
    expect(button.hasAttribute('disabled')).toBe(false)
    expect(field.matches(':disabled')).toBe(true)
    expect(fieldset.disabled).toBe(true)
    expect(email()).toBe(field)
    expect(field.value).toBe('Reader@Example.net')
    expect(field.closest('[aria-hidden], [inert]')).toBeNull()
    expect((get('modal-close') as HTMLButtonElement).disabled).toBe(true)
    expect(
      (get('status-subscription-cancel') as HTMLButtonElement).disabled,
    ).toBe(true)
    fireEvent.click(button)
    fireEvent.submit(form)
    fireEvent.keyDown(get('modal'), { key: 'Escape' })
    fireEvent.click(get('modal'))
    act(() => h.controller.close())
    expect(find('modal')).not.toBeNull()
    expect(h.sent).toHaveLength(1)
    await advance(31)
    expect(h.controller.loading.get()).toBe(true)
    expect(
      button.querySelector('[data-id="loading-button-spinner"]'),
    ).toBeNull()
    await advance(DEFAULT_SPINNER_DELAY_MS - 31)
    expect(
      button.querySelector('[data-id="loading-button-spinner"]'),
    ).not.toBeNull()
    expect(button.querySelector('span')).toBe(label)
    expect(button.textContent).toContain('Subscribe')
    h.answer({ status: 'check_inbox' })
    await settled()
    expect(find('modal')).toBeNull()
    expect(h.controller.success.get()).toBe(COPY.checkInbox)
    expect(document.activeElement).toBe(get('opener'))
  })

  it('keeps fast replies free of a spinner and does not validate an email in the view', async () => {
    const h = harness()
    await h.open()
    fireEvent.change(email(), { target: { value: 'not-an-email' } })
    await submit()
    expect(h.sent[0]?.data).toEqual({ email: 'not-an-email' })
    await advance(50)
    expect(find('loading-button-spinner')).toBeNull()
    h.answer({ status: 'check_inbox' })
    await settled()
    await advance(DEFAULT_SPINNER_DELAY_MS)
    expect(find('loading-button-spinner')).toBeNull()
    expect(h.controller.success.get()).toBe(COPY.checkInbox)
  })

  it('shows backend refusal words through the reserved error slot and keeps its ARIA association and draft', async () => {
    const h = harness()
    await h.open()
    const field = email()
    const slot = get('status-subscription-error-slot')
    fireEvent.change(field, { target: { value: 'reader@example.net' } })
    await submit()
    act(() =>
      h.emit('actionError', {
        action: 'status_subscribe',
        requestId: h.sent[0]!.requestId,
        reason: 'Please enter a valid email address.',
        errorDetail: `Internal detail ${token}`,
        errorType: 'PrivateClass',
      }),
    )
    await settled()
    expect(get('status-subscription-error-slot')).toBe(slot)
    expect(slot.getAttribute('role')).toBe('alert')
    expect(get('status-subscription-error').textContent).toBe(
      'Please enter a valid email address.',
    )
    expect(slot.contains(get('status-subscription-error'))).toBe(true)
    expect(
      field
        .getAttribute('aria-describedby')!
        .split(' ')
        .some((id) => document.getElementById(id)?.contains(slot)),
    ).toBe(true)
    expect(field.getAttribute('aria-invalid')).toBe('true')
    expect(field.matches(':disabled')).toBe(false)
    expect(field.value).toBe('reader@example.net')
    expect(bodyText()).not.toContain(token)
    expect(bodyText()).not.toContain('PrivateClass')
    fireEvent.click(get('status-subscription-error-details'))
    await settled()
    expect(get('status-subscription-error-full').textContent).toBe(
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
      act(() => h.controller.start())
      await settled()
      expect(window.location.hash).toBe('')
      expect(find('modal')).toBeNull()
      if (outcome === 'timeout') await advance(1001)
      else if (outcome === 'disconnect') h.connect('disconnected')
      else if (outcome === 'expired')
        act(() =>
          h.emit('actionError', {
            action: 'status_subscription_confirm',
            requestId: h.sent[0]!.requestId,
            reason: 'This confirmation link has expired.',
            errorDetail: token,
          }),
        )
      await settled()
      expect(h.controller.error.get()).toBe(
        outcome === 'malformed'
          ? COPY.invalidLink
          : outcome === 'expired'
            ? 'This confirmation link has expired.'
            : COPY.unconfirmed,
      )
      expect(h.controller.success.get()).toBeNull()
      expect(bodyText()).not.toContain(token)
      h.connect('connected')
      h.freeze(true)
      h.freeze(false)
      if (outcome !== 'malformed') h.answer({ status: 'subscribed' })
      await settled()
      expect(h.sent).toHaveLength(outcome === 'malformed' ? 0 : 1)
      expect(h.controller.success.get()).toBeNull()
      expect(find('modal')).toBeNull()
    },
  )

  it('previews only the mask and Keep never deletes; only an explicit submit unsubscribes after recovery', async () => {
    const keep = harness(`#status-unsubscribe=${token}`)
    act(() => keep.controller.start())
    await settled()
    expect(keep.sent).toEqual([
      {
        action: 'status_unsubscribe_preview',
        data: { token },
        requestId: expect.any(String),
      },
    ])
    expect(find('status-subscription-submit')).toBeNull()
    expect(document.activeElement).toBe(get('modal'))
    const address = get('status-subscription-address')
    const reserved = [
      ...get('modal').querySelectorAll('[aria-hidden="true"][inert]'),
    ].filter((node) => node.textContent === COPY.unsubscribe)
    expect(reserved).toHaveLength(1)
    expect(reserved[0]!.hasAttribute('data-id')).toBe(false)
    keep.answer({ maskedEmail: 'r***@example.net' })
    await settled()
    expect(get('status-subscription-address')).toBe(address)
    expect(address.textContent).toBe(
      'Stop sending status updates to r***@example.net?',
    )
    expect(document.body.querySelector('input')).toBeNull()
    expect(bodyText()).not.toContain('reader@example.net')
    expect(bodyText()).not.toContain(token)
    fireEvent.click(get('status-subscription-cancel'))
    await settled()
    expect(keep.sent).toHaveLength(1)
    expect(keep.controller.success.get()).toBeNull()
    const explicit = harness(`#status-unsubscribe=${token}`)
    act(() => explicit.controller.start())
    await settled()
    explicit.answer({ maskedEmail: 'r***@example.net' })
    await settled()
    explicit.freeze(true)
    await settled()
    expect(submitButton().disabled).toBe(true)
    await submit()
    explicit.freeze(false)
    explicit.connect('disconnected')
    await settled()
    await submit()
    expect(explicit.sent).toHaveLength(1)
    explicit.connect('connected')
    await settled()
    await submit()
    expect(explicit.sent[1]).toEqual({
      action: 'status_unsubscribe',
      data: { token },
      requestId: expect.any(String),
    })
    expect(explicit.controller.success.get()).toBeNull()
    explicit.answer({ status: 'unsubscribed' })
    await settled()
    expect(find('modal')).toBeNull()
    expect(explicit.controller.success.get()).toBe(COPY.unsubscribed)
  })

  it('draws one window and sends one request under StrictMode', async () => {
    const h = harness('', true)
    await h.open()
    expect(document.body.querySelectorAll('[data-id="modal"]')).toHaveLength(1)
    expect(document.activeElement).toBe(email())
    fireEvent.change(email(), { target: { value: 'reader@example.net' } })
    expect(submitButton().disabled).toBe(false)
    await submit()
    expect(h.sent).toEqual([
      {
        action: 'status_subscribe',
        data: { email: 'reader@example.net' },
        requestId: expect.any(String),
      },
    ])
    h.answer({ status: 'check_inbox' })
    await settled()
    expect(find('modal')).toBeNull()
    expect(h.controller.success.get()).toBe(COPY.checkInbox)
  })

  it('leaves the controller alive when it unmounts, draws its live state on re-entry and closes when the page disposes it', async () => {
    const h = harness('', true)
    const dispose = vi.spyOn(h.controller, 'dispose')
    await h.open()
    fireEvent.change(email(), { target: { value: 'reader@example.net' } })
    h.unmount()
    expect(find('modal')).toBeNull()
    expect(dispose).not.toHaveBeenCalled()
    act(() => h.controller.setDraft({ email: 'later@example.net' }))
    h.remount()
    await settled()
    expect(document.body.querySelectorAll('[data-id="modal"]')).toHaveLength(1)
    expect(email().value).toBe('later@example.net')
    await submit()
    expect(h.sent).toEqual([
      {
        action: 'status_subscribe',
        data: { email: 'later@example.net' },
        requestId: expect.any(String),
      },
    ])
    act(() => h.controller.dispose())
    await settled()
    expect(find('modal')).toBeNull()
    expect(h.sent).toHaveLength(1)
  })
})
