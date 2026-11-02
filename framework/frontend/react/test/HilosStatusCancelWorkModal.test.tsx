// The scheduled maintenance cancellation window in React over the real core
// cancellation controller, its focused Works table, HilosModal, LoadingButton
// and tracked action, inside the real admin page shell; only the wire is fake.
// Each test creates and starts the Works page's core generation itself, as the
// page does in its effect, and hands it to the window; the page is proven by
// its own test. Scenarios mirror
// framework/frontend/vue/src/admin/status/HilosStatusCancelWorkModal.test.ts.
import { act, fireEvent } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  HilosPages,
  hilosToasts,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
} from '@hilos/core/base'

import { HilosStatusCancelWorkModal } from '../src/admin/status/HilosStatusCancelWorkModal.js'
import { readerTimeZone } from './readerTimeZone.js'
import {
  announcement,
  statusWorksHarness,
  statusWorksWindows,
  workRow,
} from './statusFixtures.js'

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

function form(): HTMLElement {
  return get('hilos-status-work-cancel-form')
}

function confirmButton(): HTMLButtonElement {
  return get('hilos-status-work-cancel-confirm') as HTMLButtonElement
}

function back(): HTMLButtonElement {
  return get('hilos-status-work-cancel-close') as HTMLButtonElement
}

function interval(): string {
  return get('hilos-status-work-cancel-interval').textContent ?? ''
}

function toasts(): string[] {
  return hilosToasts.toasts.get().map((toast) => toast.message)
}

async function settled() {
  await act(async () => {
    await vi.advanceTimersByTimeAsync(0)
  })
}

/**
 * Mount the window over a started generation, answer the page, and open it on
 * the default announcement from a focused opener, as the Works page does.
 *
 * @param strict Whether to mount under StrictMode.
 */
async function open(strict = false) {
  const h = statusWorksHarness()
  cleanups.push(h.dispose)
  const w = statusWorksWindows(h.context)
  cleanups.push(w.dispose)
  const view = h.mount(
    <>
      <button type="button" data-id="works-opener">
        Cancel
      </button>
      <HilosStatusCancelWorkModal controller={w.cancel} />
    </>,
    strict,
  )
  cleanups.push(view.unmount)
  h.window()
  const opener = get('works-opener')
  opener.focus()
  act(() => {
    w.cancel.open('7')
  })
  await settled()
  return { h, w, opener }
}

