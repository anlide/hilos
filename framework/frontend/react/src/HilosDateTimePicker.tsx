// HilosDateTimePicker — an accessible segmented date, time, or date-time field.
// Driven by a headless DateTimePickerController; inputs render individual parts
// (day, month, hour, etc.) with keyboard navigation, live hints and validation.
// The wide panel mounts inside this root. Narrow layout, Done and edge chips
// belong to a later leaf.
import { Fragment, useEffect, useLayoutEffect, useRef, useState } from 'react'
import type { FocusEvent, KeyboardEvent, MouseEvent, ReactElement } from 'react'
import {
  bindPopupDismiss,
  revealPickerPanel,
  subscribeSignal,
  type DateTimePickerController,
  type DateTimePickerCount,
  type DateTimePickerEdge,
  type DateTimePickerEndpointSnapshot,
  type DateTimePickerKind,
  type DateTimePickerPart,
} from '@hilos/core/base'

import { useDateTimePickerPartKeys } from './dateTimePickerPartKeys.js'
import { HilosDateTimePickerPanel } from './HilosDateTimePickerPanel.js'
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

interface PickerOwner {
  picker: DateTimePickerController<DateTimePickerKind, DateTimePickerCount>
  active: boolean
}

interface LockFocusTarget {
  picker: DateTimePickerController<DateTimePickerKind, DateTimePickerCount>
  root: HTMLElement
  target: HTMLInputElement | HTMLButtonElement
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
  const calendar = useSignal(picker.calendar.state)
  const [focused, setFocused] = useState<{
    edge: DateTimePickerEdge
    part: DateTimePickerPart
    input: HTMLInputElement
  } | null>(null)
  const rootRef = useRef<HTMLDivElement>(null)
  const groupRef = useRef<HTMLDivElement>(null)
  const openerRef = useRef<HTMLButtonElement>(null)
  const acceptedPicker = useRef(picker)
  const ownerRef = useRef<PickerOwner>({ picker, active: true })
  const activeToken = useRef<{ picker: PickerOwner['picker'] } | null>(null)
  const returnFocusRef = useRef(false)
  const wasOpenRef = useRef(false)
  const skipOpenRef = useRef(false)
  const pendingLockFocus = useRef<LockFocusTarget | null>(null)
  // Read during render so the dismiss effect still sees a rejected replacement
  // after the picker effect has already accepted the new controller.
  skipOpenRef.current = acceptedPicker.current !== picker

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

  // After the effect above, so that effect still sees the source as active
  // before this hook's layout effect moves focus.
  const onPartKeyDown = useDateTimePickerPartKeys(picker, groupRef)

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

  // Close belongs to the effect that is actually leaving. StrictMode replays
  // setup immediately, and open() refuses a blocked picker, so a synchronous
  // close in that replay would drop an initially open panel. The token distinguishes
  // that replay from a real unmount and from a different controller.
  useLayoutEffect(() => {
    const source: PickerOwner = { picker, active: true }
    ownerRef.current = source
    const token = { picker }
    const previous = activeToken.current
    activeToken.current = token
    if (
      (previous !== null && previous.picker !== picker) ||
      acceptedPicker.current !== picker
    ) {
      returnFocusRef.current = false
      picker.calendar.close()
      acceptedPicker.current = picker
    }
    return () => {
      source.active = false
      returnFocusRef.current = false
      pendingLockFocus.current = null
      queueMicrotask(() => {
        const current = activeToken.current
        if (current === token || current?.picker !== token.picker) {
          token.picker.calendar.close()
        }
      })
    }
  }, [picker])

  useLayoutEffect(() => {
    if (skipOpenRef.current) return
    const root = rootRef.current
    const open = picker.calendar.state.get().open
    if (open) {
      if (root === null) return
      returnFocusRef.current = true
      wasOpenRef.current = true
      const ownerPicker = picker
      return bindPopupDismiss(root, () => {
        const owner = ownerRef.current
        if (
          !owner.active ||
          owner.picker !== ownerPicker ||
          !root.isConnected ||
          !ownerPicker.calendar.state.get().open
        ) {
          return
        }
        returnFocusRef.current = false
        ownerPicker.calendar.close()
      })
    }
    if (wasOpenRef.current && returnFocusRef.current) {
      const opener = openerRef.current
      if (opener !== null && opener.isConnected && !opener.disabled) {
        opener.focus()
      }
    }
    wasOpenRef.current = false
  }, [calendar.open, picker])

  // A blocked control can lose focus while React commits `disabled`. Remember
  // the target when the signal changes, before that commit.
  useEffect(() => {
    const current = picker
    function remember(blocked: boolean): void {
      if (!blocked || !current.calendar.state.get().open) return
      const root = rootRef.current
      const target = root?.ownerDocument.activeElement
      if (
        root === undefined ||
        root === null ||
        !(
          target instanceof HTMLInputElement ||
          target instanceof HTMLButtonElement
        ) ||
        !root.contains(target)
      ) {
        return
      }
      pendingLockFocus.current = { picker: current, root, target }
    }
    const stopDisabled = subscribeSignal(current.disabled, remember)
    const stopBusy = subscribeSignal(current.busy, remember)
    return () => {
      stopDisabled()
      stopBusy()
      pendingLockFocus.current = null
    }
  }, [picker])

