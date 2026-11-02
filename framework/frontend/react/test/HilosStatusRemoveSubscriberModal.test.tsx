// The subscriber removal window in React, opened from the real Subscribers page
// with the real core controller, HilosModal, LoadingButton and tracked action;
// only the wire is fake. Scenarios mirror
// framework/frontend/vue/src/admin/status/HilosStatusRemoveSubscriberModal.test.ts.
import { act, fireEvent } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { HIDDEN_VALUE, HilosPages, hilosToasts } from '@hilos/core/base'

import { HilosStatusSubscribersPage } from '../src/admin/status/HilosStatusSubscribersPage.js'
import { statusSubscribersHarness, subscriberRow } from './statusFixtures.js'

const cleanups: (() => void)[] = []
beforeEach(() => vi.useFakeTimers())
afterEach(() => {
  for (const cleanup of cleanups.splice(0).reverse()) act(cleanup)
  hilosToasts.clear()
  vi.useRealTimers()
})

function find(id: string): HTMLElement | null {
  return document.body.querySelector<HTMLElement>(`[data-id="${id}"]`)
}

function get(id: string): HTMLElement {
  const element = find(id)
  if (element === null) throw new Error(`Missing [data-id="${id}"]`)
  return element
}

function table(): HTMLTableElement {
  const element = document.querySelector('table')
  if (element === null) throw new Error('Missing table')
  return element
}

function confirmButton(): HTMLButtonElement {
  return get('hilos-status-subscriber-remove-confirm') as HTMLButtonElement
}

function form(): HTMLElement {
  return get('hilos-status-subscriber-remove-form')
}

function fieldsetOf(element: Element): HTMLFieldSetElement {
  return element.closest('fieldset')!
}

function toasts(): string[] {
  return hilosToasts.toasts.get().map((toast) => toast.message)
}

async function settled() {
  await act(async () => {
    await vi.advanceTimersByTimeAsync(0)
  })
}

/** Submit the open removal form through its own submit event. */
async function submit() {
  fireEvent.submit(form())
  await settled()
}

async function page(strict = false) {
  const h = statusSubscribersHarness()
  cleanups.push(h.dispose)
  const view = h.mount(
    <HilosStatusSubscribersPage context={h.context} />,
    strict,
  )
  let mounted = true
  const unmount = () => {
    if (!mounted) return
    mounted = false
    view.unmount()
  }
  cleanups.push(unmount)
  h.window()
  const opener = table().querySelector<HTMLElement>(
    '[data-id="hilos-status-subscriber-remove-7"]',
  )!
  opener.focus()
  fireEvent.click(opener)
  await settled()
  return { ...h, view, unmount, opener }
}

