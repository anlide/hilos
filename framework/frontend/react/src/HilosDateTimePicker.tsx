// HilosDateTimePicker — an accessible segmented date, time, or date-time field.
// Driven by a headless DateTimePickerController; inputs render individual parts
// (day, month, hour, etc.) with keyboard navigation, live hints and validation.
import {
  Fragment,
  useEffect,
  useLayoutEffect,
  useReducer,
  useRef,
  useState,
} from 'react'
import type { FocusEvent, KeyboardEvent, ReactElement } from 'react'
import {
  type DateTimePickerController,
  type DateTimePickerCount,
  type DateTimePickerEdge,
  type DateTimePickerEndpointSnapshot,
  type DateTimePickerKind,
  type DateTimePickerPart,
} from '@hilos/core/base'

import { useSignal } from './useSignal.js'

/** Props for {@link HilosDateTimePicker}. */
export interface HilosDateTimePickerProps {
  /** The parent's controller; the parent also owns its disposal. */
  picker: DateTimePickerController<DateTimePickerKind, DateTimePickerCount>
  /** A stable id for the named group and its descriptions. */
  id: string
  /** The visible group name, including the configured zone for datetime. */
  label: string
  /** Additional description ids supplied by the mounting form. */
  describedBy?: string
  /** The mounting form's stable selector for the group. */
  dataId?: string
}

// React warns when an input provides value without onChange or readOnly; input is handled via keyboard.
function ignoreChange(): void {}

function isInvalid(
  endpoint: DateTimePickerEndpointSnapshot<DateTimePickerKind>,
): boolean {
  return ['invalid', 'gap'].includes(endpoint.result.status)
}

/**
 * An accessible segmented date, time, or date-time field driven by a headless controller.
 *
 * @param props The picker controller, id, labels, and stable selector.
 * @returns The rendered date-time picker field.
 */
