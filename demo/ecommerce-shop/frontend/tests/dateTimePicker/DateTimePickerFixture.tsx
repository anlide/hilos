import { useEffect, useRef, useState } from 'react'
import type React from 'react'
import {
  createDateTimePickerController,
  createSignal,
  DEFAULT_DATE_TIME_PICKER_FACTORS,
  type DateTimePickerController,
  type DateTimePickerCount,
  type DateTimePickerFactors,
  type DateTimePickerKind,
  type WritableSignal,
} from '@hilos/core/base'
import { HilosDateTimePicker, useSignal } from '@hilos/react/base'

// Explicit examples of presentation factors; the browser locale supplies none.
const FACTORS = {
  'en-GB': {
    ...DEFAULT_DATE_TIME_PICKER_FACTORS,
    weekendDays: [6, 0],
  },
  'en-US': {
    ...DEFAULT_DATE_TIME_PICKER_FACTORS,
    firstWeekday: 0,
    weekendDays: [6, 0],
    dateOrder: ['month', 'day', 'year'],
    hourCycle: 'h12',
  },
  'pl-PL': {
    ...DEFAULT_DATE_TIME_PICKER_FACTORS,
    weekendDays: [6, 0],
    dateSeparator: '.',
    placeholders: {
      ...DEFAULT_DATE_TIME_PICKER_FACTORS.placeholders,
      year: 'rrrr',
    },
    monthNames: [
      { short: 'sty', full: 'styczeń' },
      { short: 'lut', full: 'luty' },
      { short: 'mar', full: 'marzec' },
      { short: 'kwi', full: 'kwiecień' },
      { short: 'maj', full: 'maj' },
      { short: 'cze', full: 'czerwiec' },
      { short: 'lip', full: 'lipiec' },
      { short: 'sie', full: 'sierpień' },
      { short: 'wrz', full: 'wrzesień' },
      { short: 'paź', full: 'październik' },
      { short: 'lis', full: 'listopad' },
      { short: 'gru', full: 'grudzień' },
    ],
    weekdayNames: [
      { short: 'niedz', full: 'niedziela' },
      { short: 'pon', full: 'poniedziałek' },
      { short: 'wt', full: 'wtorek' },
      { short: 'śr', full: 'środa' },
      { short: 'czw', full: 'czwartek' },
      { short: 'pt', full: 'piątek' },
      { short: 'sob', full: 'sobota' },
    ],
  },
  'en-IL': {
    ...DEFAULT_DATE_TIME_PICKER_FACTORS,
    firstWeekday: 0,
    weekendDays: [5, 6],
  },
} satisfies Record<string, DateTimePickerFactors>

// React's checkbox onChange follows click. A parent update dispatches a
// native change and does not move focus; this listener writes the same signal.
function onFixtureLockChange(
  signal: WritableSignal<boolean>,
  event: Event,
): void {
  if (!(event.currentTarget instanceof HTMLInputElement)) return
  signal.set(event.currentTarget.checked)
}

type FixturePicker = DateTimePickerController<
  DateTimePickerKind,
  DateTimePickerCount
>

/**
 * Standalone test fixture for HilosDateTimePicker end-to-end tests.
 *
 * @returns The rendered date-time picker fixture surface.
 */
