import { flushPromises, mount, DOMWrapper } from '@vue/test-utils'
import { markRaw, nextTick } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  HilosPages,
  applyServerTime,
  bindTimeZoneState,
  hilosToasts,
} from '@hilos/core/base'

import HilosStatusPage from './HilosStatusPage.vue'
import { hilosRouterKey } from '../../hilosRouterKey.js'
import {
  statusHarness,
  incident,
  overview,
} from '../../../test/statusFixtures.js'

const cleanups: (() => void)[] = []
beforeEach(() => {
  vi.useFakeTimers()
  vi.setSystemTime(1800000000000)
  applyServerTime(Date.now())
})
afterEach(() => {
  for (const cleanup of cleanups.splice(0).reverse()) cleanup()
  hilosToasts.clear()
  vi.useRealTimers()
})
function body() {
  return new DOMWrapper(document.body)
}
async function page(published = false) {
  const h = statusHarness()
  cleanups.push(h.dispose)
  h.setOverview(
    overview({
      incidents: [incident({ publishedAt: published ? 1800000000000 : null })],
    }),
  )
  const wrapper = mount(HilosStatusPage, {
    props: { context: markRaw(h.context) },
    attachTo: document.body,
    global: { provide: { [hilosRouterKey as symbol]: h.router } },
  })
  cleanups.push(() => wrapper.unmount())
  await nextTick()
  return { ...h, wrapper }
}
async function settled() {
  await flushPromises()
  await nextTick()
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
      const { wrapper, sent, reply } = await page()
      const opener = wrapper.get(`[data-id="hilos-status-${operation}-11"]`)
      ;(opener.element as HTMLElement).focus()
      await opener.trigger('click')
      await settled()
      const modal = body().get('[data-id="modal"]')
      expect(document.activeElement).toBe(modal.element)
      expect(modal.text()).toContain('Service · #11')
      const fieldset = modal.get('fieldset').element
      const form = modal.get('form')
      await form.trigger('keydown', { key: 'Enter' })
      expect(sent).toEqual([
        { action, data: { incidentId: 11 }, requestId: expect.any(String) },
      ])
      const confirm = body().get('[data-id="hilos-status-incident-confirm"]')
      expect(confirm.attributes('aria-busy')).toBe('true')
      expect(confirm.attributes('disabled')).toBeUndefined()
      expect(body().get('fieldset').element).toBe(fieldset)
      expect((fieldset as HTMLFieldSetElement).disabled).toBe(true)
      await confirm.trigger('click')
      await form.trigger('submit')
      await form.trigger('keydown', { key: 'Enter' })
      await modal.trigger('keydown', { key: 'Escape' })
      expect(sent).toHaveLength(1)
      expect(body().find('[data-id="modal"]').exists()).toBe(true)
      expect(
        (
          body().get('[data-id="hilos-status-incident-cancel"]')
            .element as HTMLButtonElement
        ).disabled,
      ).toBe(true)
      await vi.advanceTimersByTimeAsync(301)
      expect(confirm.find('[data-id="loading-button-spinner"]').exists()).toBe(
        true,
      )
      reply(true)
      await settled()
      expect(body().find('[data-id="modal"]').exists()).toBe(false)
      expect(hilosToasts.toasts.get().map((t) => t.message)).toEqual([message])
      expect(document.activeElement).toBe(opener.element)
    },
  )
  it('starts an empty draft, preserves its nodes and text on failure, and appends only on server success', async () => {
    const { wrapper, setOverview, sent, reply } = await page(true)
    await wrapper.get('[data-id="hilos-status-add-update-11"]').trigger('click')
    await settled()
    const input = body().get('[data-id="hilos-status-update-text"]')
    expect((input.element as HTMLTextAreaElement).value).toBe('')
    expect(document.activeElement).toBe(input.element)
    expect(
      (
        body().get('[data-id="hilos-status-incident-confirm"]')
          .element as HTMLButtonElement
      ).disabled,
    ).toBe(true)
    await input.setValue('Our <b>plain</b> update')
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
    await nextTick()
    expect((input.element as HTMLTextAreaElement).value).toBe(
      'Our <b>plain</b> update',
    )
    expect(
      wrapper.get('[data-id="hilos-status-timeline-11"]').text(),
    ).toContain('Another administrator’s update')
    await input.trigger('keydown', { key: 'Enter' })
    expect(sent).toHaveLength(0)
    await input.trigger('keydown', { key: 'Enter', ctrlKey: true })
    expect(sent[0]?.data).toEqual({
      incidentId: 11,
      text: 'Our <b>plain</b> update',
    })
    expect(body().get('[data-id="hilos-status-update-text"]').element).toBe(
      input.element,
    )
    expect(
      (input.element.closest('fieldset') as HTMLFieldSetElement).disabled,
    ).toBe(true)
    reply(false, { reason: 'The incident changed. Please check it.' })
    await settled()
    expect(
      body().get('[data-id="hilos-status-incident-error"]').text(),
    ).toContain('The incident changed. Please check it.')
    expect((input.element as HTMLTextAreaElement).value).toBe(
      'Our <b>plain</b> update',
    )
    expect(
      (input.element.closest('fieldset') as HTMLFieldSetElement).disabled,
    ).toBe(false)
    await body().get('form').trigger('submit')
    expect(sent[1]?.requestId).not.toBe(sent[0]?.requestId)
    reply(true)
    await settled()
    expect(body().find('[data-id="modal"]').exists()).toBe(false)
    expect(
      wrapper.get('[data-id="hilos-status-timeline-11"]').text(),
    ).not.toContain('Our <b>plain</b> update')
  })
  it('retains the original target and extractable draft when that incident ends and its component gets another id', async () => {
    const { wrapper, setOverview, sent } = await page(true)
    await wrapper.get('[data-id="hilos-status-add-update-11"]').trigger('click')
    const input = body().get('[data-id="hilos-status-update-text"]')
    await input.setValue('Keep this draft')
    setOverview(
      overview({
        incidents: [incident({ incidentId: 12, publishedAt: 1800000000000 })],
      }),
    )
    await nextTick()
    expect(body().get('[data-id="hilos-status-incident-target"]').text()).toBe(
      'Service · #11',
    )
    expect(
      body().get('[data-id="hilos-status-incident-blocked"]').text(),
    ).toContain('This incident has ended.')
    expect((input.element as HTMLTextAreaElement).value).toBe('Keep this draft')
    expect(
      (input.element.closest('fieldset') as HTMLFieldSetElement).disabled,
    ).toBe(false)
    expect(
      (
        body().get('[data-id="hilos-status-incident-confirm"]')
          .element as HTMLButtonElement
      ).disabled,
    ).toBe(true)
    await body().get('form').trigger('submit')
    await input.trigger('keydown', { key: 'Enter', ctrlKey: true })
    expect(sent).toEqual([])
  })
  it('keeps a newly suppressed or published target open with an explicit reason', async () => {
    const { wrapper, setOverview, sent } = await page()
    await wrapper
      .get('[data-id="hilos-status-publish-now-11"]')
      .trigger('click')
    for (const change of [
      { suppressedAt: 1800000000000 },
      { publishedAt: 1800000000000 },
    ]) {
      setOverview(overview({ incidents: [incident(change)] }))
      await nextTick()
      expect(
        body().get('[data-id="hilos-status-incident-blocked"]').text(),
      ).toMatch(/Publication was canceled|already published/)
      expect(
        (
          body().get('[data-id="hilos-status-incident-confirm"]')
            .element as HTMLButtonElement
        ).disabled,
      ).toBe(true)
      await body().get('form').trigger('submit')
    }
    expect(sent).toEqual([])
  })
  it('explains unconfirmed timeout without claiming the update was not written', async () => {
    const { wrapper } = await page(true)
    await wrapper.get('[data-id="hilos-status-add-update-11"]').trigger('click')
    const input = body().get('[data-id="hilos-status-update-text"]')
    await input.setValue('A draft to keep')
    await body().get('form').trigger('submit')
    await vi.advanceTimersByTimeAsync(1001)
    expect(
      body().get('[data-id="hilos-status-incident-error"]').text(),
    ).toContain('The operation may have completed.')
    expect((input.element as HTMLTextAreaElement).value).toBe('A draft to keep')
  })
  it('updates the open dialog time zone and disposes without a late reply opening another page', async () => {
    const { wrapper, context, emit, reply } = await page()
    cleanups.push(bindTimeZoneState(context.connection))
    await wrapper
      .get('[data-id="hilos-status-publish-now-11"]')
      .trigger('click')
    const before = body().get('[data-id="hilos-status-incident-time"]').text()
    emit('projectSignal', {
      type: 'handshake_response',
      data: { entities: { currentUser: { id: 1, timeZone: 'Asia/Tokyo' } } },
    })
    await nextTick()
    expect(
      body().get('[data-id="hilos-status-incident-time"]').text(),
    ).not.toBe(before)
    await body().get('form').trigger('submit')
    context.scopes.openPage(HilosPages.STATUS_COMPONENTS)
    await nextTick()
    reply(true)
    await settled()
    expect(body().find('[data-id="modal"]').exists()).toBe(false)
    expect(hilosToasts.toasts.get()).toEqual([])
  })
  it('keeps the draft during unavailable facts and lets a viewer read it while all submit paths refuse', async () => {
    const { wrapper, setOverview, viewer, sent } = await page(true)
    await wrapper.get('[data-id="hilos-status-add-update-11"]').trigger('click')
    const input = body().get('[data-id="hilos-status-update-text"]')
    await input.setValue('A draft still available to copy')
    setOverview(overview({ healthKnown: false, incidents: null }))
    await nextTick()
    expect(
      body().get('[data-id="hilos-status-incident-blocked"]').text(),
    ).toContain('Incident data is unavailable.')
    expect((input.element as HTMLTextAreaElement).value).toBe(
      'A draft still available to copy',
    )
    setOverview(
      overview({ incidents: [incident({ publishedAt: 1800000000000 })] }),
    )
    viewer()
    await nextTick()
    expect(
      (input.element.closest('fieldset') as HTMLFieldSetElement).disabled,
    ).toBe(false)
    expect(
      (
        body().get('[data-id="hilos-status-incident-confirm"]')
          .element as HTMLButtonElement
      ).disabled,
    ).toBe(true)
    await input.trigger('keydown', { key: 'Enter', ctrlKey: true })
    await body().get('form').trigger('submit')
    expect(sent).toEqual([])
    await body()
      .get('[data-id="hilos-status-incident-cancel"]')
      .trigger('click')
    expect(body().find('[data-id="modal"]').exists()).toBe(false)
  })
  it('lets Enter on a notice details button open its details without publishing', async () => {
    const { wrapper, setOverview, sent } = await page()
    await wrapper
      .get('[data-id="hilos-status-publish-now-11"]')
      .trigger('click')
    setOverview(
      overview({ incidents: [incident({ suppressedAt: 1800000000000 })] }),
    )
    await nextTick()
    const details = body().get(
      '[data-id="hilos-status-incident-blocked-details"]',
    )
    const event = new KeyboardEvent('keydown', {
      key: 'Enter',
      bubbles: true,
      cancelable: true,
    })
    details.element.dispatchEvent(event)
    expect(event.defaultPrevented).toBe(false)
    await details.trigger('click')
    expect(body().findAll('[data-id="modal"]')).toHaveLength(2)
    expect(sent).toEqual([])
  })
})
