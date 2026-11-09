import { nextTick } from 'vue'
import type {
  DateTimePickerController,
  DateTimePickerCount,
  DateTimePickerEdge,
  DateTimePickerKind,
  DateTimePickerPart,
} from '@hilos/core/base'

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
  const inputs = (): HTMLInputElement[] =>
    Array.from(
      container()?.querySelectorAll<HTMLInputElement>(
        'input[role="spinbutton"]',
      ) ?? [],
    )
  const index = inputs().indexOf(input)

  async function moveFocus(target: number): Promise<void> {
    await nextTick()
    if (
      !isCurrent() ||
      !container()?.contains(input) ||
      input.ownerDocument.activeElement !== input
    )
      return
    inputs()[target]?.focus()
  }

  switch (event.key) {
    case 'ArrowLeft':
    case 'ArrowRight':
      event.preventDefault()
      void moveFocus(index + (event.key === 'ArrowLeft' ? -1 : 1))
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
        if (picker.inputDigit(edge, part, event.key)) void moveFocus(index + 1)
      } else if (part === 'meridiem' && /^[ap]$/i.test(event.key)) {
        event.preventDefault()
        picker.setMeridiem(edge, event.key.toLowerCase() === 'a' ? 'am' : 'pm')
      }
  }
}
