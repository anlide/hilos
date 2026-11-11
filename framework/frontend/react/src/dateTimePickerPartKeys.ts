// Keyboard handling for one segmented date-time surface. The field and the
// panel share this handler so the gesture table exists once. Focus moves
// after the React commit that draws the draft the key just produced.
import { useLayoutEffect, useReducer, useRef } from 'react'
import type { KeyboardEvent, RefObject } from 'react'
import type {
  DateTimePickerController,
  DateTimePickerCount,
  DateTimePickerEdge,
  DateTimePickerKind,
  DateTimePickerPart,
} from '@hilos/core/base'

/**
 * Shared key handler for a segmented field or a panel part area.
 *
 * The source index is taken before `inputDigit`, because a completed digit
 * can insert a part that is not in the DOM yet. The intention sits in a ref
 * and a counter commits it. After that commit, focus moves to the neighbor
 * only when the same picker still owns a connected, focused source inside
 * `partsRoot`.
 *
 * @param picker The controller the key mutates.
 * @param partsRoot The element that owns this surface's part inputs.
 */
export function useDateTimePickerPartKeys<T extends HTMLElement>(
  picker: DateTimePickerController<DateTimePickerKind, DateTimePickerCount>,
  partsRoot: RefObject<T | null>,
): (
  event: KeyboardEvent<HTMLInputElement>,
  edge: DateTimePickerEdge,
  part: DateTimePickerPart,
) => void {
  const pendingMove = useRef<{
    picker: DateTimePickerController<DateTimePickerKind, DateTimePickerCount>
    input: HTMLInputElement
    index: number
    delta: number
  } | null>(null)
  const [moveTick, requestMove] = useReducer((tick: number) => tick + 1, 0)

  useLayoutEffect(() => {
    const move = pendingMove.current
    pendingMove.current = null
    const root = partsRoot.current
    if (
      !move ||
      move.picker !== picker ||
      root === null ||
      !root.contains(move.input) ||
      move.input.ownerDocument.activeElement !== move.input
    ) {
      return
    }
    const inputs = root.querySelectorAll<HTMLInputElement>(
      'input[role="spinbutton"]',
    )
    inputs[move.index + move.delta]?.focus()
  }, [moveTick, picker, partsRoot])

  return function onPartKeyDown(
    event: KeyboardEvent<HTMLInputElement>,
    edge: DateTimePickerEdge,
    part: DateTimePickerPart,
  ): void {
    if (event.ctrlKey || event.metaKey || event.altKey) {
      return
    }
    const input = event.currentTarget

    function queueMove(index: number, delta: number): void {
      if (partsRoot.current === null || index === -1) return
      pendingMove.current = { picker, input, index, delta }
      requestMove()
    }

    switch (event.key) {
      case 'ArrowLeft':
      case 'ArrowRight':
        event.preventDefault()
        queueMove(
          partIndex(partsRoot.current, input),
          event.key === 'ArrowLeft' ? -1 : 1,
        )
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
          const index = partIndex(partsRoot.current, input)
          if (picker.inputDigit(edge, part, event.key)) queueMove(index, 1)
        } else if (part === 'meridiem' && /^[ap]$/i.test(event.key)) {
          event.preventDefault()
          picker.setMeridiem(
            edge,
            event.key.toLowerCase() === 'a' ? 'am' : 'pm',
          )
        }
    }
  }
}

/** Position of one part input among the spinbuttons in `root`. */
function partIndex(root: HTMLElement | null, input: HTMLInputElement): number {
  if (root === null) return -1
  return Array.from(
    root.querySelectorAll<HTMLInputElement>('input[role="spinbutton"]'),
  ).indexOf(input)
}
