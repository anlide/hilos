// Shared narrow-view and screen-keyboard behavior on real SDK components.
import { createDateTimePickerController, createSignal } from '@hilos/core/base'
import {
  part,
  withView,
  type DateTimePickerFieldTestRunner,
  type DateTimePickerFieldView,
  type MountDateTimePickerField,
} from './dateTimePickerFieldCases.js'
import {
  click,
  day,
  named,
  opener,
  panel,
} from './dateTimePickerCalendarCases.js'

/** Supply one changing media query and restore the environment after cleanup. */
async function withNarrowScreen(
  initial: boolean,
  steps: (screen: {
    set(value: boolean): void
    listenerCount(): number
  }) => Promise<void>,
): Promise<void> {
  const original = Object.getOwnPropertyDescriptor(globalThis, 'matchMedia')
  let matches = initial
  const listeners = new Set<(event: { matches: boolean }) => void>()
  const media = {
    get matches() {
      return matches
    },
    addEventListener(
      _type: string,
      listener: (event: { matches: boolean }) => void,
    ) {
      listeners.add(listener)
    },
    removeEventListener(
      _type: string,
      listener: (event: { matches: boolean }) => void,
    ) {
      listeners.delete(listener)
    },
  }
  Object.defineProperty(globalThis, 'matchMedia', {
    configurable: true,
    value: () => media,
  })
  try {
    await steps({
      set(value) {
        matches = value
        for (const listener of listeners) listener({ matches })
      },
      listenerCount: () => listeners.size,
    })
  } finally {
    if (original) Object.defineProperty(globalThis, 'matchMedia', original)
    else Reflect.deleteProperty(globalThis, 'matchMedia')
  }
}

/** Dispatch the press separately so cases can check the native focus decision. */
function mousedown(target: HTMLElement): MouseEvent {
  const event = new MouseEvent('mousedown', { bubbles: true, cancelable: true })
  target.dispatchEvent(event)
  return event
}

/** Find a button named by its own visible text rather than an aria-label. */
function textButton(root: ParentNode, text: string): HTMLButtonElement {
  const button = Array.from(
    root.querySelectorAll<HTMLButtonElement>('button'),
  ).find((button) => button.textContent?.trim() === text)
  if (!button) throw new Error(`Missing button: ${text}`)
  return button
}

function edgeButtons(dialog: HTMLElement): HTMLButtonElement[] {
  return Array.from(
    dialog.querySelectorAll<HTMLButtonElement>('button[aria-pressed]'),
  )
}

/** Send a cancelable browser editing intent, then settle controller rendering. */
async function beforeinput(
  view: DateTimePickerFieldView,
  input: HTMLInputElement,
  inputType: string,
  data: string | null = null,
): Promise<InputEvent> {
  const event = new InputEvent('beforeinput', {
    bubbles: true,
    cancelable: true,
    inputType,
    data,
  })
  input.dispatchEvent(event)
  await view.flush()
  return event
}

