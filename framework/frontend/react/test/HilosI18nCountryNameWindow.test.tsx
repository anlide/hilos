import { act, fireEvent } from '@testing-library/react'
import { describe, expect, it, vi } from 'vitest'
import {
  HilosPages,
  createHilosI18nCountryNameWindow,
  createSignal,
  type HilosI18nCountryCard,
  type HilosI18nNameRow,
} from '@hilos/core'
import { HilosI18nCountryNameWindow } from '../src/admin/i18n/details/HilosI18nCountryNameWindow.js'
import { HilosAdminViewModeContext } from '../src/hilosLookOnly.js'
import {
  harness,
  countryCard,
  el,
  click,
  change,
  settle,
} from './hilosI18nHarness.js'
import { nameRow, focusSource } from './hilosI18nDetailsHarness.js'

function setup(
  name: string | null = 'Original',
  localeCode: string | null = null,
  viewer = false,
) {
  const h = harness(HilosPages.I18N_COUNTRY)
  const row = {
    ...nameRow,
    name: localeCode === null ? name : '',
    corrections: [{ ...nameRow.corrections[0]!, name }],
  }
  const focus = focusSource<HilosI18nNameRow>(row),
    card = createSignal<HilosI18nCountryCard | null>(countryCard),
    subject = createSignal('gg')
  const window = createHilosI18nCountryNameWindow(
    focus.controller,
    card,
    subject,
    h.context,
  )
  const view = h.mount(
    <HilosAdminViewModeContext.Provider value={viewer}>
      <button
        type="button"
        data-id="name-opener"
        onClick={() => window.open('en', localeCode)}
      >
        Open
      </button>
      <HilosI18nCountryNameWindow window={window} />
    </HilosAdminViewModeContext.Provider>,
  )
  el('name-opener').focus()
  act(() => window.open('en', localeCode))
  return { ...h, ...focus, card, subject, window, view, row }
}
function enter(): void {
  fireEvent.submit(el<HTMLInputElement>('i18n-country-name-input').form!)
}

