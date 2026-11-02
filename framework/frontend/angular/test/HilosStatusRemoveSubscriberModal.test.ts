import { HIDDEN_VALUE, hilosToasts } from '@hilos/core/base'
import { describe, expect, it, vi } from 'vitest'

import { HilosStatusSubscribersPage } from '../src/admin/status/HilosStatusSubscribersPage.js'
import {
  el,
  mountStatusSubscribers,
  settled,
  statusSubscribersHarness,
  subscriberRow,
} from './helpers/hilosStatus.js'

function page(viewer = false) {
  const h = mountStatusSubscribers(HilosStatusSubscribersPage, viewer)
  h.window([subscriberRow()])
  h.detect()
  h.act(() => el<HTMLButtonElement>('hilos-status-subscriber-remove-7').click())
  return h
}

const form = () => el<HTMLFormElement>('hilos-status-subscriber-remove-form')
const confirm = () =>
  el<HTMLButtonElement>('hilos-status-subscriber-remove-confirm')
const modal = () => el<HTMLDivElement>('modal')

function submit(): void {
  form().dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))
}

describe('HilosStatusRemoveSubscriberModal through the real page and core controller', () => {
  it('keeps the captured address and id through row replacement and waits for the exact acknowledgement', async () => {
    const h = page()
    expect(form().getAttribute('aria-label')).toBe('Remove a subscriber')
    expect(el('hilos-status-subscriber-remove-target').textContent).toContain(
      'reader@example.net · #7',
    )
    h.window([
      subscriberRow({ id: 8 }),
      subscriberRow({ email: 'changed@example.net' }),
    ])
    h.detect()
    expect(el('hilos-status-subscriber-remove-target').textContent).toContain(
      'reader@example.net · #7',
    )

    const heldForm = form()
    h.act(submit)
    expect(h.sent).toEqual([
      {
        action: 'hilos_status_subscriber_remove',
        data: { subscriberId: 7 },
        requestId: expect.any(String),
      },
    ])
    expect(confirm().getAttribute('aria-busy')).toBe('true')
    expect(form()).toBe(heldForm)
    expect(hilosToasts.toasts.get()).toEqual([])
    h.reply(true)
    await settled(h.fixture)
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    expect(hilosToasts.toasts.get().map((toast) => toast.message)).toEqual([
      'Subscriber removed.',
    ])
    expect(
      document.querySelector('[data-id="hilos-status-subscriber-email-7"]'),
    ).not.toBeNull()
    h.delta({ kind: 'row_removed', rowKey: '7', reason: 'deleted', own: true })
    h.detect()
    expect(
      document.querySelector('[data-id="hilos-status-subscriber-email-7"]'),
    ).toBeNull()
    expect(h.viewports).toEqual([])
  })

  it('keeps the removal window busy when the owner delta arrives before its acknowledgement', async () => {
    const h = page()
    h.act(submit)
    h.delta({ kind: 'row_removed', rowKey: '7', reason: 'deleted', own: true })
    h.detect()
    expect(modal()).not.toBeNull()
    expect(confirm().textContent).toContain('Remove')
    expect(confirm().getAttribute('aria-busy')).toBe('true')
    expect(hilosToasts.toasts.get()).toEqual([])
    h.reply(true)
    await settled(h.fixture)
    expect(document.querySelector('[data-id="modal"]')).toBeNull()
    expect(hilosToasts.toasts.get().map((toast) => toast.message)).toEqual([
      'Subscriber removed.',
    ])
  })

  it('keeps a safe server refusal and its details in the reserved modal error slot', async () => {
    const h = page()
    const errorSlot = el('hilos-status-subscriber-remove-error-slot')
    h.act(submit)
    h.reply(false, {
      reason: 'This subscriber cannot be removed.',
      errorType: 'SubscriberFailure',
      errorDetail: 'Admin diagnostic',
    })
    await settled(h.fixture)
    expect(el('hilos-status-subscriber-remove-error-slot')).toBe(errorSlot)
    expect(el('hilos-status-subscriber-remove-error').textContent).toContain(
      'This subscriber cannot be removed.',
    )
    expect(hilosToasts.toasts.get()).toEqual([])
    h.act(() => el('hilos-status-subscriber-remove-error-details').click())
    expect(document.querySelectorAll('[data-id="modal"]')).toHaveLength(2)
    expect(document.body.textContent).toContain('SubscriberFailure')
    expect(document.body.textContent).toContain('Admin diagnostic')
  })

  it('refuses a deleted captured id after a replacement row appears', () => {
    const h = page()
    h.delta({ kind: 'row_removed', rowKey: '7', reason: 'deleted' })
    h.window([subscriberRow({ id: 9 })])
    h.detect()
    expect(el('hilos-status-subscriber-remove-target').textContent).toContain(
      'reader@example.net · #7',
    )
    expect(confirm().textContent).toContain('Deleted')
    expect(confirm().disabled).toBe(true)
    expect(el('hilos-status-subscriber-remove-blocked').textContent).toContain(
      'This subscriber was deleted.',
    )
    h.act(submit)
    expect(h.sent).toEqual([])
  })

  it.each(['disconnect', 'freeze', 'stale'] as const)(
    'keeps the captured target visible and refuses submit during %s',
    (mode) => {
      const h = page()
      if (mode === 'disconnect') h.connect('disconnected')
      if (mode === 'freeze') h.freeze(true)
      if (mode === 'stale')
        h.delta({
          kind: 'row_stale',
          rowKey: '7',
          staleSources: ['subscriber'],
        })
      h.detect()
      expect(el('hilos-status-subscriber-remove-target').textContent).toContain(
        'reader@example.net',
      )
      expect(el('hilos-status-subscriber-remove-blocked').textContent).not.toBe(
        '',
      )
      expect(confirm().disabled).toBe(true)
      h.act(submit)
      expect(h.sent).toEqual([])
      h.act(() => el('hilos-status-subscriber-remove-cancel').click())
      expect(document.querySelector('[data-id="modal"]')).toBeNull()
    },
  )

  it('keeps HiddenValue hidden in the open window and does not send its mutation', () => {
    const h = mountStatusSubscribers(HilosStatusSubscribersPage)
    h.window([subscriberRow({ email: HIDDEN_VALUE })])
    h.detect()
    h.act(() =>
      el<HTMLButtonElement>('hilos-status-subscriber-remove-7').click(),
    )
    expect(
      el('hilos-status-subscriber-remove-target').textContent,
    ).not.toContain('reader@example.net')
    expect(
      el('hilos-status-subscriber-remove-target').textContent,
    ).not.toContain('[object Object]')
    expect(el('hilos-status-subscriber-remove-target').textContent).toContain(
      'Hidden',
    )
    expect(confirm().disabled).toBe(true)
    h.act(submit)
    expect(h.sent).toEqual([])
  })

  it('hides the captured address immediately when viewer mode starts in the open window', () => {
    const h = page()
    expect(el('hilos-status-subscriber-remove-target').textContent).toContain(
      'reader@example.net',
    )
    h.viewer()
    h.detect()
    expect(
      el('hilos-status-subscriber-remove-target').textContent,
    ).not.toContain('reader@example.net')
    expect(el('hilos-status-subscriber-remove-target').textContent).toContain(
      'Hidden',
    )
    expect(confirm().disabled).toBe(true)
    h.act(submit)
    expect(h.sent).toEqual([])
  })

  it('ignores a success that arrives after the action has timed out', async () => {
    const h = page()
    h.act(submit)
    await vi.advanceTimersByTimeAsync(1001)
    await settled(h.fixture)
    expect(el('hilos-status-subscriber-remove-error').textContent).toContain(
      'The result is unconfirmed. The operation may have completed.',
    )
    h.reply(true)
    await settled(h.fixture)
    expect(document.querySelector('[data-id="modal"]')).not.toBeNull()
    expect(hilosToasts.toasts.get()).toEqual([])
  })

  it('disposes its controller with the replaced page context and ignores the late acknowledgement', async () => {
    const h = page()
    h.act(submit)
    const second = statusSubscribersHarness()
    try {
      h.fixture.componentRef.setInput('context', second.context)
      h.detect()
      expect(h.registered.size).toBe(0)
      expect(second.registered.size).toBe(1)
      h.reply(true)
      await settled(h.fixture)
      expect(document.querySelector('[data-id="modal"]')).toBeNull()
      expect(hilosToasts.toasts.get()).toEqual([])
      expect(second.sent).toEqual([])
      h.fixture.destroy()
      expect(second.registered.size).toBe(0)
    } finally {
      second.dispose()
    }
  })
})
