// The Angular window of a language name over the core name window and the real
// names table: add and edit of the base and of a locale correction with their
// empty-value hints and base/correction notes, a stored empty value told from
// no value, one door for Save and Enter, a refusal that stays and a busy save
// that holds every close, the conflict choices and the expected value they
// send, a draft kept after its subject disappears, a late failure of a previous
// opening that paints nothing, a viewer of the admin view mode, and the row let
// go when the view ends. The session itself is the core's and is tested there.
import { signal } from '@angular/core'
import {
  HILOS_I18N_LANGUAGE_NAME_COPY,
  HILOS_I18N_LANGUAGE_NAMES,
  HilosPages,
  computedSignal,
  createHilosI18nLanguageCard,
  createHilosI18nLanguageNameWindow,
  createHilosI18nNamesTable,
} from '@hilos/core'
import { describe, expect, it } from 'vitest'

import { HilosI18nLanguageNameWindow } from '../src/admin/i18n/details/HilosI18nLanguageNameWindow.js'
import { HILOS_ADMIN_VIEW_MODE } from '../src/hilosLookOnly.js'
import {
  englishName,
  frenchName,
  i18nBench,
  mountOver,
  spanishName,
} from './helpers/hilosI18n.js'

function setup(viewer = false) {
  const bench = i18nBench(HilosPages.I18N_LANGUAGE_NAMES, [
    englishName,
    spanishName,
    frenchName,
  ])
  bench.card()
  const language = computedSignal(() =>
    String(bench.route.get().params['languageCode'] ?? ''),
  )
  const card = createHilosI18nLanguageCard(bench.context)
  const names = createHilosI18nNamesTable(
    bench.context,
    HILOS_I18N_LANGUAGE_NAMES,
    computedSignal(() => card.get()?.languageId ?? null),
  )
  names.start()
  const window = createHilosI18nLanguageNameWindow(
    names.controller,
    card,
    language,
    bench.context,
  )
  const view = mountOver(HilosI18nLanguageNameWindow, bench, { window }, [
    { provide: HILOS_ADMIN_VIEW_MODE, useValue: signal(viewer).asReadonly() },
  ])
  const input = () => view.el<HTMLInputElement>('i18n-language-name-input')!
  const button = (name: 'submit' | 'cancel') =>
    view.el<HTMLButtonElement>(`i18n-language-name-${name}`)!

  return { ...bench, ...view, names, window, input, button }
}

