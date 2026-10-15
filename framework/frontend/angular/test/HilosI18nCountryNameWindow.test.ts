// The Angular window of a country name over the core name window and the real
// names table: add and edit of the base and of a locale correction with their
// empty-value hints and the catalog's word on a base, a stored empty value told
// from no value, one door for Save and Enter, a refusal that stays and a busy
// save that holds every close, the conflict choices and the expected value they
// send, a draft kept after its subject disappears, a late failure of a previous
// opening that paints nothing, a viewer of the admin view mode, and the row let
// go when the view ends. The session itself is the core's and is tested there.
import { signal } from '@angular/core'
import {
  HILOS_I18N_COUNTRY_NAMES,
  HILOS_I18N_COUNTRY_NAME_COPY,
  HilosPages,
  computedSignal,
  createHilosI18nCountryCard,
  createHilosI18nCountryNameWindow,
  createHilosI18nNamesTable,
} from '@hilos/core'
import { describe, expect, it } from 'vitest'

import { HilosI18nCountryNameWindow } from '../src/admin/i18n/details/HilosI18nCountryNameWindow.js'
import { HILOS_ADMIN_VIEW_MODE } from '../src/hilosLookOnly.js'
import {
  i18nBench,
  mountOver,
  usInEnglish,
  usInFrench,
  usInSpanish,
} from './helpers/hilosI18n.js'

function setup(viewer = false) {
  const bench = i18nBench(HilosPages.I18N_COUNTRY_NAMES, [
    usInEnglish,
    usInSpanish,
    usInFrench,
  ])
  bench.countryCard()
  const country = computedSignal(() =>
    String(bench.route.get().params['countryCode'] ?? ''),
  )
  const card = createHilosI18nCountryCard(bench.context)
  const names = createHilosI18nNamesTable(
    bench.context,
    HILOS_I18N_COUNTRY_NAMES,
    country,
  )
  names.start()
  const window = createHilosI18nCountryNameWindow(
    names.controller,
    card,
    country,
    bench.context,
  )
  const view = mountOver(HilosI18nCountryNameWindow, bench, { window }, [
    { provide: HILOS_ADMIN_VIEW_MODE, useValue: signal(viewer).asReadonly() },
  ])
  const input = () => view.el<HTMLInputElement>('i18n-country-name-input')!
  const button = (name: 'submit' | 'cancel') =>
    view.el<HTMLButtonElement>(`i18n-country-name-${name}`)!

  return { ...bench, ...view, names, window, input, button }
}

