// The Angular locales page of a language over the real table binder and action
// lifecycle: the three states with the shared header, the pair rows — the
// language alone and one per country, with or without a locale — the opener by
// the pair's state and the delete beside a stored locale, the format lists taken
// from the page answer, the delete confirmation over the row in focus through its
// own echo with the pair kept as a row, a route change, a viewer of the admin
// view mode, and the table let go when the page ends. The windows' own behavior
// is tested beside them; the rules are the core's.
import { HilosPages } from '@hilos/core'
import { describe, expect, it } from 'vitest'

import { HilosI18nLanguageLocalesPage } from '../src/admin/i18n/details/HilosI18nLanguageLocalesPage.js'
import {
  countrylessLocale,
  i18nBench,
  localeTemplates,
  mountOver,
  polandPair,
  ukraineLocale,
} from './helpers/hilosI18n.js'

function setup(viewer = false) {
  const bench = i18nBench(
    HilosPages.I18N_LANGUAGE_LOCALES,
    [countrylessLocale, ukraineLocale, polandPair],
    viewer,
  )
  const unmounted = bench.listenerCount()
  const view = mountOver(HilosI18nLanguageLocalesPage, bench, {
    context: bench.context,
  })
  view.act(() => {
    bench.card()
    bench.templates()
  })

  return { ...bench, ...view, unmounted }
}

