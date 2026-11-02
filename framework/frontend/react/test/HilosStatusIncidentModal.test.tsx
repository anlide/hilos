// The incident window in React, opened from the real Status page with the real
// core controller, HilosModal and LoadingButton; only the wire is fake.
// Scenarios mirror framework/frontend/vue/src/admin/status/HilosStatusIncidentModal.test.ts.
import { act, fireEvent } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  HilosPages,
  applyServerTime,
  bindTimeZoneState,
  hilosToasts,
} from '@hilos/core/base'

import { HilosStatusPage } from '../src/admin/status/HilosStatusPage.js'
import { incident, overview, statusHarness } from './statusFixtures.js'

const cleanups: (() => void)[] = []
beforeEach(() => {
  vi.useFakeTimers()
  vi.setSystemTime(1800000000000)
  applyServerTime(Date.now())
})
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

function input(): HTMLTextAreaElement {
  return get('hilos-status-update-text') as HTMLTextAreaElement
}

function confirmButton(): HTMLButtonElement {
  return get('hilos-status-incident-confirm') as HTMLButtonElement
}

function fieldsetOf(element: Element): HTMLFieldSetElement {
  return element.closest('fieldset')!
}

function page(published = false, strict = false) {
  const h = statusHarness()
  cleanups.push(h.dispose)
  h.setOverview(
    overview({
      incidents: [incident({ publishedAt: published ? 1800000000000 : null })],
    }),
  )
  const view = h.mount(<HilosStatusPage context={h.context} />, strict)
  cleanups.push(view.unmount)
  return { ...h, view }
}

async function settled() {
  await act(async () => {
    await vi.advanceTimersByTimeAsync(0)
  })
}

async function answer(
  reply: (success: boolean, extra?: Record<string, unknown>) => void,
  success: boolean,
  extra?: Record<string, unknown>,
) {
  act(() => reply(success, extra))
  await settled()
}