describe('HilosStatusRemoveSubscriberModal through the real page, modal and tracked action', () => {
  it('names the fieldless dialog, traps focus, closes on Escape and returns to the opening trash', async () => {
    const h = await page()
    const modal = get('modal')
    expect(modal.textContent).toContain('Remove a subscriber')
    expect(modal.getAttribute('aria-label')).toBe('Remove a subscriber')
    expect(
      document.getElementById(form().getAttribute('aria-describedby')!)
        ?.textContent,
    ).toBe('This address will stop receiving outage updates.')
    expect(document.activeElement).toBe(modal)
    fireEvent.keyDown(modal, { key: 'Enter' })
    expect(h.sent).toEqual([])
    confirmButton().focus()
    fireEvent.keyDown(modal, { key: 'Tab' })
    expect(document.activeElement).toBe(get('modal-close'))
    fireEvent.keyDown(modal, { key: 'Tab', shiftKey: true })
    expect(document.activeElement).toBe(confirmButton())
    fireEvent.keyDown(modal, { key: 'Escape' })
    await settled()
    expect(find('modal')).toBeNull()
    expect(document.activeElement).toBe(h.opener)
  })

  it('locks busy close and duplicate submit immediately, preserves readable nodes and waits for correlated ack', async () => {
    const h = await page()
    const fieldset = fieldsetOf(form())
    fireEvent.submit(form())
    expect(h.sent).toEqual([
      {
        action: 'hilos_status_subscriber_remove',
        data: { subscriberId: 7 },
        requestId: expect.any(String),
      },
    ])
    const confirm = confirmButton()
    expect(confirm.getAttribute('aria-busy')).toBe('true')
    expect(confirm.disabled).toBe(false)
    expect(
      confirm.querySelector('[data-id="loading-button-spinner"]'),
    ).toBeNull()
    expect(fieldsetOf(form())).toBe(fieldset)
    expect(fieldset.disabled).toBe(true)
    expect(form().hasAttribute('inert')).toBe(false)
    expect(form().hasAttribute('aria-hidden')).toBe(false)
    fireEvent.submit(form())
    fireEvent.click(confirm)
    fireEvent.keyDown(get('modal'), { key: 'Escape' })
    fireEvent.click(get('modal'))
    fireEvent.click(get('hilos-status-subscriber-remove-cancel'))
    expect(h.sent).toHaveLength(1)
    expect(find('modal')).not.toBeNull()
    act(() =>
      h.emit('actionSuccess', {
        action: 'hilos_status_subscriber_remove',
        requestId: 'stale',
      }),
    )
    await settled()
    expect(confirmButton().getAttribute('aria-busy')).toBe('true')
    expect(toasts()).toEqual([])
    await act(async () => {
      await vi.advanceTimersByTimeAsync(301)
    })
    expect(
      confirmButton().querySelector('[data-id="loading-button-spinner"]'),
    ).not.toBeNull()
    act(() => h.reply(true))
    await settled()
    expect(find('modal')).toBeNull()
    expect(toasts()).toEqual(['Subscriber removed.'])
    expect(
      table().querySelector('[data-id="hilos-status-subscriber-email-7"]')
        ?.textContent,
    ).toBe('reader@example.net')
    expect(document.activeElement).toBe(h.opener)
    h.delta({ kind: 'row_removed', rowKey: '7', reason: 'deleted', own: true })
    expect(
      table().querySelector('[data-id="hilos-status-subscriber-email-7"]'),
    ).toBeNull()
  })

  it('holds the Remove label when the server deletion arrives before its ack', async () => {
    const h = await page()
    await submit()
    h.delta({ kind: 'row_removed', rowKey: '7', reason: 'deleted', own: true })
    expect(confirmButton().textContent).toBe('Remove')
    expect(confirmButton().getAttribute('aria-busy')).toBe('true')
    expect(find('modal')).not.toBeNull()
    expect(toasts()).toEqual([])
    act(() => h.reply(true))
    await settled()
    expect(find('modal')).toBeNull()
    expect(toasts()).toEqual(['Subscriber removed.'])
  })

  it('keeps the button label and spinner quiet for a fast success', async () => {
    const h = await page()
    expect(confirmButton().textContent).toBe('Remove')
    await submit()
    act(() => h.reply(true, { message: 'The subscription was removed.' }))
    await settled()
    await act(async () => {
      await vi.advanceTimersByTimeAsync(301)
    })
    expect(find('loading-button-spinner')).toBeNull()
    expect(toasts()).toEqual(['The subscription was removed.'])
  })

  it('captures the address/id through live replacement and refuses a deleted/recreated subscription with the same email', async () => {
    const h = await page()
    h.window([
      subscriberRow({ id: 8 }),
      subscriberRow({ email: 'changed@example.net' }),
    ])
    const target = () =>
      get('hilos-status-subscriber-remove-target').textContent
    expect(target()).toBe('reader@example.net · #7')
    h.delta({ kind: 'row_removed', rowKey: '7', reason: 'deleted' })
    h.window([subscriberRow({ id: 9 })])
    expect(target()).toBe('reader@example.net · #7')
    expect(confirmButton().textContent).toBe('Deleted')
    expect(confirmButton().disabled).toBe(true)
    expect(get('hilos-status-subscriber-remove-blocked').textContent).toContain(
      'This subscriber was deleted.',
    )
    await submit()
    expect(h.sent).toEqual([])
  })

  it.each(['disconnect', 'freeze', 'stale', 'viewer'] as const)(
    'keeps the captured content and explains %s while refusing submit',
    async (mode) => {
      const h = await page()
      if (mode === 'disconnect') h.connect('disconnected')
      if (mode === 'freeze') h.freeze(true)
      if (mode === 'stale')
        h.delta({
          kind: 'row_stale',
          rowKey: '7',
          staleSources: ['subscriber'],
        })
      if (mode === 'viewer') h.viewer()
      expect(
        get('hilos-status-subscriber-remove-target').textContent,
      ).toContain('reader@example.net')
      expect(
        get('hilos-status-subscriber-remove-blocked').textContent,
      ).not.toBe('')
      expect(confirmButton().disabled).toBe(true)
      expect(fieldsetOf(form()).disabled).toBe(false)
      await submit()
      expect(h.sent).toEqual([])
      fireEvent.click(get('hilos-status-subscriber-remove-cancel'))
      expect(find('modal')).toBeNull()
    },
  )

  it('uses HiddenValue in the captured modal without coercion or address leakage', async () => {
    const h = await page()
    fireEvent.click(get('hilos-status-subscriber-remove-cancel'))
    h.window([subscriberRow({ email: HIDDEN_VALUE })])
    fireEvent.click(
      table().querySelector('[data-id="hilos-status-subscriber-remove-7"]')!,
    )
    const modal = get('modal')
    expect(modal.textContent).not.toContain('reader@example.net')
    expect(modal.textContent).not.toContain('[object Object]')
    expect(
      modal.querySelector('[data-id="hilos-hidden"]')?.textContent,
    ).toContain('Hidden')
    expect(confirmButton().disabled).toBe(true)
  })

  it('keeps safe backend refusal and common error details inside reserved error/notice slots', async () => {
    const h = await page()
    expect(find('hilos-status-subscriber-remove-error-idle')).not.toBeNull()
    expect(find('hilos-status-subscriber-remove-blocked-idle')).not.toBeNull()
    const errorSlot = get('hilos-status-subscriber-remove-error-slot')
    await submit()
    act(() =>
      h.reply(false, {
        reason: 'This subscriber cannot be removed.',
        errorType: 'SubscriberFailure',
        errorDetail: 'Admin diagnostic',
      }),
    )
    await settled()
    expect(get('hilos-status-subscriber-remove-error-slot')).toBe(errorSlot)
    expect(get('hilos-status-subscriber-remove-error').textContent).toContain(
      'This subscriber cannot be removed.',
    )
    expect(toasts()).toEqual([])
    fireEvent.click(get('hilos-status-subscriber-remove-error-details'))
    expect(document.body.querySelectorAll('[data-id="modal"]')).toHaveLength(2)
    expect(document.body.textContent).toContain('SubscriberFailure')
    expect(document.body.textContent).toContain('Admin diagnostic')
    expect(h.sent).toHaveLength(1)
  })

  it('reports timeout as unconfirmed and ignores late success', async () => {
    const h = await page()
    await submit()
    await act(async () => {
      await vi.advanceTimersByTimeAsync(1001)
    })
    expect(get('hilos-status-subscriber-remove-error').textContent).toContain(
      'The operation may have completed.',
    )
    act(() => h.reply(true))
    await settled()
    expect(find('modal')).not.toBeNull()
    expect(toasts()).toEqual([])
  })

  it.each(['scope', 'unmount'] as const)(
    'disposes on %s and a late completion cannot revive the window or toast',
    async (departure) => {
      const h = await page()
      await submit()
      if (departure === 'scope')
        act(() => {
          h.context.scopes.openPage(HilosPages.STATUS_COMPONENTS)
        })
      else h.unmount()
      act(() => h.reply(true))
      await settled()
      expect(find('modal')).toBeNull()
      expect(toasts()).toEqual([])
      expect(h.registered.size).toBe(0)
    },
  )

  it('opens and submits the live generation window under StrictMode, captured on the subscription it opened on', async () => {
    const h = await page(true)
    expect(h.registered.size).toBe(1)
    expect(get('hilos-status-subscriber-remove-target').textContent).toBe(
      'reader@example.net · #7',
    )
    h.window([
      subscriberRow({ id: 8 }),
      subscriberRow({ email: 'changed@example.net' }),
    ])
    expect(get('hilos-status-subscriber-remove-target').textContent).toBe(
      'reader@example.net · #7',
    )
    expect(confirmButton().disabled).toBe(false)
    await submit()
    expect(h.sent).toEqual([
      {
        action: 'hilos_status_subscriber_remove',
        data: { subscriberId: 7 },
        requestId: expect.any(String),
      },
    ])
    act(() => h.reply(true))
    await settled()
    expect(find('modal')).toBeNull()
    expect(toasts()).toEqual(['Subscriber removed.'])
  })
})
