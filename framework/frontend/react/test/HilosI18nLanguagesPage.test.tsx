import { act, fireEvent } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { HilosPages, HILOS_I18N_LANGUAGE_DELETE_COPY } from '@hilos/core'
import { HilosI18nLanguagesPage } from '../src/admin/i18n/lists/HilosI18nLanguagesPage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'
import {
  harness,
  language,
  el,
  click,
  change,
  settle,
} from './hilosI18nHarness.js'

const english = {
  ...language,
  languageId: 11,
  rowKey: '11',
  code: 'en',
  nativeName: 'English',
  enabled: true,
  isDefault: true,
  canDelete: false,
  deleteReason: 'default' as const,
}
const own = {
  ...language,
  languageId: 46,
  rowKey: '46',
  code: 'qx',
  nativeName: 'Own tongue',
  isOwn: true,
  rtl: true,
}
function setup(rows = [english, own], viewer = false) {
  const h = harness(HilosPages.I18N_LANGUAGES, rows, viewer)
  const view = h.mount(<HilosI18nLanguagesPage context={h.context} />)
  return { ...h, view }
}

describe('React languages list', () => {
  it('uses the declared table and card projections, links codes, marks default/custom and ticks direction/state', () => {
    const h = setup()
    expect(h.view.container.querySelector('table')).not.toBeNull()
    expect(
      el<HTMLAnchorElement>('i18n-languages-link-en').getAttribute('href'),
    ).toBe('/hilos/i18n/languages/en')
    expect(el('i18n-languages-default-en').textContent).toContain('Default')
    expect(el('i18n-languages-own-qx').textContent).toContain('Custom')
    expect(el<HTMLInputElement>('i18n-languages-rtl-qx').checked).toBe(true)
    expect(el<HTMLInputElement>('i18n-languages-enabled-qx').checked).toBe(
      false,
    )
    expect(el<HTMLInputElement>('i18n-languages-enabled-qx').disabled).toBe(
      true,
    )
    expect(el('i18n-languages-link-qx').className).toContain('link-secondary')
    expect(h.view.container.querySelector('[class*="opacity"]')).toBeNull()
    expect(
      document.querySelectorAll('[data-id="i18n-languages-link-qx"]'),
    ).toHaveLength(2)
    expect(el('i18n-languages-view-en')).not.toBeNull()
    expect(el('i18n-languages-edit-qx')).not.toBeNull()
    expect(h.view.container.textContent).not.toMatch(
      /Preview|Synchronize|Translate/,
    )
  })
  it.each(['default', 'locales', 'names'] as const)(
    'shows the %s refusal with distinct descriptions in both projections',
    (deleteReason) => {
      const h = setup([{ ...own, canDelete: false, deleteReason }])
      const controls = [
        ...h.view.container.querySelectorAll<HTMLButtonElement>(
          '[data-id="i18n-languages-delete-qx"]',
        ),
      ]
      const ids = controls.map((control) =>
        control.getAttribute('aria-describedby'),
      )
      expect(new Set(ids).size).toBe(2)
      expect(ids.every((id) => id !== null && id !== '')).toBe(true)
      const descriptions = [
        ...h.view.container.querySelectorAll<HTMLElement>(
          '[data-id="i18n-languages-delete-reason-qx"]',
        ),
      ]
      expect(descriptions).toHaveLength(2)
      for (const control of controls) {
        expect(control.disabled).toBe(true)
        expect(control.hasAttribute('title')).toBe(false)
      }
      descriptions.forEach((description, index) => {
        expect(description.id).toBe(ids[index])
        expect(description.className).toBe('visually-hidden')
        expect(description.hasAttribute('hidden')).toBe(false)
        expect(description.hasAttribute('aria-hidden')).toBe(false)
        expect(description.textContent).toBe(
          HILOS_I18N_LANGUAGE_DELETE_COPY.reasons[deleteReason],
        )
      })
      const whyButtons = [
        ...h.view.container.querySelectorAll<HTMLButtonElement>(
          '[data-id="hilos-action-reason"]',
        ),
      ]
      expect(whyButtons).toHaveLength(2)
      for (const why of whyButtons) {
        expect(why.type).toBe('button')
        expect(why.getAttribute('aria-label')).toBe(
          'Why this action is unavailable',
        )
        expect(why.title).toBe(
          HILOS_I18N_LANGUAGE_DELETE_COPY.reasons[deleteReason],
        )
        expect(why.querySelector('i')?.getAttribute('aria-hidden')).toBe('true')
      }
      const cardRows = [
        ...h.view.container.querySelectorAll<HTMLElement>('.hilos-button-row'),
      ]
      expect(cardRows).toHaveLength(1)
      const cardRow = cardRows[0]!
      const groups = cardRow.querySelectorAll('.hilos-button-group')
      expect(groups).toHaveLength(1)
      const group = groups[0]!
      expect(group.parentElement).toBe(cardRow)
      expect(group.querySelector('.hilos-button-group')).toBeNull()
      expect(group.className).toBe('hilos-button-group d-md-inline-flex gap-1')
      expect(
        [...group.children].map((child) => child.getAttribute('data-id')),
      ).toEqual([
        'i18n-languages-edit-qx',
        'hilos-action-reason',
        'i18n-languages-delete-qx',
        'i18n-languages-delete-reason-qx',
      ])
      click('i18n-languages-delete-qx')
      expect(h.focuses).toEqual([])
      expect(el('modal')).toBeNull()
    },
  )
  it('shows the live catalog and legend, and opens Add without another fetch', () => {
    const h = setup([])
    expect(el('i18n-languages-catalog')).toBeNull()
    expect(el('i18n-languages-legend')).not.toBeNull()
    act(() => {
      h.scope.data.set('builtInCatalog', {
        languageCount: 50,
        countryCount: 51,
      })
      h.scope.data.set('builtInLanguages', [
        { code: 'fr', nativeName: 'Français', rtl: false },
      ])
    })
    expect(el('i18n-languages-catalog').textContent).toContain(
      '50 languages and 51 countries',
    )
    click('hilos-table-main-action')
    change('i18n-language-add-code', 'fr')
    expect(el<HTMLInputElement>('i18n-language-add-native-name').value).toBe(
      'Français',
    )
    expect(h.calls).toEqual([])
    act(() =>
      h.scope.data.set('builtInCatalog', {
        languageCount: 52,
        countryCount: 53,
      }),
    )
    expect(el('i18n-languages-catalog').textContent).toContain(
      '52 languages and 53 countries',
    )
  })
  it('opens the fresh focused row, holds a delete echo through ack and preserves the list address', async () => {
    const h = setup([own])
    act(() => h.delta({ ...own, nativeName: 'Fresh name' }))
    click('i18n-languages-delete-qx')
    expect(el('modal').textContent).toContain('Delete language · Fresh name')
    expect(h.focuses).toEqual(['46'])
    click('i18n-language-delete-confirm')
    expect(h.calls[0]).toMatchObject({
      action: 'hilos_i18n_language_row_delete',
      payload: { languageId: 46 },
    })
    act(() => h.delta(own, 'row_removed'))
    expect(el('modal')).not.toBeNull()
    expect(el('hilos-table-row-46')).not.toBeNull()
    await settle(() => h.succeed())
    expect(el('modal')).toBeNull()
    expect(h.focuses.at(-1)).toBe('')
    expect(h.navigation.navigate).not.toHaveBeenCalled()
    expect(el('hilos-table-apply')).not.toBeNull()
    click('hilos-table-apply')
    expect(el('hilos-table-placeholder').textContent).toContain('Removed')
    h.view.unmount()
    expect(h.unregister).toHaveBeenCalled()
    expect(h.listeners.get('tableWindow')?.size).toBe(0)
  })
  it('declines a stale delete click after a removed row and leaves focus released', () => {
    const h = setup([own])
    act(() => h.delta(own, 'row_removed'))
    click('i18n-languages-delete-qx')
    expect(el('modal')).toBeNull()
    expect(h.focuses).toEqual([])
  })
  it('opens row edit and releases its focus on cancel and context replacement', () => {
    const h = setup([own])
    click('i18n-languages-edit-qx')
    expect(el<HTMLInputElement>('i18n-language-edit-code').value).toBe('qx')
    click('i18n-language-edit-cancel')
    expect(h.focuses.at(-1)).toBe('')
    click('i18n-languages-edit-qx')
    const next = harness(HilosPages.I18N_LANGUAGES, [english])
    h.view.rerender(
      <HilosRouterContext.Provider value={h.navigation}>
        <HilosI18nLanguagesPage context={next.context} />
      </HilosRouterContext.Provider>,
    )
    expect(el('i18n-language-edit-window')).toBeNull()
    expect(h.unregister).toHaveBeenCalled()
  })
  it('opens read-only windows for a viewer while every mutation stays locked', () => {
    const h = setup([own], true)
    click('i18n-languages-edit-qx')
    change('i18n-language-edit-native-name', 'Draft')
    expect(el<HTMLButtonElement>('i18n-language-edit-submit').disabled).toBe(
      true,
    )
    fireEvent.submit(document.querySelector('form')!)
    expect(h.calls).toEqual([])
    h.view.unmount()
  })
  it('releases delete focus on context replacement and protects the new window from the old ack', async () => {
    const h = setup([own])
    click('i18n-languages-delete-qx')
    click('i18n-language-delete-confirm')
    const next = harness(HilosPages.I18N_LANGUAGES, [language])
    h.view.rerender(
      <HilosRouterContext.Provider value={h.navigation}>
        <HilosI18nLanguagesPage context={next.context} />
      </HilosRouterContext.Provider>,
    )
    expect(h.focuses.at(-1)).toBe('')
    expect(el('modal')).toBeNull()
    click('i18n-languages-delete-uk')
    await settle(() => h.succeed())
    expect(el('modal').textContent).toContain('Delete language · Українська')
    expect(el<HTMLButtonElement>('i18n-language-delete-confirm').disabled).toBe(
      false,
    )
    h.view.unmount()
    expect(next.focuses.at(-1)).toBe('')
  })
  it('lets a viewer open row deletion with confirmation disabled', () => {
    const h = setup([own], true)
    click('i18n-languages-delete-qx')
    expect(el<HTMLButtonElement>('i18n-language-delete-confirm').disabled).toBe(
      true,
    )
    click('i18n-language-delete-confirm')
    expect(h.calls).toEqual([])
    click('i18n-language-delete-cancel')
    expect(h.focuses.at(-1)).toBe('')
  })
})