describe('HilosStatusIncidentModal with real modal and LoadingButton', () => {
  it.each([
    [
      'cancel-publication',
      'hilos_status_cancel_publication',
      'Publication canceled.',
    ],
    ['publish-now', 'hilos_status_publish_now', 'Outage published.'],
  ])(
    'confirms %s through one guarded keyboard/click submit and waits for the tracked reply',
    async (operation, action, message) => {
      const { sent, reply } = page()
      const opener = get(`hilos-status-${operation}-11`)
      opener.focus()
      fireEvent.click(opener)
      await settled()
      const modal = get('modal')
      expect(document.activeElement).toBe(modal)
      expect(modal.textContent).toContain('Service · #11')
      const form = get('hilos-status-incident-form')
      const fieldset = fieldsetOf(form)
      fireEvent.keyDown(form, { key: 'Enter' })
      expect(sent).toEqual([
        { action, data: { incidentId: 11 }, requestId: expect.any(String) },
      ])
      const confirm = confirmButton()
      expect(confirm.getAttribute('aria-busy')).toBe('true')
      expect(confirm.disabled).toBe(false)
      expect(fieldsetOf(get('hilos-status-incident-form'))).toBe(fieldset)
      expect(fieldset.disabled).toBe(true)
      expect(
        (get('modal-interaction-scope') as HTMLFieldSetElement).disabled,
      ).toBe(false)
      fireEvent.click(confirm)
      fireEvent.submit(form)
      fireEvent.keyDown(form, { key: 'Enter' })
      fireEvent.keyDown(modal, { key: 'Escape' })
      expect(sent).toHaveLength(1)
      expect(find('modal')).not.toBeNull()
      expect(
        (get('hilos-status-incident-cancel') as HTMLButtonElement).disabled,
      ).toBe(true)
      await act(async () => {
        await vi.advanceTimersByTimeAsync(301)
      })
      expect(
        confirm.querySelector('[data-id="loading-button-spinner"]'),
      ).not.toBeNull()
      await answer(reply, true)
      expect(find('modal')).toBeNull()
      expect(hilosToasts.toasts.get().map((toast) => toast.message)).toEqual([
        message,
      ])
      expect(document.activeElement).toBe(opener)
    },
  )

  it('starts an empty draft, preserves its nodes and text on failure, and appends only on server success', async () => {
    const { setOverview, sent, reply } = page(true)
    fireEvent.click(get('hilos-status-add-update-11'))
    await settled()
    const field = input()
    expect(field.value).toBe('')
    expect(document.activeElement).toBe(field)
    expect(confirmButton().disabled).toBe(true)
    fireEvent.change(field, { target: { value: 'Our <b>plain</b> update' } })
    setOverview(
      overview({
        incidents: [
          incident({
            publishedAt: 1800000000000,
            updates: [
              {
                id: 21,
                kind: 'team',
                severity: 'major_outage',
                text: 'Another administrator’s update',
                at: 1800000001000,
              },
            ],
          }),
        ],
      }),
    )
    expect(field.value).toBe('Our <b>plain</b> update')
    expect(get('hilos-status-timeline-11').textContent).toContain(
      'Another administrator’s update',
    )
    fireEvent.keyDown(field, { key: 'Enter' })
    expect(sent).toHaveLength(0)
    fireEvent.keyDown(field, { key: 'Enter', ctrlKey: true })
    expect(sent[0]?.data).toEqual({
      incidentId: 11,
      text: 'Our <b>plain</b> update',
    })
    expect(input()).toBe(field)
    expect(fieldsetOf(field).disabled).toBe(true)
    await answer(reply, false, {
      reason: 'The incident changed. Please check it.',
    })
    expect(get('hilos-status-incident-error').textContent).toContain(
      'The incident changed. Please check it.',
    )
    expect(field.value).toBe('Our <b>plain</b> update')
    expect(fieldsetOf(field).disabled).toBe(false)
    fireEvent.submit(get('hilos-status-incident-form'))
    expect(sent[1]?.requestId).not.toBe(sent[0]?.requestId)
    await answer(reply, true)
    expect(find('modal')).toBeNull()
    expect(get('hilos-status-timeline-11').textContent).not.toContain(
      'Our <b>plain</b> update',
    )
  })

  it('retains the original target and extractable draft when that incident ends and its component gets another id', () => {
    const { setOverview, sent } = page(true)
    fireEvent.click(get('hilos-status-add-update-11'))
    const field = input()
    fireEvent.change(field, { target: { value: 'Keep this draft' } })
    setOverview(
      overview({
        incidents: [incident({ incidentId: 12, publishedAt: 1800000000000 })],
      }),
    )
    expect(get('hilos-status-incident-target').textContent).toBe(
      'Service · #11',
    )
    expect(get('hilos-status-incident-blocked').textContent).toContain(
      'This incident has ended.',
    )
    expect(field.value).toBe('Keep this draft')
    expect(fieldsetOf(field).disabled).toBe(false)
    expect(confirmButton().disabled).toBe(true)
    fireEvent.submit(get('hilos-status-incident-form'))
    fireEvent.keyDown(field, { key: 'Enter', ctrlKey: true })
    expect(sent).toEqual([])
  })

  it('keeps a newly suppressed or published target open with an explicit reason', () => {
    const { setOverview, sent } = page()
    fireEvent.click(get('hilos-status-publish-now-11'))
    for (const change of [
      { suppressedAt: 1800000000000 },
      { publishedAt: 1800000000000 },
    ]) {
      setOverview(overview({ incidents: [incident(change)] }))
      expect(get('hilos-status-incident-blocked').textContent).toMatch(
        /Publication was canceled|already published/,
      )
      expect(confirmButton().disabled).toBe(true)
      fireEvent.submit(get('hilos-status-incident-form'))
    }
    expect(sent).toEqual([])
  })

  it('explains unconfirmed timeout without claiming the update was not written', async () => {
    page(true)
    fireEvent.click(get('hilos-status-add-update-11'))
    fireEvent.change(input(), { target: { value: 'A draft to keep' } })
    fireEvent.submit(get('hilos-status-incident-form'))
    await act(async () => {
      await vi.advanceTimersByTimeAsync(1001)
    })
    expect(get('hilos-status-incident-error').textContent).toContain(
      'The operation may have completed.',
    )
    expect(input().value).toBe('A draft to keep')
  })

  it('updates the open dialog time zone and disposes without a late reply opening another page', async () => {
    const { context, emit, reply } = page()
    cleanups.push(bindTimeZoneState(context.connection))
    fireEvent.click(get('hilos-status-publish-now-11'))
    const before = get('hilos-status-incident-time').textContent
    act(() =>
      emit('projectSignal', {
        type: 'handshake_response',
        data: { entities: { currentUser: { id: 1, timeZone: 'Asia/Tokyo' } } },
      }),
    )
    expect(get('hilos-status-incident-time').textContent).not.toBe(before)
    fireEvent.submit(get('hilos-status-incident-form'))
    act(() => {
      context.scopes.openPage(HilosPages.STATUS_COMPONENTS)
    })
    await answer(reply, true)
    expect(find('modal')).toBeNull()
    expect(hilosToasts.toasts.get()).toEqual([])
  })

  it('keeps the draft during unavailable facts and lets a viewer read it while all submit paths refuse', () => {
    const { setOverview, viewer, sent } = page(true)
    fireEvent.click(get('hilos-status-add-update-11'))
    const field = input()
    fireEvent.change(field, {
      target: { value: 'A draft still available to copy' },
    })
    setOverview(overview({ healthKnown: false, incidents: null }))
    expect(get('hilos-status-incident-blocked').textContent).toContain(
      'Incident data is unavailable.',
    )
    expect(field.value).toBe('A draft still available to copy')
    setOverview(
      overview({ incidents: [incident({ publishedAt: 1800000000000 })] }),
    )
    viewer()
    expect(fieldsetOf(field).disabled).toBe(false)
    expect(confirmButton().disabled).toBe(true)
    fireEvent.keyDown(field, { key: 'Enter', ctrlKey: true })
    fireEvent.submit(get('hilos-status-incident-form'))
    expect(sent).toEqual([])
    fireEvent.click(get('hilos-status-incident-cancel'))
    expect(find('modal')).toBeNull()
  })

  it('lets Enter on a notice details button open its details without publishing', () => {
    const { setOverview, sent } = page()
    fireEvent.click(get('hilos-status-publish-now-11'))
    setOverview(
      overview({ incidents: [incident({ suppressedAt: 1800000000000 })] }),
    )
    const details = get('hilos-status-incident-blocked-details')
    const event = new KeyboardEvent('keydown', {
      key: 'Enter',
      bubbles: true,
      cancelable: true,
    })
    act(() => {
      details.dispatchEvent(event)
    })
    expect(event.defaultPrevented).toBe(false)
    fireEvent.click(details)
    expect(document.body.querySelectorAll('[data-id="modal"]')).toHaveLength(2)
    expect(sent).toEqual([])
  })

  it.each([
    ['cancel-publication', false, { key: 'Enter' }],
    ['publish-now', false, { key: 'Enter' }],
    ['add-update', true, { key: 'Enter', ctrlKey: true }],
    ['add-update', true, { key: 'Enter', metaKey: true }],
  ] as const)(
    'sends %s once when the key that confirms it is pressed inside the refusal details window',
    async (operation, published, key) => {
      const { sent, reply } = page(published)
      fireEvent.click(get(`hilos-status-${operation}-11`))
      await settled()
      if (operation === 'add-update')
        fireEvent.change(input(), { target: { value: 'An update to keep' } })
      fireEvent.submit(get('hilos-status-incident-form'))
      await answer(reply, false, {
        reason: 'The incident changed. Please check it.',
        errorType: 'IncidentChanged',
        errorDetail: 'Diagnostic details',
      })
      expect(confirmButton().disabled).toBe(false)
      fireEvent.click(get('hilos-status-incident-error-details'))
      const details = get('hilos-status-incident-error-detail').closest(
        '[data-id="modal"]',
      )!
      fireEvent.keyDown(details, key)
      expect(sent).toHaveLength(1)
      expect(get('hilos-status-incident-error').textContent).toContain(
        'The incident changed. Please check it.',
      )
    },
  )

  it('opens and submits a live window under StrictMode, captured on the incident it opened on', async () => {
    const { setOverview, sent, reply } = page(true, true)
    fireEvent.click(get('hilos-status-add-update-11'))
    await settled()
    expect(get('hilos-status-incident-target').textContent).toBe(
      'Service · #11',
    )
    fireEvent.change(input(), { target: { value: 'Strict update' } })
    setOverview(
      overview({
        incidents: [
          incident({ publishedAt: 1800000000000 }),
          incident({
            incidentId: 12,
            componentName: 'Mail',
            publishedAt: 1800000000000,
          }),
        ],
      }),
    )
    expect(get('hilos-status-incident-target').textContent).toBe(
      'Service · #11',
    )
    fireEvent.keyDown(input(), { key: 'Enter', metaKey: true })
    expect(sent).toEqual([
      {
        action: 'hilos_status_add_update',
        data: { incidentId: 11, text: 'Strict update' },
        requestId: expect.any(String),
      },
    ])
    await answer(reply, true)
    expect(find('modal')).toBeNull()
    expect(hilosToasts.toasts.get().map((toast) => toast.message)).toEqual([
      'Update published.',
    ])
  })
})
