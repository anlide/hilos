// One behavioral and accessible contract for the SDK fields. Each SDK entry
// supplies only a real mount and its lifecycle; no runtime module imports this.
import {
  createDateTimePickerController,
  createSignal,
  type DateTimePickerController,
  type DateTimePickerCount,
  type DateTimePickerKind,
  type ReadonlySignal,
} from '@hilos/core/base'
import type * as Vitest from 'vitest'

export type DateTimePickerFieldTestRunner = Pick<
  typeof Vitest,
  'describe' | 'it' | 'expect'
>

export interface DateTimePickerFieldInput {
  picker: DateTimePickerController<DateTimePickerKind, DateTimePickerCount>
  id: string
  label: string
  describedBy?: string
  dataId?: string
}

export type DateTimePickerFieldModifiers = Pick<
  KeyboardEventInit,
  'ctrlKey' | 'metaKey' | 'altKey' | 'shiftKey'
>

/** The SDK's lifecycle and native-event driver, with no queries or assertions. */
export interface DateTimePickerFieldView {
  readonly root: ParentNode
  focus(target: HTMLElement): Promise<void>
  press(
    target: HTMLElement,
    key: string,
    modifiers?: DateTimePickerFieldModifiers,
  ): Promise<KeyboardEvent>
  flush(): Promise<void>
  update(patch: Partial<DateTimePickerFieldInput>): Promise<void>
  unmount(): Promise<void>
}

export type MountDateTimePickerField = (
  input: DateTimePickerFieldInput,
) => Promise<DateTimePickerFieldView>

/** Mount a fresh field, then release its view and parent-owned controller. */
export async function withView(
  mount: MountDateTimePickerField,
  picker: DateTimePickerFieldInput['picker'],
  steps: (view: DateTimePickerFieldView) => Promise<void>,
  props: Partial<Omit<DateTimePickerFieldInput, 'picker'>> = {},
): Promise<void> {
  try {
    const view = await mount({ picker, id: 'when', label: 'When', ...props })
    try {
      await view.flush()
      await steps(view)
    } finally {
      await view.unmount()
    }
  } finally {
    picker.dispose()
  }
}

/** Find a semantic part; a missing part fails at the case that needs it. */
export function part(
  view: DateTimePickerFieldView,
  name: string,
): HTMLInputElement {
  const input = view.root.querySelector<HTMLInputElement>(
    `input[role="spinbutton"][aria-label="${name}"]`,
  )
  if (input === null) throw new Error(`Missing spinbutton: ${name}`)
  return input
}

/** Enter ASCII digits through the mounted view's native keyboard driver. */
async function typeDigits(
  view: DateTimePickerFieldView,
  name: string,
  digits: string,
): Promise<void> {
  const target = part(view, name)
  await view.focus(target)
  for (const digit of digits) await view.press(target, digit)
}

/** A partial Warsaw draft, waiting for the final minutes to resolve its zone. */
function warsawDraft(date: string): DateTimePickerFieldInput['picker'] {
  const picker = createDateTimePickerController({
    kind: 'datetime',
    count: 'single',
    value: null,
    timeZone: 'Europe/Warsaw',
    today: () => '2026-10-17',
  })
  const [year, month, day] = date.split('-')
  picker.setPartText('start', 'year', year!)
  picker.setPartText('start', 'month', month!)
  picker.setPartText('start', 'day', day!)
  picker.setPartText('start', 'hour', '02')
  return picker
}

/** Count signal reads to prove that replacement and unmount release observers. */
function observed(picker: DateTimePickerFieldInput['picker']) {
  const reads = { state: 0, disabled: 0, busy: 0 }
  let disposals = 0
  function signal<T>(source: ReadonlySignal<T>, key: keyof typeof reads) {
    return {
      get(): T {
        reads[key]++
        return source.get()
      },
    }
  }
  return {
    reads,
    disposals: () => disposals,
    picker: {
      ...picker,
      state: signal(picker.state, 'state'),
      disabled: signal(picker.disabled, 'disabled'),
      busy: signal(picker.busy, 'busy'),
      dispose() {
        disposals++
        picker.dispose()
      },
    },
  }
}

