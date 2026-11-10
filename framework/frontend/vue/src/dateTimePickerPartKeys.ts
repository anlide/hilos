import { nextTick } from 'vue'
import type {
  DateTimePickerController,
  DateTimePickerCount,
  DateTimePickerEdge,
  DateTimePickerKind,
  DateTimePickerPart,
} from '@hilos/core/base'

/** Capture the current part's position before rendering the next draft. */
function partFocus(
  input: HTMLInputElement,
  container: () => HTMLElement | null,
  isCurrent: () => boolean,
): (delta: number) => Promise<void> {
  const inputs = (): HTMLInputElement[] =>
    Array.from(
      container()?.querySelectorAll<HTMLInputElement>(
        'input[role="spinbutton"]',
      ) ?? [],
    )
  const index = inputs().indexOf(input)

  return async (delta) => {
    await nextTick()
    if (
      !isCurrent() ||
      !container()?.contains(input) ||
      input.ownerDocument.activeElement !== input
    )
      return
    inputs()[index + delta]?.focus()
  }
}

/** Handle segmented input within the current field or panel's DOM lifetime. */
export function handleDateTimePickerPartKey(
  event: KeyboardEvent,
  picker: DateTimePickerController<DateTimePickerKind, DateTimePickerCount>,
  edge: DateTimePickerEdge,
  part: DateTimePickerPart,
  container: () => HTMLElement | null,
  isCurrent: () => boolean,
): void {
  if (!isCurrent()) return
  const input = event.currentTarget as HTMLInputElement
  if (!container()?.contains(input)) return
  const moveFocus = partFocus(input, container, isCurrent)

  switch (event.key) {
    case 'ArrowLeft':
    case 'ArrowRight':
      event.preventDefault()
      void moveFocus(event.key === 'ArrowLeft' ? -1 : 1)
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
        if (picker.inputDigit(edge, part, event.key)) void moveFocus(1)
      } else if (part === 'meridiem' && /^[ap]$/i.test(event.key)) {
        event.preventDefault()
        picker.setMeridiem(edge, event.key.toLowerCase() === 'a' ? 'am' : 'pm')
      }
  }
}

/** Apply screen-keyboard input through the same controller as keydown. */
export function handleDateTimePickerPartInput(
  event: InputEvent,
  picker: DateTimePickerController<DateTimePickerKind, DateTimePickerCount>,
  edge: DateTimePickerEdge,
  part: DateTimePickerPart,
  container: () => HTMLElement | null,
  isCurrent: () => boolean,
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
    const moveFocus = partFocus(input, container, isCurrent)
    if (picker.inputDigit(edge, part, event.data)) void moveFocus(1)
  }
}

/** Offer a numeric screen keyboard only for editable numeric parts. */
export function dateTimePickerPartInputMode(
  part: DateTimePickerPart,
): 'none' | 'numeric' {
  return part === 'meridiem' || part === 'offset' ? 'none' : 'numeric'
}
