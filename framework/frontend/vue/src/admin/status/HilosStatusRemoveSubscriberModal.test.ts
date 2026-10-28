import { DOMWrapper, flushPromises, mount } from '@vue/test-utils'
import { markRaw, nextTick } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { HIDDEN_VALUE, HilosPages, hilosToasts } from '@hilos/core/base'

import HilosStatusSubscribersPage from './HilosStatusSubscribersPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'
import {
  statusSubscribersHarness,
  subscriberRow,
} from '../../../test/statusFixtures.js'

const cleanups: (() => void)[] = []
beforeEach(() => vi.useFakeTimers())
afterEach(() => {
  for (const cleanup of cleanups.splice(0).reverse()) cleanup()
  hilosToasts.clear()
  vi.useRealTimers()
})
const body = () => new DOMWrapper(document.body)
async function settled() {
  await flushPromises()
  await nextTick()
}
async function page() {
  const h = statusSubscribersHarness()
  cleanups.push(h.dispose)
  const wrapper = mount(HilosStatusSubscribersPage, {
    props: { context: markRaw(h.context) },
    attachTo: document.body,
    global: { provide: { [hilosRouterKey as symbol]: h.router } },
  })
  cleanups.push(() => wrapper.unmount())
  h.window()
  await nextTick()
  const opener = wrapper.get(
    'table [data-id="hilos-status-subscriber-remove-7"]',
  )
  ;(opener.element as HTMLElement).focus()
  await opener.trigger('click')
  await settled()
  return { ...h, wrapper, opener }
}
const confirm = () =>
  body().get('[data-id="hilos-status-subscriber-remove-confirm"]')
const form = () => body().get('[data-id="hilos-status-subscriber-remove-form"]')