export function HilosDateTimePicker({
  picker,
  id,
  label,
  describedBy,
  dataId,
}: HilosDateTimePickerProps): ReactElement {
  const state = useSignal(picker.state)
  const disabled = useSignal(picker.disabled)
  const busy = useSignal(picker.busy)
  const [focused, setFocused] = useState<{
    edge: DateTimePickerEdge
    part: DateTimePickerPart
    input: HTMLInputElement
  } | null>(null)
  const groupRef = useRef<HTMLDivElement>(null)
  const pendingMove = useRef<{
    picker: DateTimePickerController<DateTimePickerKind, DateTimePickerCount>
    input: HTMLInputElement
    index: number
    delta: number
  } | null>(null)
  const [moveTick, requestMove] = useReducer((tick: number) => tick + 1, 0)

  // Removing a focused part does not emit blur in every DOM implementation.
  useLayoutEffect(() => {
    if (
      focused !== null &&
      (!groupRef.current?.contains(focused.input) ||
        focused.input.ownerDocument.activeElement !== focused.input)
    ) {
      setFocused(null)
    }
  })

  useLayoutEffect(() => {
    const move = pendingMove.current
    pendingMove.current = null
    if (
      !move ||
      move.picker !== picker ||
      !groupRef.current?.contains(move.input) ||
      move.input.ownerDocument.activeElement !== move.input
    ) {
      return
    }
    const inputs = groupRef.current.querySelectorAll<HTMLInputElement>(
      'input[role="spinbutton"]',
    )
    inputs[move.index + move.delta]?.focus()
  }, [moveTick, picker])

  useEffect(() => {
    const group = groupRef.current
    if (!group) return
    // Synthetic React onBeforeInput does not observe native beforeinput reliably.
    function cancel(event: Event): void {
      event.preventDefault()
    }
    group.addEventListener('beforeinput', cancel)
    return () => {
      group.removeEventListener('beforeinput', cancel)
    }
  }, [])

  function queueMove(input: HTMLInputElement, delta: number): void {
    const group = groupRef.current
    if (!group) return
    const inputs = Array.from(
      group.querySelectorAll<HTMLInputElement>('input[role="spinbutton"]'),
    )
    const index = inputs.indexOf(input)
    if (index === -1) return
    pendingMove.current = { picker, input, index, delta }
    requestMove()
  }

  function onKeyDown(
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
        queueMove(input, event.key === 'ArrowLeft' ? -1 : 1)
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
          if (picker.inputDigit(edge, part, event.key)) {
            queueMove(input, 1)
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

  function onFocus(
    event: FocusEvent<HTMLInputElement>,
    edge: DateTimePickerEdge,
    part: DateTimePickerPart,
  ): void {
    setFocused({ edge, part, input: event.currentTarget })
  }

  function onBlur(
    event: FocusEvent<HTMLInputElement>,
    edge: DateTimePickerEdge,
    part: DateTimePickerPart,
  ): void {
    if (focused?.input === event.currentTarget) {
      setFocused(null)
    }
    picker.leavePart(edge, part)
  }

  function description(edge: DateTimePickerEdge): string {
    return [`${id}-${edge}-reason`, describedBy].filter(Boolean).join(' ')
  }

  function partLabel(edge: DateTimePickerEdge, partName: string): string {
    if (picker.count === 'single') return partName
    return `${picker.labels[edge === 'start' ? 'from' : 'to']} ${partName}`
  }

  const endpoints = [
    { edge: 'start' as const, snapshot: state.start },
    ...(state.end === null
      ? []
      : [{ edge: 'end' as const, snapshot: state.end }]),
  ]

  const invalid = endpoints.some(({ snapshot }) => isInvalid(snapshot))

  const visibleReason = (() => {
    if (focused === null) return null
    const current = state[focused.edge]
    if (current?.reason) return current
    return endpoints.find(({ snapshot }) => snapshot.reason)?.snapshot ?? null
  })()

  const groupClasses = [
    'form-control d-flex flex-wrap align-items-center',
    invalid ? 'is-invalid' : '',
    focused !== null && !invalid ? 'border-primary' : '',
    disabled || busy ? 'bg-body-secondary' : '',
  ]
    .filter(Boolean)
    .join(' ')

  return (
    <>
      <span id={`${id}-label`} className="form-label d-block small mb-1">
        {label}
      </span>
      <div className="position-relative">
        <div className="input-group input-group-sm flex-nowrap">
          <div
            id={id}
            ref={groupRef}
            className={groupClasses}
            role="group"
            aria-labelledby={`${id}-label`}
            data-id={dataId}
          >
            {endpoints.map(({ edge, snapshot }) => (
              <Fragment key={edge}>
                <div className="d-inline-flex align-items-center flex-nowrap text-nowrap">
                  {snapshot.parts.map((part, index) => (
                    <div
                      key={part.part}
                      className="d-inline-flex align-items-center flex-shrink-0"
                    >
                      {edge === 'end' && index === 0 && (
                        <span
                          className="mx-1 text-body-secondary"
                          aria-hidden="true"
                        >
                          –
                        </span>
                      )}
                      <span aria-hidden="true">{part.prefix}</span>
                      <input
                        type="text"
                        role="spinbutton"
                        autoComplete="off"
                        spellCheck={false}
                        size={Math.max(
                          part.text.length,
                          part.placeholder.length,
                        )}
                        value={part.text || part.placeholder}
                        className={`border-0 text-center p-0 ${
                          focused?.edge === edge && focused.part === part.part
                            ? 'text-bg-primary'
                            : part.text === ''
                              ? 'bg-transparent text-body-tertiary'
                              : 'bg-transparent text-body'
                        }`}
                        aria-label={partLabel(edge, part.label)}
                        aria-valuemin={part.valueMin}
                        aria-valuemax={part.valueMax}
                        aria-valuenow={part.valueNow ?? undefined}
                        aria-valuetext={part.valueText}
                        aria-invalid={isInvalid(snapshot) || undefined}
                        aria-describedby={description(edge)}
                        disabled={disabled || busy}
                        onChange={ignoreChange}
                        onFocus={(event) => onFocus(event, edge, part.part)}
                        onBlur={(event) => onBlur(event, edge, part.part)}
                        onKeyDown={(event) => onKeyDown(event, edge, part.part)}
                      />
                    </div>
                  ))}
                </div>
              </Fragment>
            ))}
          </div>
        </div>
        {endpoints.map(({ edge, snapshot }) => (
          <span
            key={edge}
            id={`${id}-${edge}-reason`}
            className="visually-hidden"
            aria-live="polite"
          >
            {snapshot.reason ?? ''}
          </span>
        ))}
        {visibleReason && (
          <div
            className={
              isInvalid(visibleReason)
                ? 'invalid-tooltip d-block'
                : 'position-absolute top-100 start-0 z-3 mw-100 mt-1 px-2 py-1 small rounded text-bg-secondary'
            }
            aria-hidden="true"
          >
            {visibleReason.reason}
          </div>
        )}
      </div>
    </>
  )
}
