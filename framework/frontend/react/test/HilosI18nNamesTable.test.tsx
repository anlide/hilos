import { act } from '@testing-library/react'
import { afterEach, describe, expect, it } from 'vitest'
import {
  createHilosI18nNamesTable,
  createSignal,
  HILOS_I18N_COUNTRY_NAMES,
  HILOS_I18N_LANGUAGE_NAMES,
} from '@hilos/core'
import { HilosI18nNamesTable } from '../src/admin/i18n/details/HilosI18nNamesTable.js'
import { harness, el, click } from './hilosI18nHarness.js'
import { nameRow } from './hilosI18nDetailsHarness.js'

const disposers: (() => void)[] = []
afterEach(() => {
  for (const dispose of disposers.splice(0)) dispose()
})

function setup(country = false, actions = false) {
  const target = country ? HILOS_I18N_COUNTRY_NAMES : HILOS_I18N_LANGUAGE_NAMES
  const rows = [
    nameRow,
    {
      inLanguageId: 13,
      nameId: null,
      rowKey: '13',
      code: 'fr',
      nativeName: 'Français',
      name: null,
      corrections: [],
    },
    {
      inLanguageId: 15,
      nameId: 301,
      rowKey: '15',
      code: 'es',
      nativeName: 'Español',
      name: '',
      corrections: [],
    },
  ]
  const h = harness(target.page, rows)
  const table = createHilosI18nNamesTable(h.context, target, createSignal(19))
  table.start()
  disposers.push(table.dispose)
  const view = h.mount(
    <HilosI18nNamesTable
      controller={table.controller}
      nameActions={
        actions
          ? (row) => (
              <span data-id={`test-base-${row.code}`}>
                {row.name ?? 'absent'}
              </span>
            )
          : undefined
      }
      correctionActions={
        actions
          ? (row, correction) => (
              <span data-id={`test-correction-${correction.localeCode}`}>
                {row.code}:{correction.name ?? 'inherit'}
              </span>
            )
          : undefined
      }
    />,
  )
  return { ...h, table, view }
}

describe('React shared names table', () => {
  it('labels absent and saved empty base values differently and exposes only populated panels', () => {
    setup()
    expect(el('hilos-table-row-11').textContent).toContain('English')
    expect(
      el('hilos-table-row-11').querySelector('.text-uppercase')?.textContent,
    ).toBe('en')
    expect(el('i18n-names-none-fr').textContent).toBe('No name')
    expect(el('i18n-names-none-es')).toBeNull()
    expect(el('i18n-names-none-en')).toBeNull()
    expect(el('hilos-table-expand-11').getAttribute('aria-expanded')).toBe(
      'false',
    )
    expect(el('hilos-table-expand-13')).toBeNull()
    expect(el('hilos-table-expand-15')).toBeNull()
  })
  it.each([false, true])(
    'draws null, empty and nonempty corrections in table and cards (country %s)',
    (country) => {
      setup(country, true)
      click('hilos-table-expand-11')
      const panels = document.querySelectorAll(
        '[data-id="hilos-table-row-detail-11"]',
      )
      expect(panels).toHaveLength(2)
      for (const panel of panels) {
        expect(
          panel.querySelector('[data-id="i18n-names-correction-en-GB"]')
            ?.textContent,
        ).toContain('United KingdomCorrection')
        expect(
          panel.querySelector('[data-id="i18n-names-correction-en-US"]')
            ?.textContent,
        ).toContain('US')
        expect(
          panel.querySelector('[data-id="i18n-names-inherit-en-US"]'),
        ).toBeNull()
        expect(
          panel.querySelector('[data-id="i18n-names-inherit-en-CA"]')
            ?.textContent,
        ).toBe('as in “English”')
        expect(
          panel.querySelector('[data-id="test-correction-en-US"]')?.textContent,
        ).toBe('en:')
      }
    },
  )
  it('leaves optional actions empty and applies a saved empty value live in both projections', () => {
    const h = setup(true)
    click('hilos-table-expand-11')
    expect(el('test-base-en')).toBeNull()
    act(() =>
      h.delta({
        ...nameRow,
        name: '',
        corrections: nameRow.corrections.map((row) => ({ ...row, name: '' })),
      }),
    )
    expect(el('i18n-names-none-en')).toBeNull()
    expect(el('i18n-names-inherit-en-CA')).toBeNull()
    expect(
      document.querySelectorAll('[data-id="hilos-table-row-detail-11"]'),
    ).toHaveLength(2)
    h.view.unmount()
    h.table.dispose()
  })
})
