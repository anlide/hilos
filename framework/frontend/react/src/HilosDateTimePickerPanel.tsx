// Calendar and time panel for HilosDateTimePicker at either width; the narrow
// one lies in flow under the field. The field owns opening, dismissal and
// focus return; this view draws one open snapshot and talks to the same
// headless controller. It is not a modal and it is not exported.
import { Fragment, useEffect, useLayoutEffect, useRef, useState } from 'react'
import type { FocusEvent, KeyboardEvent, MouseEvent, ReactElement } from 'react'
import {
  revealNarrowPickerPanel,
  revealPickerPanel,
  type DateTimePickerCalendarSnapshot,
  type DateTimePickerController,
  type DateTimePickerCount,
  type DateTimePickerEdge,
  type DateTimePickerEndpointSnapshot,
  type DateTimePickerKind,
  type DateTimePickerPart,
  type DateTimePickerSnapshot,
} from '@hilos/core/base'

import {
  dateTimePickerPartInputMode,
  useDateTimePickerPartKeys,
} from './dateTimePickerPartKeys.js'

const TIME_PARTS: readonly DateTimePickerPart[] = [
  'hour',
  'minute',
  'second',
  'meridiem',
]
const DATE_PARTS: readonly DateTimePickerPart[] = ['year', 'month', 'day']

/** Props for the panel mounted under an open field. */
export interface HilosDateTimePickerPanelProps {
  /** The field's controller. The panel never disposes it. */
  picker: DateTimePickerController<DateTimePickerKind, DateTimePickerCount>
  /** The field id; the dialog id is `${id}-panel`. */
  id: string
  /** The calendar snapshot the field is currently showing. */
  calendar: DateTimePickerCalendarSnapshot
  /** The draft snapshot the time rows and fold choices read. */
  state: DateTimePickerSnapshot<DateTimePickerKind, DateTimePickerCount>
  /** Disabled or busy: parts, arrows and radios are unavailable. */
  blocked: boolean
  /** The field's narrow layout: in flow under the field, edge buttons and Done. */
  narrow: boolean
  /** Description ids supplied by the mounting form, joined after the reason. */
  describedBy?: string
}

function ignoreChange(): void {}

function isInvalid(
  endpoint: DateTimePickerEndpointSnapshot<DateTimePickerKind>,
): boolean {
  return ['invalid', 'gap'].includes(endpoint.result.status)
}

function hasDate(
  endpoint: DateTimePickerEndpointSnapshot<DateTimePickerKind>,
): boolean {
  return DATE_PARTS.every(
    (name) =>
      endpoint.parts.find((part) => part.part === name)?.valueNow != null,
  )
}

function endpointText(
  snapshot: DateTimePickerEndpointSnapshot<DateTimePickerKind>,
): string {
  return snapshot.parts
    .map((part) => part.prefix + (part.text || part.placeholder))
    .join('')
}

/**
 * The date, time, or date-time panel at either width.
 *
 * @param props The controller, snapshots, and the field's description ids.
 * @returns The dialog, or the time rows when the kind has no grid.
 */
