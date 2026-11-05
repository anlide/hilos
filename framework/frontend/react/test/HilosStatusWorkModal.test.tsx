// The scheduled maintenance window in React over the real core creation and
// row-edit controllers, HilosModal, ConflictActions, LoadingButton and tracked
// action, inside the real admin page shell; only the wire is fake. Each test
// creates and starts the Works page's core generation itself, as the page does
// in its effect, and hands it to the window; the page is proven by its own test.
// Scenarios mirror framework/frontend/vue/src/admin/status/HilosStatusWorkModal.test.ts.
import { useState } from 'react'
import { act, fireEvent } from '@testing-library/react'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import {
  HilosPages,
  hilosToasts,
  HILOS_VIEW_MODE_STRIP_TEXT_ID,
  type TableRow,
} from '@hilos/core/base'
import {
  localDateTimeValue,
  type HilosStatusWorksData,
} from '@hilos/core/admin/status'

import { HilosStatusWorkModal } from '../src/admin/status/HilosStatusWorkModal.js'
import { readerTimeZone } from './readerTimeZone.js'
import {
  announcement,
  browserDateTimeValues,
  statusWorksHarness,
  statusWorksWindows,
  workRow,
  worksData,
} from './statusFixtures.js'

const cleanups: (() => void)[] = []
beforeEach(() => {
  vi.useFakeTimers()
  // First in, so it is released last, after every field has left the page.
  cleanups.push(browserDateTimeValues())
})
afterEach(() => {
  for (const cleanup of cleanups.splice(0).reverse()) act(cleanup)
  hilosToasts.clear()
  vi.useRealTimers()
})

/** The rule of announcements the interval fields are described by. */
const OVERLAP_HINT =
  'The window cannot overlap another announcement, even if the components differ. Simultaneous work is one announcement with several components. Adjacent windows are fine: one ends at 07:00, the next starts at 07:00.'

type Windows = ReturnType<typeof statusWorksWindows>

function find(id: string): HTMLElement | null {
  return document.body.querySelector<HTMLElement>(`[data-id="${id}"]`)
}

function get(id: string): HTMLElement {
  const element = find(id)
  if (element === null) throw new Error(`Missing [data-id="${id}"]`)
  return element
}

function field(name: string): HTMLInputElement {
  return get(`hilos-status-work-${name}`) as HTMLInputElement
}

function workForm(): HTMLElement {
  return get('hilos-status-work-form')
}

function workSave(): HTMLButtonElement {
  return get('hilos-status-work-save') as HTMLButtonElement
}