describe('HilosI18nCountryNameWindow', () => {
  it.each([
    ['fr', null, 'Add name', 'value', null],
    ['es', null, 'Edit name', 'value', ''],
    ['en', 'en-CA', 'Add locale correction', 'correction', null],
    ['en', 'en-US', 'Edit locale correction', 'correction', ''],
  ] as const)(
    'opens %s at %s by null against "" with its empty-value hint',
    (code, localeCode, title, kind, stored) => {
      const h = setup()
      h.act(() => h.window.open(code, localeCode))
      expect(h.window.mode.get()).toBe(stored === null ? 'add' : 'edit')
      expect(h.el('i18n-country-name-title')?.textContent).toContain(
        `${title} · United States`,
      )
      const window = h.el('i18n-country-name-window')!
      expect(window.textContent).toContain('United States (US)')
      expect(h.input().value).toBe('')
      expect(h.input().maxLength).toBe(255)
      expect(document.activeElement).toBe(h.input())
      expect(
        document.querySelector(`label[for="${h.input().id}"]`)?.textContent,
      ).toBe('Name')
      expect(
        document.getElementById(h.input().getAttribute('aria-describedby')!)
          ?.textContent,
      ).toBe(
        kind === 'value'
          ? HILOS_I18N_COUNTRY_NAME_COPY.hints.base
          : HILOS_I18N_COUNTRY_NAME_COPY.hints.correction,
      )
      expect(
        window.textContent?.includes(
          HILOS_I18N_COUNTRY_NAME_COPY.hints.catalog,
        ),
      ).toBe(kind === 'value')
      // An empty add is a value to send; an untouched edit has nothing to save.
      expect(h.button('submit').disabled).toBe(stored !== null)
      if (localeCode !== null) {
        expect(window.textContent).toContain(localeCode)
      }
      expect(h.focuses.at(-1)).toBe(code)
    },
  )

  it('sends an empty add, keeps the refusal, and closes only on the ack of the literal text', async () => {
    const h = setup()
    h.act(() => h.window.open('fr'))
    h.submit()
    expect(h.calls[0]).toMatchObject({
      action: 'hilos_i18n_country_name_add',
      payload: {
        countryCode: 'us',
        inLanguageCode: 'fr',
        localeCode: null,
        name: '',
      },
    })
    await h.settle(() => h.refuse(0, 'A name already exists.'))
    expect(h.window.mode.get()).toBe('add')
    expect(h.el('i18n-country-name-error')?.textContent).toContain(
      'A name already exists.',
    )
    h.type('i18n-country-name-input', '  États-Unis  ')
    h.click('i18n-country-name-submit')
    expect(h.calls[1]?.payload).toMatchObject({ name: '  États-Unis  ' })
    await h.settle(() => h.succeed(1, 'Name added.'))
    expect(h.el('modal')).toBeNull()
  })

  it('sends a correction edit with its expected value and holds every close while busy', async () => {
    const h = setup()
    h.act(() => h.window.open('en', 'en-US'))
    h.submit()
    expect(h.calls).toHaveLength(0)
    h.type('i18n-country-name-input', '   ')
    h.submit()
    h.submit()
    h.click('i18n-country-name-submit')
    expect(h.calls).toHaveLength(1)
    expect(h.calls[0]).toMatchObject({
      action: 'hilos_i18n_country_name_update',
      payload: {
        countryCode: 'us',
        inLanguageCode: 'en',
        localeCode: 'en-US',
        name: '   ',
        expectedName: '',
      },
    })
    expect(h.input().disabled).toBe(true)
    expect(h.button('cancel').disabled).toBe(true)
    expect(h.el<HTMLButtonElement>('modal-close')?.disabled).toBe(true)
    h.escape()
    expect(h.window.opened.get()).toBe(true)
    await h.settle(() => h.succeed())
    expect(h.window.opened.get()).toBe(false)
  })

  it('offers the conflict choices and sends the accepted incoming value as the expected one', async () => {
    const h = setup()
    h.act(() => h.window.open('en'))
    h.type('i18n-country-name-input', 'Mine')
    h.act(() => h.delta({ ...usInEnglish, name: 'Theirs' }))
    expect(h.el('i18n-country-name-notice')?.textContent).toContain(
      'Name changed elsewhere to "Theirs".',
    )
    expect(h.button('submit').disabled).toBe(true)
    h.submit()
    expect(h.calls).toHaveLength(0)
    h.click('conflict-accept-mine')
    expect(h.input().value).toBe('Mine')
    h.click('i18n-country-name-submit')
    expect(h.calls[0]?.payload).toMatchObject({
      name: 'Mine',
      expectedName: 'Theirs',
    })
    await h.settle(() => h.refuse(0, 'Try again.'))
    h.act(() => h.delta({ ...usInEnglish, name: 'Newer' }))
    h.click('conflict-accept-theirs')
    expect(h.input().value).toBe('Newer')
    expect(h.button('submit').disabled).toBe(true)
    expect(h.el('i18n-country-name-error')?.textContent).toContain('Try again.')
  })

  it.each(['fr', 'en'])(
    'keeps the %s draft to copy after its row disappears and sends nothing from it',
    (code) => {
      const h = setup()
      h.act(() => h.window.open(code))
      h.type('i18n-country-name-input', 'Copy me')
      h.act(() =>
        h.delta(code === 'fr' ? usInFrench : usInEnglish, 'row_removed'),
      )
      expect(h.window.opened.get()).toBe(true)
      expect(h.button('submit').textContent?.trim()).toBe('Deleted')
      expect(h.button('submit').disabled).toBe(true)
      expect(h.input().value).toBe('Copy me')
      expect(h.el('i18n-country-name-notice')?.textContent).toContain(
        'Deleted elsewhere',
      )
      h.submit()
      expect(h.calls).toHaveLength(0)
    },
  )

  it('paints nothing of a late failure from the opening before a route change', async () => {
    const h = setup()
    h.act(() => h.window.open('fr'))
    h.type('i18n-country-name-input', 'Old draft')
    h.submit()
    h.act(() => {
      h.route.set({ ...h.route.get(), params: { countryCode: 'ca' } })
      h.route.set({ ...h.route.get(), params: { countryCode: 'us' } })
    })
    expect(h.window.opened.get()).toBe(false)
    h.act(() => h.window.open('es'))
    h.type('i18n-country-name-input', 'New draft')
    await h.settle(() => h.refuse(0, 'Old failure'))
    expect(h.window.opened.get()).toBe(true)
    expect(h.input().value).toBe('New draft')
    expect(h.el('i18n-country-name-error')?.textContent).not.toContain(
      'Old failure',
    )
    expect(h.button('submit').disabled).toBe(false)
  })

  it('lets a viewer fill the draft and cancel, while Save and Enter send nothing', () => {
    const h = setup(true)
    h.act(() => h.window.open('es'))
    h.type('i18n-country-name-input', 'Viewer draft')
    expect(h.input().disabled).toBe(false)
    expect(h.button('submit').disabled).toBe(true)
    expect(h.button('submit').getAttribute('aria-describedby')).toBeTruthy()
    h.submit()
    h.button('submit').click()
    expect(h.calls).toHaveLength(0)
    expect(h.button('cancel').disabled).toBe(false)
    h.click('i18n-country-name-cancel')
    h.click('modal-confirm-discard')
    expect(h.window.opened.get()).toBe(false)
  })

  it('lets the row go when the view ends', () => {
    const h = setup()
    h.act(() => h.window.open('en'))
    expect(h.names.controller.focusedRow.get()?.code).toBe('en')
    h.fixture.destroy()
    expect(h.window.opened.get()).toBe(false)
    expect(h.names.controller.focusedRow.get()).toBeUndefined()
  })
})
