import { nextTick } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  HilosPages,
  hilosToasts,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
} from '@hilos/core/base'
import { localDateTimeValue } from '@hilos/core/admin/status'

import {
  announcement,
  body,
  settled,
  worksPage,
  workForm,
  workSave,
  field,
  fillWork,
} from '../../../test/statusWorksFixtures.js'
import HilosStatusWorkModal from './HilosStatusWorkModal.vue'
import { readerTimeZone } from '../../../test/helpers/readerTimeZone.js'

const cleanups: (() => void)[] = []
beforeEach(() => vi.useFakeTimers())
afterEach(() => {
  for (const cleanup of cleanups.splice(0).reverse()) cleanup()
  hilosToasts.clear()
  vi.useRealTimers()
})
async function open(kind: 'create' | 'edit' = 'create') {
  const h = await worksPage(cleanups)
  h.window()
  await nextTick()
  const opener = await h.open(kind)
  return { ...h, opener }
}

describe('HilosStatusWorkModal with real creation and row-edit factories', () => {
  it('uses the captured IANA zone, second precision and core payload; success waits for its correlated commit ack', async () => {
    const zone = readerTimeZone()
    cleanups.push(zone.release)
    zone.choose('Asia/Tokyo')
    const h = await open()
    expect(body().get('[data-id="modal"]').attributes('aria-label')).toBe(
      'Schedule maintenance',
    )
    expect(document.activeElement).toBe(field('title').element)
    expect(field('starts').attributes('step')).toBe('1')
    expect(field('ends').attributes('step')).toBe('1')
    expect(body().text()).toContain('Starts, Asia/Tokyo')
    expect(workForm().get('legend').text()).toBe('Affects')
    for (const control of workForm().findAll('input,textarea')) {
      expect(
        workForm()
          .find(`label[for="${control.attributes('id')}"]`)
          .exists(),
      ).toBe(true)
    }
    expect(workSave().attributes('disabled')).toBeDefined()
    await fillWork()
    zone.choose('America/New_York')
    await nextTick()
    expect(body().text()).toContain('Starts, Asia/Tokyo')
    await field('title').trigger('keydown', { key: 'Enter' })
    expect(h.sent).toEqual([
      {
        action: 'hilos_status_work_schedule',
        requestId: expect.any(String),
        data: {
          title: 'Upgrade <database>',
          description: 'Plain text <b>only</b>',
          componentKeys: ['service'],
          startsAt: announcement.startsAt,
          endsAt: announcement.endsAt,
        },
      },
    ])
    expect(workSave().text()).toBe('Schedule maintenance')
    expect(workSave().attributes('aria-busy')).toBe('true')
    expect(workSave().attributes('disabled')).toBeUndefined()
    const fieldset = workForm().element.closest(
      'fieldset',
    ) as HTMLFieldSetElement
    expect(fieldset.disabled).toBe(true)
    await workForm().trigger('submit')
    await workSave().trigger('click')
    await body().get('[data-id="modal"]').trigger('keydown', { key: 'Escape' })
    await body().get('[data-id="modal"]').trigger('click')
    await body().get('[data-id="modal-close"]').trigger('click')
    await field('close').trigger('click')
    h.wrapper.findComponent(HilosStatusWorkModal).props('controller').close()
    await h.open('create')
    expect(h.sent).toHaveLength(1)
    expect(body().find('[data-id="modal-confirm"]').exists()).toBe(false)
    h.emit('actionSuccess', {
      action: h.sent[0]!.action,
      requestId: 'unrelated',
    })
    await settled()
    expect(body().find('[data-id="modal"]').exists()).toBe(true)
    expect(hilosToasts.toasts.get()).toEqual([])
    await vi.advanceTimersByTimeAsync(301)
    expect(workSave().find('[data-id="loading-button-spinner"]').exists()).toBe(
      true,
    )
    h.reply(true)
    await settled()
    expect(body().find('[data-id="modal"]').exists()).toBe(false)
    expect(hilosToasts.toasts.get().map((toast) => toast.message)).toEqual([
      'Maintenance scheduled.',
    ])
    expect(document.activeElement).toBe(h.opener.element)
    expect(
      h.wrapper.get('table [data-id="hilos-status-work-mail-7"]').text(),
    ).toBe('—')
  })

  it('uses the standard dirty confirmation, trap and return; another opener never drops the dirty draft', async () => {
    const h = await open()
    await field('title').setValue('Unsaved draft')
    await h.open('edit')
    expect(body().findAll('[data-id="modal"]')).toHaveLength(1)
    expect((field('title').element as HTMLInputElement).value).toBe(
      'Unsaved draft',
    )
    ;(workSave().element as HTMLElement).focus()
    // Invalid Save is disabled, so the last operable control is Cancel.
    ;(field('close').element as HTMLElement).focus()
    await body().get('[data-id="modal"]').trigger('keydown', { key: 'Tab' })
    expect(document.activeElement).toBe(
      body().get('[data-id="modal-close"]').element,
    )
    await body().get('[data-id="modal"]').trigger('keydown', { key: 'Escape' })
    await settled()
    expect(body().text()).toContain('Discard changes?')
    await body().get('[data-id="modal-confirm-cancel"]').trigger('click')
    expect((field('title').element as HTMLInputElement).value).toBe(
      'Unsaved draft',
    )
    await field('close').trigger('click')
    await body().get('[data-id="modal-confirm-discard"]').trigger('click')
    await settled()
    expect(body().find('[data-id="modal"]').exists()).toBe(false)
    expect(document.activeElement).toBe(h.opener.element)
    expect(h.sent).toEqual([])
  })

  it('merges independent text/list/time fields, resolves conflicting fields with Keep/Take and sends the current version and public identity', async () => {
    const zone = readerTimeZone()
    cleanups.push(zone.release)
    zone.choose('UTC')
    const h = await open('edit')
    expect(h.focuses.at(-1)).toEqual([
      'hilos_status_works',
      'hilosStatusWorks',
      '7',
    ])
    await field('title').setValue('My title')
    h.update({
      description: 'Incoming description',
      componentKeys: ['queue'],
      endsAt: announcement.endsAt + 1000,
      version: 2,
    })
    await nextTick()
    expect((field('description').element as HTMLTextAreaElement).value).toBe(
      'Incoming description',
    )
    expect((field('component-queue').element as HTMLInputElement).checked).toBe(
      true,
    )
    expect((field('ends').element as HTMLInputElement).value).toBe(
      localDateTimeValue(announcement.endsAt + 1000, 'UTC'),
    )
    expect(body().get('[data-id="hilos-status-work-notice"]').text()).toContain(
      'Updated just now',
    )
    h.update({ title: 'Their title', version: 3 })
    await nextTick()
    expect(body().get('[data-id="hilos-status-work-notice"]').text()).toContain(
      'Keep mine: My title; Take theirs: Their title',
    )
    expect(workSave().attributes('disabled')).toBeDefined()
    await workForm().trigger('submit')
    expect(h.sent).toEqual([])
    await body().get('[data-id="conflict-accept-mine"]').trigger('click')
    expect((field('title').element as HTMLInputElement).value).toBe('My title')
    await field('component-service').setValue(true)
    await field('ends').setValue('2031-01-02T04:06:03')
    h.update({
      componentKeys: ['database'],
      endsAt: announcement.endsAt + 2000,
      version: 4,
    })
    zone.choose('Asia/Tokyo')
    await nextTick()
    expect(body().get('[data-id="hilos-status-work-notice"]').text()).toContain(
      'Keep mine: 2031-01-02T04:06:03 (UTC)',
    )
    expect(body().get('[data-id="hilos-status-work-notice"]').text()).toContain(
      '(Asia/Tokyo)',
    )
    expect(workSave().attributes('disabled')).toBeDefined()
    await body().get('[data-id="conflict-accept-theirs"]').trigger('click')
    expect(
      (field('component-database').element as HTMLInputElement).checked,
    ).toBe(true)
    expect(
      (field('component-service').element as HTMLInputElement).checked,
    ).toBe(false)
    expect((field('ends').element as HTMLInputElement).value).toBe(
      localDateTimeValue(announcement.endsAt + 2000, 'UTC'),
    )
    h.update({ lastMailQueuedAt: 1_800_000_000_000 })
    await nextTick()
    expect((field('title').element as HTMLInputElement).value).toBe('My title')
    expect(workSave().attributes('disabled')).toBeUndefined()
    await workForm().trigger('submit')
    expect(h.sent[0]).toEqual({
      action: 'hilos_status_work_change',
      requestId: expect.any(String),
      data: {
        workId: 7,
        publicId: announcement.publicId,
        expectedVersion: 4,
        title: 'My title',
        description: 'Incoming description',
        componentKeys: ['database'],
        startsAt: announcement.startsAt,
        endsAt: announcement.endsAt + 2000,
      },
    })
    h.reply(true)
    await settled()
    expect(body().find('[data-id="modal"]').exists()).toBe(false)
    expect(hilosToasts.toasts.get().map((toast) => toast.message)).toEqual([
      'Announcement changed.',
    ])
  })

  it.each(['deleted', 'canceled', 'consumed', 'expired', 'stale'] as const)(
    'keeps the draft readable and blocks Save when the target is %s',
    async (outcome) => {
      const h = await open('edit')
      await field('title').setValue('Keep this draft')
      if (outcome === 'deleted')
        h.delta({ kind: 'row_removed', rowKey: '7', reason: 'deleted' })
      else if (outcome === 'stale')
        h.delta({ kind: 'row_stale', rowKey: '7', staleSources: ['work'] })
      else h.update({ [`${outcome}At`]: 1_900_000_000_000 })
      await nextTick()
      expect((field('title').element as HTMLInputElement).value).toBe(
        'Keep this draft',
      )
      expect(workSave().attributes('disabled')).toBeDefined()
      if (outcome !== 'stale') expect(workSave().text()).toBe('Deleted')
      expect(
        body().get('[data-id="hilos-status-work-notice"]').text(),
      ).not.toBe('')
      await workForm().trigger('submit')
      expect(h.sent).toEqual([])
    },
  )

  it('keeps Cancel and draft fields live after access is revoked and marks Save with the shared explanation', async () => {
    const h = await open('edit')
    await field('title').setValue('My draft')
    h.viewer()
    await nextTick()
    expect(workSave().attributes('disabled')).toBeDefined()
    expect(workSave().attributes('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )
    expect((field('close').element as HTMLButtonElement).disabled).toBe(false)
    expect((field('title').element as HTMLInputElement).disabled).toBe(false)
    await field('title').trigger('keydown', { key: 'Enter' })
    expect(h.sent).toEqual([])
    await field('close').trigger('click')
    expect(body().text()).toContain('Discard changes?')
  })

  it('reserves error/notice slots and shows exact backend refusal and standard diagnostic details', async () => {
    const h = await open()
    const errorSlot = body().get(
      '[data-id="hilos-status-work-error-slot"]',
    ).element
    expect(
      body().find('[data-id="hilos-status-work-error-idle"]').exists(),
    ).toBe(true)
    expect(
      body().find('[data-id="hilos-status-work-notice-idle"]').exists(),
    ).toBe(true)
    await fillWork()
    await workForm().trigger('submit')
    h.reply(false, {
      reason: 'Another announcement already occupies this window.',
      errorType: 'WorkFailure',
      errorDetail: 'Diagnostic details',
    })
    await settled()
    expect(body().get('[data-id="hilos-status-work-error-slot"]').element).toBe(
      errorSlot,
    )
    expect(body().get('[data-id="hilos-status-work-error"]').text()).toContain(
      'Another announcement already occupies this window.',
    )
    expect((field('title').element as HTMLInputElement).value).toBe(
      '  Upgrade <database>  ',
    )
    await body()
      .get('[data-id="hilos-status-work-error-details"]')
      .trigger('click')
    expect(body().findAll('[data-id="modal"]')).toHaveLength(2)
    expect(body().text()).toContain('WorkFailure')
    expect(body().text()).toContain('Diagnostic details')
    expect(hilosToasts.toasts.get()).toEqual([])
  })

  it.each(['timeout', 'disconnect'] as const)(
    'keeps an unconfirmed draft after %s and ignores late success',
    async (outcome) => {
      const h = await open()
      await fillWork()
      await workForm().trigger('submit')
      if (outcome === 'timeout') await vi.advanceTimersByTimeAsync(1001)
      else h.connect('disconnected')
      await settled()
      expect(
        body().get('[data-id="hilos-status-work-error"]').text(),
      ).toContain('The operation may have completed.')
      h.reply(true)
      await settled()
      expect(body().find('[data-id="modal"]').exists()).toBe(true)
      expect(hilosToasts.toasts.get()).toEqual([])
      expect((field('title').element as HTMLInputElement).value).toBe(
        '  Upgrade <database>  ',
      )
    },
  )

  it('does not toast or revive a window after leaving the page', async () => {
    const h = await open()
    await fillWork()
    await workForm().trigger('submit')
    h.context.scopes.openPage(HilosPages.STATUS_COMPONENTS)
    h.reply(true)
    await settled()
    expect(body().find('[data-id="modal"]').exists()).toBe(false)
    expect(hilosToasts.toasts.get()).toEqual([])
    expect(h.registered.size).toBe(0)
  })
})
