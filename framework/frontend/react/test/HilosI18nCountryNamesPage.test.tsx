import { act } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { HilosPages } from '@hilos/core'
import { HilosI18nCountryNamesPage } from '../src/admin/i18n/details/HilosI18nCountryNamesPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'
import {
  harness,
  countryCard,
  el,
  click,
  change,
  settle,
} from './hilosI18nHarness.js'
import { nameRow } from './hilosI18nDetailsHarness.js'

function setup(viewer = false) {
  const rows = [
    nameRow,
    { ...nameRow, code: 'fr', name: null, corrections: [] },
    { ...nameRow, code: 'es', name: '', corrections: [] },
  ]
  const h = harness(HilosPages.I18N_COUNTRY_NAMES, rows, viewer)
  const view = h.mount(<HilosI18nCountryNamesPage context={h.context} />)
  return { ...h, view }
}

describe('React country names page', () => {
  it('waits for the card, draws live counts and tabs, then distinguishes its deletion', () => {
    const h = setup()
    expect(el('country-card-header')).toBeNull()
    expect(el('hilos-viewport-table')).toBeNull()
    expect(
      document.querySelector('[aria-label="Loading country"]'),
    ).not.toBeNull()
    act(() => h.card())
    expect(document.querySelectorAll('h1')).toHaveLength(1)
    expect(
      el('country-card-tab-hilos_i18n_country_names').getAttribute(
        'aria-current',
      ),
    ).toBe('page')
    expect(el('country-card-tab-hilos_i18n_country').getAttribute('href')).toBe(
      '/hilos/i18n/countries/gg',
    )
    act(() =>
      h.card({
        ...countryCard,
        enabled: true,
        summary: {
          ...countryCard.summary,
          name: 'Live country',
          defaultLocaleCode: 'en-GG',
          nameCount: 5,
        },
      }),
    )
    expect(el('country-card-title').textContent).toBe('Live country')
    expect(el('country-card-line').textContent).toContain('en-GG')
    expect(document.body.textContent).not.toMatch(/Preview|Synchroniz|Flags/)
    act(() => h.card(null))
    expect(el('country-names-unavailable')).not.toBeNull()
    expect(el('hilos-viewport-table')).toBeNull()
    expect(h.calls).toEqual([])
  })
  it('renders add/edit/delete by null for bases and corrections in both projections', () => {
    const h = setup()
    act(() => h.card())
    click('hilos-table-expand-en')
    for (const projection of [
      document.querySelector('table')!,
      el('hilos-table-cards'),
    ]) {
      for (const id of [
        'i18n-country-name-edit-en',
        'i18n-country-name-edit-es',
        'i18n-country-name-add-fr',
        'i18n-country-name-locale-edit-en-GB',
        'i18n-country-name-locale-edit-en-US',
        'i18n-country-name-locale-add-en-CA',
        'i18n-names-delete-es',
        'i18n-names-delete-en-US',
      ])
        expect(projection.querySelector(`[data-id="${id}"]`)).not.toBeNull()
      expect(
        projection.querySelector('[data-id="i18n-names-delete-fr"]'),
      ).toBeNull()
      expect(
        projection.querySelector('[data-id="i18n-names-delete-en-CA"]'),
      ).toBeNull()
    }
  })
  it('opens a correction beneath an empty base and coordinates its shared focus with deletion', async () => {
    const h = setup()
    act(() => {
      h.card()
      h.delta({ ...nameRow, name: '' })
    })
    click('hilos-table-expand-en')
    click('i18n-country-name-locale-edit-en-US')
    expect(el('i18n-country-name-title').textContent).toContain(
      'Edit locale correction',
    )
    expect(h.focuses).toContain('en')
    await settle(() => click('i18n-names-delete-en'))
    expect(el('i18n-country-name-window')).toBeNull()
    expect(el('i18n-country-name-delete-window')).not.toBeNull()
    click('i18n-country-name-edit-es')
    expect(el('i18n-country-name-delete-window')).toBeNull()
    expect(el('i18n-country-name-window')).not.toBeNull()
    expect(h.focuses.at(-1)).toBe('es')
  })
  it('freezes the fresh focused text after await and keeps newer values for the server refusal', async () => {
    const h = setup()
    act(() => h.card())
    await settle(() => {
      click('i18n-names-delete-en')
      h.delta({ ...nameRow, name: 'Fresh' })
    })
    expect(el('i18n-country-name-delete-window').textContent).toContain('Fresh')
    act(() => h.delta({ ...nameRow, name: 'Newer' }))
    click('i18n-country-name-delete-confirm')
    expect(h.calls[0]?.payload).toMatchObject({ expectedName: 'Fresh' })
    await settle(() => h.refuse(0, 'The name changed'))
    expect(el('i18n-country-name-delete-error').textContent).toContain(
      'The name changed',
    )
    click('i18n-country-name-delete-cancel')
    expect(h.focuses.at(-1)).toBe('')
  })
  it('cancels a late delete opening without releasing a newly opened name', async () => {
    const h = setup()
    act(() => h.card())
    await settle(() => {
      click('i18n-names-delete-en')
      click('i18n-country-name-edit-es')
    })
    expect(el('i18n-country-name-delete-window')).toBeNull()
    expect(el('i18n-country-name-window')).not.toBeNull()
    expect(h.focuses.at(-1)).toBe('es')
  })
  it.each(['idle', 'pending'] as const)(
    'keeps a %s draft or confirmation through card deletion and cleans up on subject change',
    async (mode) => {
      const h = setup()
      act(() => h.card())
      if (mode === 'idle') {
        click('i18n-country-name-edit-en')
        change('i18n-country-name-input', 'Copy me')
      } else {
        await settle(() => click('i18n-names-delete-en'))
        click('i18n-country-name-delete-confirm')
      }
      act(() => h.card(null))
      if (mode === 'idle') {
        expect(el<HTMLInputElement>('i18n-country-name-input').value).toBe(
          'Copy me',
        )
        expect(el('i18n-country-name-submit').textContent).toBe('Deleted')
      } else
        expect(
          el<HTMLButtonElement>('i18n-country-name-delete-cancel').disabled,
        ).toBe(true)
      act(() => {
        h.route.set({
          page: HilosPages.I18N_COUNTRY_NAMES,
          params: { countryCode: 'fr' },
          admin: true,
        })
        h.card({ ...countryCard, code: 'fr' })
      })
      expect(el('modal')).toBeNull()
      expect(h.unregister).toHaveBeenCalled()
      expect(h.sendViewport).toHaveBeenLastCalledWith(
        HilosPages.I18N_COUNTRY_NAMES,
        'hilosI18nCountryNames',
        expect.objectContaining({ filter: { country: 'fr' } }),
      )
      click('i18n-country-name-edit-en')
      if (mode === 'pending') await settle(() => h.succeed())
      expect(el('i18n-country-name-window')).not.toBeNull()
      h.view.unmount()
      expect(h.focuses.at(-1)).toBe('')
      for (const [event, listeners] of h.listeners)
        expect(listeners.size).toBe(h.baseListenerCounts.get(event) ?? 0)
    },
  )
  it('abandons an awaited focus from the former subject without disturbing the new form', async () => {
    const h = setup()
    act(() => h.card())
    await settle(() => {
      click('i18n-names-delete-en')
      h.route.set({
        page: HilosPages.I18N_COUNTRY_NAMES,
        params: { countryCode: 'fr' },
        admin: true,
      })
      h.card({ ...countryCard, code: 'fr' })
    })
    expect(el('i18n-country-name-delete-window')).toBeNull()
    click('i18n-country-name-edit-en')
    change('i18n-country-name-input', 'New subject')
    click('i18n-country-name-submit')
    expect(h.calls[0]?.payload).toMatchObject({
      countryCode: 'fr',
      name: 'New subject',
    })
    expect(h.focuses.at(-1)).toBe('en')
    await settle(() => h.succeed())
    expect(el('modal')).toBeNull()
  })

  it('releases the old context and keeps its late failure out of the new opening', async () => {
    const h = setup()
    act(() => h.card())
    click('i18n-country-name-edit-en')
    change('i18n-country-name-input', 'Old')
    click('i18n-country-name-submit')
    const next = harness(HilosPages.I18N_COUNTRY_NAMES, [nameRow])
    next.card()
    h.view.rerender(
      <HilosRouterContext.Provider value={h.navigation}>
        <HilosI18nCountryNamesPage context={next.context} />
      </HilosRouterContext.Provider>,
    )
    click('i18n-country-name-edit-en')
    change('i18n-country-name-input', 'New')
    await settle(() => h.refuse(0, 'Old failure'))
    expect(el<HTMLInputElement>('i18n-country-name-input').value).toBe('New')
    expect(el('i18n-country-name-error').textContent).not.toContain(
      'Old failure',
    )
    expect(h.unregister).toHaveBeenCalled()
  })
  it('lets a viewer read the form and correction while every write remains disabled', () => {
    const h = setup(true)
    act(() => h.card())
    expect(el<HTMLButtonElement>('i18n-names-delete-en').disabled).toBe(true)
    click('hilos-table-expand-en')
    click('i18n-country-name-locale-edit-en-US')
    change('i18n-country-name-input', 'Viewer')
    click('i18n-country-name-submit')
    expect(el<HTMLButtonElement>('i18n-country-name-submit').disabled).toBe(
      true,
    )
    expect(h.calls).toEqual([])
  })
  it('uses the controller pending/Apply gate and its refusal state', () => {
    const h = setup()
    act(() => h.card())
    act(() => h.delta(nameRow, 'row_removed'))
    expect(el('hilos-table-row-en').textContent).toContain('English')
    expect(el('hilos-table-apply')).not.toBeNull()
    click('hilos-table-apply')
    expect(el('hilos-table-row-en').textContent).toContain('Removed')
    act(() =>
      h.emit('tableWindowRefused', {
        data: {
          page: HilosPages.I18N_COUNTRY_NAMES,
          tableKey: h.tableKey,
          errorCode: 'source_unavailable',
        },
      }),
    )
    expect(el('hilos-table-unavailable')).not.toBeNull()
  })
  it('draws the immediate catalog base return, removes corrections and waits for the deletion ack', async () => {
    const h = setup()
    act(() => h.card({ ...countryCard, code: 'gg' }))
    await settle(() => click('i18n-names-delete-en'))
    expect(el('i18n-country-name-delete-window').textContent).toContain(
      'returns immediately as an unlocked name',
    )
    click('i18n-country-name-delete-confirm')
    act(() => h.delta({ ...nameRow, name: 'Catalog base', corrections: [] }))
    expect(el('hilos-table-row-en').textContent).toContain('Catalog base')
    expect(el('i18n-country-name-delete-window')).not.toBeNull()
    expect(h.calls[0]?.payload).toEqual({
      countryCode: 'gg',
      inLanguageCode: 'en',
      expectedName: 'Ukrainian',
    })
    await settle(() => h.succeed())
    expect(el('modal')).toBeNull()
    expect(el('i18n-country-name-edit-en')).not.toBeNull()
    expect(el('hilos-table-expand-en')).toBeNull()
  })
  it('shows an absent custom base after deletion and inherits the base after removing a correction', async () => {
    const h = setup()
    act(() => h.card())
    click('hilos-table-expand-en')
    await settle(() => click('i18n-names-delete-en-US'))
    click('i18n-country-name-delete-confirm')
    act(() =>
      h.delta({
        ...nameRow,
        corrections: nameRow.corrections.map((item) =>
          item.localeCode === 'en-US' ? { ...item, name: null } : item,
        ),
      }),
    )
    await settle(() => h.succeed())
    expect(el('i18n-country-name-locale-add-en-US')).not.toBeNull()
    expect(el('i18n-names-delete-en-US')).toBeNull()
    expect(el('hilos-table-row-en').textContent).toContain('Ukrainian')
    await settle(() => click('i18n-names-delete-en'))
    click('i18n-country-name-delete-confirm')
    act(() => h.delta({ ...nameRow, name: null, corrections: [] }))
    await settle(() => h.succeed(1))
    expect(el('i18n-country-name-add-en')).not.toBeNull()
    expect(el('i18n-names-delete-en')).toBeNull()
  })
})
