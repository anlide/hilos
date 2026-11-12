// Keyboard handling for one segmented date-time surface. The field and the
// panel share this handler so the gesture table exists once. Focus moves
// after the React commit that draws the draft the key just produced. The same
// hook drives input from a screen keyboard, which arrives as beforeinput.
import { useLayoutEffect, useReducer, useRef } from 'react'
import type { KeyboardEvent, RefObject } from 'react'
import type {
  DateTimePickerController,
  DateTimePickerCount,
  DateTimePickerEdge,
  DateTimePickerKind,
  DateTimePickerPart,
} from '@hilos/core/base'

/** The part handlers one segmented surface attaches to each part input. */
export interface DateTimePickerPartKeys {
  /**
   * Handle a key on one part.
   *
   * @param event The key event on the part input.
   * @param edge The endpoint the part belongs to.
   * @param part The part the input draws.
   */
  onPartKeyDown(
    event: KeyboardEvent<HTMLInputElement>,
    edge: DateTimePickerEdge,
    part: DateTimePickerPart,
  ): void
  /**
   * A callback ref that routes screen-keyboard input on one part.
   *
   * @param edge The endpoint the part belongs to.
   * @param part The part the input draws.
   * @param isCurrent Whether the surface still owns an event target.
   * @returns The ref for that part's input.
   */
  partInputRef(
    edge: DateTimePickerEdge,
    part: DateTimePickerPart,
    isCurrent: (target: EventTarget | null) => boolean,
  ): (input: HTMLInputElement | null) => void
}

// The latest render's handler for each part input that has a native
// beforeinput listener. Weak, so a dropped input takes its entry with it.
const partInputs = new WeakMap<HTMLInputElement, (event: InputEvent) => void>()

// The browser never writes into a part itself; the current handler, if any,
// decides what the input means.
function cancelPartInput(event: InputEvent): void {
  event.preventDefault()
  const input = event.currentTarget
  if (input instanceof HTMLInputElement) partInputs.get(input)?.(event)
}

/**
 * Shared key and screen-keyboard handlers for a segmented field or a panel
 * part area.
 *
 * The source index is taken before `inputDigit`, because a completed digit
 * can insert a part that is not in the DOM yet. The intention sits in a ref
 * and a counter commits it. After that commit, focus moves to the neighbor
 * only when the same picker still owns a connected, focused source inside
 * `partsRoot`.
 *
 * @param picker The controller the key mutates.
 * @param partsRoot The element that owns this surface's part inputs.
 * @returns The key handler and the screen-keyboard ref for each part.
 */
export function useDateTimePickerPartKeys<T extends HTMLElement>(
  picker: DateTimePickerController<DateTimePickerKind, DateTimePickerCount>,
  partsRoot: RefObject<T | null>,
): DateTimePickerPartKeys {
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

  function queueMove(
    input: HTMLInputElement,
    index: number,
    delta: number,
  ): void {
    if (partsRoot.current === null || index === -1) return
    pendingMove.current = { picker, input, index, delta }
    requestMove()
  }

  function onPartKeyDown(
    event: KeyboardEvent<HTMLInputElement>,
    edge: DateTimePickerEdge,
    part: DateTimePickerPart,
  ): void {
    if (event.ctrlKey || event.metaKey || event.altKey) {
      return
    }
    const input = event.currentTarget

    switch (event.key) {
      case 'ArrowLeft':
      case 'ArrowRight':
        event.preventDefault()
        queueMove(
          input,
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
          if (picker.inputDigit(edge, part, event.key)) {
            queueMove(input, index, 1)
          }
        } else if (part === 'meridiem' && /^[ap]$/i.test(event.key)) {
          event.preventDefault()
          picker.setMeridiem(
            edge,
            event.key.toLowerCase() === 'a' ? 'am' : 'pm',
          )
        }
    }
  }

  function partInputRef(
    edge: DateTimePickerEdge,
    part: DateTimePickerPart,
    isCurrent: (target: EventTarget | null) => boolean,
  ): (input: HTMLInputElement | null) => void {
    return (input) => {
      if (input === null) return
      // Never removed, and no ref cleanup: a late beforeinput on a part that a
      // picker replacement or an unmount detached must still be canceled,
      // and React's delegated events do not reach a detached node.
      if (!partInputs.has(input)) {
        input.addEventListener('beforeinput', cancelPartInput)
      }
      partInputs.set(input, (event) => {
        const root = partsRoot.current
        if (!isCurrent(input) || root === null || !root.contains(input)) {
          return
        }
        if (event.inputType.startsWith('delete')) {
          picker.clearPart(edge, part)
        } else if (
          event.data !== null &&
          /^[0-9]$/.test(event.data) &&
          part !== 'meridiem' &&
          part !== 'offset'
        ) {
          const index = partIndex(root, input)
          if (picker.inputDigit(edge, part, event.data)) {
            queueMove(input, index, 1)
          }
        }
      })
    }
  }

  return { onPartKeyDown, partInputRef }
}

/**
 * The screen keyboard a part offers: numeric for numbers, none for AM/PM and
 * the fold offset.
 *
 * @param part The part the input draws.
 * @returns The input's `inputMode`.
 */
export function dateTimePickerPartInputMode(
  part: DateTimePickerPart,
): 'none' | 'numeric' {
  return part === 'meridiem' || part === 'offset' ? 'none' : 'numeric'
}

/** Position of one part input among the spinbuttons in `root`. */
function partIndex(root: HTMLElement | null, input: HTMLInputElement): number {
  if (root === null) return -1
  return Array.from(
    root.querySelectorAll<HTMLInputElement>('input[role="spinbutton"]'),
  ).indexOf(input)
}
