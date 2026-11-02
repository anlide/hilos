import { By } from '@angular/platform-browser'
import { HILOS_VIEW_MODE_STRIP_TEXT_ID, hilosToasts } from '@hilos/core/base'
import { localDateTimeValue } from '@hilos/core/admin/status'
import { describe, expect, it, vi } from 'vitest'

import { HilosStatusWorkModal } from '../src/admin/status/HilosStatusWorkModal.js'
import {
  announcement,
  el,
  enter,
  query,
  settled,
  statusWorksHarness,
  workRow,
} from './helpers/hilosStatus.js'
import {
  field,
  fillWork,
  setWorkField,
  submitWork,
  toggleComponent,
  workForm,
  workSave,
  worksPage,
} from './helpers/hilosStatusWorks.js'

function open(kind: 'create' | 'edit' = 'create', zone = 'Asia/Tokyo') {
  const h = worksPage()
  h.window()
  h.detect()
  h.chooseZone(zone)
  const opener = h.open(kind)
  return { ...h, opener }
}

describe('HilosStatusWorkModal over real creation and row-edit windows', () => {
  it.each(['create', 'edit'] as const)(
    'keeps component labels and selections separate from same-key fields during %s',
    (kind) => {
      const components = [
        { componentKey: 'title', name: 'Title service' },
        { componentKey: 'starts', name: 'Starts service' },
        { componentKey: 'ends', name: 'Ends service' },
        { componentKey: 'description', name: 'Description service' },
      ]
      const h = worksPage()
      h.window([workRow({ componentKeys: [] })], {
        components,
        upcomingCount: 1,
        nextWork: null,
      })
      h.detect()
      h.chooseZone('UTC')
      h.open(kind)
      const modals = h.fixture.debugElement
        .queryAll(By.directive(HilosStatusWorkModal))
        .map((node) => node.componentInstance as HilosStatusWorkModal)
      const controller = modals
        .find((modal) => modal.controller().opened.get())!
        .controller()
      h.act(() => {
        setWorkField('title', 'Maintenance')
        setWorkField('description', 'Details for visitors')
        setWorkField('starts', '2031-01-02T03:04:05')
        setWorkField('ends', '2031-01-02T04:05:06')
      })
      const form = workForm()
      const labels = [...form.querySelectorAll('label')]
      const staticNames = [
        'Title',
        'Starts, UTC',
        'Ends, UTC',
        'What visitors read',
      ]
      const staticFields = components.map(({ componentKey }, index) => {
        const control = field(componentKey)
        const label = labels.find(
          (node) => node.textContent?.trim() === staticNames[index],
        )!
        expect.soft(label.control).toBe(control)
        expect.soft([...control.labels!]).toEqual([label])
        return control
      })
      const values = staticFields.map((control) => control.value)
      const selected: string[] = []
      for (const { componentKey, name } of components) {
        const checkbox = field(`component-${componentKey}`)
        const label = labels.find((node) => node.textContent?.trim() === name)!
        expect.soft(label.control).toBe(checkbox)
        // Native labels supply the accessible name of these plain controls.
        expect.soft([...checkbox.labels!]).toEqual([label])
        expect.soft(checkbox.labels?.[0]?.textContent?.trim()).toBe(name)
        field('close').focus()
        h.act(() => label.click())
        selected.push(componentKey)
        expect.soft(checkbox.checked).toBe(true)
        expect.soft(controller.form.get().componentKeys).toEqual(selected)
        expect.soft(staticFields).not.toContain(document.activeElement)
        expect
          .soft(staticFields.map((control) => control.value))
          .toEqual(values)
      }
      h.act(submitWork)
      expect(h.sent).toEqual([
        {
          action:
            kind === 'create'
              ? 'hilos_status_work_schedule'
              : 'hilos_status_work_change',
          requestId: expect.any(String),
          data: {
            ...(kind === 'edit'
              ? {
                  workId: 7,
                  publicId: announcement.publicId,
                  expectedVersion: 1,
                }
              : {}),
            title: 'Maintenance',
            description: 'Details for visitors',
            componentKeys: ['description', 'ends', 'starts', 'title'],
            startsAt: announcement.startsAt,
            endsAt: announcement.endsAt,
          },
        },
      ])
    },
  )

  it('labels both second-precision inputs in the captured zone and links them to the shared overlap explanation', () => {
    const h = open()
    expect(document.activeElement).toBe(field('title'))
    expect(workForm().getAttribute('aria-label')).toBe('Schedule maintenance')
    const starts = field('starts')
    const ends = field('ends')
    for (const input of [starts, ends]) {
      expect(input.type).toBe('datetime-local')
      expect(input.step).toBe('1')
      expect(
        workForm().querySelector(`label[for="${input.id}"]`)?.textContent,
      ).toContain('Asia/Tokyo')
    }
    const hintId = starts.getAttribute('aria-describedby')!
    expect(ends.getAttribute('aria-describedby')).toBe(hintId)
    expect(
      document.getElementById(hintId)?.textContent?.trim().replace(/\s+/g, ' '),
    ).toBe(
      'The window cannot overlap another announcement, even if the components differ. Simultaneous work is one announcement with several components. Adjacent windows are fine: one ends at 07:00, the next starts at 07:00.',
    )
    h.act(fillWork)
    h.chooseZone('America/New_York')
    expect(
      workForm().querySelector(`label[for="${starts.id}"]`)?.textContent,
    ).toContain('Starts, Asia/Tokyo')
    expect(starts.value).toBe('2031-01-02T12:04:05')
  })

  it('sends the core epoch payload once and holds the same busy fields until the correlated owner acknowledgement', async () => {
    const h = open()
    h.act(fillWork)
    const titleInput = field('title')
    h.act(() => enter(titleInput))
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
    expect(workSave().getAttribute('aria-busy')).toBe('true')
    expect(workForm().closest('fieldset')?.disabled).toBe(true)
    h.act(submitWork)
    h.act(() => el('hilos-status-work-close').click())
    h.act(() => el('modal-close').click())
    h.act(() =>
      el('modal').dispatchEvent(
        new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }),
      ),
    )
    expect(field('title')).toBe(titleInput)
    expect(h.sent).toHaveLength(1)
    h.emit('actionSuccess', {
      action: h.sent[0]!.action,
      requestId: 'unrelated',
    })
    await settled(h.fixture)
    expect(query('modal')).not.toBeNull()
    expect(hilosToasts.toasts.get()).toEqual([])
    h.reply(true)
    await settled(h.fixture)
    expect(query('modal')).toBeNull()
    expect(document.activeElement).toBe(h.opener)
    expect(hilosToasts.toasts.get().map((toast) => toast.message)).toEqual([
      'Maintenance scheduled.',
    ])
    expect(el('hilos-status-work-mail-7').textContent?.trim()).toBe('—')
  })

  it('keeps one dirty draft when another row opener fires and closes only through standard Discard', () => {
    const h = open()
    h.act(() => setWorkField('title', 'Unsaved draft'))
    const form = workForm()
    h.act(() =>
      document
        .querySelector<HTMLButtonElement>(
          'table [data-id="hilos-status-work-edit-7"]',
        )!
        .click(),
    )
    expect(workForm()).toBe(form)
    expect(field('title').value).toBe('Unsaved draft')
    expect(h.focuses).toEqual([])
    h.act(() => el('hilos-status-work-close').click())
    expect(query('modal-confirm')).not.toBeNull()
    h.act(() => el('modal-confirm-cancel').click())
    expect(field('title').value).toBe('Unsaved draft')
    h.act(() => el('hilos-status-work-close').click())
    h.act(() => el('modal-confirm-discard').click())
    expect(query('modal')).toBeNull()
    expect(document.activeElement).toBe(h.opener)
    expect(h.sent).toEqual([])
  })

  it('merges independent fields, exposes conflicting values, and sends captured public identity with the latest live version', async () => {
    const h = open('edit', 'UTC')
    expect(h.focuses.at(-1)).toEqual([
      'hilos_status_works',
      'hilosStatusWorks',
      '7',
    ])
    h.act(() => setWorkField('title', 'My title'))
    h.update({
      description: 'Incoming description',
      componentKeys: ['queue'],
      endsAt: announcement.endsAt + 1000,
      version: 2,
    })
    h.detect()
    expect(field('description').value).toBe('Incoming description')
    expect(field('component-queue').checked).toBe(true)
    expect(field('ends').value).toBe(
      localDateTimeValue(announcement.endsAt + 1000, 'UTC'),
    )
    expect(el('hilos-status-work-notice').textContent).toContain(
      'Updated just now',
    )
    h.update({ title: 'Their title', version: 3 })
    h.detect()
    expect(el('hilos-status-work-notice').textContent).toContain(
      'Keep mine: My title; Take theirs: Their title',
    )
    expect(workSave().disabled).toBe(true)
    h.act(submitWork)
    expect(h.sent).toEqual([])
    h.act(() => el('conflict-accept-mine').click())
    expect(field('title').value).toBe('My title')
    h.act(() => {
      toggleComponent('service', true)
      setWorkField('ends', '2031-01-02T04:06:03')
    })
    h.update({
      componentKeys: ['database'],
      endsAt: announcement.endsAt + 2000,
      version: 4,
    })
    h.chooseZone('Asia/Tokyo')
    expect(el('hilos-status-work-notice').textContent).toContain(
      'Keep mine: 2031-01-02T04:06:03 (UTC)',
    )
    expect(el('hilos-status-work-notice').textContent).toContain('(Asia/Tokyo)')
    h.act(() => el('conflict-accept-theirs').click())
    expect(field('component-database').checked).toBe(true)
    expect(field('component-service').checked).toBe(false)
    expect(field('ends').value).toBe(
      localDateTimeValue(announcement.endsAt + 2000, 'UTC'),
    )
    h.update({ lastMailQueuedAt: 1_800_000_000_000 })
    h.detect()
    h.act(submitWork)
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
    await settled(h.fixture)
    expect(query('modal')).toBeNull()
    expect(hilosToasts.toasts.get().map((toast) => toast.message)).toEqual([
      'Announcement changed.',
    ])
  })

  it.each(['deleted', 'canceled', 'consumed', 'expired', 'stale'] as const)(
    'preserves the draft and blocks Save when its target becomes %s',
    (outcome) => {
      const h = open('edit')
      h.act(() => setWorkField('title', 'Keep this draft'))
      if (outcome === 'deleted')
        h.delta({ kind: 'row_removed', rowKey: '7', reason: 'deleted' })
      else if (outcome === 'stale')
        h.delta({ kind: 'row_stale', rowKey: '7', staleSources: ['work'] })
      else h.update({ [`${outcome}At`]: 1_900_000_000_000 })
      h.detect()
      expect(field('title').value).toBe('Keep this draft')
      expect(workSave().disabled).toBe(true)
      if (outcome !== 'stale')
        expect(workSave().textContent?.trim()).toBe('Deleted')
      expect(el('hilos-status-work-notice').textContent?.trim()).not.toBe('')
      h.act(submitWork)
      expect(h.sent).toEqual([])
    },
  )

  it('keeps viewer draft fields and Cancel readable while Save and Enter cannot mutate', () => {
    const h = open('edit')
    h.act(() => setWorkField('title', 'My draft'))
    h.viewer()
    h.detect()
    expect(workSave().disabled).toBe(true)
    expect(workSave().getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )
    expect(field('title').disabled).toBe(false)
    expect(field('close').disabled).toBe(false)
    h.act(() => enter(field('title')))
    expect(h.sent).toEqual([])
    h.act(() => el('hilos-status-work-close').click())
    expect(query('modal-confirm')).not.toBeNull()
  })

  it.each([
    'Another announcement already occupies this window.',
    'The announcement changed. Reload its current version.',
  ])('retains the draft after the owner refuses: %s', async (reason) => {
    const h = open('edit')
    const slot = el('hilos-status-work-error-slot')
    h.act(() => setWorkField('title', 'Keep this draft'))
    h.act(submitWork)
    h.reply(false, {
      reason,
      errorType: 'WorkFailure',
      errorDetail: 'Diagnostic details',
    })
    await settled(h.fixture)
    expect(el('hilos-status-work-error-slot')).toBe(slot)
    expect(el('hilos-status-work-error').textContent).toContain(reason)
    expect(field('title').value).toBe('Keep this draft')
    expect(hilosToasts.toasts.get()).toEqual([])
    h.act(() => el('hilos-status-work-error-details').click())
    expect(document.body.textContent).toContain('Diagnostic details')
    expect(document.body.textContent).toContain('WorkFailure')
  })

  it('uses the core verdict for an empty or reversed interval and for an unknown selected component', () => {
    const h = open()
    h.act(submitWork)
    expect(workSave().disabled).toBe(true)
    h.act(fillWork)
    h.act(() => setWorkField('ends', '2031-01-02T12:04:05'))
    h.act(submitWork)
    expect(workSave().disabled).toBe(true)
    h.act(() => setWorkField('ends', '2031-01-02T11:04:05'))
    h.act(submitWork)
    expect(h.sent).toEqual([])
    h.act(() => setWorkField('ends', '2031-01-02T13:05:06'))
    h.data({
      components: [{ componentKey: 'queue', name: 'Queue' }],
      upcomingCount: 0,
      nextWork: null,
    })
    h.detect()
    expect(workSave().disabled).toBe(true)
    h.act(submitWork)
    expect(h.sent).toEqual([])
    expect(field('title').value).toBe('  Upgrade <database>  ')
  })

  it.each(['timeout', 'disconnect'] as const)(
    'retains an unconfirmed draft after %s and ignores late success',
    async (outcome) => {
      const h = open()
      h.act(fillWork)
      h.act(submitWork)
      if (outcome === 'timeout') await vi.advanceTimersByTimeAsync(1001)
      else h.connect('disconnected')
      await settled(h.fixture)
      expect(el('hilos-status-work-error').textContent).toContain(
        'The result is unconfirmed. The operation may have completed.',
      )
      expect(field('title').value).toBe('  Upgrade <database>  ')
      h.reply(true)
      await settled(h.fixture)
      expect(query('modal')).not.toBeNull()
      expect(hilosToasts.toasts.get()).toEqual([])
    },
  )

  it('releases the old opening and ignores its late acknowledgement when context is replaced', async () => {
    const h = open('edit')
    h.act(() => setWorkField('title', 'Pending draft'))
    h.act(submitWork)
    const next = statusWorksHarness()
    try {
      h.fixture.componentRef.setInput('context', next.context)
      h.detect()
      expect(h.registered.size).toBe(0)
      expect(h.focuses.at(-1)).toEqual([
        'hilos_status_works',
        'hilosStatusWorks',
        '',
      ])
      h.reply(true)
      await settled(h.fixture)
      expect(query('modal')).toBeNull()
      expect(hilosToasts.toasts.get()).toEqual([])
      next.window()
      h.detect()
      h.open('create')
      expect(field('title').value).toBe('')
      expect(next.sent).toEqual([])
      h.fixture.destroy()
      expect(next.registered.size).toBe(0)
    } finally {
      next.dispose()
    }
  })
})