describe('HilosI18nLanguageNameWindow', () => {
  it.each([null, 501])(
    'keeps the draft and blocks a same-text replacement at %s',
    (localeId) => {
      const h = setup()
      h.act(() => h.window.open('11', localeId))
      h.type('i18n-language-name-input', 'Keep my draft')
      h.act(() =>
        h.delta({
          ...englishName,
          ...(localeId === null
            ? { nameId: 999 }
            : {
                corrections: [{ ...englishName.corrections[0]!, nameId: 999 }],
              }),
        }),
      )
      expect(h.input().value).toBe('Keep my draft')
      expect(h.button('submit').disabled).toBe(true)
      expect(h.button('submit').textContent).toContain('Deleted')
      h.submit()
      expect(h.calls).toEqual([])
    },
  )

  it.each([
    ['13', null, 'Add name', 'value', null],
    ['15', null, 'Edit name', 'value', ''],
    ['11', 'en-CA', 'Add locale correction', 'correction', null],
    ['11', 'en-US', 'Edit locale correction', 'correction', ''],
  ] as const)(
    'opens %s at %s by null against "" with its empty-value hint',
    (code, localeCode, title, kind, stored) => {
      const h = setup()
      h.act(() =>
        h.window.open(
          code,
          localeCode === null
            ? null
            : ({ 'en-CA': 503, 'en-US': 502 } as Record<string, number>)[
                localeCode
              ],
        ),
      )
      expect(h.window.mode.get()).toBe(stored === null ? 'add' : 'edit')
      expect(h.el('i18n-language-name-title')?.textContent).toContain(
        `${title} · Українська`,
      )
      expect(h.input().value).toBe('')
      expect(h.input().maxLength).toBe(255)
      expect(
        document.querySelector(`label[for="${h.input().id}"]`)?.textContent,
      ).toBe('Name')
      const descriptions =
        h.input().getAttribute('aria-describedby')?.split(/\s+/) ?? []
      expect(descriptions).toEqual([
        'i18n-language-name-hint',
        'i18n-language-name-note',
      ])
      const hint = document.getElementById(descriptions[0]!)
      const note = document.getElementById(descriptions[1]!)
      expect(hint?.textContent).toBe(
        kind === 'value'
          ? 'Empty text is saved as your own value. Delete the name to remove it.'
          : 'Empty text is saved as your own correction. Delete the correction to inherit the base name.',
      )
      expect(note?.textContent?.trim()).toBe(
        localeCode === null
          ? HILOS_I18N_LANGUAGE_NAME_COPY.notes.base
          : HILOS_I18N_LANGUAGE_NAME_COPY.notes.correction,
      )
      expect(
        document.querySelector('[role="dialog"]')?.getAttribute('aria-label'),
      ).toBe(`${title} · Українська`)
      // An empty add is a value to send; an untouched edit has nothing to save.
      expect(h.button('submit').disabled).toBe(stored !== null)
      if (localeCode !== null) {
        expect(h.el('i18n-language-name-window')?.textContent).toContain(
          localeCode,
        )
      }
      expect(h.focuses.at(-1)).toBe(code)
    },
  )

  it('sends an empty add, keeps the refusal, and closes only on the ack of the literal text', async () => {
    const h = setup()
    h.act(() => h.window.open('13'))
    h.submit()
    expect(h.calls[0]).toMatchObject({
      action: 'hilos_i18n_language_name_add',
      payload: {
        languageId: 19,
        inLanguageId: 13,
        baseNameId: null,
        localeId: null,
        name: '',
      },
    })
    await h.settle(() => h.refuse(0, 'A name already exists.'))
    expect(h.window.mode.get()).toBe('add')
    expect(h.el('i18n-language-name-error')?.textContent).toContain(
      'A name already exists.',
    )
    h.type('i18n-language-name-input', '  My value  ')
    h.click('i18n-language-name-submit')
    expect(h.calls[1]?.payload).toMatchObject({ name: '  My value  ' })
    await h.settle(() => h.succeed(1))
    expect(h.el('modal')).toBeNull()
  })

  it('sends a correction edit with its expected value and holds every close while busy', async () => {
    const h = setup()
    h.act(() => h.window.open('11', 502))
    h.submit()
    expect(h.calls).toHaveLength(0)
    h.type('i18n-language-name-input', '   ')
    h.submit()
    h.submit()
    h.click('i18n-language-name-submit')
    expect(h.calls).toHaveLength(1)
    expect(h.calls[0]).toMatchObject({
      action: 'hilos_i18n_language_name_update',
      payload: {
        languageId: 19,
        inLanguageId: 11,
        nameId: 402,
        localeId: 502,
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
    h.act(() => h.window.open('11'))
    h.type('i18n-language-name-input', 'Mine')
    h.act(() => h.delta({ ...englishName, name: 'Theirs' }))
    expect(h.el('i18n-language-name-notice')?.textContent).toContain(
      'Name changed elsewhere to "Theirs".',
    )
    expect(h.button('submit').disabled).toBe(true)
    h.submit()
    expect(h.calls).toHaveLength(0)
    h.click('conflict-accept-mine')
    expect(h.input().value).toBe('Mine')
    h.click('i18n-language-name-submit')
    expect(h.calls[0]?.payload).toMatchObject({
      name: 'Mine',
      expectedName: 'Theirs',
    })
    await h.settle(() => h.refuse(0, 'Try again.'))
    h.act(() => h.delta({ ...englishName, name: 'Newer' }))
    h.click('conflict-accept-theirs')
    expect(h.input().value).toBe('Newer')
    expect(h.button('submit').disabled).toBe(true)
    expect(h.el('i18n-language-name-error')?.textContent).toContain(
      'Try again.',
    )
  })

  it.each(['fr', 'en'])(
    'keeps the %s draft to copy after its row disappears and sends nothing from it',
    (code) => {
      const h = setup()
      h.act(() => h.window.open(code === 'fr' ? '13' : '11'))
      h.type('i18n-language-name-input', 'Copy me')
      h.act(() =>
        h.delta(code === 'fr' ? frenchName : englishName, 'row_removed'),
      )
      expect(h.window.opened.get()).toBe(true)
      expect(h.button('submit').textContent?.trim()).toBe('Deleted')
      expect(h.button('submit').disabled).toBe(true)
      expect(h.input().value).toBe('Copy me')
      expect(h.el('i18n-language-name-notice')?.textContent).toContain(
        'Deleted elsewhere',
      )
      h.submit()
      expect(h.calls).toHaveLength(0)
    },
  )

  it('paints nothing of a late failure from the opening before a route change', async () => {
    const h = setup()
    h.act(() => h.window.open('13'))
    h.type('i18n-language-name-input', 'Old draft')
    h.submit()
    h.act(() => {
      h.route.set({ ...h.route.get(), params: { languageCode: 'ru' } })
      h.route.set({ ...h.route.get(), params: { languageCode: 'uk' } })
    })
    expect(h.window.opened.get()).toBe(false)
    h.act(() => h.window.open('15'))
    h.type('i18n-language-name-input', 'New draft')
    await h.settle(() => h.refuse(0, 'Old failure'))
    expect(h.window.opened.get()).toBe(true)
    expect(h.input().value).toBe('New draft')
    expect(h.el('i18n-language-name-error')?.textContent).not.toContain(
      'Old failure',
    )
    expect(h.button('submit').disabled).toBe(false)
  })

  it('lets a viewer fill the draft and cancel, while Save and Enter send nothing', () => {
    const h = setup(true)
    h.act(() => h.window.open('15'))
    expect(h.el('i18n-language-name-note')?.textContent?.trim()).toBe(
      HILOS_I18N_LANGUAGE_NAME_COPY.notes.base,
    )
    h.type('i18n-language-name-input', 'Viewer draft')
    expect(h.input().disabled).toBe(false)
    expect(h.button('submit').disabled).toBe(true)
    expect(h.button('submit').getAttribute('aria-describedby')).toBeTruthy()
    h.submit()
    h.button('submit').click()
    expect(h.calls).toHaveLength(0)
    expect(h.button('cancel').disabled).toBe(false)
    h.click('i18n-language-name-cancel')
    h.click('modal-confirm-discard')
    expect(h.window.opened.get()).toBe(false)
  })

  it('lets the row go when the view ends', () => {
    const h = setup()
    h.act(() => h.window.open('11'))
    expect(h.names.controller.focusedRow.get()?.code).toBe('en')
    h.fixture.destroy()
    expect(h.window.opened.get()).toBe(false)
    expect(h.names.controller.focusedRow.get()).toBeUndefined()
  })
})
