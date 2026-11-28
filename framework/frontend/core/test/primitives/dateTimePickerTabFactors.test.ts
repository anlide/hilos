import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { hilosTabPickerFactors } from '../../src/primitives/dateTimePickerTabFactors.js'
import { bindReader, type ReaderFixture } from '../format/readerFixture.js'

let reader: ReaderFixture

beforeEach(() => {
  reader = bindReader()
})

afterEach(() => {
  reader.release()
})

describe('hilosTabPickerFactors', () => {
  it.each([
    ['DD.MM.YYYY', ['day', 'month', 'year'], '.'],
    ['DD/MM/YYYY', ['day', 'month', 'year'], '/'],
    ['DD-MM-YYYY', ['day', 'month', 'year'], '-'],
    ['YYYY.MM.DD', ['year', 'month', 'day'], '.'],
    ['YYYY/MM/DD', ['year', 'month', 'day'], '/'],
    ['YYYY-MM-DD', ['year', 'month', 'day'], '-'],
    ['MM/DD/YYYY', ['month', 'day', 'year'], '/'],
  ])(
    'reads the date order and separator of %s',
    (date, dateOrder, dateSeparator) => {
      reader.region({ date })
      expect(hilosTabPickerFactors()).toEqual({
        dateOrder,
        dateSeparator,
        hourCycle: 'h23',
      })
    },
  )

  it.each([
    ['HH:mm:ss', 'h23'],
    ['hh:mm:ss a', 'h12'],
  ])('reads the hour cycle of %s', (time, hourCycle) => {
    reader.region({ time })
    expect(hilosTabPickerFactors().hourCycle).toBe(hourCycle)
  })

  it('reads the built-in formats before the tab knows a region', () => {
    expect(hilosTabPickerFactors()).toEqual({
      dateOrder: ['day', 'month', 'year'],
      dateSeparator: '.',
      hourCycle: 'h23',
    })
  })

  it('reads the region at the moment it is asked, not later', () => {
    reader.region({ date: 'MM/DD/YYYY', time: 'hh:mm:ss a' })
    const taken = hilosTabPickerFactors()
    reader.region({ date: 'YYYY-MM-DD' })
    expect(taken).toEqual({
      dateOrder: ['month', 'day', 'year'],
      dateSeparator: '/',
      hourCycle: 'h12',
    })
    expect(hilosTabPickerFactors().dateOrder).toEqual(['year', 'month', 'day'])
  })
})
