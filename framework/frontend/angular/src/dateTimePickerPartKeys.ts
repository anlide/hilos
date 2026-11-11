// Keyboard handling for one segmented field. The calendar opener and
// screen-keyboard digits are separate surfaces; this module only moves, steps,
// clears and fills parts from keydown. Focus moves after the next render,
// because a completed digit can insert a part that is not in the DOM yet.
import type {
  DateTimePickerController,
  DateTimePickerCount,
  DateTimePickerEdge,
  DateTimePickerKind,
  DateTimePickerPart,
} from '@hilos/core/base'

/** Run `callback` once the view has drawn the draft this key just produced. */
type AfterRender = (callback: () => void) => void

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
