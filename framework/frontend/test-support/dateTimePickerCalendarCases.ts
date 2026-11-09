// Shared panel behavior; SDK entries provide their real view lifecycle only.
import {
  createDateTimePickerController,
  createSignal,
  subscribeSignal,
} from '@hilos/core/base'
import {
  part,
  withView,
  type DateTimePickerFieldInput,
  type DateTimePickerFieldTestRunner,
  type DateTimePickerFieldView,
  type MountDateTimePickerField,
} from './dateTimePickerFieldCases.js'

/** Find a named native control or ARIA role inside the supplied surface. */
function named<T extends HTMLElement = HTMLElement>(
  root: ParentNode,
  role: string,
  name: string,
): T {
  const element = Array.from(
    root.querySelectorAll<T>(`[role="${role}"], ${role}`),
  ).find((element) => element.getAttribute('aria-label') === name)
  if (!element) throw new Error(`Missing ${role}: ${name}`)
  return element
}

function panel(
  view: DateTimePickerFieldView,
  name = 'Choose date',
): HTMLElement {
  return named(view.root, 'dialog', name)
}

function opener(
  view: DateTimePickerFieldView,
  name = 'Open calendar',
): HTMLButtonElement {
  return named(view.root, 'button', name)
}

/** Click through the native DOM and settle the view's signal rendering. */
async function click(
  view: DateTimePickerFieldView,
  element: HTMLElement,
): Promise<void> {
  element.click()
  await view.flush()
}

function day(view: DateTimePickerFieldView, name: string): HTMLButtonElement {
  return named(panel(view), 'gridcell', name)
}

function panelPart(
  view: DateTimePickerFieldView,
  name: string,
  label = 'Choose date',
): HTMLInputElement {
  return named(panel(view, label), 'spinbutton', name)
}

function focusDay(view: DateTimePickerFieldView): HTMLElement {
  const target = panel(view).querySelector<HTMLElement>(
    '[role="gridcell"][tabindex="0"]',
  )
  if (!target) throw new Error('Missing active calendar day')
  return target
}

/** A real local gap or fold, resolved by the ordinary zone adapter. */
function warsawDraft(date: string): DateTimePickerFieldInput['picker'] {
  const picker = createDateTimePickerController({
    kind: 'datetime',
    count: 'single',
    value: null,
    timeZone: 'Europe/Warsaw',
    today: () => '2026-10-17',
  })
  picker.setDate('start', date)
  picker.setPartText('start', 'hour', '02')
  picker.setPartText('start', 'minute', '30')
  return picker
}