/** Register common narrow-screen behavior without prescribing styling. */
export function registerDateTimePickerNarrowCases(
  sdk: string,
  runner: DateTimePickerFieldTestRunner,
  mount: MountDateTimePickerField,
): void {
  const { describe, it, expect } = runner

  describe(`HilosDateTimePicker narrow view on ${sdk}`, () => {
    for (const value of ['2026-10-20', null]) {
      it(`opens an idle field without focusing a part and focuses ${value === null ? 'today' : 'the selection'}`, async () => {
        await withNarrowScreen(true, async () => {
          const picker = createDateTimePickerController({
            kind: 'date',
            count: 'single',
            value,
            today: () => '2026-10-17',
          })
          await withView(mount, picker, async (view) => {
            const input = part(view, 'Day')
            expect(mousedown(input).defaultPrevented).toBe(true)
            expect(document.activeElement).not.toBe(input)
            await click(view, input)
            expect(picker.calendar.state.get().open).toBe(true)
            expect(document.activeElement).toBe(
              day(
                view,
                value === null
                  ? 'Saturday, 17 October 2026'
                  : 'Tuesday, 20 October 2026',
              ),
            )
          })
        })
      })
    }

    it('closes into the pressed part and keeps ordinary part navigation while typing', async () => {
      await withNarrowScreen(true, async () => {
        const picker = createDateTimePickerController({
          kind: 'date',
          count: 'single',
          value: '2026-10-17',
          today: () => '2026-10-17',
        })
        await withView(mount, picker, async (view) => {
          await click(view, opener(view))
          const input = part(view, 'Day')
          expect(mousedown(input).defaultPrevented).toBe(false)
          await view.focus(input)
          await click(view, input)
          expect(picker.calendar.state.get().open).toBe(false)
          expect(document.activeElement).toBe(input)
          const month = part(view, 'Month')
          expect(mousedown(month).defaultPrevented).toBe(false)
          await view.focus(month)
          await click(view, month)
          expect(document.activeElement).toBe(month)
          expect(view.root.querySelector('[role="dialog"]')).toBeNull()
        })
      })
    })

    it('returns focus to the opener when blank field space closes the panel', async () => {
      await withNarrowScreen(true, async () => {
        const picker = createDateTimePickerController({
          kind: 'date',
          count: 'single',
          value: null,
          today: () => '2026-10-17',
        })
        await withView(mount, picker, async (view) => {
          await click(view, opener(view))
          const field = document.getElementById('when')!
          expect(mousedown(field).defaultPrevented).toBe(false)
          await click(view, field)
          expect(view.root.querySelector('[role="dialog"]')).toBeNull()
          expect(document.activeElement).toBe(opener(view))
        })
      })
    })

    for (const openReplacement of [false, true]) {
      it(`ignores an unfinished field press after replacement when the new panel is ${openReplacement ? 'open' : 'closed'}`, async () => {
        await withNarrowScreen(true, async () => {
          let changes = 0
          const original = createDateTimePickerController({
            kind: 'datetime',
            count: 'single',
            value: null,
            timeZone: 'Europe/Warsaw',
            today: () => '2026-10-17',
            onChange: () => changes++,
          })
          const replacement = createDateTimePickerController({
            kind: 'datetime',
            count: 'single',
            value: null,
            timeZone: 'UTC',
            today: () => '2026-10-17',
            onChange: () => changes++,
          })
          original.setDate('start', '2026-10-20')
          original.setPartText('start', 'hour', '2')
          replacement.setDate('start', '2026-10-17')
          replacement.setPartText('start', 'hour', '1')
          const outside = document.body.appendChild(
            document.createElement('button'),
          )
          try {
            await withView(mount, original, async (view) => {
              const field = document.getElementById('when')!
              await view.focus(outside)
              expect(original.calendar.state.get().open).toBe(false)
              expect(mousedown(field).defaultPrevented).toBe(true)
              const originalBefore = original.state.get()
              const replacementBefore = replacement.state.get()
              const changesBefore = changes

              await view.update({ picker: replacement })
              await view.flush()
              expect(document.getElementById('when')).toBe(field)
              expect(replacement.calendar.state.get().open).toBe(false)
              expect(document.activeElement).toBe(outside)
              if (openReplacement) {
                replacement.calendar.open()
                await view.flush()
                await view.focus(outside)
              }
              const calendarBefore = replacement.calendar.state.get()

              await click(view, field)
              expect(replacement.calendar.state.get()).toBe(calendarBefore)
              expect(replacement.calendar.state.get().open).toBe(
                openReplacement,
              )
              expect(original.calendar.state.get().open).toBe(false)
              expect(original.state.get()).toBe(originalBefore)
              expect(replacement.state.get()).toBe(replacementBefore)
              expect(changes).toBe(changesBefore)
              expect(document.activeElement).toBe(outside)

              expect(mousedown(field).defaultPrevented).toBe(!openReplacement)
              await click(view, field)
              expect(replacement.calendar.state.get().open).toBe(
                !openReplacement,
              )
              expect(original.state.get()).toBe(originalBefore)
              expect(replacement.state.get()).toBe(replacementBefore)
              expect(changes).toBe(changesBefore)
            })
          } finally {
            replacement.dispose()
            outside.remove()
          }
        })
      })
    }

    it('leaves a wide field press uncanceled and does not open its panel', async () => {
      await withNarrowScreen(false, async () => {
        const picker = createDateTimePickerController({
          kind: 'date',
          count: 'single',
          value: null,
          today: () => '2026-10-17',
        })
        await withView(mount, picker, async (view) => {
          const input = part(view, 'Day')
          expect(mousedown(input).defaultPrevented).toBe(false)
          await view.focus(input)
          await click(view, input)
          expect(document.activeElement).toBe(input)
          expect(view.root.querySelector('[role="dialog"]')).toBeNull()
        })
      })
    })

    it('names both range edges and applies the next day to the chosen start', async () => {
      await withNarrowScreen(true, async () => {
        const picker = createDateTimePickerController({
          kind: 'date',
          count: 'range',
          value: ['2026-10-17', '2026-10-20'],
          today: () => '2026-10-17',
        })
        await withView(mount, picker, async (view) => {
          await click(view, opener(view))
          const buttons = edgeButtons(panel(view))
          expect(buttons).toHaveLength(2)
          expect(buttons.map((button) => button.type)).toEqual([
            'button',
            'button',
          ])
          expect(
            buttons.map((button) => button.textContent?.replace(/\s+/g, '')),
          ).toEqual(['From17/10/2026', 'To20/10/2026'])
          await view.focus(buttons[1]!)
          await click(view, buttons[1]!)
          expect(
            buttons.map((button) => button.getAttribute('aria-pressed')),
          ).toEqual(['false', 'true'])
          expect(picker.calendar.state.get().activeEdge).toBe('end')
          expect(document.activeElement).toBe(buttons[1])
          await view.focus(buttons[0]!)
          await click(view, buttons[0]!)
          expect(
            buttons.map((button) => button.getAttribute('aria-pressed')),
          ).toEqual(['true', 'false'])
          expect(picker.calendar.state.get().activeEdge).toBe('start')
          expect(document.activeElement).toBe(buttons[0])
          await click(view, day(view, 'Sunday, 18 October 2026'))
          expect(picker.state.get().result).toEqual({
            status: 'valid',
            value: ['2026-10-18', '2026-10-20'],
          })
        })
      })
    })

    for (const kind of ['date', 'datetime', 'time'] as const) {
      it(`offers no edge buttons on a ${kind} single panel`, async () => {
        await withNarrowScreen(true, async () => {
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
                    timeZone: 'UTC',
                    today: () => '2026-10-17',
                  })
          await withView(mount, picker, async (view) => {
            const label = kind === 'time' ? 'Choose time' : 'Choose date'
            await click(
              view,
              opener(view, kind === 'time' ? 'Choose time' : 'Open calendar'),
            )
            expect(edgeButtons(panel(view, label))).toHaveLength(0)
          })
        })
      })
    }

    it('shows only the active datetime edge even before its date is filled', async () => {
      await withNarrowScreen(true, async () => {
        const picker = createDateTimePickerController({
          kind: 'datetime',
          count: 'range',
          value: null,
          timeZone: 'UTC',
          today: () => '2026-10-17',
        })
        picker.setEndpointValue('start', Date.parse('2026-10-17T06:30:00Z'))
        await withView(mount, picker, async (view) => {
          await click(view, opener(view))
          const dialog = panel(view)
          expect(picker.calendar.state.get().activeEdge).toBe('end')
          expect(
            named<HTMLInputElement>(dialog, 'spinbutton', 'To Hours').value,
          ).toBe('--')
          expect(dialog.querySelector('[aria-label="From Hours"]')).toBeNull()
          const buttons = edgeButtons(dialog)
          expect(buttons[0]?.textContent?.replace(/\s+/g, '')).toBe(
            'From17/10/2026,06:30',
          )
          expect(buttons[1]?.textContent?.replace(/\s+/g, '')).toBe(
            'Todd/mm/yyyy,--:--',
          )
          await click(view, buttons[0]!)
          expect(
            named<HTMLInputElement>(dialog, 'spinbutton', 'From Hours').value,
          ).toBe('06')
          expect(dialog.querySelector('[aria-label="To Hours"]')).toBeNull()
          expect(dialog.querySelectorAll('[role="spinbutton"]')).toHaveLength(2)
          await click(view, buttons[1]!)
          expect(
            named<HTMLInputElement>(dialog, 'spinbutton', 'To Hours').value,
          ).toBe('--')
          expect(dialog.querySelector('[aria-label="From Hours"]')).toBeNull()
        })
      })
    })

    it('keeps both time-only range rows without edge buttons or a calendar grid', async () => {
      await withNarrowScreen(true, async () => {
        const picker = createDateTimePickerController({
          kind: 'time',
          count: 'range',
          value: ['06:30:00', '07:45:00'],
        })
        await withView(mount, picker, async (view) => {
          await click(view, opener(view, 'Choose time'))
          const dialog = panel(view, 'Choose time')
          expect(dialog.querySelector('[role="grid"]')).toBeNull()
          expect(edgeButtons(dialog)).toHaveLength(0)
          expect(
            named<HTMLInputElement>(dialog, 'spinbutton', 'From Hours').value,
          ).toBe('06')
          expect(
            named<HTMLInputElement>(dialog, 'spinbutton', 'To Hours').value,
          ).toBe('07')
          expect(dialog.querySelectorAll('[role="spinbutton"]')).toHaveLength(4)
          expect(document.activeElement).toBe(dialog)
        })
      })
    })

    for (const lock of ['none', 'busy', 'disabled'] as const) {
      it(`closes with Done without changing the draft or saving while ${lock}`, async () => {
        await withNarrowScreen(true, async () => {
          const busy = createSignal(false)
          const disabled = createSignal(false)
          let changes = 0
          const picker = createDateTimePickerController({
            kind: 'datetime',
            count: 'range',
            value: [
              Date.parse('2026-10-17T06:30:00Z'),
              Date.parse('2026-10-20T07:45:00Z'),
            ],
            timeZone: 'UTC',
            today: () => '2026-10-17',
            busy: () => busy.get(),
            disabled: () => disabled.get(),
            onChange: () => changes++,
          })
          await withView(mount, picker, async (view) => {
            await click(view, opener(view))
            if (lock === 'busy') busy.set(true)
            if (lock === 'disabled') disabled.set(true)
            await view.flush()
            const before = picker.state.get()
            const dialog = panel(view)
            if (lock !== 'none') {
              expect(
                named<HTMLInputElement>(dialog, 'spinbutton', 'From Hours')
                  .disabled,
              ).toBe(true)
              expect(
                named<HTMLButtonElement>(
                  dialog,
                  'button',
                  'Increase From Hours',
                ).disabled,
              ).toBe(true)
              expect(
                day(view, 'Sunday, 18 October 2026').getAttribute(
                  'aria-disabled',
                ),
              ).toBe('true')
              await click(view, day(view, 'Sunday, 18 October 2026'))
              await beforeinput(
                view,
                part(view, 'From Hours'),
                'insertText',
                '9',
              )
              expect(picker.state.get()).toBe(before)
            }
            const end = edgeButtons(dialog)[1]!
            expect(end.disabled).toBe(false)
            await click(view, end)
            expect(picker.calendar.state.get().activeEdge).toBe('end')
            const done = textButton(dialog, 'Done')
            expect(done.type).toBe('button')
            expect(done.disabled).toBe(false)
            await click(view, done)
            expect(view.root.querySelector('[role="dialog"]')).toBeNull()
            expect(picker.state.get()).toBe(before)
            expect(changes).toBe(0)
            // A disabled opener cannot receive native focus.
            if (lock === 'none')
              expect(document.activeElement).toBe(opener(view))
          })
        })
      })
    }

    it('offers Done for a date and omits it in the wide view', async () => {
      await withNarrowScreen(true, async (screen) => {
        const picker = createDateTimePickerController({
          kind: 'date',
          count: 'single',
          value: null,
          today: () => '2026-10-17',
        })
        await withView(mount, picker, async (view) => {
          await click(view, opener(view))
          await click(view, textButton(panel(view), 'Done'))
          expect(picker.state.get().result).toEqual({ status: 'empty' })
          expect(document.activeElement).toBe(opener(view))
          screen.set(false)
          await view.flush()
          await click(view, opener(view))
          expect(
            Array.from(panel(view).querySelectorAll('button')).some(
              (button) => button.textContent?.trim() === 'Done',
            ),
          ).toBe(false)
        })
      })
    })

    for (const type of ['pointerdown', 'focusin']) {
      it(`ignores outside ${type} while narrow and dismisses after widening`, async () => {
        await withNarrowScreen(true, async (screen) => {
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
              const interact = () => {
                if (type === 'focusin') outside.focus()
                outside.dispatchEvent(new Event(type, { bubbles: true }))
              }
              await click(view, opener(view))
              interact()
              await view.flush()
              expect(picker.calendar.state.get().open).toBe(true)
              screen.set(false)
              await view.flush()
              interact()
              await view.flush()
              expect(view.root.querySelector('[role="dialog"]')).toBeNull()
              expect(document.activeElement).not.toBe(opener(view))
              screen.set(true)
              await view.flush()
              await click(view, opener(view))
              interact()
              await view.flush()
              expect(picker.calendar.state.get().open).toBe(true)
            })
          } finally {
            outside.remove()
          }
        })
      })
    }

    it('scrolls the field label to the sheet start and focuses a time dialog without a keyboard', async () => {
      await withNarrowScreen(true, async () => {
        const picker = createDateTimePickerController({
          kind: 'time',
          count: 'single',
          value: '06:30:00',
        })
        await withView(mount, picker, async (view) => {
          const label = document.getElementById('when-label')!
          const scrolls: (ScrollIntoViewOptions | boolean | undefined)[] = []
          const original = Object.getOwnPropertyDescriptor(
            label,
            'scrollIntoView',
          )
          Object.defineProperty(label, 'scrollIntoView', {
            configurable: true,
            value: (options?: ScrollIntoViewOptions | boolean) =>
              scrolls.push(options),
          })
          try {
            await click(view, opener(view, 'Choose time'))
            expect(scrolls).toEqual([{ block: 'start' }])
            expect(document.activeElement).toBe(panel(view, 'Choose time'))
          } finally {
            if (original)
              Object.defineProperty(label, 'scrollIntoView', original)
            else Reflect.deleteProperty(label, 'scrollIntoView')
          }
        })
      })
    })

    it('retains the open panel, partial draft and day focus through width changes', async () => {
      await withNarrowScreen(true, async (screen) => {
        const picker = createDateTimePickerController({
          kind: 'datetime',
          count: 'single',
          value: null,
          timeZone: 'UTC',
          today: () => '2026-10-17',
        })
        picker.setDate('start', '2026-10-17')
        picker.setPartText('start', 'hour', '1')
        await withView(mount, picker, async (view) => {
          await click(view, opener(view))
          const dialog = panel(view)
          const target = day(view, 'Saturday, 17 October 2026')
          const before = picker.state.get()
          expect(document.activeElement).toBe(target)
          for (const narrow of [false, true]) {
            screen.set(narrow)
            await view.flush()
            expect(panel(view)).toBe(dialog)
            expect(picker.state.get()).toBe(before)
            expect(document.activeElement).toBe(target)
          }
        })
      })
    })

    it('moves focus from removed Done to the dialog and captures Escape before an outer surface', async () => {
      await withNarrowScreen(true, async (screen) => {
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
            const dialog = panel(view)
            const done = textButton(dialog, 'Done')
            await view.focus(done)
            screen.set(false)
            await view.flush()
            expect(done.isConnected).toBe(false)
            expect(document.activeElement).toBe(dialog)
            expect((await view.press(dialog, 'Escape')).defaultPrevented).toBe(
              true,
            )
            expect(escapes).toBe(0)
            expect(view.root.querySelector('[role="dialog"]')).toBeNull()
            expect(document.activeElement).toBe(opener(view))
          })
        } finally {
          document.body.removeEventListener('keydown', onEscape)
        }
      })
    })

    it('preserves focus that the reader moves while a width redraw is pending', async () => {
      await withNarrowScreen(true, async (screen) => {
        const picker = createDateTimePickerController({
          kind: 'time',
          count: 'single',
          value: '06:30:00',
        })
        await withView(mount, picker, async (view) => {
          await click(view, opener(view, 'Choose time'))
          await view.focus(textButton(panel(view, 'Choose time'), 'Done'))
          screen.set(false)
          await view.focus(part(view, 'Minutes'))
          await view.flush()
          expect(document.activeElement).toBe(part(view, 'Minutes'))
          expect(picker.calendar.state.get().open).toBe(true)
        })
      })
    })

    it('uses narrow behavior on the first render of an already open panel and releases its sole listener', async () => {
      await withNarrowScreen(true, async (screen) => {
        const picker = createDateTimePickerController({
          kind: 'time',
          count: 'range',
          value: null,
        })
        picker.calendar.open()
        expect(screen.listenerCount()).toBe(0)
        await withView(mount, picker, async (view) => {
          const dialog = panel(view, 'Choose time')
          expect(textButton(dialog, 'Done').disabled).toBe(false)
          expect(document.activeElement).toBe(dialog)
          expect(screen.listenerCount()).toBe(1)
          const replacement = createDateTimePickerController({
            kind: 'time',
            count: 'single',
            value: null,
          })
          try {
            await view.update({ picker: replacement })
            expect(screen.listenerCount()).toBe(1)
          } finally {
            // Restore before releasing the replacement's parent-owned controller.
            await view.update({ picker })
            replacement.dispose()
          }
        })
        expect(screen.listenerCount()).toBe(0)
      })
    })

    it('offers no screen keyboard for a fold offset and ignores digits on it', async () => {
      await withNarrowScreen(true, async () => {
        const picker = createDateTimePickerController({
          kind: 'datetime',
          count: 'single',
          value: null,
          timeZone: 'Europe/Warsaw',
          today: () => '2026-10-17',
        })
        picker.setDate('start', '2026-10-25')
        picker.setPartText('start', 'hour', '02')
        picker.setPartText('start', 'minute', '30')
        await withView(mount, picker, async (view) => {
          const offset = part(view, 'UTC offset')
          const before = picker.state.get()
          expect(offset.inputMode).toBe('none')
          expect(
            (await beforeinput(view, offset, 'insertText', '1'))
              .defaultPrevented,
          ).toBe(true)
          expect(picker.state.get()).toBe(before)
        })
      })
    })

    for (const surface of ['field', 'panel']) {
      it(`cancels late beforeinput from a removed ${surface} after replacement and unmount`, async () => {
        await withNarrowScreen(true, async () => {
          let changes = 0
          const original = createDateTimePickerController({
            kind: 'time',
            count: 'single',
            value: '06:30:00',
            onChange: () => changes++,
          })
          const replacement = createDateTimePickerController({
            kind: 'date',
            count: 'single',
            value: '2026-10-17',
            today: () => '2026-10-17',
            onChange: () => changes++,
          })
          let view: DateTimePickerFieldView | undefined
          let unmounted = false
          try {
            view = await mount({ picker: original, id: 'when', label: 'When' })
            await view.flush()
            if (surface === 'panel')
              await click(view, opener(view, 'Choose time'))
            const removed =
              surface === 'panel'
                ? named<HTMLInputElement>(
                    panel(view, 'Choose time'),
                    'spinbutton',
                    'Hours',
                  )
                : part(view, 'Hours')
            await view.update({ picker: replacement })
            expect(removed.isConnected).toBe(false)
            const beforeOriginal = original.state.get()
            const beforeReplacement = replacement.state.get()
            for (const departure of ['replacement', 'unmount']) {
              if (departure === 'unmount') {
                await view.unmount()
                unmounted = true
              }
              for (const inputType of ['insertText', 'deleteContentBackward']) {
                expect(
                  (await beforeinput(view, removed, inputType, '1'))
                    .defaultPrevented,
                ).toBe(true)
                expect(original.state.get()).toBe(beforeOriginal)
                expect(replacement.state.get()).toBe(beforeReplacement)
                expect(changes).toBe(0)
              }
            }
          } finally {
            if (!unmounted) await view?.unmount()
            original.dispose()
            replacement.dispose()
          }
        })
      })
    }

    for (const narrow of [false, true]) {
      for (const surface of ['field', 'panel']) {
        it(`accepts screen-keyboard digits once per edit in the ${narrow ? 'narrow' : 'wide'} ${surface}`, async () => {
          await withNarrowScreen(narrow, async () => {
            let changes = 0
            const picker = createDateTimePickerController({
              kind: 'time',
              count: 'single',
              value: '06:30:00',
              factors: { hourCycle: 'h12' },
              onChange: () => changes++,
            })
            await withView(mount, picker, async (view) => {
              if (surface === 'panel')
                await click(view, opener(view, 'Choose time'))
              const root =
                surface === 'panel'
                  ? panel(view, 'Choose time')
                  : document.getElementById('when')!
              const hours = named<HTMLInputElement>(root, 'spinbutton', 'Hours')
              const minutes = named<HTMLInputElement>(
                root,
                'spinbutton',
                'Minutes',
              )
              const meridiem = named<HTMLInputElement>(
                root,
                'spinbutton',
                'AM/PM',
              )
              expect(hours.inputMode).toBe('numeric')
              expect(minutes.inputMode).toBe('numeric')
              expect(meridiem.inputMode).toBe('none')
              await view.focus(hours)
              expect(
                (await view.press(hours, 'Unidentified')).defaultPrevented,
              ).toBe(false)
              expect(
                (await beforeinput(view, hours, 'insertText', '1'))
                  .defaultPrevented,
              ).toBe(true)
              expect(hours.value).toBe('1')
              expect(document.activeElement).toBe(hours)
              expect(changes).toBe(1)
              expect(
                (await beforeinput(view, hours, 'insertText', '2'))
                  .defaultPrevented,
              ).toBe(true)
              expect(hours.value).toBe('12')
              expect(document.activeElement).toBe(minutes)
              expect(changes).toBe(2)
              expect(picker.state.get().result).toEqual({
                status: 'valid',
                value: '00:30:00',
              })
              expect(
                (await beforeinput(view, minutes, 'deleteContentBackward'))
                  .defaultPrevented,
              ).toBe(true)
              expect(minutes.value).toBe('--')
              expect(picker.state.get().result).toEqual({
                status: 'unresolved',
              })
              expect(changes).toBe(3)
              const before = picker.state.get()
              for (const [inputType, data] of [
                ['insertText', 'x'],
                ['insertFromPaste', '12'],
              ] as const) {
                expect(
                  (await beforeinput(view, minutes, inputType, data))
                    .defaultPrevented,
                ).toBe(true)
                expect(picker.state.get()).toBe(before)
                expect(minutes.value).toBe('--')
                expect(changes).toBe(3)
              }
              expect(
                (await beforeinput(view, meridiem, 'insertText', '1'))
                  .defaultPrevented,
              ).toBe(true)
              expect(picker.state.get()).toBe(before)
              expect(changes).toBe(3)
            })
          })
        })
      }
    }
  })
}