function notice(): string {
  return get('hilos-status-work-notice').textContent ?? ''
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
 * Type into a field the way the person does: one native change.
 *
 * @param name The field's data-id suffix.
 * @param value The whole new value.
 */
function type(name: string, value: string): void {
  fireEvent.change(field(name), { target: { value } })
}

/** Fill a valid announcement whose Tokyo times are the default announcement's. */
function fillWork(): void {
  type('title', '  Upgrade <database>  ')
  type('starts', '2031-01-02T12:04:05')
  type('ends', '2031-01-02T13:05:06')
  fireEvent.click(field('component-service'))
  type('description', '  Plain text <b>only</b>  ')
}

/**
 * Choose the reader zone for the rest of the test.
 *
 * @param timeZone The reader's own IANA zone.
 */
function readIn(timeZone: string) {
  const zone = readerTimeZone()
  cleanups.push(zone.release)
  act(() => zone.choose(timeZone))
  return zone
}

/** The page's opener and both windows, over the generation it is handed. */
let handOver: (next: Windows) => void = () => {}
function WorksWindows({ initial }: { initial: Windows }) {
  const [windows, setWindows] = useState(initial)
  handOver = setWindows
  return (
    <>
      <button type="button" data-id="works-opener">
        Schedule maintenance
      </button>
      <HilosStatusWorkModal controller={windows.create} state={windows.state} />
      <HilosStatusWorkModal controller={windows.edit} state={windows.state} />
    </>
  )
}

/**
 * Mount the windows over a started generation, answer the page, and open one
 * from a focused opener, as the Works page does.
 *
 * @param kind Which window to open.
 * @param strict Whether to mount under StrictMode.
 * @param rows The rows of the window.
 * @param data The catalog and summary of the page answer.
 */
async function open(
  kind: 'create' | 'edit' = 'create',
  strict = false,
  rows: readonly TableRow[] = [workRow()],
  data: HilosStatusWorksData = worksData,
) {
  const h = statusWorksHarness()
  cleanups.push(h.dispose)
  const w = statusWorksWindows(h.context)
  cleanups.push(w.dispose)
  const view = h.mount(<WorksWindows initial={w} />, strict)
  let mounted = true
  const unmount = () => {
    if (!mounted) return
    mounted = false
    view.unmount()
  }
  cleanups.push(unmount)
  h.window(rows, data)
  const opener = get('works-opener')
  opener.focus()
  act(() => {
    if (kind === 'create') w.create.open()
    else w.edit.open('7')
  })
  await settled()
  return { h, w, unmount, opener }
}

describe('HilosStatusWorkModal with real creation and row-edit controllers', () => {
  it.each(['create', 'edit'] as const)(
    'keeps component labels and selections separate from same-key fields during %s',
    async (kind) => {
      const components = [
        { componentKey: 'title', name: 'Title service' },
        { componentKey: 'starts', name: 'Starts service' },
      ]
      readIn('UTC')
      const { w } = await open(kind, false, [workRow({ componentKeys: [] })], {
        ...worksData!,
        components,
      })
      const controller = kind === 'create' ? w.create : w.edit
      type('title', 'Maintenance')
      type('description', 'Details for visitors')
      type('starts', '2031-01-02T03:04:05')
      type('ends', '2031-01-02T04:05:06')
      const labels = [...workForm().querySelectorAll('label')]
      const staticEntries = [
        { key: 'title', label: 'Title' },
        { key: 'starts', label: 'Starts, UTC' },
        { key: 'ends', label: 'Ends, UTC' },
        { key: 'description', label: 'What visitors read' },
      ]
      const staticFields = staticEntries.map(({ key, label: text }) => {
        const control = field(key) as HTMLInputElement | HTMLTextAreaElement
        const label = labels.find((node) => node.textContent?.trim() === text)!
        expect.soft(label.control).toBe(control)
        expect.soft([...(control.labels ?? [])]).toEqual([label])
        return control
      })
      const values = staticFields.map((control) => control.value)
      const selected: string[] = []
      for (const { componentKey, name } of components) {
        const checkbox = field(`component-${componentKey}`)
        const label = labels.find((node) => node.textContent?.trim() === name)!
        expect.soft(label.control).toBe(checkbox)
        // Native labels supply the accessible name of these plain controls.
        expect.soft([...(checkbox.labels ?? [])]).toEqual([label])
        expect.soft(checkbox.labels?.[0]?.textContent?.trim()).toBe(name)
        ;(field('close') as HTMLElement).focus()
        fireEvent.click(label)
        selected.push(componentKey)
        expect.soft(checkbox.checked).toBe(true)
        for (const item of components) {
          const itemCheckbox = field(`component-${item.componentKey}`)
          expect
            .soft(itemCheckbox.checked)
            .toBe(selected.includes(item.componentKey))
        }
        expect.soft(controller.form.get().componentKeys).toEqual(selected)
        expect.soft(staticFields).not.toContain(document.activeElement)
        expect
          .soft(staticFields.map((control) => control.value))
          .toEqual(values)
      }
    },
  )

  it('uses the captured IANA zone, second precision and core payload; success waits for its correlated commit ack', async () => {
    const zone = readIn('Asia/Tokyo')
    const { h, w, opener } = await open()
    const modal = get('modal')
    expect(modal.getAttribute('aria-label')).toBe('Schedule maintenance')
    expect(workForm().getAttribute('aria-label')).toBe('Schedule maintenance')
    expect(
      document.getElementById(workForm().getAttribute('aria-describedby')!)
        ?.textContent,
    ).toContain('This is an advance announcement.')
    expect(document.activeElement).toBe(field('title'))
    expect(field('starts').getAttribute('step')).toBe('1')
    expect(field('ends').getAttribute('step')).toBe('1')
    expect(modal.textContent).toContain('Starts, Asia/Tokyo')
    expect(modal.textContent).toContain('Ends, Asia/Tokyo')
    expect(workForm().querySelector('legend')?.textContent).toBe('Affects')
    for (const control of workForm().querySelectorAll('input, textarea')) {
      expect(
        workForm().querySelector(`label[for="${control.id}"]`),
      ).not.toBeNull()
    }
    expect(workSave().disabled).toBe(true)
    fillWork()
    act(() => zone.choose('America/New_York'))
    expect(modal.textContent).toContain('Starts, Asia/Tokyo')
    fireEvent.keyDown(field('title'), { key: 'Enter' })
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
    expect(workSave().textContent).toBe('Schedule maintenance')
    expect(workSave().getAttribute('aria-busy')).toBe('true')
    expect(workSave().disabled).toBe(false)
    const fieldset = workForm().closest('fieldset')!
    expect(fieldset.disabled).toBe(true)
    fireEvent.submit(workForm())
    fireEvent.click(workSave())
    fireEvent.keyDown(get('modal'), { key: 'Escape' })
    fireEvent.click(get('modal'))
    fireEvent.click(get('modal-close'))
    fireEvent.click(field('close'))
    act(() => w.create.close())
    act(() => {
      w.create.open()
    })
    expect(h.sent).toHaveLength(1)
    expect(find('modal-confirm')).toBeNull()
    expect(workForm().closest('fieldset')).toBe(fieldset)
    act(() =>
      h.emit('actionSuccess', {
        action: h.sent[0]!.action,
        requestId: 'unrelated',
      }),
    )
    await settled()
    expect(find('modal')).not.toBeNull()
    expect(toasts()).toEqual([])
    await act(async () => {
      await vi.advanceTimersByTimeAsync(301)
    })
    expect(
      workSave().querySelector('[data-id="loading-button-spinner"]'),
    ).not.toBeNull()
    act(() => h.reply(true))
    await settled()
    expect(find('modal')).toBeNull()
    expect(toasts()).toEqual(['Maintenance scheduled.'])
    expect(document.activeElement).toBe(opener)
  })

  it.each(['create', 'edit'] as const)(
    'describes both interval fields of the %s window by the rule that announcements never overlap',
    async (kind) => {
      await open(kind)
      for (const name of ['starts', 'ends']) {
        const description = field(name)
          .getAttribute('aria-describedby')!
          .split(' ')
          .map((id) => document.getElementById(id)?.textContent ?? '')
          .join(' ')
        expect(description.replace(/\s+/g, ' ')).toContain(OVERLAP_HINT)
      }
    },
  )

  it('uses the standard dirty confirmation, trap and return to the opener', async () => {
    const { h, opener } = await open()
    type('title', 'Unsaved draft')
    // Invalid Save is disabled, so the last operable control is Cancel.
    field('close').focus()
    fireEvent.keyDown(get('modal'), { key: 'Tab' })
    expect(document.activeElement).toBe(get('modal-close'))
    fireEvent.keyDown(get('modal'), { key: 'Escape' })
    await settled()
    expect(document.body.textContent).toContain('Discard changes?')
    fireEvent.click(get('modal-confirm-cancel'))
    await settled()
    expect(field('title').value).toBe('Unsaved draft')
    fireEvent.click(field('close'))
    fireEvent.click(get('modal-confirm-discard'))
    await settled()
    expect(find('modal')).toBeNull()
    expect(document.activeElement).toBe(opener)
    expect(h.sent).toEqual([])
  })

  it('merges independent text/list/time fields, resolves conflicting fields with Keep/Take and sends the current version and public identity', async () => {
    const zone = readIn('UTC')
    const { h } = await open('edit')
    expect(get('modal').getAttribute('aria-label')).toBe(
      'Change scheduled maintenance',
    )
    expect(h.focuses.at(-1)).toEqual([
      'hilos_status_works',
      'hilosStatusWorks',
      '7',
    ])
    type('title', 'My title')
    h.update({
      description: 'Incoming description',
      componentKeys: ['queue'],
      endsAt: announcement.endsAt + 1000,
      version: 2,
    })
    expect(field('description').value).toBe('Incoming description')
    expect(field('component-queue').checked).toBe(true)
    expect(field('ends').value).toBe(
      localDateTimeValue(announcement.endsAt + 1000, 'UTC'),
    )
    expect(notice()).toContain('Updated just now')
    h.update({ title: 'Their title', version: 3 })
    expect(notice()).toContain('Keep mine: My title; Take theirs: Their title')
    expect(workSave().disabled).toBe(true)
    fireEvent.submit(workForm())
    expect(h.sent).toEqual([])
    fireEvent.click(get('conflict-accept-mine'))
    expect(field('title').value).toBe('My title')
    fireEvent.click(field('component-service'))
    type('ends', '2031-01-02T04:06:03')
    h.update({
      componentKeys: ['database'],
      endsAt: announcement.endsAt + 2000,
      version: 4,
    })
    act(() => zone.choose('Asia/Tokyo'))
    expect(notice()).toContain('Keep mine: 2031-01-02T04:06:03 (UTC)')
    expect(notice()).toContain('(Asia/Tokyo)')
    expect(workSave().disabled).toBe(true)
    fireEvent.click(get('conflict-accept-theirs'))
    expect(field('component-database').checked).toBe(true)
    expect(field('component-service').checked).toBe(false)
    expect(field('ends').value).toBe(
      localDateTimeValue(announcement.endsAt + 2000, 'UTC'),
    )
    h.update({ lastMailQueuedAt: 1_800_000_000_000 })
    expect(field('title').value).toBe('My title')
    expect(workSave().disabled).toBe(false)
    fireEvent.submit(workForm())
    expect(h.sent).toEqual([
      {
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
      },
    ])
    expect(workSave().textContent).toBe('Save')
    act(() => h.reply(true))
    await settled()
    expect(find('modal')).toBeNull()
    expect(toasts()).toEqual(['Announcement changed.'])
  })

  it.each(['deleted', 'canceled', 'consumed', 'expired', 'stale'] as const)(
    'keeps the draft readable and blocks Save when the target is %s',
    async (outcome) => {
      const { h } = await open('edit')
      type('title', 'Keep this draft')
      if (outcome === 'deleted')
        h.delta({ kind: 'row_removed', rowKey: '7', reason: 'deleted' })
      else if (outcome === 'stale')
        h.delta({ kind: 'row_stale', rowKey: '7', staleSources: ['work'] })
      else h.update({ [`${outcome}At`]: 1_900_000_000_000 })
      expect(field('title').value).toBe('Keep this draft')
      expect(field('title').disabled).toBe(false)
      expect(workSave().disabled).toBe(true)
      if (outcome !== 'stale') expect(workSave().textContent).toBe('Deleted')
      expect(notice()).not.toBe('')
      fireEvent.submit(workForm())
      fireEvent.keyDown(field('title'), { key: 'Enter' })
      expect(h.sent).toEqual([])
    },
  )

  it('keeps Cancel and draft fields live after access is revoked and marks Save with the shared explanation', async () => {
    const { h } = await open('edit')
    type('title', 'My draft')
    h.viewer()
    expect(workSave().disabled).toBe(true)
    expect(workSave().getAttribute('aria-describedby')).toContain(
      HILOS_VIEW_MODE_STRIP_TEXT_ID,
    )
    expect(notice()).not.toBe('')
    expect(field('close').disabled).toBe(false)
    expect(field('title').disabled).toBe(false)
    fireEvent.keyDown(field('title'), { key: 'Enter' })
    fireEvent.submit(workForm())
    expect(h.sent).toEqual([])
    fireEvent.click(field('close'))
    await settled()
    expect(document.body.textContent).toContain('Discard changes?')
  })

  it('reserves error/notice slots and shows exact backend refusal and standard diagnostic details', async () => {
    readIn('Asia/Tokyo')
    const { h } = await open()
    const errorSlot = get('hilos-status-work-error-slot')
    expect(find('hilos-status-work-error-idle')).not.toBeNull()
    expect(find('hilos-status-work-notice-idle')).not.toBeNull()
    fillWork()
    fireEvent.submit(workForm())
    act(() =>
      h.reply(false, {
        reason: 'Another announcement already occupies this window.',
        errorType: 'WorkFailure',
        errorDetail: 'Diagnostic details',
      }),
    )
    await settled()
    expect(get('hilos-status-work-error-slot')).toBe(errorSlot)
    expect(get('hilos-status-work-error').textContent).toContain(
      'Another announcement already occupies this window.',
    )
    expect(field('title').value).toBe('  Upgrade <database>  ')
    fireEvent.click(get('hilos-status-work-error-details'))
    expect(document.body.querySelectorAll('[data-id="modal"]')).toHaveLength(2)
    expect(document.body.textContent).toContain('WorkFailure')
    expect(document.body.textContent).toContain('Diagnostic details')
    expect(toasts()).toEqual([])
  })

  it('schedules once when Enter is pressed inside the refusal details window', async () => {
    readIn('Asia/Tokyo')
    const { h } = await open()
    fillWork()
    fireEvent.submit(workForm())
    act(() =>
      h.reply(false, {
        reason: 'Another announcement already occupies this window.',
        errorType: 'WorkFailure',
        errorDetail: 'Diagnostic details',
      }),
    )
    await settled()
    expect(workSave().disabled).toBe(false)
    fireEvent.click(get('hilos-status-work-error-details'))
    const details = get('hilos-status-work-error-detail').closest(
      '[data-id="modal"]',
    )!
    fireEvent.keyDown(details, { key: 'Enter' })
    expect(h.sent).toHaveLength(1)
    expect(get('hilos-status-work-error').textContent).toContain(
      'Another announcement already occupies this window.',
    )
  })

  it('changes nothing when Enter is pressed inside the details window of an incoming change', async () => {
    readIn('UTC')
    const { h } = await open('edit')
    type('title', 'My title')
    h.update({ description: 'Incoming description', version: 2 })
    expect(notice()).toContain('Updated just now')
    expect(workSave().disabled).toBe(false)
    fireEvent.click(get('hilos-status-work-notice-details'))
    const details = get('hilos-status-work-notice-full').closest(
      '[data-id="modal"]',
    )!
    fireEvent.keyDown(details, { key: 'Enter' })
    expect(h.sent).toEqual([])
    fireEvent.keyDown(field('title'), { key: 'Enter' })
    expect(h.sent.map((sent) => sent.action)).toEqual([
      'hilos_status_work_change',
    ])
  })

  it.each(['timeout', 'disconnect'] as const)(
    'keeps an unconfirmed draft after %s and ignores late success',
    async (outcome) => {
      readIn('Asia/Tokyo')
      const { h } = await open()
      fillWork()
      fireEvent.submit(workForm())
      if (outcome === 'timeout')
        await act(async () => {
          await vi.advanceTimersByTimeAsync(1001)
        })
      else h.connect('disconnected')
      await settled()
      expect(get('hilos-status-work-error').textContent).toContain(
        'The operation may have completed.',
      )
      act(() => h.reply(true))
      await settled()
      expect(find('modal')).not.toBeNull()
      expect(toasts()).toEqual([])
      expect(field('title').value).toBe('  Upgrade <database>  ')
    },
  )

  it.each(['scope', 'dispose'] as const)(
    'does not toast or revive a window after the page %s ends its generation',
    async (departure) => {
      readIn('Asia/Tokyo')
      const { h, w } = await open()
      fillWork()
      fireEvent.submit(workForm())
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
      act(() => {
        w.create.open()
      })
      expect(find('modal')).toBeNull()
    },
  )

  it('schedules and changes through the live windows under StrictMode, one request and one toast each', async () => {
    readIn('Asia/Tokyo')
    const { h, w, opener } = await open('create', true)
    expect(h.registered.size).toBe(1)
    expect(document.activeElement).toBe(field('title'))
    fillWork()
    fireEvent.submit(workForm())
    expect(h.sent).toHaveLength(1)
    act(() => h.reply(true))
    await settled()
    expect(find('modal')).toBeNull()
    expect(toasts()).toEqual(['Maintenance scheduled.'])
    expect(document.activeElement).toBe(opener)
    act(() => {
      w.edit.open('7')
    })
    await settled()
    expect(get('modal').getAttribute('aria-label')).toBe(
      'Change scheduled maintenance',
    )
    expect(field('title').value).toBe('Database upgrade')
    type('title', 'Renamed')
    fireEvent.submit(workForm())
    expect(h.sent).toHaveLength(2)
    expect(h.sent[1]).toMatchObject({
      action: 'hilos_status_work_change',
      data: { workId: 7, expectedVersion: 1, title: 'Renamed' },
    })
    act(() => h.reply(true))
    await settled()
    expect(find('modal')).toBeNull()
    expect(toasts()).toEqual([
      'Maintenance scheduled.',
      'Announcement changed.',
    ])
  })

  it('follows the controller it is handed and leaves the draft with the page-owned controller', async () => {
    readIn('Asia/Tokyo')
    const { h, w, unmount } = await open()
    type('title', 'Kept draft')
    unmount()
    expect(find('modal')).toBeNull()
    const again = h.mount(<WorksWindows initial={w} />)
    cleanups.push(again.unmount)
    expect(field('title').value).toBe('Kept draft')
    const next = statusWorksWindows(h.context)
    cleanups.push(next.dispose)
    act(() => w.dispose())
    expect(find('modal')).toBeNull()
    act(() => handOver(next))
    act(() => {
      w.create.open()
    })
    expect(find('modal')).toBeNull()
    act(() => {
      next.create.open()
    })
    expect(field('title').value).toBe('')
    fillWork()
    fireEvent.submit(workForm())
    expect(h.sent).toHaveLength(1)
    act(() => h.reply(true))
    await settled()
    expect(find('modal')).toBeNull()
    expect(toasts()).toEqual(['Maintenance scheduled.'])
  })
})