/** Register the common calendar and time panel contract on one real SDK. */
export function registerDateTimePickerCalendarCases(
  sdk: string,
  runner: DateTimePickerFieldTestRunner,
  mount: MountDateTimePickerField,
): void {
  const { describe, it, expect } = runner

  describe(`HilosDateTimePicker calendar on ${sdk}`, () => {
    for (const kind of ['date', 'datetime', 'time'] as const) {
      it(`names the ${kind} opener and dialog and returns focus on toggle`, async () => {
        const picker =
          kind === 'time'
            ? createDateTimePickerController({
                kind,
                count: 'single',
                value: null,
              })
            : kind === 'date'
              ? createDateTimePickerController({
                  kind,
                  count: 'single',
                  value: null,
                  today: () => '2026-10-17',
                })
              : createDateTimePickerController({
                  kind,
                  count: 'single',
                  value: null,
                  today: () => '2026-10-17',
                  timeZone: 'Europe/Warsaw',
                })
        await withView(mount, picker, async (view) => {
          const button = opener(
            view,
            kind === 'time' ? 'Choose time' : 'Open calendar',
          )
          expect(button.type).toBe('button')
          expect(button.getAttribute('aria-haspopup')).toBe('dialog')
          expect(button.getAttribute('aria-expanded')).toBe('false')
          expect(button.hasAttribute('aria-controls')).toBe(false)
          expect(button.querySelector('i')?.getAttribute('aria-hidden')).toBe(
            'true',
          )
          await click(view, button)
          const dialog = panel(
            view,
            kind === 'time' ? 'Choose time' : 'Choose date',
          )
          expect(button.getAttribute('aria-expanded')).toBe('true')
          expect(button.getAttribute('aria-controls')).toBe(dialog.id)
          expect(dialog.id).toBe('when-panel')
          expect(dialog.hasAttribute('aria-modal')).toBe(false)
          await click(view, button)
          expect(view.root.querySelector('[role="dialog"]')).toBeNull()
          expect(button.getAttribute('aria-expanded')).toBe('false')
          expect(button.hasAttribute('aria-controls')).toBe(false)
          expect(document.activeElement).toBe(button)
        })
      })
    }

    for (const value of ['2026-10-20', null]) {
      it(`focuses ${value === null ? 'today in an empty field' : 'the selected date'} on opening`, async () => {
        const picker = createDateTimePickerController({
          kind: 'date',
          count: 'single',
          value,
          today: () => '2026-10-17',
        })
        await withView(mount, picker, async (view) => {
          await click(view, opener(view))
          expect(
            panel(view).querySelectorAll('[role="gridcell"][tabindex="0"]'),
          ).toHaveLength(1)
          const target = focusDay(view)
          expect(document.activeElement).toBe(target)
          expect(
            target.getAttribute(
              value === null ? 'aria-current' : 'aria-selected',
            ),
          ).toBe(value === null ? 'date' : 'true')
          expect(target.getAttribute('aria-label')).toBe(
            value === null
              ? 'Saturday, 17 October 2026'
              : 'Tuesday, 20 October 2026',
          )
          const grid = panel(view).querySelector('[role="grid"]')!
          const title = document.getElementById(
            grid.getAttribute('aria-labelledby')!,
          )!
          expect(title.textContent?.trim()).toBe('October 2026')
          expect(title.getAttribute('aria-live')).toBe('polite')
          expect(
            panel(view)
              .querySelector('th[scope="col"][aria-label="Monday"]')
              ?.textContent?.trim(),
          ).toBe('Mon')
        })
      })
    }

    it('opens with Alt+Down and refocuses the panel without claiming browser shortcuts', async () => {
      const picker = createDateTimePickerController({
        kind: 'date',
        count: 'single',
        value: '2026-10-17',
        today: () => '2026-10-17',
      })
      await withView(mount, picker, async (view) => {
        const field = part(view, 'Day')
        await view.focus(field)
        expect(
          (await view.press(field, 'ArrowDown', { altKey: true }))
            .defaultPrevented,
        ).toBe(true)
        expect(document.activeElement).toBe(focusDay(view))
        await view.focus(field)
        expect(
          (await view.press(field, 'ArrowDown', { altKey: true }))
            .defaultPrevented,
        ).toBe(true)
        expect(document.activeElement).toBe(focusDay(view))
        await view.focus(field)
        for (const modifiers of [
          { ctrlKey: true },
          { altKey: true, ctrlKey: true },
          { altKey: true, metaKey: true },
        ]) {
          expect(
            (await view.press(field, 'ArrowDown', modifiers)).defaultPrevented,
          ).toBe(false)
          expect(document.activeElement).toBe(field)
        }
      })
    })

    it('changes only the date and publishes once per choice while datetime stays open', async () => {
      let changes = 0
      const picker = createDateTimePickerController({
        kind: 'datetime',
        count: 'single',
        value: Date.parse('2026-10-17T04:30:45.123Z'),
        timeZone: 'Europe/Warsaw',
        today: () => '2026-10-17',
        onChange: () => changes++,
      })
      await withView(mount, picker, async (view) => {
        await click(view, opener(view))
        await click(view, named(panel(view), 'button', 'Next month'))
        await click(view, named(panel(view), 'button', 'Previous month'))
        day(view, 'Tuesday, 20 October 2026').dispatchEvent(
          new Event('pointerover', { bubbles: true }),
        )
        await view.flush()
        expect(changes).toBe(0)
        await click(view, day(view, 'Tuesday, 20 October 2026'))
        expect(changes).toBe(1)
        expect(picker.state.get().result).toEqual({
          status: 'valid',
          value: Date.parse('2026-10-20T04:30:45.123Z'),
        })
        expect(picker.calendar.state.get().open).toBe(true)
        expect(
          panel(view)
            .querySelector('[aria-selected="true"]')
            ?.getAttribute('aria-label'),
        ).toBe('Tuesday, 20 October 2026')
      })
    })

    it('closes a single date after selection and returns focus to its opener', async () => {
      let changes = 0
      const picker = createDateTimePickerController({
        kind: 'date',
        count: 'single',
        value: null,
        today: () => '2026-10-17',
        onChange: () => changes++,
      })
      await withView(mount, picker, async (view) => {
        const button = opener(view)
        await click(view, button)
        await click(view, day(view, 'Tuesday, 20 October 2026'))
        expect(picker.state.get().result).toEqual({
          status: 'valid',
          value: '2026-10-20',
        })
        expect(changes).toBe(1)
        expect(view.root.querySelector('[role="dialog"]')).toBeNull()
        expect(document.activeElement).toBe(button)
      })
    })

    it('previews a range without publishing a value, restarts before its start and completes the new pair', async () => {
      let changes = 0
      let publications = 0
      const picker = createDateTimePickerController({
        kind: 'date',
        count: 'range',
        value: null,
        today: () => '2026-10-17',
        onChange: () => changes++,
      })
      const stop = subscribeSignal(picker.state, () => publications++)
      try {
        await withView(mount, picker, async (view) => {
          await click(view, opener(view))
          const voice = panel(view).querySelector(
            '[aria-live="polite"]:not([id])',
          )!
          expect(voice.textContent?.trim()).toBe('Choose date: From')
          await click(view, day(view, 'Saturday, 17 October 2026'))
          expect(voice.textContent?.trim()).toBe('Choose date: To')
          expect(picker.state.get().result).toEqual({ status: 'unresolved' })
          const before = picker.state.get()
          const count = publications
          day(view, 'Tuesday, 20 October 2026').dispatchEvent(
            new Event('pointerover', { bubbles: true }),
          )
          await view.flush()
          expect(
            picker.calendar.state
              .get()
              .grid?.weeks.flat()
              .find((entry) => entry?.date === '2026-10-20')?.span,
          ).toBe('preview-end')
          expect(picker.state.get()).toBe(before)
          expect(publications).toBe(count)
          panel(view)
            .querySelector('[role="grid"]')!
            .dispatchEvent(new Event('pointerleave'))
          await view.flush()
          expect(
            picker.calendar.state
              .get()
              .grid?.weeks.flat()
              .find((entry) => entry?.date === '2026-10-20')?.span,
          ).toBe('none')
          await click(view, day(view, 'Tuesday, 13 October 2026'))
          expect(picker.state.get().result).toEqual({ status: 'unresolved' })
          expect(changes).toBe(2)
          await click(view, day(view, 'Tuesday, 20 October 2026'))
          expect(picker.state.get().result).toEqual({
            status: 'valid',
            value: ['2026-10-13', '2026-10-20'],
          })
          expect(changes).toBe(3)
          expect(picker.calendar.state.get().open).toBe(false)
          expect(document.activeElement).toBe(opener(view))
        })
      } finally {
        stop()
      }
    })

    it('exposes a prohibited day without disabling its keyboard focus', async () => {
      const picker = createDateTimePickerController({
        kind: 'date',
        count: 'single',
        value: '2026-10-13',
        minDate: '2026-10-13',
        today: () => '2026-10-17',
      })
      await withView(mount, picker, async (view) => {
        await click(view, opener(view))
        const prohibited = day(view, 'Monday, 12 October 2026')
        expect(prohibited.getAttribute('aria-disabled')).toBe('true')
        expect(prohibited.disabled).toBe(false)
        const before = picker.state.get()
        await click(view, prohibited)
        expect(picker.state.get()).toBe(before)
        expect(
          (await view.press(focusDay(view), 'ArrowLeft')).defaultPrevented,
        ).toBe(true)
        expect(document.activeElement).toBe(prohibited)
        expect(prohibited.tabIndex).toBe(0)
        expect((await view.press(prohibited, 'Enter')).defaultPrevented).toBe(
          true,
        )
        expect(picker.state.get()).toBe(before)
      })
    })

    it('keeps focus on month buttons and moves the roving day with calendar keys', async () => {
      const picker = createDateTimePickerController({
        kind: 'date',
        count: 'single',
        value: '2026-10-17',
        today: () => '2026-10-17',
      })
      await withView(mount, picker, async (view) => {
        await click(view, opener(view))
        const next = named<HTMLButtonElement>(
          panel(view),
          'button',
          'Next month',
        )
        await view.focus(next)
        await click(view, next)
        expect(document.activeElement).toBe(next)
        expect(document.getElementById('when-month')?.textContent?.trim()).toBe(
          'November 2026',
        )
        const previous = named<HTMLButtonElement>(
          panel(view),
          'button',
          'Previous month',
        )
        await view.focus(previous)
        await click(view, previous)
        expect(document.activeElement).toBe(previous)
        expect(document.getElementById('when-month')?.textContent?.trim()).toBe(
          'October 2026',
        )
        await view.focus(focusDay(view))
        for (const [key, shiftKey, expected] of [
          ['ArrowRight', false, 'Sunday, 18 October 2026'],
          ['ArrowDown', false, 'Sunday, 25 October 2026'],
          ['PageDown', false, 'Wednesday, 25 November 2026'],
          ['PageDown', true, 'Thursday, 25 November 2027'],
          ['Home', false, 'Monday, 22 November 2027'],
          ['End', false, 'Sunday, 28 November 2027'],
        ] as const) {
          expect(
            (await view.press(focusDay(view), key, { shiftKey }))
              .defaultPrevented,
          ).toBe(true)
          expect(document.activeElement).toBe(focusDay(view))
          expect(focusDay(view).getAttribute('aria-label')).toBe(expected)
        }
        expect(picker.calendar.state.get().grid?.focusDate).toBe('2027-11-28')
        const before = picker.calendar.state.get()
        for (const [key, modifiers] of [
          ['Tab', {}],
          ['ArrowRight', { ctrlKey: true }],
          ['ArrowRight', { metaKey: true }],
          ['ArrowRight', { altKey: true }],
        ] as const) {
          expect(
            (await view.press(focusDay(view), key, modifiers)).defaultPrevented,
          ).toBe(false)
          expect(picker.calendar.state.get()).toBe(before)
        }
        expect(picker.state.get().result).toEqual({
          status: 'valid',
          value: '2026-10-17',
        })
      })
    })

    for (const key of ['Enter', ' ']) {
      it(`selects a day once with ${key === ' ' ? 'Space' : key}`, async () => {
        let changes = 0
        const picker = createDateTimePickerController({
          kind: 'datetime',
          count: 'single',
          value: Date.parse('2026-10-17T04:30:00Z'),
          timeZone: 'Europe/Warsaw',
          today: () => '2026-10-17',
          onChange: () => changes++,
        })
        await withView(mount, picker, async (view) => {
          await click(view, opener(view))
          await view.press(focusDay(view), 'ArrowRight')
          expect((await view.press(focusDay(view), key)).defaultPrevented).toBe(
            true,
          )
          const release = new KeyboardEvent('keyup', {
            key,
            bubbles: true,
            cancelable: true,
          })
          focusDay(view).dispatchEvent(release)
          await view.flush()
          expect(release.defaultPrevented).toBe(key === ' ')
          expect(changes).toBe(1)
          expect(picker.state.get().result).toEqual({
            status: 'valid',
            value: Date.parse('2026-10-18T04:30:00Z'),
          })
        })
      })
    }

    it('steps and types time parts within the panel and cancels Enter', async () => {
      const picker = createDateTimePickerController({
        kind: 'time',
        count: 'single',
        value: '06:30:45',
        showSeconds: true,
      })
      await withView(mount, picker, async (view) => {
        await click(view, opener(view, 'Choose time'))
        const dialog = panel(view, 'Choose time')
        const increase = named<HTMLButtonElement>(
          dialog,
          'button',
          'Increase Minutes',
        )
        expect(increase.tabIndex).toBe(-1)
        expect(increase.querySelector('i')?.getAttribute('aria-hidden')).toBe(
          'true',
        )
        await click(view, increase)
        const minutes = panelPart(view, 'Minutes', 'Choose time')
        expect(document.activeElement).toBe(minutes)
        expect(minutes.value).toBe('31')
        const decrease = named<HTMLButtonElement>(
          dialog,
          'button',
          'Decrease Minutes',
        )
        expect(decrease.tabIndex).toBe(-1)
        await click(view, decrease)
        expect(minutes.value).toBe('30')
        expect(document.activeElement).toBe(minutes)
        await view.press(minutes, 'ArrowLeft')
        const hours = panelPart(view, 'Hours', 'Choose time')
        expect(document.activeElement).toBe(hours)
        await view.press(hours, '1')
        await view.press(hours, '2')
        expect(document.activeElement).toBe(minutes)
        await view.press(minutes, 'ArrowRight')
        const seconds = panelPart(view, 'Seconds', 'Choose time')
        expect(document.activeElement).toBe(seconds)
        const before = picker.state.get()
        expect((await view.press(seconds, 'Enter')).defaultPrevented).toBe(true)
        expect(picker.state.get()).toBe(before)
        expect(picker.state.get().result).toEqual({
          status: 'valid',
          value: '12:30:45',
        })
        const nativeInput = new InputEvent('beforeinput', {
          bubbles: true,
          cancelable: true,
          inputType: 'insertText',
          data: '9',
        })
        seconds.dispatchEvent(nativeInput)
        expect(nativeInput.defaultPrevented).toBe(true)
        expect(seconds.getAttribute('aria-valuemin')).toBe('0')
        expect(seconds.getAttribute('aria-valuemax')).toBe('59')
        expect(seconds.getAttribute('aria-valuenow')).toBe('45')
      })
    })

    it('renders a time-only panel without a month and focuses hours', async () => {
      const picker = createDateTimePickerController({
        kind: 'time',
        count: 'range',
        value: ['06:30:00', '07:30:00'],
      })
      await withView(mount, picker, async (view) => {
        await click(view, opener(view, 'Choose time'))
        const dialog = panel(view, 'Choose time')
        expect(dialog.querySelector('[role="grid"]')).toBeNull()
        expect(
          dialog.querySelector('button[aria-label="Previous month"]'),
        ).toBeNull()
        expect(document.activeElement).toBe(
          panelPart(view, 'From Hours', 'Choose time'),
        )
        expect(panelPart(view, 'To Hours', 'Choose time').value).toBe('07')
        expect(dialog.querySelectorAll('[role="spinbutton"]')).toHaveLength(4)
        expect(dialog.textContent).toContain('From')
        expect(dialog.textContent).toContain('To')
      })
    })

    it('keeps both panel endpoints linked to their reason and current parent descriptions', async () => {
      const picker = createDateTimePickerController({
        kind: 'time',
        count: 'range',
        value: ['06:30:00', '07:30:00'],
      })
      await withView(
        mount,
        picker,
        async (view) => {
          await click(view, opener(view, 'Choose time'))
          for (const describedBy of [
            'window-help',
            'updated-help policy',
            undefined,
          ]) {
            await view.update({ describedBy })
            await view.flush()
            for (const [name, reason] of [
              ['From Hours', 'when-start-reason'],
              ['From Minutes', 'when-start-reason'],
              ['To Hours', 'when-end-reason'],
              ['To Minutes', 'when-end-reason'],
            ] as const) {
              expect(
                panelPart(view, name, 'Choose time').getAttribute(
                  'aria-describedby',
                ),
              ).toBe(
                describedBy === undefined ? reason : `${reason} ${describedBy}`,
              )
            }
          }
        },
        { describedBy: 'window-help' },
      )
    })

    it('renders time for a single datetime and no time parts for a date', async () => {
      const datetime = createDateTimePickerController({
        kind: 'datetime',
        count: 'single',
        value: Date.parse('2026-10-17T04:30:00Z'),
        timeZone: 'Europe/Warsaw',
        today: () => '2026-10-17',
      })
      await withView(mount, datetime, async (view) => {
        await click(view, opener(view))
        expect(panel(view).textContent).toContain('Time')
        expect(
          panel(view).querySelectorAll('[role="spinbutton"]'),
        ).toHaveLength(2)
        expect(
          panel(view).querySelector('[aria-live="polite"]:not([id])'),
        ).toBeNull()
      })
      const date = createDateTimePickerController({
        kind: 'date',
        count: 'single',
        value: null,
        today: () => '2026-10-17',
      })
      await withView(mount, date, async (view) => {
        await click(view, opener(view))
        expect(
          panel(view).querySelectorAll('[role="spinbutton"]'),
        ).toHaveLength(0)
      })
    })

    it('adds the ending datetime row only once its date exists', async () => {
      const picker = createDateTimePickerController({
        kind: 'datetime',
        count: 'range',
        value: null,
        timeZone: 'Europe/Warsaw',
        today: () => '2026-10-17',
      })
      await withView(mount, picker, async (view) => {
        await click(view, opener(view))
        expect(panelPart(view, 'From Hours').value).toBe('--')
        expect(
          panel(view).querySelector(
            '[role="spinbutton"][aria-label="To Hours"]',
          ),
        ).toBeNull()
        await click(view, day(view, 'Saturday, 17 October 2026'))
        expect(
          panel(view).querySelector(
            '[role="spinbutton"][aria-label="To Hours"]',
          ),
        ).toBeNull()
        await click(view, day(view, 'Tuesday, 20 October 2026'))
        expect(panelPart(view, 'To Hours').value).toBe('--')
        expect(
          panel(view).querySelectorAll('[role="spinbutton"]'),
        ).toHaveLength(4)
        expect(picker.calendar.state.get().open).toBe(true)
      })
    })

    it('explains a gap in the panel and steps to either neighboring valid hour', async () => {
      const picker = warsawDraft('2026-03-29')
      await withView(mount, picker, async (view) => {
        await click(view, opener(view))
        const reason = picker.state.get().start.reason
        expect(reason).toContain('does not exist')
        expect(panel(view).textContent).toContain(reason)
        for (const input of panel(view).querySelectorAll(
          '[role="spinbutton"]',
        )) {
          expect(input.getAttribute('aria-invalid')).toBe('true')
        }
        const hours = panelPart(view, 'Hours')
        const live = document.getElementById(
          hours.getAttribute('aria-describedby')!,
        )!
        expect(live.getAttribute('aria-live')).toBe('polite')
        expect(live.textContent).toBe(reason)
        await click(view, named(panel(view), 'button', 'Decrease Hours'))
        expect(hours.value).toBe('01')
        expect(picker.state.get().result).toEqual({
          status: 'valid',
          value: Date.parse('2026-03-29T00:30:00Z'),
        })
        for (const input of panel(view).querySelectorAll(
          '[role="spinbutton"]',
        )) {
          expect(input.hasAttribute('aria-invalid')).toBe(false)
        }
        picker.setPartText('start', 'hour', '02')
        await view.flush()
        await click(view, named(panel(view), 'button', 'Increase Hours'))
        expect(hours.value).toBe('03')
        expect(picker.state.get().result).toEqual({
          status: 'valid',
          value: Date.parse('2026-03-29T01:30:00Z'),
        })
      })
    })

    it('names fold choices and resolves the selected exact occurrence', async () => {
      const picker = warsawDraft('2026-10-25')
      await withView(mount, picker, async (view) => {
        await click(view, opener(view))
        expect(picker.state.get().start.result.status).toBe('ambiguous')
        for (const input of panel(view).querySelectorAll(
          '[role="spinbutton"]',
        )) {
          expect(input.hasAttribute('aria-invalid')).toBe(false)
        }
        const group = named(panel(view), 'radiogroup', 'Choose UTC offset')
        const radios = Array.from(
          group.querySelectorAll<HTMLInputElement>('input[type="radio"]'),
        )
        expect(radios).toHaveLength(2)
        expect(
          radios.map((radio) =>
            document
              .querySelector(`label[for="${radio.id}"]`)
              ?.textContent?.trim(),
          ),
        ).toEqual(['First · UTC+02:00', 'Second · UTC+01:00'])
        expect(radios.map((radio) => radio.checked)).toEqual([false, false])
        await click(view, radios[1]!)
        expect(radios[1]!.checked).toBe(true)
        expect(picker.state.get().result).toEqual({
          status: 'valid',
          value: Date.parse('2026-10-25T01:30:00Z'),
        })
        expect(picker.calendar.state.get().open).toBe(true)
      })
    })

    it('captures Escape only while open and returns focus without closing an outer surface', async () => {
      const picker = createDateTimePickerController({
        kind: 'date',
        count: 'single',
        value: null,
        today: () => '2026-10-17',
      })
      let escapes = 0
      const onEscape = (event: KeyboardEvent) => {
        if (event.key === 'Escape') escapes++
      }
      document.body.addEventListener('keydown', onEscape)
      try {
        await withView(mount, picker, async (view) => {
          await click(view, opener(view))
          expect(
            (await view.press(focusDay(view), 'Escape')).defaultPrevented,
          ).toBe(true)
          expect(escapes).toBe(0)
          expect(view.root.querySelector('[role="dialog"]')).toBeNull()
          expect(document.activeElement).toBe(opener(view))
          expect(
            (await view.press(part(view, 'Day'), 'Escape')).defaultPrevented,
          ).toBe(false)
          expect(escapes).toBe(1)
        })
      } finally {
        document.body.removeEventListener('keydown', onEscape)
      }
    })

    it('leaves the reader focus alone on an initially closed mount', async () => {
      const outside = document.body.appendChild(
        document.createElement('button'),
      )
      const picker = createDateTimePickerController({
        kind: 'time',
        count: 'single',
        value: '06:30:00',
      })
      try {
        outside.focus()
        await withView(mount, picker, async (view) => {
          expect(picker.calendar.state.get().open).toBe(false)
          expect(view.root.querySelector('[role="dialog"]')).toBeNull()
          expect(document.activeElement).toBe(outside)
        })
      } finally {
        outside.remove()
      }
    })

    for (const type of ['pointerdown', 'focusin']) {
      it(`dismisses an initially open panel on outside ${type} without returning focus`, async () => {
        const outside = document.body.appendChild(
          document.createElement('button'),
        )
        const picker = createDateTimePickerController({
          kind: 'date',
          count: 'single',
          value: '2026-10-17',
          today: () => '2026-10-17',
        })
        picker.calendar.open()
        try {
          await withView(mount, picker, async (view) => {
            expect(document.activeElement).toBe(focusDay(view))
            if (type === 'pointerdown') {
              outside.dispatchEvent(new Event('pointerdown', { bubbles: true }))
            } else {
              outside.focus()
              outside.dispatchEvent(
                new FocusEvent('focusin', { bubbles: true }),
              )
            }
            await view.flush()
            expect(picker.calendar.state.get().open).toBe(false)
            expect(view.root.querySelector('[role="dialog"]')).toBeNull()
            expect(document.activeElement).not.toBe(opener(view))
            if (type === 'focusin') expect(document.activeElement).toBe(outside)
          })
        } finally {
          outside.remove()
        }
      })

      it(`closes on an outside ${type} without moving the reader's focus`, async () => {
        const outside = document.body.appendChild(
          document.createElement('button'),
        )
        const picker = createDateTimePickerController({
          kind: 'date',
          count: 'single',
          value: null,
          today: () => '2026-10-17',
        })
        try {
          await withView(mount, picker, async (view) => {
            await click(view, opener(view))
            const previousFocus = document.activeElement
            if (type === 'pointerdown') {
              document.body.dispatchEvent(
                new Event('pointerdown', { bubbles: true }),
              )
              expect(document.activeElement).toBe(previousFocus)
            } else {
              outside.focus()
              outside.dispatchEvent(
                new FocusEvent('focusin', { bubbles: true }),
              )
            }
            await view.flush()
            expect(picker.calendar.state.get().open).toBe(false)
            expect(document.activeElement).not.toBe(opener(view))
            if (type === 'focusin') expect(document.activeElement).toBe(outside)
          })
        } finally {
          outside.remove()
        }
      })
    }

    it('keeps the panel open while typing in the field and changes selection without moving its month', async () => {
      const picker = createDateTimePickerController({
        kind: 'datetime',
        count: 'single',
        value: Date.parse('2026-10-17T04:30:00Z'),
        timeZone: 'Europe/Warsaw',
        today: () => '2026-10-17',
      })
      await withView(mount, picker, async (view) => {
        await click(view, opener(view))
        const field = part(view, 'Day')
        field.dispatchEvent(new Event('pointerdown', { bubbles: true }))
        await view.focus(field)
        await view.press(field, '2')
        await view.press(field, '0')
        expect(picker.calendar.state.get().open).toBe(true)
        expect(
          day(view, 'Tuesday, 20 October 2026').getAttribute('aria-selected'),
        ).toBe('true')
        expect(document.getElementById('when-month')?.textContent?.trim()).toBe(
          'October 2026',
        )
        const month = part(view, 'Month')
        await view.focus(month)
        await view.press(month, '1')
        await view.press(month, '1')
        expect(picker.state.get().result).toEqual({
          status: 'valid',
          value: Date.parse('2026-11-20T05:30:00Z'),
        })
        expect(document.getElementById('when-month')?.textContent?.trim()).toBe(
          'October 2026',
        )
        expect(panel(view).querySelector('[aria-selected="true"]')).toBeNull()
      })
    })

    for (const lock of ['busy', 'disabled'] as const) {
      it(`focuses an initially open ${lock} time panel so Escape stays inside`, async () => {
        const outside = document.body.appendChild(
          document.createElement('button'),
        )
        const locked = createSignal(false)
        let changes = 0
        const picker = createDateTimePickerController({
          kind: 'time',
          count: 'single',
          value: '06:30:00',
          [lock]: () => locked.get(),
          onChange: () => changes++,
        })
        picker.calendar.open()
        locked.set(true)
        const before = picker.state.get()
        let escapes = 0
        const onEscape = (event: KeyboardEvent) => {
          if (event.key === 'Escape') escapes++
        }
        document.body.addEventListener('keydown', onEscape)
        try {
          outside.focus()
          await withView(mount, picker, async (view) => {
            const dialog = panel(view, 'Choose time')
            expect(picker.calendar.state.get().open).toBe(true)
            expect(panelPart(view, 'Hours', 'Choose time').disabled).toBe(true)
            expect(document.activeElement).toBe(dialog)
            expect((await view.press(dialog, 'Escape')).defaultPrevented).toBe(
              true,
            )
            expect(picker.calendar.state.get().open).toBe(false)
            expect(view.root.querySelector('[role="dialog"]')).toBeNull()
            expect(escapes).toBe(0)
            expect(picker.state.get()).toBe(before)
            expect(changes).toBe(0)
          })
        } finally {
          outside.remove()
          document.body.removeEventListener('keydown', onEscape)
        }
      })

      it(`keeps Escape inside the picker when a focused time part becomes ${lock}`, async () => {
        const locked = createSignal(false)
        const picker = createDateTimePickerController({
          kind: 'time',
          count: 'single',
          value: '06:30:00',
          [lock]: () => locked.get(),
        })
        let escapes = 0
        const onEscape = (event: KeyboardEvent) => {
          if (event.key === 'Escape') escapes++
        }
        document.body.addEventListener('keydown', onEscape)
        try {
          await withView(mount, picker, async (view) => {
            for (const location of ['panel', 'field']) {
              await click(view, opener(view, 'Choose time'))
              const hours =
                location === 'panel'
                  ? panelPart(view, 'Hours', 'Choose time')
                  : part(view, 'Hours')
              await view.focus(hours)
              expect(document.activeElement).toBe(hours)
              const before = picker.state.get()
              locked.set(true)
              await view.flush()
              const active = document.activeElement as HTMLElement
              expect(panel(view, 'Choose time').contains(active)).toBe(true)
              expect(active.matches(':disabled')).toBe(false)
              expect(
                (await view.press(active, 'Escape')).defaultPrevented,
              ).toBe(true)
              expect(view.root.querySelector('[role="dialog"]')).toBeNull()
              expect(escapes).toBe(0)
              expect(picker.state.get()).toBe(before)
              locked.set(false)
              await view.flush()
            }
          })
        } finally {
          document.body.removeEventListener('keydown', onEscape)
        }
      })

      it(`keeps an open ${lock} panel readable and navigable while preventing selection`, async () => {
        const locked = createSignal(false)
        const picker = createDateTimePickerController({
          kind: 'datetime',
          count: 'single',
          value: Date.parse('2026-10-17T04:30:00Z'),
          timeZone: 'Europe/Warsaw',
          today: () => '2026-10-17',
          [lock]: () => locked.get(),
        })
        await withView(mount, picker, async (view) => {
          await click(view, opener(view))
          locked.set(true)
          await view.flush()
          expect(picker.calendar.state.get().open).toBe(true)
          expect(opener(view).disabled).toBe(true)
          expect(
            Array.from(
              panel(view).querySelectorAll<HTMLInputElement>(
                '[role="spinbutton"]',
              ),
            ).every((input) => input.disabled),
          ).toBe(true)
          expect(
            named<HTMLButtonElement>(panel(view), 'button', 'Increase Hours')
              .disabled,
          ).toBe(true)
          expect(
            named<HTMLButtonElement>(panel(view), 'button', 'Decrease Hours')
              .disabled,
          ).toBe(true)
          const target = day(view, 'Tuesday, 20 October 2026')
          expect(target.getAttribute('aria-disabled')).toBe('true')
          const before = picker.state.get()
          await click(view, target)
          expect(picker.state.get()).toBe(before)
          await click(view, named(panel(view), 'button', 'Next month'))
          expect(
            document.getElementById('when-month')?.textContent?.trim(),
          ).toBe('November 2026')
          expect(
            (await view.press(focusDay(view), 'Escape')).defaultPrevented,
          ).toBe(true)
          expect(view.root.querySelector('[role="dialog"]')).toBeNull()
        })
      })
    }

    it('hides the field tooltip while retaining its live reason when the panel is open', async () => {
      const picker = warsawDraft('2026-03-29')
      await withView(mount, picker, async (view) => {
        const field = part(view, 'Hours')
        await view.focus(field)
        expect(
          view.root.querySelector('div[aria-hidden="true"]')?.textContent,
        ).toContain('does not exist')
        await view.press(field, 'ArrowDown', { altKey: true })
        await view.focus(field)
        expect(view.root.querySelector('div[aria-hidden="true"]')).toBeNull()
        expect(
          document.getElementById('when-start-reason')?.textContent,
        ).toContain('does not exist')
        expect(
          document
            .getElementById('when-start-reason')
            ?.getAttribute('aria-live'),
        ).toBe('polite')
      })
    })

    for (const departure of ['close', 'replacement', 'unmount'] as const) {
      it(`ignores late panel part keys, blur and step clicks after ${departure}`, async () => {
        let originalChanges = 0
        let replacementChanges = 0
        const original = createDateTimePickerController({
          kind: 'time',
          count: 'single',
          value: '06:30:00',
          onChange: () => originalChanges++,
        })
        const replacement = createDateTimePickerController({
          kind: 'time',
          count: 'single',
          value: '12:45:00',
          onChange: () => replacementChanges++,
        })
        const outside = document.body.appendChild(
          document.createElement('button'),
        )
        const errors: unknown[] = []
        const onError = (event: ErrorEvent) => errors.push(event.error)
        window.addEventListener('error', onError)
        let view: DateTimePickerFieldView | undefined
        try {
          view = await mount({ picker: original, id: 'when', label: 'When' })
          await view.flush()
          await click(view, opener(view, 'Choose time'))
          const departed = panelPart(view, 'Hours', 'Choose time')
          const increase = named(
            panel(view, 'Choose time'),
            'button',
            'Increase Hours',
          )
          original.setPartText('start', 'hour', '6')
          await view.flush()
          if (departure === 'replacement') {
            await view.update({ picker: replacement })
          } else if (departure === 'unmount') {
            await view.unmount()
          } else {
            original.calendar.close()
          }
          await view.flush()
          outside.focus()
          const beforeOriginal = original.state.get()
          const beforeReplacement = replacement.state.get()
          const beforeOriginalChanges = originalChanges
          const beforeReplacementChanges = replacementChanges

          departed.dispatchEvent(new FocusEvent('blur'))
          await view.press(departed, 'ArrowUp')
          await view.press(departed, 'Delete')
          await view.press(departed, '2')
          await click(view, increase)
          expect(original.state.get()).toBe(beforeOriginal)
          expect(replacement.state.get()).toBe(beforeReplacement)
          expect(originalChanges).toBe(beforeOriginalChanges)
          expect(replacementChanges).toBe(beforeReplacementChanges)
          expect(document.activeElement).toBe(outside)
          expect(errors).toEqual([])
          if (departure === 'unmount') view = undefined
        } finally {
          if (departure !== 'unmount') await view?.unmount()
          original.dispose()
          replacement.dispose()
          outside.remove()
          window.removeEventListener('error', onError)
        }
      })
    }

    it('ignores a departed panel Escape after remounting the same controller', async () => {
      let changes = 0
      const picker = createDateTimePickerController({
        kind: 'time',
        count: 'single',
        value: '06:30:00',
        onChange: () => changes++,
      })
      let view: DateTimePickerFieldView | undefined
      try {
        view = await mount({ picker, id: 'when', label: 'When' })
        await view.flush()
        await click(view, opener(view, 'Choose time'))
        const departed = panelPart(view, 'Hours', 'Choose time')
        await view.unmount()
        view = undefined
        expect(picker.calendar.state.get().open).toBe(false)

        view = await mount({ picker, id: 'when', label: 'When' })
        await view.flush()
        await click(view, opener(view, 'Choose time'))
        const current = panelPart(view, 'Hours', 'Choose time')
        expect(document.activeElement).toBe(current)
        const before = picker.state.get()
        const calendarBefore = picker.calendar.state.get()
        const beforeChanges = changes

        const event = await view.press(departed, 'Escape')

        expect(picker.calendar.state.get()).toBe(calendarBefore)
        expect(picker.calendar.state.get().open).toBe(true)
        expect(panelPart(view, 'Hours', 'Choose time')).toBe(current)
        expect(picker.state.get()).toBe(before)
        expect(changes).toBe(beforeChanges)
        expect(document.activeElement).toBe(current)
        expect(event.defaultPrevented).toBe(false)
      } finally {
        await view?.unmount()
        picker.dispose()
      }
    })

    for (const gesture of ['opener click', 'field Alt+Down']) {
      it(`ignores a late ${gesture} after unmount`, async () => {
        let changes = 0
        const picker = createDateTimePickerController({
          kind: 'time',
          count: 'single',
          value: '06:30:00',
          onChange: () => changes++,
        })
        const outside = document.body.appendChild(
          document.createElement('button'),
        )
        let view: DateTimePickerFieldView | undefined
        try {
          view = await mount({ picker, id: 'when', label: 'When' })
          await view.flush()
          const button = opener(view, 'Choose time')
          const field = part(view, 'Hours')
          await view.unmount()
          view = undefined
          outside.focus()
          const before = picker.state.get()
          const calendarBefore = picker.calendar.state.get()
          const beforeChanges = changes

          if (gesture === 'opener click') button.click()
          else
            field.dispatchEvent(
              new KeyboardEvent('keydown', {
                key: 'ArrowDown',
                altKey: true,
                bubbles: true,
                cancelable: true,
              }),
            )

          expect(picker.calendar.state.get()).toBe(calendarBefore)
          expect(picker.calendar.state.get().open).toBe(false)
          expect(picker.state.get()).toBe(before)
          expect(changes).toBe(beforeChanges)
          expect(document.activeElement).toBe(outside)
        } finally {
          await view?.unmount()
          picker.dispose()
          outside.remove()
        }
      })
    }

    it('ignores an old panel day, navigation and hover after close and reopen', async () => {
      let changes = 0
      const picker = createDateTimePickerController({
        kind: 'date',
        count: 'range',
        value: null,
        today: () => '2026-10-17',
        onChange: () => changes++,
      })
      picker.setDate('start', '2026-10-17')
      await withView(mount, picker, async (view) => {
        await click(view, opener(view))
        const departed = day(view, 'Tuesday, 20 October 2026')
        const month = named(panel(view), 'button', 'Next month')
        const grid = panel(view).querySelector('[role="grid"]')!
        picker.calendar.close()
        await view.flush()
        await click(view, opener(view))
        const currentFocus = document.activeElement
        const before = picker.state.get()
        const calendarBefore = picker.calendar.state.get()
        const beforeChanges = changes

        departed.dispatchEvent(new Event('pointerover', { bubbles: true }))
        await view.flush()
        expect(picker.calendar.state.get()).toBe(calendarBefore)
        grid.dispatchEvent(new Event('pointerleave'))
        await click(view, month)
        await view.press(departed, 'ArrowRight')
        await click(view, departed)
        expect(picker.state.get()).toBe(before)
        expect(picker.calendar.state.get()).toBe(calendarBefore)
        expect(changes).toBe(beforeChanges)
        expect(document.activeElement).toBe(currentFocus)
      })
    })

    it('closes the old controller on replacement and unmount without stealing focus', async () => {
      const original = createDateTimePickerController({
        kind: 'date',
        count: 'single',
        value: null,
        today: () => '2026-10-17',
      })
      const replacement = createDateTimePickerController({
        kind: 'time',
        count: 'single',
        value: '06:30:00',
      })
      const outside = document.body.appendChild(
        document.createElement('button'),
      )
      let view: DateTimePickerFieldView | undefined
      try {
        view = await mount({ picker: original, id: 'when', label: 'When' })
        await view.flush()
        await click(view, opener(view))
        const readerFocus = document.activeElement
        replacement.calendar.open()
        expect(replacement.calendar.state.get().open).toBe(true)
        await view.update({ picker: replacement })
        await view.flush()
        expect(original.calendar.state.get().open).toBe(false)
        expect(replacement.calendar.state.get().open).toBe(false)
        expect(view.root.querySelector('[role="dialog"]')).toBeNull()
        expect(document.activeElement).not.toBe(opener(view, 'Choose time'))
        expect(
          document.activeElement === readerFocus ||
            document.activeElement === document.body,
        ).toBe(true)
        original.calendar.open()
        await view.flush()
        expect(view.root.querySelector('[role="dialog"]')).toBeNull()
        expect(replacement.calendar.state.get().open).toBe(false)
        original.calendar.close()
        await click(view, opener(view, 'Choose time'))
        expect(document.activeElement).toBe(
          panelPart(view, 'Hours', 'Choose time'),
        )
        expect(replacement.calendar.state.get().open).toBe(true)
        expect(
          (await view.press(panelPart(view, 'Hours', 'Choose time'), 'Escape'))
            .defaultPrevented,
        ).toBe(true)
        expect(replacement.calendar.state.get().open).toBe(false)
        expect(view.root.querySelector('[role="dialog"]')).toBeNull()
        expect(document.activeElement).toBe(opener(view, 'Choose time'))
        await click(view, opener(view, 'Choose time'))
        await view.unmount()
        view = undefined
        expect(replacement.calendar.state.get().open).toBe(false)

        view = await mount({ picker: replacement, id: 'when', label: 'When' })
        await view.flush()
        await click(view, opener(view, 'Choose time'))
        outside.focus()
        // Reopen without flushing so unmount itself must close the model.
        replacement.calendar.open()
        await view.unmount()
        view = undefined
        expect(replacement.calendar.state.get().open).toBe(false)
        expect(document.activeElement).toBe(outside)
      } finally {
        await view?.unmount()
        original.dispose()
        replacement.dispose()
        outside.remove()
      }
    })
  })
}