describe('HilosI18nLanguageLocalesPage', () => {
  it('tells loading from a card that is gone and draws the header and tabs above the locales table', () => {
    const bench = i18nBench(HilosPages.I18N_LANGUAGE_LOCALES, [
      countrylessLocale,
    ])
    const h = mountOver(HilosI18nLanguageLocalesPage, bench, {
      context: bench.context,
    })
    expect(
      document.querySelector('[aria-label="Loading language"]'),
    ).not.toBeNull()
    expect(document.querySelector('table')).toBeNull()
    h.act(() => bench.card())
    expect(document.querySelectorAll('h1')).toHaveLength(1)
    expect(
      h
        .el(`language-card-tab-${HilosPages.I18N_LANGUAGE_LOCALES}`)
        ?.classList.contains('active'),
    ).toBe(true)
    expect(document.querySelector('table')).not.toBeNull()
    h.act(() => bench.card([]))
    expect(h.el('language-card-unavailable')?.textContent).toContain(
      'no longer available',
    )
  })

  it('draws the language alone and every country pair, with the opener by the pair and a delete beside a stored locale', () => {
    const h = setup()
    expect(h.el('i18n-locales-country-uk')?.textContent?.trim()).toBe(
      '— no country',
    )
    const ukraine = h.el('i18n-locales-country-uk-UA')!
    expect(ukraine.querySelector('.text-uppercase')?.textContent).toBe('ua')
    expect(ukraine.textContent).toContain('Україна')
    expect(ukraine.classList.contains('text-body-secondary')).toBe(true)
    expect(
      h
        .el('i18n-locales-code-uk-UA')
        ?.classList.contains('text-body-secondary'),
    ).toBe(true)
    expect(
      h.el('i18n-locales-code-uk')?.classList.contains('text-body-secondary'),
    ).toBe(false)
    expect(h.el('i18n-locales-none-uk-PL')?.textContent?.trim()).toBe(
      'No locale',
    )
    expect(h.el<HTMLInputElement>('i18n-locales-enabled-uk')?.checked).toBe(
      true,
    )
    expect(h.el<HTMLInputElement>('i18n-locales-enabled-uk-UA')?.checked).toBe(
      false,
    )
    expect(h.el('i18n-locales-enabled-uk-PL')).toBeNull()
    expect(h.el('i18n-locales-view-uk')?.getAttribute('aria-label')).toBe(
      'View locale: nothing to edit',
    )
    expect(h.el('i18n-locales-edit-uk-UA')?.getAttribute('aria-label')).toBe(
      'Edit locale',
    )
    expect(h.el('i18n-locales-add-uk-PL')?.getAttribute('aria-label')).toBe(
      'Add locale',
    )
    expect(h.el('i18n-locales-delete-uk-UA')?.getAttribute('aria-label')).toBe(
      'Delete locale uk-UA',
    )
    expect(h.el('i18n-locales-delete-uk-PL')).toBeNull()
  })

  it.each([
    ['i18n-locales-add-uk-PL', '19:146', 'Add locale'],
    ['i18n-locales-edit-uk-UA', '19:108', 'Edit locale'],
    ['i18n-locales-view-uk', '19:null', 'Locale'],
  ] as const)(
    '%s opens the window in its mode over the pair in focus, with the page lists',
    (opener, rowKey, title) => {
      const h = setup()
      h.click(opener)
      expect(h.el('i18n-locale-title')?.textContent).toContain(`${title} ·`)
      expect(h.focuses.at(-1)).toBe(rowKey)
      expect(
        [...h.el<HTMLSelectElement>('i18n-locale-field-date')!.options].map(
          (option) => option.value,
        ),
      ).toEqual(
        rowKey === '19:146'
          ? ['', ...localeTemplates.date]
          : [...localeTemplates.date],
      )
      h.click(rowKey === '19:null' ? 'i18n-locale-close' : 'i18n-locale-cancel')
      expect(h.el('modal')).toBeNull()
      expect(h.focuses.at(-1)).toBe('')
    },
  )

  it('deletes a locale through its own echo, closes on the ack and keeps the pair as a row', async () => {
    const h = setup()
    h.click('i18n-locales-delete-uk-UA')
    expect(h.focuses.at(-1)).toBe('19:108')
    expect(
      document.querySelector('[role="dialog"]')?.getAttribute('aria-label'),
    ).toBe('Delete locale · Українська in Україна')
    h.click('i18n-locale-delete-confirm')
    expect(h.calls[0]).toMatchObject({
      action: 'hilos_i18n_locale_delete',
      payload: { languageId: 19, countryId: 108, localeId: 19108 },
    })
    const pair = {
      ...ukraineLocale,
      localeCode: null,
      enabled: null,
      formats: null,
    }
    h.act(() => h.delta(pair))
    expect(h.el('modal')).not.toBeNull()
    expect(h.el('i18n-locale-delete-notice')).toBeNull()
    await h.settle(() => h.succeed())
    expect(h.el('modal')).toBeNull()
    expect(h.focuses.at(-1)).toBe('')
    expect(h.el('i18n-locales-none-uk-UA')).not.toBeNull()
    expect(h.el('i18n-locales-add-uk-UA')).not.toBeNull()
  })

  it('says a locale deleted elsewhere under an idle confirmation', () => {
    const h = setup()
    h.click('i18n-locales-delete-uk-UA')
    h.act(() =>
      h.delta({
        ...ukraineLocale,
        localeCode: null,
        enabled: null,
        formats: null,
      }),
    )
    expect(h.el('i18n-locale-delete-notice')?.textContent).toContain(
      'Deleted elsewhere.',
    )
    expect(
      h.el<HTMLButtonElement>('i18n-locale-delete-confirm')?.disabled,
    ).toBe(true)
  })

  it('closes the windows on a route change and lets their row go', () => {
    const h = setup()
    h.click('i18n-locales-edit-uk-UA')
    h.act(() =>
      h.route.set({ ...h.route.get(), params: { languageCode: 'ru' } }),
    )
    expect(h.el('modal')).toBeNull()
    expect(h.focuses.at(-1)).toBe('')
    h.act(() =>
      h.route.set({ ...h.route.get(), params: { languageCode: 'uk' } }),
    )
    h.click('i18n-locales-delete-uk-UA')
    h.act(() =>
      h.route.set({ ...h.route.get(), params: { languageCode: 'ru' } }),
    )
    expect(h.el('modal')).toBeNull()
    expect(h.focuses.at(-1)).toBe('')
  })

  it('lets a viewer open every window and read it while the confirms send nothing', () => {
    const h = setup(true)
    h.click('i18n-locales-delete-uk-UA')
    expect(
      h.el<HTMLButtonElement>('i18n-locale-delete-confirm')?.disabled,
    ).toBe(true)
    h.click('i18n-locale-delete-cancel')
    h.click('i18n-locales-view-uk')
    h.click('i18n-locale-switch-off')
    expect(
      h.el<HTMLButtonElement>('i18n-locale-switch-off-confirm')?.disabled,
    ).toBe(true)
    h.click('i18n-locale-switch-off-back')
    h.click('i18n-locale-close')
    expect(h.calls).toHaveLength(0)
  })

  it('binds a new context once and lets the table go when the page ends', () => {
    const h = setup()
    const bound = h.listenerCount()
    h.click('i18n-locales-edit-uk-UA')
    h.act(() => h.fixture.componentRef.setInput('context', { ...h.context }))
    expect(h.el('modal')).toBeNull()
    expect(h.listenerCount()).toBe(bound)
    h.fixture.destroy()
    expect(h.focuses.at(-1)).toBe('')
    expect(h.unregister).toHaveBeenCalled()
    expect(h.listenerCount()).toBe(h.unmounted)
  })
})