describe('React country name window', () => {
  it.each([
    [null, null, 'Add name'],
    ['', null, 'Edit name'],
    [null, 'en-GB', 'Add locale correction'],
    ['', 'en-GB', 'Edit locale correction'],
  ] as const)(
    'fixes coordinates and distinguishes %s at %s',
    (name, locale, title) => {
      const h = setup(name, locale)
      const input = el<HTMLInputElement>('i18n-country-name-input')
      expect(document.activeElement).toBe(input)
      expect(input.required).toBe(false)
      expect(input.maxLength).toBe(255)
      expect(el('i18n-country-name-title').textContent).toContain(
        `${title} · Guernsey`,
      )
      expect(el('i18n-country-name-window').textContent).toContain(
        'English (en)',
      )
      expect(
        document.getElementById(input.getAttribute('aria-describedby')!)
          ?.textContent,
      ).toContain(locale ? 'own correction' : 'own value')
      expect(el<HTMLButtonElement>('i18n-country-name-submit').disabled).toBe(
        name !== null,
      )
      act(() =>
        h.card.set({
          ...countryCard,
          summary: { ...countryCard.summary, name: 'Changed' },
        }),
      )
      expect(el('i18n-country-name-title').textContent).toContain('Guernsey')
    },
  )
  it.each([null, 'en-GB'])(
    'adds empty text at %s and preserves literal spaces on duplicate refusal',
    async (localeCode) => {
      const h = setup(null, localeCode)
      enter()
      expect(h.calls[0]).toMatchObject({
        action: 'hilos_i18n_country_name_add',
        payload: {
          countryCode: 'gg',
          inLanguageCode: 'en',
          localeCode,
          name: '',
        },
      })
      act(() => h.focused.set(nameRow))
      await settle(() => h.refuse(0, 'A name already exists'))
      expect(el('i18n-country-name-error').textContent).toContain(
        'A name already exists',
      )
      expect(h.window.mode.get()).toBe('add')
      change('i18n-country-name-input', '  My value  ')
      click('i18n-country-name-submit')
      expect(h.calls[1]?.payload).toMatchObject({ name: '  My value  ' })
      await settle(() => h.succeed(1))
      expect(el('i18n-country-name-window')).toBeNull()
    },
  )
  it.each([null, 'en-GB'])(
    'guards unchanged and repeated submit at %s through own echo until ack',
    async (localeCode) => {
      const h = setup('', localeCode)
      enter()
      expect(h.calls).toEqual([])
      change('i18n-country-name-input', '   ')
      enter()
      enter()
      click('i18n-country-name-submit')
      expect(h.calls).toHaveLength(1)
      expect(h.calls[0]).toMatchObject({
        action: 'hilos_i18n_country_name_update',
        payload: {
          countryCode: 'gg',
          inLanguageCode: 'en',
          localeCode,
          name: '   ',
          expectedName: '',
        },
      })
      act(() =>
        h.focused.set({
          ...h.row,
          name: '   ',
          corrections: [{ ...h.row.corrections[0]!, name: '   ' }],
        }),
      )
      click('modal-close')
      fireEvent.keyDown(el('modal'), { key: 'Escape' })
      click('modal')
      expect(el<HTMLInputElement>('i18n-country-name-input').disabled).toBe(
        true,
      )
      expect(el<HTMLButtonElement>('i18n-country-name-cancel').disabled).toBe(
        true,
      )
      expect(h.window.opened.get()).toBe(true)
      await settle(() => h.succeed())
      expect(h.window.opened.get()).toBe(false)
      expect(h.focused.get()).toBeUndefined()
    },
  )
  it('takes live text, offers both conflict choices and sends the accepted expectedName', async () => {
    const h = setup()
    act(() => h.focused.set({ ...h.row, name: 'Incoming' }))
    expect(el<HTMLInputElement>('i18n-country-name-input').value).toBe(
      'Incoming',
    )
    expect(el('i18n-country-name-notice').textContent).toContain(
      'Updated just now',
    )
    change('i18n-country-name-input', 'Mine')
    act(() => h.focused.set({ ...h.row, name: 'Theirs' }))
    expect(el('i18n-country-name-notice').textContent).toContain('"Theirs"')
    enter()
    expect(h.calls).toEqual([])
    expect(el('conflict-merge')).toBeNull()
    click('conflict-accept-mine')
    click('i18n-country-name-submit')
    expect(h.calls[0]?.payload).toMatchObject({
      name: 'Mine',
      expectedName: 'Theirs',
    })
    await settle(() => h.refuse(0, 'Try again'))
    expect(el('i18n-country-name-error').querySelector('button')).not.toBeNull()
    act(() => h.focused.set({ ...h.row, name: 'Newer' }))
    click('conflict-accept-theirs')
    expect(el<HTMLInputElement>('i18n-country-name-input').value).toBe('Newer')
    expect(el<HTMLButtonElement>('i18n-country-name-submit').disabled).toBe(
      true,
    )
    act(() => {
      h.window.close()
      h.window.open('en')
    })
    expect(el('i18n-country-name-error').textContent).not.toContain('Try again')
  })
  it.each([null, 'Original'])(
    'keeps the %s draft after deletion and blocks a viewer',
    (name) => {
      const h = setup(name)
      change('i18n-country-name-input', 'Copy me')
      act(() => h.card.set(null))
      expect(el<HTMLInputElement>('i18n-country-name-input').value).toBe(
        'Copy me',
      )
      expect(el<HTMLInputElement>('i18n-country-name-input').disabled).toBe(
        false,
      )
      expect(el('i18n-country-name-submit').textContent).toBe('Deleted')
      enter()
      expect(h.calls).toEqual([])
      h.view.unmount()
      const viewer = setup(name, null, true)
      change('i18n-country-name-input', 'Viewer draft')
      enter()
      click('i18n-country-name-submit')
      expect(viewer.calls).toEqual([])
      expect(
        el<HTMLButtonElement>('i18n-country-name-submit').getAttribute(
          'aria-describedby',
        ),
      ).toBeTruthy()
    },
  )
  it.each([null, 'Original'])(
    'ignores an old %s failure after switching subject',
    async (name) => {
      const h = setup(name)
      change('i18n-country-name-input', 'Old')
      enter()
      act(() => {
        h.subject.set('fr')
        h.card.set({ ...countryCard, code: 'fr' })
      })
      act(() => h.window.open('en'))
      change('i18n-country-name-input', 'New')
      enter()
      await settle(() => h.refuse(0, 'Old failure'))
      expect(el('i18n-country-name-error').textContent).not.toContain(
        'Old failure',
      )
      expect(el<HTMLInputElement>('i18n-country-name-input').value).toBe('New')
      expect(el<HTMLButtonElement>('i18n-country-name-cancel').disabled).toBe(
        true,
      )
      await settle(() => h.succeed(1))
      expect(el('modal')).toBeNull()
    },
  )
  it('confirms dirty Escape and restores focus on close and releases on unmount', () => {
    const h = setup()
    change('i18n-country-name-input', 'Dirty')
    fireEvent.keyDown(el('modal'), { key: 'Escape' })
    click('modal-confirm-discard')
    expect(document.activeElement).toBe(el('name-opener'))
    act(() => h.window.open('en'))
    h.view.unmount()
    expect(h.window.opened.get()).toBe(false)
    expect(h.focused.get()).toBeUndefined()
  })
  it.each(['timeout', 'disconnected'] as const)(
    'keeps the name draft after %s and permits retry',
    async (outcome) => {
      vi.useFakeTimers()
      try {
        const h = setup()
        change('i18n-country-name-input', 'Retained draft')
        enter()
        await settle(() => {
          if (outcome === 'timeout') vi.advanceTimersByTime(30000)
          else h.emit('state', 'disconnected')
        })
        expect(el<HTMLInputElement>('i18n-country-name-input').value).toBe(
          'Retained draft',
        )
        expect(el('i18n-country-name-error').textContent).toContain(
          outcome === 'timeout' ? 'timed out' : 'connection dropped',
        )
        expect(el<HTMLButtonElement>('i18n-country-name-submit').disabled).toBe(
          false,
        )
        enter()
        await settle(() => h.succeed(1))
        expect(el('modal')).toBeNull()
      } finally {
        vi.useRealTimers()
      }
    },
  )
  it('accepts matching country and writing-language codes as separate catalogs', () => {
    const h = setup(null)
    act(() => {
      h.window.close()
      h.fresh({ ...nameRow, code: 'gg', name: null })
      h.window.open('gg')
    })
    enter()
    expect(h.calls[0]?.payload).toMatchObject({
      countryCode: 'gg',
      inLanguageCode: 'gg',
      name: '',
    })
  })
})