export function HilosDateTimePickerPanel({
  picker,
  id,
  calendar,
  state,
  blocked,
  narrow,
  describedBy,
}: HilosDateTimePickerPanelProps): ReactElement {
  const panelRef = useRef<HTMLDivElement>(null)
  const pendingReveal = useRef<HTMLElement | null>(null)
  const didReveal = useRef(false)
  const [focused, setFocused] = useState<{
    edge: DateTimePickerEdge
    part: DateTimePickerPart
  } | null>(null)
  const { onPartKeyDown, partInputRef } = useDateTimePickerPartKeys(
    picker,
    panelRef,
  )
  const hasGrid = calendar.grid !== null

  // The first commit reveals. A later commit reveals only when a grid key
  // changed the snapshot and this same panel element is still the open one.
  useLayoutEffect(() => {
    const element = panelRef.current
    const pending = pendingReveal.current
    const opening = !didReveal.current
    pendingReveal.current = null
    if (element === null || !element.isConnected) return
    if (!picker.calendar.state.get().open) return
    if (!opening && pending !== element) return
    didReveal.current = true
    if (opening && narrow) {
      revealNarrowPickerPanel(
        element,
        element.ownerDocument.getElementById(`${id}-label`) ?? element,
      )
    } else {
      revealPickerPanel(element)
    }
  })

  useEffect(() => {
    const panel = panelRef.current
    if (panel === null) return
    const grid = panel.querySelector<HTMLElement>('[role="grid"]')
    // pointerleave does not bubble, so React's pointer-out emulation never
    // sees a leave dispatched on the grid itself.
    function leaveGrid(): void {
      if (panel === null || !panel.isConnected) return
      if (!picker.calendar.state.get().open) return
      picker.calendar.hoverDay(null)
    }
    grid?.addEventListener('pointerleave', leaveGrid)
    return () => {
      grid?.removeEventListener('pointerleave', leaveGrid)
    }
  }, [picker, hasGrid])

  function isCurrent(target: EventTarget | null): boolean {
    const element = panelRef.current
    return (
      element !== null &&
      element.isConnected &&
      element.contains(target as Node | null) &&
      picker.calendar.state.get().open
    )
  }

  function description(edge: DateTimePickerEdge): string {
    return [`${id}-${edge}-reason`, describedBy].filter(Boolean).join(' ')
  }

  function partLabel(edge: DateTimePickerEdge, label: string): string {
    if (picker.count === 'single') return label
    return `${picker.labels[edge === 'start' ? 'from' : 'to']} ${label}`
  }

  function offsetText(index: number, text: string): string {
    if (index === 0) return `${picker.labels.firstOccurrence} · ${text}`
    if (index === 1) return `${picker.labels.secondOccurrence} · ${text}`
    return text
  }

  const endpoints = [
    { edge: 'start' as const, snapshot: state.start },
    ...(state.end === null
      ? []
      : [{ edge: 'end' as const, snapshot: state.end }]),
  ]
  const timeRows =
    picker.kind === 'date'
      ? []
      : endpoints
          .filter(({ edge, snapshot }) =>
            narrow && calendar.grid !== null
              ? edge === calendar.activeEdge
              : edge === 'start' || picker.kind === 'time' || hasDate(snapshot),
          )
          .map(({ edge, snapshot }) => ({
            edge,
            invalid: isInvalid(snapshot),
            label:
              picker.count === 'range'
                ? picker.labels[edge === 'start' ? 'from' : 'to']
                : picker.kind === 'datetime'
                  ? picker.labels.time
                  : null,
            parts: snapshot.parts.filter((part) =>
              TIME_PARTS.includes(part.part),
            ),
          }))
  const grid = calendar.grid

  function onDayKeyDown(event: KeyboardEvent<HTMLButtonElement>): void {
    if (
      !isCurrent(event.currentTarget) ||
      event.ctrlKey ||
      event.metaKey ||
      event.altKey
    ) {
      return
    }
    const element = panelRef.current
    if (element === null) return
    const before = picker.calendar.state.get()
    if (!picker.calendar.pressKey(event.key, event.shiftKey)) return
    event.preventDefault()
    if (picker.calendar.state.get() !== before) pendingReveal.current = element
  }

  function onDayKeyUp(event: KeyboardEvent<HTMLButtonElement>): void {
    if (event.key !== ' ' || !isCurrent(event.currentTarget)) return
    event.preventDefault()
  }

  function onPanelPartKeyDown(
    event: KeyboardEvent<HTMLInputElement>,
    edge: DateTimePickerEdge,
    part: DateTimePickerPart,
  ): void {
    if (!isCurrent(event.currentTarget)) return
    if (event.key === 'Enter') {
      event.preventDefault()
      return
    }
    onPartKeyDown(event, edge, part)
  }

  function leavePart(
    event: FocusEvent<HTMLInputElement>,
    edge: DateTimePickerEdge,
    part: DateTimePickerPart,
  ): void {
    if (!isCurrent(event.currentTarget)) return
    setFocused(null)
    picker.leavePart(edge, part)
  }

  function stepPart(
    event: MouseEvent<HTMLButtonElement>,
    edge: DateTimePickerEdge,
    part: DateTimePickerPart,
    delta: 1 | -1,
  ): void {
    if (!isCurrent(event.currentTarget)) return
    const column = event.currentTarget.parentElement
    picker.stepPart(edge, part, delta)
    column?.querySelector<HTMLInputElement>('input')?.focus()
  }

  return (
    <div
      id={`${id}-panel`}
      ref={panelRef}
      role="dialog"
      tabIndex={-1}
      aria-label={
        picker.kind === 'time'
          ? picker.labels.chooseTime
          : picker.labels.chooseDate
      }
      className={
        narrow
          ? 'mt-2 border rounded-3 bg-body p-2'
          : 'position-absolute top-100 start-0 z-3 mt-1 border rounded-3 shadow bg-body p-2'
      }
    >
      {grid !== null && (
        <>
          {narrow && picker.count === 'range' && (
            <div className="row g-2 mb-2">
              {endpoints.map(({ edge, snapshot }) => (
                <div key={edge} className="col-6">
                  <button
                    type="button"
                    className={`btn btn-sm w-100 text-start ${
                      calendar.activeEdge === edge
                        ? 'btn-outline-primary active'
                        : 'btn-outline-secondary'
                    }`}
                    aria-pressed={calendar.activeEdge === edge}
                    onClick={(event) => {
                      if (!isCurrent(event.currentTarget)) return
                      picker.calendar.setActiveEdge(edge)
                    }}
                  >
                    <span className="d-block small opacity-75">
                      {picker.labels[edge === 'start' ? 'from' : 'to']}
                    </span>
                    <span className="small">{endpointText(snapshot)}</span>
                  </button>
                </div>
              ))}
            </div>
          )}
          <div className="d-flex align-items-center gap-1 mb-1">
            <button
              type="button"
              className={
                narrow
                  ? 'btn btn-lg btn-outline-secondary'
                  : 'btn btn-sm btn-link text-body-secondary'
              }
              aria-label={picker.labels.previousMonth}
              disabled={!grid.canPreviousMonth}
              onClick={(event) => {
                if (!isCurrent(event.currentTarget)) return
                picker.calendar.showPreviousMonth()
              }}
            >
              <i className="bi bi-chevron-left" aria-hidden="true" />
            </button>
            <div
              id={`${id}-month`}
              className="flex-grow-1 text-center fw-semibold small"
              aria-live="polite"
            >
              {grid.title}
            </div>
            <button
              type="button"
              className={
                narrow
                  ? 'btn btn-lg btn-outline-secondary'
                  : 'btn btn-sm btn-link text-body-secondary'
              }
              aria-label={picker.labels.nextMonth}
              disabled={!grid.canNextMonth}
              onClick={(event) => {
                if (!isCurrent(event.currentTarget)) return
                picker.calendar.showNextMonth()
              }}
            >
              <i className="bi bi-chevron-right" aria-hidden="true" />
            </button>
          </div>
          <table
            className="table table-sm table-borderless text-center mb-1"
            role="grid"
            aria-labelledby={`${id}-month`}
          >
            <thead>
              <tr>
                {grid.weekdays.map((weekday) => (
                  <th
                    key={weekday.weekday}
                    scope="col"
                    className={[
                      'fw-normal small text-body-secondary px-0 py-1',
                      weekday.weekend ? 'bg-body-tertiary' : '',
                    ]
                      .filter(Boolean)
                      .join(' ')}
                    aria-label={weekday.full}
                  >
                    {weekday.short}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {grid.weeks.map((week, row) => (
                <tr key={row}>
                  {week.map((day, column) =>
                    day === null ? (
                      <td
                        key={`empty-${column}`}
                        className={[
                          'p-0',
                          grid.weekdays[column]?.weekend
                            ? 'bg-body-tertiary'
                            : '',
                        ]
                          .filter(Boolean)
                          .join(' ')}
                      />
                    ) : (
                      <td
                        key={day.date}
                        role="presentation"
                        className={[
                          'p-0',
                          day.span === 'inside'
                            ? 'bg-primary-subtle'
                            : day.span === 'preview' ||
                                day.span === 'preview-end'
                              ? 'bg-secondary-subtle'
                              : day.weekend
                                ? 'bg-body-tertiary'
                                : '',
                        ]
                          .filter(Boolean)
                          .join(' ')}
                      >
                        <button
                          type="button"
                          role="gridcell"
                          className={[
                            'btn w-100',
                            narrow ? 'btn-lg px-0' : 'btn-sm px-2',
                            day.selected ? 'btn-primary' : '',
                            !day.selected && day.disabled
                              ? 'text-body-tertiary'
                              : '',
                            !day.selected &&
                            (day.today || day.span === 'preview-end')
                              ? 'border-primary'
                              : '',
                          ]
                            .filter(Boolean)
                            .join(' ')}
                          tabIndex={day.focusTarget ? 0 : -1}
                          aria-label={day.label}
                          aria-selected={day.selected || undefined}
                          aria-disabled={day.disabled || blocked || undefined}
                          aria-current={day.today ? 'date' : undefined}
                          onClick={(event) => {
                            if (!isCurrent(event.currentTarget)) return
                            picker.calendar.selectDay(day.date)
                          }}
                          onPointerOver={(event) => {
                            if (!isCurrent(event.currentTarget)) return
                            picker.calendar.hoverDay(day.date)
                          }}
                          onKeyDown={onDayKeyDown}
                          onKeyUp={onDayKeyUp}
                        >
                          {day.day}
                        </button>
                      </td>
                    ),
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </>
      )}
      {timeRows.map(({ edge, label, parts, invalid }) => (
        <div
          key={edge}
          className={[
            'd-flex align-items-center gap-2',
            picker.kind === 'time' ? 'py-1' : 'border-top pt-2 mt-1',
          ].join(' ')}
        >
          {label !== null && (
            <span className="small text-body-secondary flex-grow-1">
              {label}
            </span>
          )}
          <div
            className={[
              'd-flex align-items-center gap-1',
              label === null ? 'mx-auto' : '',
            ]
              .filter(Boolean)
              .join(' ')}
          >
            {parts.map((part) => (
              <div key={part.part} className="d-flex align-items-center gap-1">
                {(part.part === 'minute' || part.part === 'second') && (
                  <span className="text-body-secondary" aria-hidden="true">
                    :
                  </span>
                )}
                <div
                  className={[
                    'd-flex flex-column align-items-center',
                    narrow ? 'gap-1' : '',
                  ]
                    .filter(Boolean)
                    .join(' ')}
                >
                  <button
                    type="button"
                    tabIndex={-1}
                    className={
                      narrow
                        ? 'btn btn-lg btn-outline-secondary'
                        : 'btn btn-link btn-sm p-0 lh-1 text-body-secondary'
                    }
                    aria-label={`${picker.labels.increase} ${partLabel(edge, part.label)}`}
                    disabled={blocked}
                    onClick={(event) => stepPart(event, edge, part.part, 1)}
                  >
                    <i className="bi bi-chevron-up" aria-hidden="true" />
                  </button>
                  <input
                    type="text"
                    role="spinbutton"
                    autoComplete="off"
                    inputMode={dateTimePickerPartInputMode(part.part)}
                    spellCheck={false}
                    size={Math.max(part.text.length, part.placeholder.length)}
                    value={part.text || part.placeholder}
                    ref={partInputRef(edge, part.part, isCurrent)}
                    className={[
                      'border-0 text-center',
                      narrow
                        ? 'form-control form-control-lg w-auto px-2 py-2'
                        : 'p-0',
                      focused?.edge === edge && focused.part === part.part
                        ? 'text-bg-primary'
                        : part.text === ''
                          ? 'bg-transparent text-body-tertiary'
                          : 'bg-transparent text-body',
                    ].join(' ')}
                    aria-label={partLabel(edge, part.label)}
                    aria-valuemin={part.valueMin}
                    aria-valuemax={part.valueMax}
                    aria-valuenow={part.valueNow ?? undefined}
                    aria-valuetext={part.valueText}
                    aria-invalid={invalid || undefined}
                    aria-describedby={description(edge)}
                    disabled={blocked}
                    onChange={ignoreChange}
                    onFocus={(event) => {
                      if (!isCurrent(event.currentTarget)) return
                      setFocused({ edge, part: part.part })
                    }}
                    onBlur={(event) => leavePart(event, edge, part.part)}
                    onKeyDown={(event) =>
                      onPanelPartKeyDown(event, edge, part.part)
                    }
                  />
                  <button
                    type="button"
                    tabIndex={-1}
                    className={
                      narrow
                        ? 'btn btn-lg btn-outline-secondary'
                        : 'btn btn-link btn-sm p-0 lh-1 text-body-secondary'
                    }
                    aria-label={`${picker.labels.decrease} ${partLabel(edge, part.label)}`}
                    disabled={blocked}
                    onClick={(event) => stepPart(event, edge, part.part, -1)}
                  >
                    <i className="bi bi-chevron-down" aria-hidden="true" />
                  </button>
                </div>
              </div>
            ))}
          </div>
        </div>
      ))}
      {endpoints.map(({ edge, snapshot }) => (
        <div key={edge}>
          {snapshot.reason !== null && (
            <div
              className={[
                'small pt-2',
                isInvalid(snapshot) ? 'text-danger-emphasis' : '',
              ]
                .filter(Boolean)
                .join(' ')}
            >
              {snapshot.reason}
            </div>
          )}
          {snapshot.offsetChoices.length > 0 && (
            <div
              className={`mb-2 btn-group-vertical w-100 ${
                narrow ? 'btn-group-lg' : 'btn-group-sm'
              }`}
              role="radiogroup"
              aria-label={partLabel(edge, picker.labels.chooseOffset)}
            >
              {snapshot.offsetChoices.map((choice, index) => (
                <Fragment key={choice.epochMs}>
                  <input
                    id={`${id}-${edge}-offset-${index}`}
                    type="radio"
                    className="btn-check"
                    name={`${id}-${edge}-offset`}
                    checked={choice.selected}
                    disabled={blocked}
                    onChange={(event) => {
                      if (!isCurrent(event.currentTarget)) return
                      picker.selectOffset(edge, choice.epochMs)
                    }}
                  />
                  <label
                    className="btn btn-outline-secondary"
                    htmlFor={`${id}-${edge}-offset-${index}`}
                  >
                    {offsetText(index, choice.text)}
                  </label>
                </Fragment>
              ))}
            </div>
          )}
        </div>
      ))}
      {narrow && (
        <button
          type="button"
          className="btn btn-lg btn-outline-secondary w-100 mt-2"
          onClick={(event) => {
            if (!isCurrent(event.currentTarget)) return
            picker.calendar.close()
          }}
        >
          {picker.labels.done}
        </button>
      )}
      {picker.count === 'range' && (
        <span className="visually-hidden" aria-live="polite">
          {`${picker.labels.chooseDate}: ${picker.labels[calendar.activeEdge === 'start' ? 'from' : 'to']}`}
        </span>
      )}
    </div>
  )
}
