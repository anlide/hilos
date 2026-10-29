import { nextTick } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { hilosToasts, HILOS_VIEW_MODE_STRIP_TEXT_ID } from '@hilos/core/base'

import {
  announcement,
  body,
  settled,
  worksPage,
  workRow,
} from '../../../test/statusWorksFixtures.js'

const cleanups: (() => void)[] = []
beforeEach(() => vi.useFakeTimers())
afterEach(() => {
  for (const cleanup of cleanups.splice(0).reverse()) cleanup()
  hilosToasts.clear()
  vi.useRealTimers()
})
async function open() {
  const h = await worksPage(cleanups)
  h.window()
  await nextTick()
  const opener = await h.open('cancel')
  return { ...h, opener }
}
const form = () => body().get('[data-id="hilos-status-work-cancel-form"]')
const confirm = () => body().get('[data-id="hilos-status-work-cancel-confirm"]')

describe('HilosStatusCancelWorkModal with the real focused target and action handle', () => {
  it('names the confirmation, traps focus, returns to the opener and follows the latest title and interval', async () => {
    const h = await open()
    const modal = body().get('[data-id="modal"]')
    expect(modal.attributes('aria-label')).toBe('Cancel scheduled maintenance')
    expect(modal.attributes('aria-modal')).toBe('true')
    expect(document.activeElement).toBe(modal.element)
    const before = body()
      .get('[data-id="hilos-status-work-cancel-interval"]')
      .text()
    h.update({
      title: 'Latest title',
      startsAt: announcement.startsAt + 1000,
      endsAt: announcement.endsAt + 1000,
      version: 2,
    })
    await nextTick()
    expect(
      body().get('[data-id="hilos-status-work-cancel-target"]').text(),
    ).toBe('Latest title')
    expect(
      body().get('[data-id="hilos-status-work-cancel-interval"]').text(),
    ).not.toBe(before)
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
    expect(h.sent).toEqual([])
    expect(h.focuses.at(-1)).toEqual([
      'hilos_status_works',
      'hilosStatusWorks',
      '',
    ])
  })

  it('sends the live version once, holds every busy close path and leaves the mail mark unchanged until a real row echo', async () => {
    const h = await open()
    h.update({ title: 'Latest title', version: 4 })
    await nextTick()
    await form().trigger('submit')
    expect(h.sent).toEqual([
      {
        action: 'hilos_status_work_cancel',
        requestId: expect.any(String),
        data: {
          workId: 7,
          publicId: announcement.publicId,
          expectedVersion: 4,
        },
      },
    ])
    expect(confirm().attributes('aria-busy')).toBe('true')
    expect(confirm().attributes('disabled')).toBeUndefined()
    expect(confirm().text()).toBe('Cancel maintenance')
    expect(
      (form().element.closest('fieldset') as HTMLFieldSetElement).disabled,
    ).toBe(true)
    await form().trigger('submit')
    await confirm().trigger('click')
    await body().get('[data-id="modal-close"]').trigger('click')
    await body().get('[data-id="modal"]').trigger('click')
    await body().get('[data-id="modal"]').trigger('keydown', { key: 'Escape' })
    await body()
      .get('[data-id="hilos-status-work-cancel-close"]')
      .trigger('click')
    expect(h.sent).toHaveLength(1)
    expect(body().find('[data-id="modal"]').exists()).toBe(true)
    expect(hilosToasts.toasts.get()).toEqual([])
    h.reply(true)
    await settled()
    expect(body().find('[data-id="modal"]').exists()).toBe(false)
    expect(hilosToasts.toasts.get().map((toast) => toast.message)).toEqual([
      'Announcement canceled.',
    ])
    expect(
      h.wrapper.get('table [data-id="hilos-status-work-mail-7"]').text(),
    ).toBe('—')
  })

  it.each([
    'deleted',
    'recreated',
    'canceled',
    'consumed',
    'expired',
    'stale',
    'viewer',
  ] as const)(
    'blocks a %s target with a reserved explanation and keeps Back available',
    async (outcome) => {
      const h = await open()
      if (outcome === 'deleted')
        h.delta({ kind: 'row_removed', rowKey: '7', reason: 'deleted' })
      else if (outcome === 'recreated')
        h.window([workRow({ publicId: 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb' })])
      else if (outcome === 'stale')
        h.delta({ kind: 'row_stale', rowKey: '7', staleSources: ['work'] })
      else if (outcome === 'viewer') h.viewer()
      else h.update({ [`${outcome}At`]: 1_900_000_000_000 })
      await nextTick()
      expect(confirm().attributes('disabled')).toBeDefined()
      if (outcome === 'viewer')
        expect(confirm().attributes('aria-describedby')).toContain(
          HILOS_VIEW_MODE_STRIP_TEXT_ID,
        )
      expect(
        body().get('[data-id="hilos-status-work-cancel-blocked"]').text(),
      ).not.toBe('')
      await form().trigger('submit')
      expect(h.sent).toEqual([])
      await body()
        .get('[data-id="hilos-status-work-cancel-close"]')
        .trigger('click')
      expect(body().find('[data-id="modal"]').exists()).toBe(false)
    },
  )

  it('shows exact backend refusal and diagnostics through the standard reserved form error', async () => {
    const h = await open()
    const slot = body().get(
      '[data-id="hilos-status-work-cancel-error-slot"]',
    ).element
    expect(
      body().find('[data-id="hilos-status-work-cancel-error-idle"]').exists(),
    ).toBe(true)
    await form().trigger('submit')
    h.reply(false, {
      reason: 'This announcement has already started.',
      errorType: 'WorkFailure',
      errorDetail: 'Diagnostic details',
    })
    await settled()
    expect(
      body().get('[data-id="hilos-status-work-cancel-error-slot"]').element,
    ).toBe(slot)
    expect(
      body().get('[data-id="hilos-status-work-cancel-error"]').text(),
    ).toContain('This announcement has already started.')
    await body()
      .get('[data-id="hilos-status-work-cancel-error-details"]')
      .trigger('click')
    expect(body().text()).toContain('Diagnostic details')
    expect(body().findAll('[data-id="modal"]')).toHaveLength(2)
    expect(hilosToasts.toasts.get()).toEqual([])
  })

  it('does not close a later dialog or toast when an old request answers after timeout', async () => {
    const h = await open()
    await form().trigger('submit')
    await vi.advanceTimersByTimeAsync(1001)
    expect(
      body().get('[data-id="hilos-status-work-cancel-error"]').text(),
    ).toContain('The operation may have completed.')
    await body()
      .get('[data-id="hilos-status-work-cancel-close"]')
      .trigger('click')
    h.update({ title: 'New opening', version: 2 })
    await nextTick()
    await h.open('cancel')
    h.reply(true, {}, 0)
    await settled()
    expect(
      body().get('[data-id="hilos-status-work-cancel-target"]').text(),
    ).toBe('New opening')
    expect(body().find('[data-id="modal"]').exists()).toBe(true)
    expect(hilosToasts.toasts.get()).toEqual([])
  })
})
