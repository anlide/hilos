import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import {
  HILOS_DEFAULT_READER_FORMATS,
  hilosReaderDisplay,
  type HilosReaderDisplay,
} from '../../src/format/readerDisplay.js'
import { subscribeSignal, type Unsubscribe } from '../../src/state/signal.js'
import { bindReader, type ReaderFixture } from './readerFixture.js'

let reader: ReaderFixture
let seen: HilosReaderDisplay[]
let stop: Unsubscribe

beforeEach(() => {
  reader = bindReader()
  reader.choose('UTC')
  seen = []
  stop = subscribeSignal(hilosReaderDisplay, (value) => seen.push(value))
})

afterEach(() => {
  stop()
  reader.release()
})

describe('hilosReaderDisplay', () => {
  it('holds the built-in English formats without a country until the tab knows a region', () => {
    expect(hilosReaderDisplay.get()).toEqual({
      timeZone: 'UTC',
      formats: HILOS_DEFAULT_READER_FORMATS,
    })
    expect(HILOS_DEFAULT_READER_FORMATS).toEqual({
      date: 'DD.MM.YYYY',
      time: 'HH:mm:ss',
      number: '1,000.00',
      phone: '+X (XXX) XXX-XXXX',
      address: 'House, Street, State, Index',
      measurement: 'metric',
      collation: 'und',
      firstWeekday: 1,
    })
    expect(Object.isFrozen(HILOS_DEFAULT_READER_FORMATS)).toBe(true)
  })

  it('changes when the reader zone changes', () => {
    reader.choose('Europe/Kyiv')
    expect(seen).toHaveLength(1)
    expect(hilosReaderDisplay.get().timeZone).toBe('Europe/Kyiv')
    expect(hilosReaderDisplay.get().formats).toBe(HILOS_DEFAULT_READER_FORMATS)
  })

  it('changes on a new tabLanguage node and keeps the zone', () => {
    reader.choose('Asia/Kathmandu')
    reader.region({ date: 'MM/DD/YYYY', time: 'hh:mm:ss a' })
    expect(hilosReaderDisplay.get()).toEqual({
      timeZone: 'Asia/Kathmandu',
      formats: {
        ...HILOS_DEFAULT_READER_FORMATS,
        date: 'MM/DD/YYYY',
        time: 'hh:mm:ss a',
      },
    })
    reader.region({ number: '1.000,00' })
    expect(hilosReaderDisplay.get().formats.number).toBe('1.000,00')
    expect(hilosReaderDisplay.get().formats.date).toBe('DD.MM.YYYY')
    expect(seen).toHaveLength(3)
  })

  it('falls back to the built-in formats when the node turns null', () => {
    reader.region({ date: 'YYYY-MM-DD' })
    reader.region(null)
    expect(hilosReaderDisplay.get().formats).toBe(HILOS_DEFAULT_READER_FORMATS)
    expect(seen.map((display) => display.formats.date)).toEqual([
      'YYYY-MM-DD',
      'DD.MM.YYYY',
    ])
  })

  it('wakes nobody on an unchanged answer', () => {
    reader.region({ date: 'YYYY-MM-DD' })
    reader.region({ date: 'YYYY-MM-DD' })
    reader.choose('UTC')
    expect(seen).toHaveLength(1)
  })
})