describe('HilosStatusRemoveSubscriberModal through the real page, modal and tracked action', () => {
  it('names the fieldless dialog, traps focus, closes on Escape and returns to the opening trash', async () => {
    const h = await page()
    const modal = body().get('[data-id="modal"]')
    expect(modal.attributes('role')).toBe('dialog')
    expect(modal.attributes('aria-modal')).toBe('true')
    expect(modal.text()).toContain('Remove a subscriber')
    expect(modal.attributes('aria-label')).toBe('Remove a subscriber')
    expect(
      document.getElementById(form().attributes('aria-describedby')!)
        ?.textContent,
    ).toBe('This address will stop receiving outage updates.')
    expect(document.activeElement).toBe(modal.element)
    await modal.trigger('keydown', { key: 'Enter' })
    expect(h.sent).toEqual([])
    ;(confirm().element as HTMLElement).focus()
    await modal.trigger('keydown', { key: 'Tab' })
    expect(document.activeElement).toBe(
      body().get('[data-id="modal-close"]').element,
    )
    await modal.trigger('keydown', { key: 'Tab', shiftKey: true })
    expect(document.activeElement).toBe(confirm().element)
    await modal.trigger('keydown', { key: 'Escape' })
    await settled()
    expect(body().find('[data-id="modal"]').exists()).toBe(false)
    expect(document.activeElement).toBe(h.opener.element)
  })

  it('locks busy close and duplicate submit immediately, preserves readable nodes and waits for correlated ack', async () => {
    const h = await page()
    const fieldset = form().element.closest('fieldset') as HTMLFieldSetElement
    await form().trigger('submit')
    expect(h.sent).toEqual([
      {
        action: 'hilos_status_subscriber_remove',
        data: { subscriberId: 7 },
        requestId: expect.any(String),
      },
    ])
    expect(confirm().attributes('aria-busy')).toBe('true')
    expect(confirm().attributes('disabled')).toBeUndefined()
    expect(confirm().find('[data-id="loading-button-spinner"]').exists()).toBe(
      false,
    )
    expect(form().element.closest('fieldset')).toBe(fieldset)
    expect(fieldset.disabled).toBe(true)
    expect(form().attributes('inert')).toBeUndefined()
    expect(form().attributes('aria-hidden')).toBeUndefined()
    await form().trigger('submit')
    await confirm().trigger('click')
    await body().get('[data-id="modal"]').trigger('keydown', { key: 'Escape' })
    await body().get('[data-id="modal"]').trigger('click')
    await body()
      .get('[data-id="hilos-status-subscriber-remove-cancel"]')
      .trigger('click')
    expect(h.sent).toHaveLength(1)
    expect(body().find('[data-id="modal"]').exists()).toBe(true)
    h.emit('actionSuccess', {
      action: 'hilos_status_subscriber_remove',
      requestId: 'stale',
    })
    await settled()
    expect(confirm().attributes('aria-busy')).toBe('true')
    expect(hilosToasts.toasts.get()).toEqual([])
    await vi.advanceTimersByTimeAsync(301)
    expect(confirm().find('[data-id="loading-button-spinner"]').exists()).toBe(
      true,
    )
    h.reply(true)
    await settled()
    expect(body().find('[data-id="modal"]').exists()).toBe(false)
    expect(hilosToasts.toasts.get().map((t) => t.message)).toEqual([
      'Subscriber removed.',
    ])
    expect(
      h.wrapper.get('table [data-id="hilos-status-subscriber-email-7"]').text(),
    ).toBe('reader@example.net')
    expect(document.activeElement).toBe(h.opener.element)
    h.delta({ kind: 'row_removed', rowKey: '7', reason: 'deleted', own: true })
    await nextTick()
    expect(
      h.wrapper
        .find('table [data-id="hilos-status-subscriber-email-7"]')
        .exists(),
    ).toBe(false)
  })

  it('holds the Remove label when the server deletion arrives before its ack', async () => {
    const h = await page()
    await form().trigger('submit')
    h.delta({ kind: 'row_removed', rowKey: '7', reason: 'deleted', own: true })
    await nextTick()
    expect(confirm().text()).toBe('Remove')
    expect(confirm().attributes('aria-busy')).toBe('true')
    expect(body().find('[data-id="modal"]').exists()).toBe(true)
    expect(hilosToasts.toasts.get()).toEqual([])
    h.reply(true)
    await settled()
    expect(body().find('[data-id="modal"]').exists()).toBe(false)
    expect(hilosToasts.toasts.get().map((t) => t.message)).toEqual([
      'Subscriber removed.',
    ])
  })

  it('keeps the button label and spinner quiet for a fast success', async () => {
    const h = await page()
    const button = confirm()
    expect(button.text()).toBe('Remove')
    await form().trigger('submit')
    h.reply(true, { message: 'The subscription was removed.' })
    await settled()
    await vi.advanceTimersByTimeAsync(301)
    expect(body().find('[data-id="loading-button-spinner"]').exists()).toBe(
      false,
    )
    expect(hilosToasts.toasts.get().map((t) => t.message)).toEqual([
      'The subscription was removed.',
    ])
  })

  it('captures the address/id through live replacement and refuses a deleted/recreated subscription with the same email', async () => {
    const h = await page()
    h.window([
      subscriberRow({ id: 8 }),
      subscriberRow({ email: 'changed@example.net' }),
    ])
    await nextTick()
    const target = () =>
      body().get('[data-id="hilos-status-subscriber-remove-target"]').text()
    expect(target()).toBe('reader@example.net · #7')
    h.delta({ kind: 'row_removed', rowKey: '7', reason: 'deleted' })
    h.window([subscriberRow({ id: 9 })])
    await nextTick()
    expect(target()).toBe('reader@example.net · #7')
    expect(confirm().text()).toBe('Deleted')
    expect((confirm().element as HTMLButtonElement).disabled).toBe(true)
    expect(
      body().get('[data-id="hilos-status-subscriber-remove-blocked"]').text(),
    ).toContain('This subscriber was deleted.')
    await form().trigger('submit')
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
      await nextTick()
      expect(
        body().get('[data-id="hilos-status-subscriber-remove-target"]').text(),
      ).toContain('reader@example.net')
      expect(
        body().get('[data-id="hilos-status-subscriber-remove-blocked"]').text(),
      ).not.toBe('')
      expect((confirm().element as HTMLButtonElement).disabled).toBe(true)
      expect(
        (form().element.closest('fieldset') as HTMLFieldSetElement).disabled,
      ).toBe(false)
      await form().trigger('submit')
      expect(h.sent).toEqual([])
      await body()
        .get('[data-id="hilos-status-subscriber-remove-cancel"]')
        .trigger('click')
      expect(body().find('[data-id="modal"]').exists()).toBe(false)
    },
  )

  it('uses HiddenValue in the captured modal without coercion or address leakage', async () => {
    const h = await page()
    await body()
      .get('[data-id="hilos-status-subscriber-remove-cancel"]')
      .trigger('click')
    h.window([subscriberRow({ email: HIDDEN_VALUE })])
    await nextTick()
    await h.wrapper
      .get('table [data-id="hilos-status-subscriber-remove-7"]')
      .trigger('click')
    await nextTick()
    const modal = body().get('[data-id="modal"]')
    expect(modal.text()).not.toContain('reader@example.net')
    expect(modal.text()).not.toContain('[object Object]')
    expect(modal.get('[data-id="hilos-hidden"]').text()).toContain('Hidden')
    expect((confirm().element as HTMLButtonElement).disabled).toBe(true)
  })

  it('keeps safe backend refusal and common error details inside reserved error/notice slots', async () => {
    const h = await page()
    expect(
      body()
        .find('[data-id="hilos-status-subscriber-remove-error-idle"]')
        .exists(),
    ).toBe(true)
    expect(
      body()
        .find('[data-id="hilos-status-subscriber-remove-blocked-idle"]')
        .exists(),
    ).toBe(true)
    const errorSlot = body().get(
      '[data-id="hilos-status-subscriber-remove-error-slot"]',
    ).element
    await form().trigger('submit')
    h.reply(false, {
      reason: 'This subscriber cannot be removed.',
      errorType: 'SubscriberFailure',
      errorDetail: 'Admin diagnostic',
    })
    await settled()
    expect(
      body().get('[data-id="hilos-status-subscriber-remove-error-slot"]')
        .element,
    ).toBe(errorSlot)
    expect(
      body().get('[data-id="hilos-status-subscriber-remove-error"]').text(),
    ).toContain('This subscriber cannot be removed.')
    expect(hilosToasts.toasts.get()).toEqual([])
    await body()
      .get('[data-id="hilos-status-subscriber-remove-error-details"]')
      .trigger('click')
    expect(body().findAll('[data-id="modal"]')).toHaveLength(2)
    expect(body().text()).toContain('SubscriberFailure')
    expect(body().text()).toContain('Admin diagnostic')
    expect(h.sent).toHaveLength(1)
  })

  it('reports timeout as unconfirmed and ignores late success', async () => {
    const h = await page()
    await form().trigger('submit')
    await vi.advanceTimersByTimeAsync(1001)
    expect(
      body().get('[data-id="hilos-status-subscriber-remove-error"]').text(),
    ).toContain('The operation may have completed.')
    h.reply(true)
    await settled()
    expect(body().find('[data-id="modal"]').exists()).toBe(true)
    expect(hilosToasts.toasts.get()).toEqual([])
  })

  it.each(['scope', 'unmount'] as const)(
    'disposes on %s and a late completion cannot revive the window or toast',
    async (departure) => {
      const h = await page()
      await form().trigger('submit')
      if (departure === 'scope')
        h.context.scopes.openPage(HilosPages.STATUS_COMPONENTS)
      else h.wrapper.unmount()
      h.reply(true)
      await settled()
      expect(body().find('[data-id="modal"]').exists()).toBe(false)
      expect(hilosToasts.toasts.get()).toEqual([])
      expect(h.registered.size).toBe(0)
    },
  )
})
