// The Angular delete confirmation of a locale: the pair's codes and labels the
// page fixed, the countryless pair sent with a null country, its own echo held
// until the ack, an external disappearance said and blocking the confirm, the
// server's three refusals — an enabled locale, a country's default, any name
// override, empty ones included — each kept in the reserved row with a retry, a
// busy action that holds every close, and a viewer of the admin view mode. The
// dispatch is the core's and is tested there.
import { signal } from '@angular/core'
import { HilosPages, createHilosI18nLocaleDelete } from '@hilos/core'
import type { HilosI18nLocaleDeleteTarget } from '@hilos/core'
import { describe, expect, it } from 'vitest'

import { HilosI18nLocaleDelete } from '../src/admin/i18n/details/HilosI18nLocaleDelete.js'
import { HILOS_ADMIN_VIEW_MODE } from '../src/hilosLookOnly.js'
import { i18nBench, mountOver } from './helpers/hilosI18n.js'

const ukraine: HilosI18nLocaleDeleteTarget = {
  languageId: 19,
  countryId: 108,
  localeId: 19108,
  languageCode: 'uk',
  countryCode: 'ua',
  localeCode: 'uk-UA',
  nativeName: 'Українська',
  countryLabel: 'Україна',
}
const countryless: HilosI18nLocaleDeleteTarget = {
  ...ukraine,
  countryId: null,
  countryCode: null,
  localeId: 19000,
  localeCode: 'uk',
  countryLabel: null,
}

function setup(target = ukraine, viewer = false) {
  const bench = i18nBench(HilosPages.I18N_LANGUAGE_LOCALES, [])
  const view = mountOver(
    HilosI18nLocaleDelete,
    bench,
    {
      open: false,
      target,
      source: target,
      deleteLocale: createHilosI18nLocaleDelete(bench.context).deleteLocale,
    },
    [{ provide: HILOS_ADMIN_VIEW_MODE, useValue: signal(viewer).asReadonly() }],
  )
  let closed = 0
  view.fixture.componentInstance.closed.subscribe(() => closed++)
  const set = (name: string, value: unknown): void =>
    view.act(() => view.fixture.componentRef.setInput(name, value))
  set('open', true)
  const confirm = () =>
    view.el<HTMLButtonElement>('i18n-locale-delete-confirm')!

  return { ...bench, ...view, set, confirm, closed: () => closed }
}

describe('HilosI18nLocaleDelete', () => {
  it('deletes the pair through its own echo and closes only on the ack', async () => {
    const h = setup()
    expect(document.activeElement?.getAttribute('role')).toBe('dialog')
    expect(
      document.querySelector('[role="dialog"]')?.getAttribute('aria-label'),
    ).toBe('Delete locale · Українська in Україна')
    expect(h.el('i18n-locale-delete-window')?.textContent).toContain(
      'Remove any language or country name overrides for this locale first',
    )
    h.click('i18n-locale-delete-confirm')
    h.click('i18n-locale-delete-confirm')
    expect(h.calls).toHaveLength(1)
    expect(h.calls[0]).toMatchObject({
      action: 'hilos_i18n_locale_delete',
      payload: { languageId: 19, countryId: 108, localeId: 19108 },
    })
    expect(h.el<HTMLButtonElement>('i18n-locale-delete-cancel')?.disabled).toBe(
      true,
    )
    expect(h.el<HTMLButtonElement>('modal-close')?.disabled).toBe(true)
    h.escape()
    h.set('source', null)
    expect(h.el('modal')).not.toBeNull()
    expect(h.el('i18n-locale-delete-window')?.textContent).not.toContain(
      'Deleted elsewhere.',
    )
    await h.settle(() => h.succeed())
    expect(h.closed()).toBe(1)
  })

  it('sends the countryless pair with a null country, never an empty one', () => {
    const h = setup(countryless)
    expect(h.el('i18n-locale-delete-window')?.textContent).toContain(
      'This removes the seven base formats of uk.',
    )
    h.click('i18n-locale-delete-confirm')
    expect(h.calls[0]?.payload).toEqual({
      languageId: 19,
      countryId: null,
      localeId: 19000,
    })
  })

  it.each([
    'Switch the locale off before deleting it.',
    'This locale is the default of a country.',
    'Language or country name overrides still use this locale.',
  ])(
    'keeps the server refusal "%s" in the window and lets it retry',
    async (reason) => {
      const h = setup()
      h.click('i18n-locale-delete-confirm')
      await h.settle(() => h.refuse(0, reason))
      expect(h.el('modal')).not.toBeNull()
      expect(h.el('i18n-locale-delete-error')?.textContent).toContain(reason)
      expect(h.confirm().disabled).toBe(false)
      h.click('i18n-locale-delete-confirm')
      expect(h.calls).toHaveLength(2)
      expect(h.closed()).toBe(0)
    },
  )

  it.each([
    ['gone', null],
    [
      'another pair',
      { ...ukraine, localeId: 19146, localeCode: 'uk-PL', countryCode: 'pl' },
    ],
  ] as const)(
    'stays open over a locale %s elsewhere, saying so and blocking the confirm',
    (_, source) => {
      const h = setup()
      h.set('source', source)
      expect(h.el('i18n-locale-delete-notice')?.textContent).toContain(
        'Deleted elsewhere.',
      )
      expect(h.confirm().disabled).toBe(true)
      h.confirm().click()
      expect(h.calls).toHaveLength(0)
      h.click('i18n-locale-delete-cancel')
      expect(h.closed()).toBe(1)
    },
  )

  it('lets a viewer read the window and cancel while the confirm sends nothing', () => {
    const h = setup(ukraine, true)
    expect(h.confirm().disabled).toBe(true)
    expect(h.confirm().getAttribute('aria-describedby')).toBeTruthy()
    h.confirm().click()
    expect(h.calls).toHaveLength(0)
    h.click('i18n-locale-delete-cancel')
    expect(h.closed()).toBe(1)
  })

  it('opens again without the refusal of the previous visit', async () => {
    const h = setup()
    h.click('i18n-locale-delete-confirm')
    await h.settle(() =>
      h.refuse(0, 'Switch the locale off before deleting it.'),
    )
    h.set('open', false)
    h.set('open', true)
    expect(h.el('i18n-locale-delete-error')?.textContent).not.toContain(
      'Switch the locale off',
    )
  })
})