  useLayoutEffect(() => {
    const pending = pendingLockFocus.current
    if (pending === null) return
    pendingLockFocus.current = null
    const root = rootRef.current
    if (
      root !== pending.root ||
      picker !== pending.picker ||
      !root.isConnected ||
      !pending.picker.calendar.state.get().open ||
      !root.contains(pending.target) ||
      !pending.target.matches(':disabled')
    ) {
      return
    }
    const active = root.ownerDocument.activeElement
    if (active !== pending.target && active !== root.ownerDocument.body) return
    root
      .querySelector<HTMLElement>('[role="dialog"]')
      ?.focus({ preventScroll: true })
  })

  function isCurrent(target: EventTarget | null): boolean {
    const root = rootRef.current
    const owner = ownerRef.current
    return (
      owner.active &&
      owner.picker === picker &&
      root !== null &&
      root.isConnected &&
      root.contains(target as Node | null)
    )
  }

  function openFromField(): void {
    const root = rootRef.current
    if (root === null || !root.isConnected) return
    if (!picker.calendar.state.get().open) {
      picker.calendar.open()
      return
    }
    const panel = root.querySelector<HTMLElement>('[role="dialog"]')
    if (panel !== null) revealPickerPanel(panel)
  }

  function onFieldPartKeyDown(
    event: KeyboardEvent<HTMLInputElement>,
    edge: DateTimePickerEdge,
    part: DateTimePickerPart,
  ): void {
    if (!isCurrent(event.currentTarget)) return
    if (
      event.altKey &&
      !event.ctrlKey &&
      !event.metaKey &&
      event.key === 'ArrowDown'
    ) {
      event.preventDefault()
      openFromField()
      return
    }
    onPartKeyDown(event, edge, part)
  }

  function onFocus(
    event: FocusEvent<HTMLInputElement>,
    edge: DateTimePickerEdge,
    part: DateTimePickerPart,
  ): void {
    if (!isCurrent(event.currentTarget)) return
    setFocused({ edge, part, input: event.currentTarget })
  }

  function onBlur(
    event: FocusEvent<HTMLInputElement>,
    edge: DateTimePickerEdge,
    part: DateTimePickerPart,
  ): void {
    if (!isCurrent(event.currentTarget)) return
    if (focused?.input === event.currentTarget) setFocused(null)
    picker.leavePart(edge, part)
  }

  function toggleCalendar(event: MouseEvent<HTMLButtonElement>): void {
    if (!isCurrent(event.currentTarget)) return
    if (picker.calendar.state.get().open) picker.calendar.close()
    else picker.calendar.open()
  }

  function onRootKeyDown(event: KeyboardEvent<HTMLDivElement>): void {
    if (event.key !== 'Escape') return
    if (!isCurrent(event.target) || !picker.calendar.state.get().open) return
    event.preventDefault()
    event.stopPropagation()
    picker.calendar.close()
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
  const blocked = disabled || busy
  const showPanel = calendar.open && acceptedPicker.current === picker
  const visibleReason = (() => {
    if (focused === null || calendar.open) return null
    const current = state[focused.edge]
    if (current?.reason) return current
    return endpoints.find(({ snapshot }) => snapshot.reason)?.snapshot ?? null
  })()
  const groupClasses = [
    'form-control d-flex flex-wrap align-items-center',
    invalid ? 'is-invalid' : '',
    focused !== null && !invalid ? 'border-primary' : '',
    blocked ? 'bg-body-secondary' : '',
  ]
    .filter(Boolean)
    .join(' ')

  return (
    <>
      <span id={`${id}-label`} className="form-label d-block small mb-1">
        {label}
      </span>
      <div
        ref={rootRef}
        className="position-relative"
        onKeyDown={onRootKeyDown}
      >
        <div
          className={[
            'input-group input-group-sm flex-nowrap',
            invalid ? 'has-validation' : '',
          ]
            .filter(Boolean)
            .join(' ')}
        >
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
                        disabled={blocked}
                        onChange={ignoreChange}
                        onFocus={(event) => onFocus(event, edge, part.part)}
                        onBlur={(event) => onBlur(event, edge, part.part)}
                        onKeyDown={(event) =>
                          onFieldPartKeyDown(event, edge, part.part)
                        }
                      />
                    </div>
                  ))}
                </div>
              </Fragment>
            ))}
          </div>
          <button
            ref={openerRef}
            type="button"
            className={[
              'btn btn-outline-secondary',
              calendar.open ? 'active' : '',
            ]
              .filter(Boolean)
              .join(' ')}
            disabled={blocked}
            aria-label={
              picker.kind === 'time'
                ? picker.labels.chooseTime
                : picker.labels.openCalendar
            }
            aria-expanded={calendar.open}
            aria-haspopup="dialog"
            aria-controls={calendar.open ? `${id}-panel` : undefined}
            onClick={toggleCalendar}
          >
            <i
              className={`bi ${picker.kind === 'time' ? 'bi-clock' : 'bi-calendar3'}`}
              aria-hidden="true"
            />
          </button>
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
        {showPanel && (
          <HilosDateTimePickerPanel
            picker={picker}
            id={id}
            calendar={calendar}
            state={state}
            blocked={blocked}
            describedBy={describedBy}
          />
        )}
      </div>
    </>
  )
}
