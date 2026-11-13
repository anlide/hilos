import { By } from '@angular/platform-browser'
import { HILOS_VIEW_MODE_STRIP_TEXT_ID, hilosToasts } from '@hilos/core/base'
import { afterAll, beforeAll, describe, expect, it, vi } from 'vitest'

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
  WINDOW_PARTS,
  field,
  fillWork,
  press,
  setWorkField,
  submitWork,
  toggleComponent,
  typeWindowEdge,
  windowPart,
  windowTexts,
  workForm,
  workSave,
  worksPage,
} from './helpers/hilosStatusWorks.js'

let scrollDescriptor: PropertyDescriptor | undefined
let stubbedScroll = false
beforeAll(() => {
  scrollDescriptor = Object.getOwnPropertyDescriptor(
    HTMLElement.prototype,
    'scrollIntoView',
  )
  // jsdom draws no layout and so implements no scrollIntoView, which the
  // window calendar calls when it opens.
  if (typeof HTMLElement.prototype.scrollIntoView !== 'function') {
    Object.defineProperty(HTMLElement.prototype, 'scrollIntoView', {
      configurable: true,
      writable: true,
      value() {},
    })
    stubbedScroll = true
  }
})
afterAll(() => {
  if (!stubbedScroll) return
  if (scrollDescriptor === undefined) {
    Reflect.deleteProperty(HTMLElement.prototype, 'scrollIntoView')
  } else {
    Object.defineProperty(
      HTMLElement.prototype,
      'scrollIntoView',
      scrollDescriptor,
    )
  }
})

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
    async (kind) => {
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
      })
      await typeWindowEdge(
        'Starts',
        ['02', '01', '2031', '03', '04', '05'],
        h.detect,
      )
      await typeWindowEdge(
        'Ends',
        ['02', '01', '2031', '04', '05', '06'],
        h.detect,
      )
      const form = workForm()
      const labels = [...form.querySelectorAll('label')]
      const staticFields = (
        [
          ['title', 'Title'],
          ['description', 'What visitors read'],
        ] as const
      ).map(([id, name]) => {
        const control = field(id)
        const label = labels.find((node) => node.textContent?.trim() === name)!
        expect.soft(label.control).toBe(control)
        expect.soft([...control.labels!]).toEqual([label])
        return control
      })
      const values = staticFields.map((control) => control.value)
      const windowGroup = field('window')
      expect
        .soft(
          document
            .getElementById(windowGroup.getAttribute('aria-labelledby')!)
            ?.textContent?.trim(),
        )
        .toBe('Window, UTC')
      const windowValues = [...windowTexts('Starts'), ...windowTexts('Ends')]
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
        expect.soft(windowGroup.contains(document.activeElement)).toBe(false)
        expect
          .soft(staticFields.map((control) => control.value))
          .toEqual(values)
        expect
          .soft([...windowTexts('Starts'), ...windowTexts('Ends')])
          .toEqual(windowValues)
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

  it('names the window group in the captured zone, gives both edges seconds, and links every part to the shared overlap explanation', async () => {
    const h = open()
    expect(document.activeElement).toBe(field('title'))
    expect(workForm().getAttribute('aria-label')).toBe('Schedule maintenance')
    const group = field('window')
    const groupName = () =>
      document
        .getElementById(group.getAttribute('aria-labelledby')!)
        ?.textContent?.trim()
    expect(groupName()).toBe('Window, Asia/Tokyo')
    const descriptions = (part: HTMLElement) =>
      (part.getAttribute('aria-describedby') ?? '')
        .split(' ')
        .map((id) =>
          document.getElementById(id)?.textContent?.trim().replace(/\s+/g, ' '),
        )
    for (const edge of ['Starts', 'Ends'] as const)
      for (const name of WINDOW_PARTS) {
        const part = windowPart(edge, name)
        expect(part).not.toBeNull()
        expect(part.getAttribute('aria-label')).toBe(`${edge} ${name}`)
        expect(descriptions(part)).toContain(
          'The window cannot overlap another announcement, even if the components differ. Simultaneous work is one announcement with several components. Adjacent windows are fine: one ends at 07:00, the next starts at 07:00.',
        )
      }
    await fillWork(h.detect)
    h.chooseZone('America/New_York')
    expect(groupName()).toBe('Window, Asia/Tokyo')
    expect(windowTexts('Starts')).toEqual([
      '02',
      '01',
      '2031',
      '12',
      '04',
      '05',
    ])
  })

  it('sends the core epoch payload once and holds the same busy fields until the correlated owner acknowledgement', async () => {
    const h = open()
    await fillWork(h.detect)
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
    expect(windowTexts('Ends')).toEqual(['02', '01', '2031', '04', '05', '07'])
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
    h.act(() => toggleComponent('service', true))
    await typeWindowEdge(
      'Ends',
      ['02', '01', '2031', '04', '06', '03'],
      h.detect,
    )
    h.update({
      componentKeys: ['database'],
      endsAt: announcement.endsAt + 2000,
      version: 4,
    })
    h.chooseZone('Asia/Tokyo')
    expect(el('hilos-status-work-notice').textContent).toContain(
      'Keep mine: 02/01/2031, 04:06:03 (UTC)',
    )
    expect(el('hilos-status-work-notice').textContent).toContain('(Asia/Tokyo)')
    h.act(() => el('conflict-accept-theirs').click())
    expect(field('component-database').checked).toBe(true)
    expect(field('component-service').checked).toBe(false)
    expect(windowTexts('Ends')).toEqual(['02', '01', '2031', '04', '05', '08'])
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

  it('uses the core verdict for an empty or reversed interval and for an unknown selected component', async () => {
    const h = open()
    h.act(submitWork)
    expect(workSave().disabled).toBe(true)
    await fillWork(h.detect)
    await typeWindowEdge(
      'Ends',
      ['02', '01', '2031', '12', '04', '05'],
      h.detect,
    )
    h.act(submitWork)
    expect(workSave().disabled).toBe(true)
    await typeWindowEdge(
      'Ends',
      ['02', '01', '2031', '11', '04', '05'],
      h.detect,
    )
    h.act(submitWork)
    expect(h.sent).toEqual([])
    await typeWindowEdge(
      'Ends',
      ['02', '01', '2031', '13', '05', '06'],
      h.detect,
    )
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
      await fillWork(h.detect)
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

describe('Works window field', () => {
  /** Open the window calendar with its own button, as a pointer does. */
  function openCalendar(h: { act(run: () => void): void }): void {
    h.act(() =>
      workForm()
        .querySelector<HTMLButtonElement>('button[aria-label="Open calendar"]')!
        .click(),
    )
  }

  function calendar(): HTMLElement | null {
    return workForm().querySelector<HTMLElement>('[role="dialog"]')
  }

  /**
   * Run steps on a narrow screen and restore the environment's media query.
   *
   * @param steps The steps that mount and drive the page.
   */
  async function withNarrowScreen(steps: () => Promise<void>): Promise<void> {
    const original = Object.getOwnPropertyDescriptor(globalThis, 'matchMedia')
    const media = {
      matches: true,
      addEventListener() {},
      removeEventListener() {},
    }
    Object.defineProperty(globalThis, 'matchMedia', {
      configurable: true,
      value: () => media,
    })
    try {
      await steps()
    } finally {
      if (original) Object.defineProperty(globalThis, 'matchMedia', original)
      else Reflect.deleteProperty(globalThis, 'matchMedia')
    }
  }

  it('offers no day before today in the captured zone', () => {
    vi.setSystemTime(Date.UTC(2030, 5, 15, 12, 0, 0))
    const h = open('create', 'UTC')
    openCalendar(h)
    const days = [
      ...calendar()!.querySelectorAll<HTMLElement>('[role="gridcell"]'),
    ]
    const todayIndex = days.findIndex(
      (day) => day.getAttribute('aria-current') === 'date',
    )
    const today = days[todayIndex]!
    const yesterday = days[todayIndex - 1]!
    expect(today.textContent?.trim()).toBe('15')
    expect(today.getAttribute('aria-disabled')).toBeNull()
    expect(yesterday.textContent?.trim()).toBe('14')
    expect(yesterday.getAttribute('aria-disabled')).toBe('true')
    h.act(() => yesterday.click())
    expect(windowPart('Starts', 'Day').getAttribute('aria-valuenow')).toBeNull()
    h.act(() =>
      calendar()!
        .querySelector<HTMLElement>('[role="gridcell"][aria-current="date"]')!
        .click(),
    )
    expect(windowPart('Starts', 'Day').getAttribute('aria-valuenow')).toBe('15')
  })

  it('locks the window while the request is in flight and returns it intact after a refusal', async () => {
    const h = open()
    await fillWork(h.detect)
    const typed = [...windowTexts('Starts'), ...windowTexts('Ends')]
    h.act(submitWork)
    expect(h.sent).toHaveLength(1)
    const opener = workForm().querySelector<HTMLButtonElement>(
      'button[aria-label="Open calendar"]',
    )!
    expect(opener.disabled).toBe(true)
    for (const part of field('window').querySelectorAll('input'))
      expect(part.disabled).toBe(true)
    h.reply(false, {
      reason: 'Another announcement already occupies this window.',
    })
    await settled(h.fixture)
    expect(el('hilos-status-work-error').textContent).toContain(
      'Another announcement already occupies this window.',
    )
    expect(opener.disabled).toBe(false)
    for (const part of field('window').querySelectorAll('input'))
      expect(part.disabled).toBe(false)
    expect([...windowTexts('Starts'), ...windowTexts('Ends')]).toEqual(typed)
  })

  it('keeps an unfinished window from Save and from Enter and asks before discarding it', async () => {
    const h = open()
    h.act(() => {
      setWorkField('title', 'Upgrade')
      toggleComponent('service', true)
    })
    await typeWindowEdge(
      'Starts',
      ['02', '01', '2031', '12', '04', '05'],
      h.detect,
    )
    const endsDay = windowPart('Ends', 'Day')
    h.act(() => endsDay.focus())
    for (const key of ['0', '2']) await press(endsDay, key, h.detect)
    expect(windowTexts('Ends')[0]).toBe('02')
    expect(workSave().disabled).toBe(true)
    await press(windowPart('Ends', 'Month'), 'Enter', h.detect)
    expect(h.sent).toEqual([])
    await press(windowPart('Ends', 'Month'), 'Escape', h.detect)
    expect(document.body.textContent).toContain('Discard changes?')
  })

  it('leaves Enter inside the window field to its open calendar and submits from the field once it is closed', async () => {
    const h = open()
    await fillWork(h.detect)
    expect(workSave().disabled).toBe(false)
    openCalendar(h)
    await press(
      calendar()!.querySelector<HTMLElement>(
        '[role="gridcell"][tabindex="0"]',
      )!,
      'Enter',
      h.detect,
    )
    expect(h.sent).toEqual([])
    expect(calendar()).not.toBeNull()
    const time = calendar()!.querySelector<HTMLElement>(
      'input[role="spinbutton"]',
    )!
    // jsdom has no implicit submission; a canceled Enter is what stops a browser's.
    expect((await press(time, 'Enter', h.detect)).defaultPrevented).toBe(true)
    expect(h.sent).toEqual([])
    expect(query('modal')).not.toBeNull()
    // A field part leaves Enter alone; the form silences it for the calendar.
    expect(
      (await press(windowPart('Ends', 'Seconds'), 'Enter', h.detect))
        .defaultPrevented,
    ).toBe(true)
    expect(h.sent).toEqual([])
    await press(time, 'Escape', h.detect)
    expect(calendar()).toBeNull()
    expect(document.body.textContent).not.toContain('Discard changes?')
    expect(workSave().disabled).toBe(false)
    await press(windowPart('Ends', 'Seconds'), 'Enter', h.detect)
    expect(h.sent.map((frame) => frame.action)).toEqual([
      'hilos_status_work_schedule',
    ])
  })

  it('opens the next form with an empty window in the newly chosen zone', async () => {
    const h = open('create', 'UTC')
    await typeWindowEdge(
      'Starts',
      ['02', '01', '2031', '12', '04', '05'],
      h.detect,
    )
    h.act(() => el('hilos-status-work-close').click())
    h.act(() => el('modal-confirm-discard').click())
    expect(query('modal')).toBeNull()
    h.chooseZone('Asia/Tokyo')
    h.open('create')
    expect(el('modal').textContent).toContain('Window, Asia/Tokyo')
    for (const part of field('window').querySelectorAll('input'))
      expect(part.getAttribute('aria-valuenow')).toBeNull()
  })

  it('submits on Enter from another field while the narrow calendar sheet stays open', async () => {
    await withNarrowScreen(async () => {
      const h = open()
      await fillWork(h.detect)
      openCalendar(h)
      const title = field('title')
      // The narrow sheet lies in flow and stays open when focus leaves it.
      h.act(() => title.focus())
      expect(calendar()).not.toBeNull()
      const time = calendar()!.querySelector<HTMLElement>(
        'input[role="spinbutton"]',
      )!
      await press(time, 'Enter', h.detect)
      expect(h.sent).toEqual([])
      await press(title, 'Enter', h.detect)
      expect(h.sent.map((frame) => frame.action)).toEqual([
        'hilos_status_work_schedule',
      ])
    })
  })
})