/** Register the same field scenarios against one SDK's real component. */
export function registerDateTimePickerFieldCases(
  sdk: string,
  runner: DateTimePickerFieldTestRunner,
  mount: MountDateTimePickerField,
): void {
  const { describe, it, expect } = runner

  describe(`HilosDateTimePicker field on ${sdk}`, () => {
    it('names its group with a visible label and exposes numeric part values', async () => {
      const picker = createDateTimePickerController({
        kind: 'date',
        count: 'single',
        value: '2026-10-17',
        today: () => '2026-10-17',
      })
      await withView(mount, picker, async (view) => {
        const group = view.root.querySelector('[role="group"]')
        expect(group?.id).toBe('when')
        expect(group?.getAttribute('aria-labelledby')).toBe('when-label')
        const label = view.root.querySelector('#when-label')
        expect(label?.textContent).toBe('When')
        expect(label?.getAttribute('aria-hidden')).toBeNull()
        expect(part(view, 'Day').value).toBe('17')
        expect(part(view, 'Month').getAttribute('aria-valuemin')).toBe('1')
        expect(part(view, 'Month').getAttribute('aria-valuemax')).toBe('12')
        expect(part(view, 'Month').getAttribute('aria-valuenow')).toBe('10')
        expect(part(view, 'Month').getAttribute('aria-valuetext')).toBe(
          'October',
        )
        expect(part(view, 'Year').getAttribute('type')).toBe('text')
        expect(part(view, 'Year').getAttribute('aria-valuenow')).toBe('2026')
        const reason = view.root.querySelector('#when-start-reason')
        expect(reason?.getAttribute('aria-live')).toBe('polite')
        expect(reason?.textContent?.trim()).toBe('')
        expect(view.root.querySelector('#when-end-reason')).toBeNull()
      })
    })

    it('names the parts of both range endpoints and hides separators from speech', async () => {
      const picker = createDateTimePickerController({
        kind: 'datetime',
        count: 'range',
        value: null,
        timeZone: 'Europe/Warsaw',
        today: () => '2026-10-17',
      })
      await withView(
        mount,
        picker,
        async (view) => {
          expect(part(view, 'From Day').getAttribute('aria-describedby')).toBe(
            'when-start-reason',
          )
          expect(part(view, 'To Hours').getAttribute('aria-describedby')).toBe(
            'when-end-reason',
          )
          expect(
            view.root.querySelectorAll('[aria-live="polite"]'),
          ).toHaveLength(2)
          const separator = Array.from(view.root.querySelectorAll('span')).find(
            (node) => node.textContent === '–',
          )
          expect(separator?.getAttribute('aria-hidden')).toBe('true')
          for (const input of view.root.querySelectorAll<HTMLInputElement>(
            '[role="spinbutton"]',
          )) {
            expect(input.hasAttribute('aria-valuenow')).toBe(false)
            expect(input.getAttribute('aria-valuetext')).toBe(input.value)
          }
        },
        { label: 'Window (Europe/Warsaw)' },
      )
    })

    it('uses the controller endpoint labels in range part names', async () => {
      const picker = createDateTimePickerController({
        kind: 'time',
        count: 'range',
        value: null,
        labels: { from: 'Starts', to: 'Ends' },
      })
      await withView(mount, picker, async (view) => {
        expect(part(view, 'Starts Hours').value).toBe('--')
        expect(part(view, 'Ends Minutes').value).toBe('--')
      })
    })

    it('fills numeric parts, advances focus and withholds a value until complete', async () => {
      const picker = createDateTimePickerController({
        kind: 'date',
        count: 'single',
        value: null,
        today: () => '2026-10-17',
      })
      await withView(mount, picker, async (view) => {
        expect(picker.state.get().result).toEqual({ status: 'empty' })
        await typeDigits(view, 'Day', '17')
        expect(document.activeElement).toBe(part(view, 'Month'))
        expect(picker.state.get().result).toEqual({ status: 'unresolved' })
        expect(part(view, 'Day').getAttribute('aria-valuenow')).toBe('17')
        await typeDigits(view, 'Month', '10')
        expect(document.activeElement).toBe(part(view, 'Year'))
        await typeDigits(view, 'Year', '202')
        expect(picker.state.get().result).toEqual({ status: 'unresolved' })
        expect(part(view, 'Year').hasAttribute('aria-valuenow')).toBe(false)
        await view.press(part(view, 'Year'), '6')
        expect(picker.state.get().result).toEqual({
          status: 'valid',
          value: '2026-10-17',
        })
      })
    })

    it('navigates across endpoints and stays at the field boundaries without changes', async () => {
      let changes = 0
      const picker = createDateTimePickerController({
        kind: 'time',
        count: 'range',
        value: ['06:00:00', '07:00:00'],
        onChange: () => changes++,
      })
      await withView(mount, picker, async (view) => {
        const start = part(view, 'From Hours')
        const startMinute = part(view, 'From Minutes')
        const end = part(view, 'To Hours')
        const last = part(view, 'To Minutes')
        await view.focus(start)
        expect((await view.press(start, 'ArrowLeft')).defaultPrevented).toBe(
          true,
        )
        expect(document.activeElement).toBe(start)
        await view.press(start, 'ArrowRight')
        expect(document.activeElement).toBe(startMinute)
        await view.press(startMinute, 'ArrowRight')
        expect(document.activeElement).toBe(end)
        await view.press(end, 'ArrowLeft')
        expect(document.activeElement).toBe(startMinute)
        await view.focus(last)
        await view.press(last, 'ArrowRight')
        expect(document.activeElement).toBe(last)
        expect(changes).toBe(0)
      })
    })

    it('steps cyclically in both directions and reports one change per edit', async () => {
      let changes = 0
      const picker = createDateTimePickerController({
        kind: 'time',
        count: 'single',
        value: '06:00:59',
        showSeconds: true,
        onChange: () => changes++,
      })
      await withView(mount, picker, async (view) => {
        const seconds = part(view, 'Seconds')
        await view.focus(seconds)
        expect(changes).toBe(0)
        expect((await view.press(seconds, 'ArrowUp')).defaultPrevented).toBe(
          true,
        )
        expect(seconds.value).toBe('00')
        expect(picker.state.get().result).toEqual({
          status: 'valid',
          value: '06:00:00',
        })
        expect(changes).toBe(1)
        await view.press(seconds, 'ArrowDown')
        expect(seconds.value).toBe('59')
        expect(document.activeElement).toBe(seconds)
        expect(changes).toBe(2)
        await view.press(seconds, 'ArrowLeft')
        expect(changes).toBe(2)
      })
    })

    for (const key of ['Backspace', 'Delete']) {
      it(`clears a part with ${key} and immediately removes its canonical value`, async () => {
        const picker = createDateTimePickerController({
          kind: 'time',
          count: 'single',
          value: '06:30:00',
        })
        await withView(mount, picker, async (view) => {
          const minutes = part(view, 'Minutes')
          await view.focus(minutes)
          expect((await view.press(minutes, key)).defaultPrevented).toBe(true)
          expect(minutes.value).toBe('--')
          expect(minutes.hasAttribute('aria-valuenow')).toBe(false)
          expect(picker.state.get().result).toEqual({ status: 'unresolved' })
          expect(document.activeElement).toBe(minutes)
        })
      })
    }

    it('sets meridiem with a/p in either case and ignores digits on that part', async () => {
      const picker = createDateTimePickerController({
        kind: 'time',
        count: 'single',
        value: '06:30:00',
        factors: { hourCycle: 'h12' },
      })
      await withView(mount, picker, async (view) => {
        const meridiem = part(view, 'AM/PM')
        await view.focus(meridiem)
        expect((await view.press(meridiem, 'P')).defaultPrevented).toBe(true)
        expect(meridiem.value).toBe('PM')
        expect(picker.state.get().result).toEqual({
          status: 'valid',
          value: '18:30:00',
        })
        await view.press(meridiem, 'a')
        expect(meridiem.value).toBe('AM')
        expect(picker.state.get().result).toEqual({
          status: 'valid',
          value: '06:30:00',
        })
        const before = picker.state.get()
        await view.press(meridiem, '1')
        expect(picker.state.get()).toBe(before)
      })
    })

    it('exposes invalid dates through descriptions and a permanent live region', async () => {
      const picker = createDateTimePickerController({
        kind: 'date',
        count: 'single',
        value: null,
        today: () => '2026-10-17',
      })
      await withView(mount, picker, async (view) => {
        const region = view.root.querySelector('#when-start-reason')
        await typeDigits(view, 'Day', '31')
        await typeDigits(view, 'Month', '02')
        await typeDigits(view, 'Year', '2026')
        expect(picker.state.get().result).toEqual({ status: 'unresolved' })
        expect(part(view, 'Day').getAttribute('aria-invalid')).toBe('true')
        expect(part(view, 'Year').getAttribute('aria-describedby')).toBe(
          'when-start-reason',
        )
        expect(view.root.querySelector('#when-start-reason')).toBe(region)
        expect(region?.textContent?.trim()).toBe('This date does not exist.')
      })
    })

    it('describes a gap and shows its reason only while the field holds focus', async () => {
      const picker = warsawDraft('2026-03-29')
      await withView(
        mount,
        picker,
        async (view) => {
          const region = view.root.querySelector('#when-start-reason')
          await typeDigits(view, 'Minutes', '30')
          const reason =
            '02:30 does not exist on 29/03/2026 in Europe/Warsaw: at 02:00 the clocks jump to 03:00.'
          expect(picker.state.get().start.result).toEqual({ status: 'gap' })
          expect(picker.state.get().result).toEqual({ status: 'unresolved' })
          expect(view.root.querySelector('#when-start-reason')).toBe(region)
          expect(region?.textContent?.trim()).toBe(reason)
          expect(region?.getAttribute('aria-live')).toBe('polite')
          for (const input of view.root.querySelectorAll(
            '[role="spinbutton"]',
          )) {
            expect(input.getAttribute('aria-invalid')).toBe('true')
            expect(input.getAttribute('aria-describedby')).toBe(
              'when-start-reason',
            )
          }
          const tooltip = view.root.querySelector('div[aria-hidden="true"]')
          expect(tooltip?.textContent?.trim()).toBe(reason)
          expect(tooltip?.hasAttribute('aria-live')).toBe(false)
          expect(tooltip?.hasAttribute('role')).toBe(false)
          part(view, 'Minutes').blur()
          await view.flush()
          expect(view.root.querySelector('div[aria-hidden="true"]')).toBeNull()
          expect(region?.textContent?.trim()).toBe(reason)
        },
        { label: 'When (Europe/Warsaw)' },
      )
    })

    it('moves to a new fold offset and chooses the exact occurrence without marking it invalid', async () => {
      const picker = warsawDraft('2026-10-25')
      await withView(mount, picker, async (view) => {
        await typeDigits(view, 'Minutes', '30')
        const offset = part(view, 'UTC offset')
        const reason =
          '02:30 happens twice on 25/10/2026 in Europe/Warsaw. Which one?'
        expect(document.activeElement).toBe(offset)
        expect(offset.hasAttribute('aria-valuenow')).toBe(false)
        expect(offset.getAttribute('aria-describedby')).toBe(
          'when-start-reason',
        )
        expect(picker.state.get().result).toEqual({ status: 'unresolved' })
        expect(view.root.querySelector('[aria-invalid="true"]')).toBeNull()
        expect(
          view.root.querySelector('#when-start-reason')?.textContent?.trim(),
        ).toBe(reason)
        expect(
          view.root
            .querySelector('div[aria-hidden="true"]')
            ?.textContent?.trim(),
        ).toBe(reason)
        await view.press(offset, 'ArrowUp')
        expect(offset.value).toBe('UTC+02:00')
        expect(offset.getAttribute('aria-valuenow')).toBe('0')
        expect(picker.state.get().result).toEqual({
          status: 'valid',
          value: Date.UTC(2026, 9, 25, 0, 30),
        })
        await view.press(offset, 'ArrowUp')
        expect(offset.value).toBe('UTC+01:00')
        expect(offset.getAttribute('aria-valuenow')).toBe('1')
        expect(picker.state.get().result).toEqual({
          status: 'valid',
          value: Date.UTC(2026, 9, 25, 1, 30),
        })
        await view.press(offset, 'Backspace')
        expect(picker.state.get().result).toEqual({ status: 'unresolved' })
        await view.press(offset, 'ArrowDown')
        expect(offset.value).toBe('UTC+01:00')
        expect(picker.state.get().result).toEqual({
          status: 'valid',
          value: Date.UTC(2026, 9, 25, 1, 30),
        })
      })
    })

    it('prefers the focused endpoint reason and otherwise shows the other reason', async () => {
      const picker = createDateTimePickerController({
        kind: 'datetime',
        count: 'range',
        value: null,
        timeZone: 'Europe/Warsaw',
        today: () => '2026-10-17',
      })
      picker.setPartText('start', 'year', '2026')
      picker.setPartText('start', 'month', '02')
      picker.setPartText('start', 'day', '31')
      picker.setPartText('end', 'year', '2026')
      picker.setPartText('end', 'month', '10')
      picker.setPartText('end', 'day', '25')
      picker.setPartText('end', 'hour', '02')
      picker.setPartText('end', 'minute', '30')
      await withView(mount, picker, async (view) => {
        expect(view.root.querySelector('div[aria-hidden="true"]')).toBeNull()
        await view.focus(part(view, 'From Day'))
        expect(
          view.root
            .querySelector('div[aria-hidden="true"]')
            ?.textContent?.trim(),
        ).toBe('This date does not exist.')
        await view.focus(part(view, 'To Hours'))
        expect(
          view.root
            .querySelector('div[aria-hidden="true"]')
            ?.textContent?.trim(),
        ).toBe('02:30 happens twice on 25/10/2026 in Europe/Warsaw. Which one?')
        picker.setEndpointValue('end', Date.UTC(2026, 9, 17, 4))
        await view.flush()
        expect(
          view.root
            .querySelector('div[aria-hidden="true"]')
            ?.textContent?.trim(),
        ).toBe('This date does not exist.')
        expect(part(view, 'To Hours').hasAttribute('aria-invalid')).toBe(false)
        expect(part(view, 'From Day').getAttribute('aria-invalid')).toBe('true')
      })
    })

    it('preserves parent descriptions alongside the reason and places its selector on the group', async () => {
      const picker = createDateTimePickerController({
        kind: 'time',
        count: 'single',
        value: null,
      })
      await withView(
        mount,
        picker,
        async (view) => {
          const group = view.root.querySelector('[data-id="work-window"]')
          expect(group?.getAttribute('role')).toBe('group')
          expect(part(view, 'Hours').getAttribute('aria-describedby')).toBe(
            'when-start-reason help policy',
          )
          await view.update({ describedBy: 'updated-help', label: 'Starts' })
          expect(part(view, 'Hours').getAttribute('aria-describedby')).toBe(
            'when-start-reason updated-help',
          )
          expect(view.root.querySelector('#when-label')?.textContent).toBe(
            'Starts',
          )
        },
        { describedBy: 'help policy', dataId: 'work-window' },
      )
    })

    for (const lock of ['disabled', 'busy'] as const) {
      it(`retains its visible value while ${lock} and edits again when unlocked`, async () => {
        const locked = createSignal(false)
        const picker = createDateTimePickerController({
          kind: 'time',
          count: 'single',
          value: '06:30:00',
          [lock]: () => locked.get(),
        })
        await withView(mount, picker, async (view) => {
          const hours = part(view, 'Hours')
          expect(hours.disabled).toBe(false)
          locked.set(true)
          await view.flush()
          const before = picker.state.get()
          for (const input of view.root.querySelectorAll<HTMLInputElement>(
            '[role="spinbutton"]',
          ))
            expect(input.disabled).toBe(true)
          expect(hours.value).toBe('06')
          for (const key of [
            'ArrowUp',
            'ArrowDown',
            'Backspace',
            'Delete',
            '1',
          ])
            await view.press(hours, key)
          expect(picker.state.get()).toBe(before)
          locked.set(false)
          await view.flush()
          expect(hours.disabled).toBe(false)
          await view.focus(hours)
          await view.press(hours, 'ArrowUp')
          expect(picker.state.get().result).toEqual({
            status: 'valid',
            value: '07:30:00',
          })
        })
      })
    }

    it('loads only the owner-addressed endpoint while preserving the other draft', async () => {
      const picker = createDateTimePickerController({
        kind: 'time',
        count: 'range',
        value: null,
      })
      await withView(mount, picker, async (view) => {
        await typeDigits(view, 'To Hours', '1')
        const end = picker.state.get().end
        picker.setEndpointValue('start', '06:30:45')
        await view.flush()
        expect(part(view, 'From Hours').value).toBe('06')
        expect(part(view, 'From Minutes').value).toBe('30')
        expect(part(view, 'To Hours').value).toBe('1')
        expect(picker.state.get().end).toEqual(end)
        expect(picker.state.get().result).toEqual({ status: 'unresolved' })
      })
    })

    it('leaves browser shortcuts, Tab and Enter uncanceled and blocks native beforeinput', async () => {
      const picker = createDateTimePickerController({
        kind: 'time',
        count: 'single',
        value: '06:30:00',
      })
      await withView(mount, picker, async (view) => {
        const hours = part(view, 'Hours')
        await view.focus(hours)
        const before = picker.state.get()
        for (const [key, modifiers] of [
          ['1', { ctrlKey: true }],
          ['ArrowUp', { metaKey: true }],
          ['Tab', {}],
          ['Enter', {}],
        ] as const) {
          expect(
            (await view.press(hours, key, modifiers)).defaultPrevented,
          ).toBe(false)
        }
        expect(picker.state.get()).toBe(before)
        const event = new InputEvent('beforeinput', {
          inputType: 'insertText',
          data: '9',
          bubbles: true,
          cancelable: true,
        })
        hours.dispatchEvent(event)
        await view.flush()
        expect(event.defaultPrevented).toBe(true)
        expect(hours.value).toBe('06')
        expect(picker.state.get()).toBe(before)
      })
    })

    for (const change of ['kind', 'count'] as const) {
      it(`drops focus from a removed part on ${change} replacement and ignores its late events`, async () => {
        const original =
          change === 'kind'
            ? createDateTimePickerController({
                kind: 'datetime',
                count: 'single',
                value: null,
                timeZone: 'Europe/Warsaw',
                today: () => '2026-10-17',
              })
            : createDateTimePickerController({
                kind: 'date',
                count: 'range',
                value: null,
                today: () => '2026-10-17',
              })
        let changes = 0
        const replacement = createDateTimePickerController({
          kind: 'date',
          count: 'single',
          value: null,
          today: () => '2026-10-17',
          onChange: () => changes++,
        })
        replacement.setPartText('start', 'day', '31')
        replacement.setPartText('start', 'month', '2')
        replacement.setPartText('start', 'year', '2026')
        const before = replacement.state.get()
        const beforeChanges = changes
        const errors: unknown[] = []
        const onError = (event: ErrorEvent) => errors.push(event.error)
        window.addEventListener('error', onError)
        try {
          await withView(mount, original, async (view) => {
            const departed = part(
              view,
              change === 'kind' ? 'Hours' : 'To Month',
            )
            await view.focus(departed)
            expect(document.activeElement).toBe(departed)
            await view.update({ picker: replacement })
            await view.flush()
            const group = view.root.querySelector('[role="group"]')
            expect(group?.contains(document.activeElement)).toBe(false)
            expect(
              view.root.querySelector('div[aria-hidden="true"]'),
            ).toBeNull()
            expect(
              view.root
                .querySelector('#when-start-reason')
                ?.textContent?.trim(),
            ).toBe('This date does not exist.')

            await view.focus(part(view, 'Day'))
            departed.dispatchEvent(new FocusEvent('blur'))
            await view.press(departed, 'ArrowUp')
            await view.flush()
            expect(document.activeElement).toBe(part(view, 'Day'))
            expect(
              view.root
                .querySelector('div[aria-hidden="true"]')
                ?.textContent?.trim(),
            ).toBe('This date does not exist.')
            expect(part(view, 'Month').value).toBe('2')
            expect(replacement.state.get()).toBe(before)
            expect(changes).toBe(beforeChanges)
            expect(errors).toEqual([])
            part(view, 'Day').blur()
            await view.flush()
            expect(
              view.root.querySelector('div[aria-hidden="true"]'),
            ).toBeNull()
          })
        } finally {
          window.removeEventListener('error', onError)
          replacement.dispose()
        }
      })
    }

    it('keeps a surviving focused part on replacement and completes only the current draft on blur', async () => {
      const original = createDateTimePickerController({
        kind: 'date',
        count: 'range',
        value: null,
        today: () => '2026-10-17',
      })
      const replacement = createDateTimePickerController({
        kind: 'date',
        count: 'single',
        value: null,
        today: () => '2026-10-17',
      })
      original.setPartText('start', 'month', '3')
      replacement.setPartText('start', 'day', '31')
      replacement.setPartText('start', 'month', '2')
      replacement.setPartText('start', 'year', '2026')
      const oldDraft = original.state.get()
      try {
        await withView(mount, original, async (view) => {
          await view.focus(part(view, 'From Month'))
          await view.update({ picker: replacement })
          await view.flush()
          expect(document.activeElement).toBe(part(view, 'Month'))
          expect(part(view, 'Month').value).toBe('2')
          expect(
            view.root
              .querySelector('div[aria-hidden="true"]')
              ?.textContent?.trim(),
          ).toBe('This date does not exist.')
          part(view, 'Month').blur()
          await view.flush()
          expect(part(view, 'Month').value).toBe('02')
          expect(original.state.get()).toBe(oldDraft)
          expect(view.root.querySelector('div[aria-hidden="true"]')).toBeNull()
        })
      } finally {
        replacement.dispose()
      }
    })

    it('does not advance a replacement controller after a digit completed on the previous one', async () => {
      let view: DateTimePickerFieldView | undefined
      let switched: Promise<void> | undefined
      const replacement = createDateTimePickerController({
        kind: 'time',
        count: 'single',
        value: null,
      })
      replacement.setPartText('start', 'hour', '9')
      const before = replacement.state.get()
      const original = createDateTimePickerController({
        kind: 'time',
        count: 'single',
        value: null,
        onChange: () => {
          switched = view?.update({ picker: replacement })
        },
      })
      original.setPartText('start', 'hour', '0')
      try {
        await withView(mount, original, async (mounted) => {
          view = mounted
          const hours = part(view, 'Hours')
          await view.focus(hours)
          await view.press(hours, '6')
          await switched
          await view.flush()
          expect(original.state.get().start.parts[0]?.text).toBe('06')
          expect(document.activeElement).toBe(part(view, 'Hours'))
          expect(part(view, 'Hours').value).toBe('9')
          expect(replacement.state.get()).toBe(before)
        })
      } finally {
        replacement.dispose()
      }
    })

    it('switches controllers and releases all signal subscriptions without disposing either owner', async () => {
      const oldLock = createSignal(false)
      const newLock = createSignal(false)
      const original = createDateTimePickerController({
        kind: 'time',
        count: 'single',
        value: '06:30:00',
        disabled: () => oldLock.get(),
        busy: () => oldLock.get(),
      })
      const replacement = createDateTimePickerController({
        kind: 'time',
        count: 'single',
        value: '12:45:00',
        disabled: () => newLock.get(),
        busy: () => newLock.get(),
      })
      const first = observed(original)
      const second = observed(replacement)
      let view: DateTimePickerFieldView | undefined
      let unmounted = false
      try {
        view = await mount({ picker: first.picker, id: 'when', label: 'When' })
        await view.flush()
        expect(part(view, 'Hours').value).toBe('06')
        await view.update({ picker: second.picker })
        await view.flush()
        expect(part(view, 'Hours').value).toBe('12')
        const oldReads = { ...first.reads }
        original.setValue('08:15:00')
        oldLock.set(true)
        await view.flush()
        expect(first.reads).toEqual(oldReads)
        expect(part(view, 'Hours').value).toBe('12')
        expect(part(view, 'Hours').disabled).toBe(false)
        replacement.setValue('14:20:00')
        await view.flush()
        expect(part(view, 'Hours').value).toBe('14')
        await view.unmount()
        unmounted = true
        const newReads = { ...second.reads }
        replacement.setValue('15:10:00')
        newLock.set(true)
        await view.flush()
        expect(second.reads).toEqual(newReads)
        expect(first.disposals()).toBe(0)
        expect(second.disposals()).toBe(0)
        expect(view.root.querySelector('[role="group"]')).toBeNull()
      } finally {
        if (!unmounted) await view?.unmount()
        original.dispose()
        replacement.dispose()
      }
    })
  })
}