describe('HilosStatusCancelWorkModal with the real focused target and action handle', () => {
  it('names the confirmation, traps focus, returns to the opener and follows the latest title and interval', async () => {
    const { h, opener } = await open()
    const modal = get('modal')
    expect(modal.getAttribute('aria-label')).toBe(
      'Cancel scheduled maintenance',
    )
    expect(form().getAttribute('aria-label')).toBe(
      'Cancel scheduled maintenance',
    )
    expect(
      document.getElementById(form().getAttribute('aria-describedby')!)
        ?.textContent,
    ).toBe(
      'This announcement will leave the upcoming list. Subscribers will be notified of the cancellation.',
    )
    expect(document.activeElement).toBe(modal)
    expect(get('hilos-status-work-cancel-target').textContent).toBe(
      'Database upgrade',
    )
    const before = interval()
    h.update({
      title: 'Latest title',
      startsAt: announcement.startsAt + 1000,
      endsAt: announcement.endsAt + 1000,
      version: 2,
    })
    expect(get('hilos-status-work-cancel-target').textContent).toBe(
      'Latest title',
    )
    expect(interval()).not.toBe(before)
    confirmButton().focus()
    fireEvent.keyDown(modal, { key: 'Tab' })
    expect(document.activeElement).toBe(get('modal-close'))
    fireEvent.keyDown(modal, { key: 'Tab', shiftKey: true })
    expect(document.activeElement).toBe(confirmButton())
    fireEvent.keyDown(modal, { key: 'Escape' })
    await settled()
    expect(find('modal')).toBeNull()
    expect(document.activeElement).toBe(opener)
    expect(h.sent).toEqual([])
    expect(h.focuses.at(-1)).toEqual([
      'hilos_status_works',
      'hilosStatusWorks',
      '',
    ])
  })

  it('draws the interval in the reader zone and follows its change without a server reread', async () => {
    const zone = readerTimeZone()
    cleanups.push(zone.release)
    act(() => zone.choose('UTC'))
    const { h } = await open()
    const before = interval()
    const viewports = h.viewports.length
    act(() => zone.choose('Asia/Tokyo'))
    expect(interval()).not.toBe(before)
    expect(h.viewports).toHaveLength(viewports)
    expect(h.sent).toEqual([])
  })

  it('sends the live version once, holds every busy close path and waits for its correlated ack', async () => {
    const { h } = await open()
    h.update({ title: 'Latest title', version: 4 })
    const fieldset = form().closest('fieldset')!
    fireEvent.submit(form())
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
    expect(confirmButton().getAttribute('aria-busy')).toBe('true')
    expect(confirmButton().disabled).toBe(false)
    expect(confirmButton().textContent).toBe('Cancel maintenance')
    expect(form().closest('fieldset')).toBe(fieldset)
    expect(fieldset.disabled).toBe(true)
    expect(back().disabled).toBe(true)
    fireEvent.submit(form())
    fireEvent.click(confirmButton())
    fireEvent.click(get('modal-close'))
    fireEvent.click(get('modal'))
    fireEvent.keyDown(get('modal'), { key: 'Escape' })
    fireEvent.click(back())
    expect(h.sent).toHaveLength(1)
    expect(find('modal')).not.toBeNull()
    act(() =>
      h.emit('actionSuccess', {
        action: 'hilos_status_work_cancel',
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
    expect(toasts()).toEqual(['Announcement canceled.'])
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
      const { h } = await open()
      if (outcome === 'deleted')
        h.delta({ kind: 'row_removed', rowKey: '7', reason: 'deleted' })
      else if (outcome === 'recreated')
        h.window([workRow({ publicId: 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb' })])
      else if (outcome === 'stale')
        h.delta({ kind: 'row_stale', rowKey: '7', staleSources: ['work'] })
      else if (outcome === 'viewer') h.viewer()
      else h.update({ [`${outcome}At`]: 1_900_000_000_000 })
      expect(confirmButton().disabled).toBe(true)
      if (outcome === 'viewer')
        expect(confirmButton().getAttribute('aria-describedby')).toContain(
          HILOS_VIEW_MODE_STRIP_TEXT_ID,
        )
      expect(get('hilos-status-work-cancel-blocked').textContent).not.toBe('')
      expect(form().closest('fieldset')!.disabled).toBe(false)
      fireEvent.submit(form())
      expect(h.sent).toEqual([])
      fireEvent.click(back())
      expect(find('modal')).toBeNull()
    },
  )

  it('names a target that is gone and labels the confirmation Deleted', async () => {
    const { h } = await open()
    h.window([workRow({ publicId: 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb' })])
    expect(get('hilos-status-work-cancel-target').textContent).toBe(
      'Announcement unavailable',
    )
    expect(find('hilos-status-work-cancel-interval')).toBeNull()
    expect(confirmButton().textContent).toBe('Deleted')
    expect(confirmButton().disabled).toBe(true)
  })

  it('shows exact backend refusal and diagnostics through the standard reserved form error', async () => {
    const { h } = await open()
    const slot = get('hilos-status-work-cancel-error-slot')
    expect(find('hilos-status-work-cancel-error-idle')).not.toBeNull()
    expect(find('hilos-status-work-cancel-blocked-idle')).not.toBeNull()
    fireEvent.submit(form())
    act(() =>
      h.reply(false, {
        reason: 'This announcement has already started.',
        errorType: 'WorkFailure',
        errorDetail: 'Diagnostic details',
      }),
    )
    await settled()
    expect(get('hilos-status-work-cancel-error-slot')).toBe(slot)
    expect(get('hilos-status-work-cancel-error').textContent).toContain(
      'This announcement has already started.',
    )
    fireEvent.click(get('hilos-status-work-cancel-error-details'))
    expect(document.body.textContent).toContain('Diagnostic details')
    expect(document.body.querySelectorAll('[data-id="modal"]')).toHaveLength(2)
    expect(toasts()).toEqual([])
  })

  it('does not close a later dialog or toast when an old request answers after timeout', async () => {
    const { h, w } = await open()
    fireEvent.submit(form())
    await act(async () => {
      await vi.advanceTimersByTimeAsync(1001)
    })
    expect(get('hilos-status-work-cancel-error').textContent).toContain(
      'The operation may have completed.',
    )
    fireEvent.click(back())
    expect(find('modal')).toBeNull()
    h.update({ title: 'New opening', version: 2 })
    act(() => {
      w.cancel.open('7')
    })
    act(() => h.reply(true, {}, 0))
    await settled()
    expect(get('hilos-status-work-cancel-target').textContent).toBe(
      'New opening',
    )
    expect(find('modal')).not.toBeNull()
    expect(toasts()).toEqual([])
  })

  it.each(['scope', 'dispose'] as const)(
    'does not toast or revive the window after the page %s ends its generation',
    async (departure) => {
      const { h, w } = await open()
      fireEvent.submit(form())
      if (departure === 'scope')
        act(() => {
          h.context.scopes.openPage(HilosPages.STATUS_COMPONENTS)
        })
      else act(() => w.dispose())
      act(() => h.reply(true))
      await settled()
      expect(find('modal')).toBeNull()
      expect(toasts()).toEqual([])
      expect(h.registered.size).toBe(0)
    },
  )

  it('opens and cancels through the live window under StrictMode, one request and one toast', async () => {
    const { h } = await open(true)
    expect(h.registered.size).toBe(1)
    expect(get('hilos-status-work-cancel-target').textContent).toBe(
      'Database upgrade',
    )
    expect(confirmButton().disabled).toBe(false)
    fireEvent.submit(form())
    expect(h.sent).toEqual([
      {
        action: 'hilos_status_work_cancel',
        requestId: expect.any(String),
        data: {
          workId: 7,
          publicId: announcement.publicId,
          expectedVersion: 1,
        },
      },
    ])
    act(() => h.reply(true))
    await settled()
    expect(find('modal')).toBeNull()
    expect(toasts()).toEqual(['Announcement canceled.'])
  })
})
