import { nextTick } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  HilosPages,
  hilosToasts,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  type TableRow,
} from '@hilos/core/base'
import type { HilosStatusWorksData } from '@hilos/core/admin/status'

import {
  announcement,
  body,
  settled,
  worksPage,
  workForm,
  workRow,
  workSave,
  worksData,
  field,
  fillWork,
  press,
  typeWindowEdge,
  windowPart,
  windowTexts,
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
async function open(
  kind: 'create' | 'edit' = 'create',
  rows: readonly TableRow[] = [workRow()],
  data: HilosStatusWorksData = worksData,
) {
  const h = await worksPage(cleanups)
  h.window(rows, data)
  await nextTick()
  const opener = await h.open(kind)
  return { ...h, opener }
}

describe('HilosStatusWorkModal with real creation and row-edit factories', () => {
  it.each(['create', 'edit'] as const)(
    'keeps component labels and selections separate from same-key fields during %s',
    async (kind) => {
      const components = [
        { componentKey: 'title', name: 'Title service' },
        { componentKey: 'starts', name: 'Starts service' },
      ]
      const zone = readerTimeZone()
      cleanups.push(zone.release)
      zone.choose('UTC')
      const h = await open(kind, [workRow({ componentKeys: [] })], {
        ...worksData!,
        components,
      })
      const modals = h.wrapper.findAllComponents(HilosStatusWorkModal)
      const activeModal = modals.find((modal) =>
        modal.props('controller').opened.get(),
      )!
      const controller = activeModal.props('controller')
      await field('title').setValue('Maintenance')
      await field('description').setValue('Details for visitors')
      await typeWindowEdge('Starts', ['02', '01', '2031', '03', '04', '05'])
      await typeWindowEdge('Ends', ['02', '01', '2031', '04', '05', '06'])
      const windowGroup = field('window').element
      expect
        .soft(
          document
            .getElementById(windowGroup.getAttribute('aria-labelledby')!)
            ?.textContent?.trim(),
        )
        .toBe('Window, UTC')
      const shownWindow = [...windowTexts('Starts'), ...windowTexts('Ends')]
      const labels = [...workForm().element.querySelectorAll('label')]
      const staticEntries = [
        { key: 'title', label: 'Title' },
        { key: 'description', label: 'What visitors read' },
      ]
      const staticFields = staticEntries.map(({ key, label: text }) => {
        const control = field(key).element as
          HTMLInputElement | HTMLTextAreaElement
        const label = labels.find((node) => node.textContent?.trim() === text)!
        expect.soft(label.control).toBe(control)
        expect.soft([...(control.labels ?? [])]).toEqual([label])
        return control
      })
      const values = staticFields.map((control) => control.value)
      const selected: string[] = []
      for (const { componentKey, name } of components) {
        const checkbox = field(`component-${componentKey}`)
          .element as HTMLInputElement
        const label = labels.find((node) => node.textContent?.trim() === name)!
        expect.soft(label.control).toBe(checkbox)
        // Native labels supply the accessible name of these plain controls.
        expect.soft([...(checkbox.labels ?? [])]).toEqual([label])
        expect.soft(checkbox.labels?.[0]?.textContent?.trim()).toBe(name)
        ;(field('close').element as HTMLElement).focus()
        label.click()
        await nextTick()
        selected.push(componentKey)
        expect.soft(checkbox.checked).toBe(true)
        for (const item of components) {
          const itemCheckbox = field(`component-${item.componentKey}`)
            .element as HTMLInputElement
          expect
            .soft(itemCheckbox.checked)
            .toBe(selected.includes(item.componentKey))
        }
        expect.soft(controller.form.get().componentKeys).toEqual(selected)
        expect.soft(staticFields).not.toContain(document.activeElement)
        expect.soft(windowGroup.contains(document.activeElement)).toBe(false)
        expect
          .soft(staticFields.map((control) => control.value))
          .toEqual(values)
        expect
          .soft([...windowTexts('Starts'), ...windowTexts('Ends')])
          .toEqual(shownWindow)
      }
    },
  )

  it('uses the captured IANA zone, second precision and core payload; success waits for its correlated commit ack', async () => {
    const zone = readerTimeZone()
    cleanups.push(zone.release)
    zone.choose('Asia/Tokyo')
    const h = await open()
    expect(body().get('[data-id="modal"]').attributes('aria-label')).toBe(
      'Schedule maintenance',
    )
    expect(document.activeElement).toBe(field('title').element)
    expect(windowPart('Starts', 'Seconds').attributes('aria-valuemax')).toBe(
      '59',
    )
    expect(windowPart('Ends', 'Seconds').attributes('aria-valuemax')).toBe('59')
    expect(body().text()).toContain('Window, Asia/Tokyo')
    expect(workForm().get('legend').text()).toBe('Affects')
    for (const control of workForm().findAll(
      'input:not([role="spinbutton"]),textarea',
    )) {
      expect(
        workForm()
          .find(`label[for="${control.attributes('id')}"]`)
          .exists(),
      ).toBe(true)
    }
    const overlapHint = workForm()
      .findAll('[id]')
      .find((node) =>
        node
          .text()
          .startsWith('The window cannot overlap another announcement'),
      )!
    const windowParts = field('window').findAll('input')
    expect(windowParts).toHaveLength(12)
    for (const part of windowParts) {
      expect(part.attributes('aria-label')).toMatch(/^(Starts|Ends) /)
      expect(part.attributes('aria-describedby')?.split(' ')).toContain(
        overlapHint.attributes('id'),
      )
    }
    expect(workSave().attributes('disabled')).toBeDefined()
    await fillWork()
    zone.choose('America/New_York')
    await nextTick()
    expect(body().text()).toContain('Window, Asia/Tokyo')
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
    expect(windowTexts('Ends')).toEqual(['02', '01', '2031', '04', '05', '07'])
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
    await typeWindowEdge('Ends', ['02', '01', '2031', '04', '06', '03'])
    h.update({
      componentKeys: ['database'],
      endsAt: announcement.endsAt + 2000,
      version: 4,
    })
    zone.choose('Asia/Tokyo')
    await nextTick()
    expect(body().get('[data-id="hilos-status-work-notice"]').text()).toContain(
      'Keep mine: 02/01/2031, 04:06:03 (UTC)',
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
    expect(windowTexts('Ends')).toEqual(['02', '01', '2031', '04', '05', '08'])
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

describe('Works window field', () => {
  const openCalendar = async () => {
    await workForm().get('button[aria-label="Open calendar"]').trigger('click')
    await settled()
  }
  const calendar = () => workForm().find('[role="dialog"]')

  it('offers no day before today in the captured zone', async () => {
    const zone = readerTimeZone()
    cleanups.push(zone.release)
    zone.choose('UTC')
    vi.setSystemTime(Date.UTC(2030, 5, 15, 12, 0, 0))
    await open()
    await openCalendar()
    const days = calendar().findAll('[role="gridcell"]')
    const todayIndex = days.findIndex(
      (day) => day.attributes('aria-current') === 'date',
    )
    const today = days[todayIndex]!
    const yesterday = days[todayIndex - 1]!
    expect(today.text()).toBe('15')
    expect(today.attributes('aria-disabled')).toBeUndefined()
    expect(yesterday.text()).toBe('14')
    expect(yesterday.attributes('aria-disabled')).toBe('true')
    await yesterday.trigger('click')
    expect(windowPart('Starts', 'Day').attributes('aria-valuenow')).toBe(
      undefined,
    )
    await calendar()
      .get('[role="gridcell"][aria-current="date"]')
      .trigger('click')
    expect(windowPart('Starts', 'Day').attributes('aria-valuenow')).toBe('15')
  })

  it('locks the window while the request is in flight and returns it intact after a refusal', async () => {
    const h = await open()
    await fillWork()
    const typed = [...windowTexts('Starts'), ...windowTexts('Ends')]
    await workForm().trigger('submit')
    await settled()
    expect(h.sent).toHaveLength(1)
    const opener = workForm().get('button[aria-label="Open calendar"]')
    expect(opener.attributes('disabled')).toBeDefined()
    for (const part of field('window').findAll('input'))
      expect(part.attributes('disabled')).toBeDefined()
    h.reply(false, {
      reason: 'Another announcement already occupies this window.',
    })
    await settled()
    expect(body().get('[data-id="hilos-status-work-error"]').text()).toContain(
      'Another announcement already occupies this window.',
    )
    expect(opener.attributes('disabled')).toBeUndefined()
    for (const part of field('window').findAll('input'))
      expect(part.attributes('disabled')).toBeUndefined()
    expect([...windowTexts('Starts'), ...windowTexts('Ends')]).toEqual(typed)
  })

  it('keeps an unfinished window from Save and from Enter and asks before discarding it', async () => {
    const h = await open()
    await field('title').setValue('Upgrade')
    await field('component-service').setValue(true)
    await typeWindowEdge('Starts', ['02', '01', '2031', '12', '04', '05'])
    const endsDay = windowPart('Ends', 'Day')
    ;(endsDay.element as HTMLInputElement).focus()
    for (const key of ['0', '2']) await press(endsDay, key)
    expect(windowTexts('Ends')[0]).toBe('02')
    expect(workSave().attributes('disabled')).toBeDefined()
    await press(windowPart('Ends', 'Month'), 'Enter')
    expect(h.sent).toEqual([])
    await press(windowPart('Ends', 'Month'), 'Escape')
    await settled()
    expect(body().text()).toContain('Discard changes?')
  })

  it('leaves Enter to the open calendar and submits from the field once it is closed', async () => {
    const h = await open()
    await fillWork()
    expect(workSave().attributes('disabled')).toBeUndefined()
    await openCalendar()
    await press(calendar().get('[role="gridcell"][tabindex="0"]'), 'Enter')
    expect(h.sent).toEqual([])
    expect(calendar().exists()).toBe(true)
    const time = calendar().get('input[role="spinbutton"]')
    const enter = await press(time, 'Enter')
    expect(enter.defaultPrevented).toBe(true)
    expect(h.sent).toEqual([])
    expect(body().find('[data-id="modal"]').exists()).toBe(true)
    await press(time, 'Escape')
    await settled()
    expect(calendar().exists()).toBe(false)
    expect(body().text()).not.toContain('Discard changes?')
    expect(workSave().attributes('disabled')).toBeUndefined()
    await press(windowPart('Ends', 'Seconds'), 'Enter')
    expect(h.sent.map((frame) => frame.action)).toEqual([
      'hilos_status_work_schedule',
    ])
  })

  it('submits on Enter from another field while the window calendar is open', async () => {
    const h = await open()
    await fillWork()
    await openCalendar()
    expect(calendar().exists()).toBe(true)
    // Focus stays in the calendar: a focused Title would close a wide popup.
    await press(field('title'), 'Enter')
    expect(h.sent.map((frame) => frame.action)).toEqual([
      'hilos_status_work_schedule',
    ])
  })

  it('opens the next form with an empty window in the newly chosen zone', async () => {
    const zone = readerTimeZone()
    cleanups.push(zone.release)
    zone.choose('UTC')
    const h = await open()
    await typeWindowEdge('Starts', ['02', '01', '2031', '12', '04', '05'])
    await field('close').trigger('click')
    await body().get('[data-id="modal-confirm-discard"]').trigger('click')
    await settled()
    expect(body().find('[data-id="modal"]').exists()).toBe(false)
    zone.choose('Asia/Tokyo')
    await h.open('create')
    expect(body().text()).toContain('Window, Asia/Tokyo')
    for (const part of field('window').findAll('input'))
      expect(part.attributes('aria-valuenow')).toBeUndefined()
  })
})
