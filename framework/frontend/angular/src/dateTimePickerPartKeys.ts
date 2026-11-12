// Keyboard and screen-keyboard handling for one segmented field. This module
// moves, steps, clears and fills parts from keydown and from the beforeinput a
// screen keyboard sends; the calendar opener is a separate surface. Focus
// moves after the next render, because a completed digit can insert a part
// that is not in the DOM yet.
import { Directive, ElementRef, inject } from '@angular/core'
import type {
  DateTimePickerController,
  DateTimePickerCount,
  DateTimePickerEdge,
  DateTimePickerKind,
  DateTimePickerPart,
} from '@hilos/core/base'

/** Run `callback` once the view has drawn the draft this key just produced. */
type AfterRender = (callback: () => void) => void

/** Native insertion never lands in a part: the controller owns its text. */
function cancelNativeInput(event: Event): void {
  event.preventDefault()
}

/**
 * Internal: cancels every native insertion into a part input. The template's
 * own beforeinput listener drives the controller; this one only cancels.
 */
@Directive({ selector: 'input[hilosDateTimePickerPartInput]' })
export class DateTimePickerPartInput {
  constructor() {
    // Never removed: Angular removes a template listener together with the
    // part's view, and a late beforeinput on a part that a picker replacement
    // or an unmount detached must still be canceled.
    const input = inject<ElementRef<HTMLInputElement>>(ElementRef).nativeElement
    input.addEventListener('beforeinput', cancelNativeInput)
  }
}

/** Capture the current part's position before rendering the next draft. */
function partFocus(
  input: HTMLInputElement,
  container: () => HTMLElement | null,
  isCurrent: () => boolean,
  afterRender: AfterRender,
): (delta: number) => void {
  const inputs = (): HTMLInputElement[] =>
    Array.from(
      container()?.querySelectorAll<HTMLInputElement>(
        'input[role="spinbutton"]',
      ) ?? [],
    )
  const index = inputs().indexOf(input)

  return (delta) => {
    afterRender(() => {
      if (
        !isCurrent() ||
        !container()?.contains(input) ||
        input.ownerDocument.activeElement !== input
      ) {
        return
      }
      inputs()[index + delta]?.focus()
    })
  }
}

/** Handle segmented input within the current field's DOM lifetime. */
export function handleDateTimePickerPartKey(
  event: KeyboardEvent,
  picker: DateTimePickerController<DateTimePickerKind, DateTimePickerCount>,
  edge: DateTimePickerEdge,
  part: DateTimePickerPart,
  container: () => HTMLElement | null,
  isCurrent: () => boolean,
  afterRender: AfterRender,
): void {
  if (!isCurrent()) return
  const input = event.currentTarget as HTMLInputElement
  if (!container()?.contains(input)) return
  const moveFocus = partFocus(input, container, isCurrent, afterRender)

  switch (event.key) {
    case 'ArrowLeft':
    case 'ArrowRight':
      event.preventDefault()
      moveFocus(event.key === 'ArrowLeft' ? -1 : 1)
      break
    case 'ArrowUp':
    case 'ArrowDown':
      event.preventDefault()
      picker.stepPart(edge, part, event.key === 'ArrowUp' ? 1 : -1)
      break
    case 'Backspace':
    case 'Delete':
      event.preventDefault()
      picker.clearPart(edge, part)
      break
    default:
      if (
        /^[0-9]$/.test(event.key) &&
        part !== 'meridiem' &&
        part !== 'offset'
      ) {
        event.preventDefault()
        if (picker.inputDigit(edge, part, event.key)) moveFocus(1)
      } else if (part === 'meridiem' && /^[ap]$/i.test(event.key)) {
        event.preventDefault()
        picker.setMeridiem(edge, event.key.toLowerCase() === 'a' ? 'am' : 'pm')
      }
  }
}

/** Apply screen-keyboard input through the same controller path as keydown. */
export function handleDateTimePickerPartInput(
  event: InputEvent,
  picker: DateTimePickerController<DateTimePickerKind, DateTimePickerCount>,
  edge: DateTimePickerEdge,
  part: DateTimePickerPart,
  container: () => HTMLElement | null,
  isCurrent: () => boolean,
  afterRender: AfterRender,
): void {
  event.preventDefault()
  if (!isCurrent()) return
  const input = event.currentTarget as HTMLInputElement
  if (!container()?.contains(input)) return
  if (event.inputType.startsWith('delete')) picker.clearPart(edge, part)
  else if (
    event.data !== null &&
    /^[0-9]$/.test(event.data) &&
    part !== 'meridiem' &&
    part !== 'offset'
  ) {
    const moveFocus = partFocus(input, container, isCurrent, afterRender)
    if (picker.inputDigit(edge, part, event.data)) moveFocus(1)
  }
}

/** Offer a numeric screen keyboard only for editable numeric parts. */
export function dateTimePickerPartInputMode(
  part: DateTimePickerPart,
): 'none' | 'numeric' {
  return part === 'meridiem' || part === 'offset' ? 'none' : 'numeric'
}