export function DateTimePickerFixture(): React.JSX.Element {
  const [kind, setKind] = useState<DateTimePickerKind>('datetime')
  const [count, setCount] = useState<DateTimePickerCount>('single')
  const [factors, setFactors] = useState<keyof typeof FACTORS>('en-GB')
  const [zone, setZone] = useState('Europe/Warsaw')
  const [today, setToday] = useState('2026-10-13')
  const [minDate, setMinDate] = useState('')
  const [seconds, setSeconds] = useState(false)
  const [changes, setChanges] = useState(0)
  const [mountId, setMountId] = useState(0)

  const [busySignal] = useState(() => createSignal(false))
  const [disabledSignal] = useState(() => createSignal(false))
  const busy = useSignal(busySignal)
  const disabled = useSignal(disabledSignal)
  const busyInputRef = useRef<HTMLInputElement>(null)
  const disabledInputRef = useRef<HTMLInputElement>(null)

  function createPicker(params: {
    kind: DateTimePickerKind
    count: DateTimePickerCount
    factors: keyof typeof FACTORS
    zone: string
    today: string
    minDate: string
    seconds: boolean
  }): FixturePicker {
    const options = {
      count: params.count,
      value: null,
      factors: FACTORS[params.factors],
      showSeconds: params.seconds,
      busy: () => busySignal.get(),
      disabled: () => disabledSignal.get(),
      onChange: () => setChanges((v) => v + 1),
    }
    const minimum = params.minDate === '' ? {} : { minDate: params.minDate }
    switch (params.kind) {
      case 'date':
        return createDateTimePickerController({
          ...options,
          kind: 'date',
          today: () => params.today,
          ...minimum,
        })
      case 'time':
        return createDateTimePickerController({
          ...options,
          kind: 'time',
        })
      case 'datetime':
        return createDateTimePickerController({
          ...options,
          kind: 'datetime',
          timeZone: params.zone,
          today: () => params.today,
          ...minimum,
        })
    }
  }

  const [picker, setPicker] = useState<FixturePicker>(() =>
    createPicker({
      kind: 'datetime',
      count: 'single',
      factors: 'en-GB',
      zone: 'Europe/Warsaw',
      today: '2026-10-13',
      minDate: '',
      seconds: false,
    }),
  )

  useEffect(() => () => picker.dispose(), [picker])

  useEffect(() => {
    const busyInput = busyInputRef.current
    const disabledInput = disabledInputRef.current
    if (busyInput === null || disabledInput === null) return

    const onBusyChange = (event: Event): void => {
      onFixtureLockChange(busySignal, event)
    }
    const onDisabledChange = (event: Event): void => {
      onFixtureLockChange(disabledSignal, event)
    }
    busyInput.addEventListener('change', onBusyChange)
    disabledInput.addEventListener('change', onDisabledChange)
    return () => {
      busyInput.removeEventListener('change', onBusyChange)
      disabledInput.removeEventListener('change', onDisabledChange)
    }
  }, [busySignal, disabledSignal])

  const pickerSnapshot = useSignal(picker.state)
  const label = kind === 'datetime' ? `Starts, ${zone}` : 'Starts'
  const canLoad = kind === 'datetime' && zone !== 'Nowhere/Zone'

  function loadValue(): void {
    if (canLoad) {
      picker.setEndpointValue('start', 1792209600000)
    }
  }

  function mountOpenTime(): void {
    if (kind !== 'time' || count !== 'single') return
    const previous = picker.state.get().result
    const wasBusy = busySignal.get()
    const wasDisabled = disabledSignal.get()
    busySignal.set(false)
    disabledSignal.set(false)
    const current = createPicker({
      kind,
      count,
      factors,
      zone,
      today,
      minDate,
      seconds,
    })
    if (previous.status === 'valid') current.setValue(previous.value)
    current.calendar.open()
    busySignal.set(wasBusy)
    disabledSignal.set(wasDisabled)
    setPicker(current)
    setMountId((value) => value + 1)
  }

  return (
    <main className="container py-4" data-id="date-time-picker-fixture">
      <h1 className="h4 mb-4">DateTimePicker fixture</h1>
      <div className="row g-3 mb-4">
        <div className="col-sm-6 col-lg-4">
          <label className="form-label" htmlFor="fixture-kind">
            Kind
          </label>
          <select
            id="fixture-kind"
            className="form-select"
            data-id="fixture-kind"
            value={kind}
            onChange={(e) => {
              const nextKind = e.target.value as DateTimePickerKind
              setKind(nextKind)
              setPicker(
                createPicker({
                  kind: nextKind,
                  count,
                  factors,
                  zone,
                  today,
                  minDate,
                  seconds,
                }),
              )
            }}
          >
            <option value="date">date</option>
            <option value="time">time</option>
            <option value="datetime">datetime</option>
          </select>
        </div>
        <div className="col-sm-6 col-lg-4">
          <label className="form-label" htmlFor="fixture-count">
            Count
          </label>
          <select
            id="fixture-count"
            className="form-select"
            data-id="fixture-count"
            value={count}
            onChange={(e) => {
              const nextCount = e.target.value as DateTimePickerCount
              setCount(nextCount)
              setPicker(
                createPicker({
                  kind,
                  count: nextCount,
                  factors,
                  zone,
                  today,
                  minDate,
                  seconds,
                }),
              )
            }}
          >
            <option value="single">single</option>
            <option value="range">range</option>
          </select>
        </div>
        <div className="col-sm-6 col-lg-4">
          <label className="form-label" htmlFor="fixture-factors">
            Display factors
          </label>
          <select
            id="fixture-factors"
            className="form-select"
            data-id="fixture-factors"
            value={factors}
            onChange={(e) => {
              const nextFactors = e.target.value as keyof typeof FACTORS
              setFactors(nextFactors)
              picker.setFactors(FACTORS[nextFactors])
            }}
          >
            <option value="en-GB" lang="en-GB">
              English (United Kingdom)
            </option>
            <option value="en-US" lang="en-US">
              English (United States)
            </option>
            <option value="pl-PL" lang="pl-PL">
              Polski (Polska)
            </option>
            <option value="en-IL" lang="en-IL">
              English (Israel)
            </option>
          </select>
        </div>
        <div className="col-sm-6 col-lg-4">
          <label className="form-label" htmlFor="fixture-zone">
            Time zone
          </label>
          <select
            id="fixture-zone"
            className="form-select"
            data-id="fixture-zone"
            value={zone}
            onChange={(e) => {
              const nextZone = e.target.value
              setZone(nextZone)
              setPicker(
                createPicker({
                  kind,
                  count,
                  factors,
                  zone: nextZone,
                  today,
                  minDate,
                  seconds,
                }),
              )
            }}
          >
            <option value="Europe/Warsaw">Europe/Warsaw</option>
            <option value="UTC">UTC</option>
            <option value="Pacific/Apia">Pacific/Apia</option>
            <option value="Nowhere/Zone">Nowhere/Zone</option>
          </select>
        </div>
        <div className="col-sm-6 col-lg-4">
          <label className="form-label" htmlFor="fixture-today">
            Today
          </label>
          <input
            id="fixture-today"
            className="form-control"
            type="text"
            data-id="fixture-today"
            value={today}
            onChange={(e) => {
              const nextToday = e.target.value
              setToday(nextToday)
              setPicker(
                createPicker({
                  kind,
                  count,
                  factors,
                  zone,
                  today: nextToday,
                  minDate,
                  seconds,
                }),
              )
            }}
          />
        </div>
        <div className="col-sm-6 col-lg-4">
          <label className="form-label" htmlFor="fixture-min">
            Minimum day
          </label>
          <select
            id="fixture-min"
            className="form-select"
            data-id="fixture-min"
            value={minDate}
            onChange={(e) => {
              const nextMinDate = e.target.value
              setMinDate(nextMinDate)
              setPicker(
                createPicker({
                  kind,
                  count,
                  factors,
                  zone,
                  today,
                  minDate: nextMinDate,
                  seconds,
                }),
              )
            }}
          >
            <option value="">none</option>
            <option value="2026-10-13">2026-10-13</option>
          </select>
        </div>
      </div>
      <div className="d-flex flex-wrap gap-3 mb-4">
        <div className="form-check">
          <input
            id="fixture-seconds"
            type="checkbox"
            className="form-check-input"
            data-id="fixture-seconds"
            checked={seconds}
            onChange={(e) => {
              const nextSeconds = e.target.checked
              setSeconds(nextSeconds)
              picker.setShowSeconds(nextSeconds)
            }}
          />
          <label className="form-check-label" htmlFor="fixture-seconds">
            Seconds
          </label>
        </div>
        <div className="form-check">
          <input
            id="fixture-busy"
            ref={busyInputRef}
            type="checkbox"
            className="form-check-input"
            data-id="fixture-busy"
            checked={busy}
            onChange={(e) =>
              busySignal.set((e.target as HTMLInputElement).checked)
            }
          />
          <label className="form-check-label" htmlFor="fixture-busy">
            Busy
          </label>
        </div>
        <div className="form-check">
          <input
            id="fixture-disabled"
            ref={disabledInputRef}
            type="checkbox"
            className="form-check-input"
            data-id="fixture-disabled"
            checked={disabled}
            onChange={(e) =>
              disabledSignal.set((e.target as HTMLInputElement).checked)
            }
          />
          <label className="form-check-label" htmlFor="fixture-disabled">
            Disabled
          </label>
        </div>
      </div>
      <div className="mb-4">
        <HilosDateTimePicker
          key={mountId}
          picker={picker}
          id="fixture-picker"
          label={label}
          dataId="fixture-picker"
        />
      </div>
      <button
        type="button"
        className="btn btn-outline-primary"
        data-id="fixture-mount-open-time"
        disabled={kind !== 'time' || count !== 'single'}
        onClick={mountOpenTime}
      >
        Mount open time
      </button>
      <button
        type="button"
        className="btn btn-outline-primary"
        data-id="fixture-load"
        disabled={!canLoad}
        aria-describedby="fixture-load-description"
        onClick={loadValue}
      >
        Load 1792209600000
      </button>
      <p id="fixture-load-description" className="form-text">
        Load the start of a datetime field in a usable time zone.
      </p>
      <div className="mb-2">
        <label className="form-label me-2" htmlFor="fixture-result">
          Result
        </label>
        <output
          id="fixture-result"
          className="font-monospace text-break"
          data-id="fixture-result"
        >
          {JSON.stringify(pickerSnapshot.result)}
        </output>
      </div>
      <div>
        <label className="form-label me-2" htmlFor="fixture-changes">
          Changes
        </label>
        <output id="fixture-changes" data-id="fixture-changes">
          {changes}
        </output>
      </div>
    </main>
  )
}
