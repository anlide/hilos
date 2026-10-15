// The Angular names page of a language over the real table binder and action
// lifecycle: the three states with the shared header, the plus and the pencil by
// null against '' in the row and the correction alike, corrections under an empty
// base, the delete confirmation opened over the fresh row in focus with its value
// fixed, one row in focus shared by both windows, a route change, a card that
// disappears under an open confirmation, a viewer of the admin view mode, and the
// table let go when the page ends. The windows' own behavior is tested beside
// them; the rules are the core's.
import { HilosPages } from '@hilos/core'
import { describe, expect, it } from 'vitest'

import { HilosI18nLanguageNamesPage } from '../src/admin/i18n/details/HilosI18nLanguageNamesPage.js'
import {
  englishName,
  frenchName,
  i18nBench,
  mountOver,
  spanishName,
} from './helpers/hilosI18n.js'

function setup(viewer = false) {
  const bench = i18nBench(
    HilosPages.I18N_LANGUAGE_NAMES,
    [englishName, spanishName, frenchName],
    viewer,
  )
  const unmounted = bench.listenerCount()
  const view = mountOver(HilosI18nLanguageNamesPage, bench, {
    context: bench.context,
  })
  view.act(() => bench.card())

  return { ...bench, ...view, unmounted }
}

/** The target and live row the delete confirmation was handed, as its inputs. */
function deleteInputs(h: ReturnType<typeof setup>) {
  return h.fixture.debugElement.query(
    (node) => node.name === 'hilos-i18n-language-name-delete',
  ).componentInstance as {
    open: () => boolean
    target: () => { expectedName: string; kind: string } | null
  }
}

describe('HilosI18nLanguageNamesPage', () => {
  it('tells loading from a card that is gone and draws the header and tabs above one names table', () => {
    const bench = i18nBench(HilosPages.I18N_LANGUAGE_NAMES, [englishName])
    const h = mountOver(HilosI18nLanguageNamesPage, bench, {
      context: bench.context,
    })
    expect(
      document.querySelector('[aria-label="Loading language"]'),
    ).not.toBeNull()
    expect(h.el('language-card-header')).toBeNull()
    expect(document.querySelector('table')).toBeNull()
    h.act(() => bench.card())
    expect(document.querySelectorAll('h1')).toHaveLength(1)
    expect(
      h
        .el(`language-card-tab-${HilosPages.I18N_LANGUAGE_NAMES}`)
        ?.classList.contains('active'),
    ).toBe(true)
    expect(document.querySelector('table')).not.toBeNull()
    h.act(() => bench.card([]))
    expect(document.querySelector('table')).toBeNull()
    expect(h.el('language-card-unavailable')?.textContent).toContain(
      'no longer available',
    )
  })

  it('draws the plus by null and the pencil by "" in rows, corrections and cards, with a trash on every stored value', () => {
    const h = setup()
    h.click('hilos-table-expand-en')
    for (const surface of ['table', '[data-id="hilos-table-cards"]']) {
      const one = (id: string) =>
        document.querySelector(`${surface} [data-id="${id}"]`)
      for (const id of [
        'i18n-language-name-edit-en',
        'i18n-language-name-edit-es',
        'i18n-language-name-add-fr',
        'i18n-language-name-locale-edit-en-GB',
        'i18n-language-name-locale-edit-en-US',
        'i18n-language-name-locale-add-en-CA',
        'i18n-names-delete-en',
        'i18n-names-delete-es',
        'i18n-names-delete-en-GB',
        'i18n-names-delete-en-US',
      ]) {
        expect(one(id), `${surface} ${id}`).not.toBeNull()
      }
      for (const id of ['i18n-names-delete-fr', 'i18n-names-delete-en-CA']) {
        expect(one(id), `${surface} ${id}`).toBeNull()
      }
    }
    expect(h.el('i18n-language-name-edit-en')?.getAttribute('aria-label')).toBe(
      'Edit name · Українська in English',
    )
    expect(
      h.el('i18n-language-name-locale-add-en-CA')?.getAttribute('aria-label'),
    ).toBe('Add locale correction · Українська in English · en-CA')
    expect(h.el('i18n-names-delete-en-US')?.getAttribute('aria-label')).toBe(
      'Remove locale correction en-US',
    )
    expect(
      [...document.querySelectorAll('th')].filter(
        (cell) => cell.textContent?.trim() === 'Actions',
      ),
    ).toHaveLength(1)
  })

  it('opens a correction under an empty base and takes its parent row into focus', () => {
    const h = setup()
    h.act(() => h.delta({ ...englishName, name: '' }))
    h.click('hilos-table-expand-en')
    h.click('i18n-language-name-locale-add-en-CA')
    expect(h.el('i18n-language-name-title')?.textContent).toContain(
      'Add locale correction',
    )
    expect(h.el('i18n-language-name-window')?.textContent).toContain('en-CA')
    expect(h.focuses.at(-1)).toBe('en')
    h.click('i18n-language-name-submit')
    expect(h.calls[0]).toMatchObject({
      action: 'hilos_i18n_language_name_add',
      payload: { inLanguageCode: 'en', localeCode: 'en-CA', name: '' },
    })
  })

  it('opens the confirmation over the fresh row, fixes its value, follows the row and lets it go on close', () => {
    const h = setup()
    h.act(() => h.delta({ ...englishName, name: 'Fresh' }))
    h.click('i18n-names-delete-en')
    expect(h.focuses.at(-1)).toBe('en')
    expect(deleteInputs(h).open()).toBe(true)
    expect(deleteInputs(h).target()).toMatchObject({
      kind: 'base',
      expectedName: 'Fresh',
    })
    h.act(() => h.delta({ ...englishName, name: 'Newer' }))
    expect(deleteInputs(h).target()?.expectedName).toBe('Fresh')
    h.click('i18n-language-name-delete-cancel')
    expect(h.focuses.at(-1)).toBe('')
    expect(h.el('modal')).toBeNull()
    h.click('i18n-names-delete-en')
    expect(deleteInputs(h).target()?.expectedName).toBe('Newer')
  })

  it('takes a saved empty correction from the fresh row and removes only it', async () => {
    const h = setup()
    h.click('hilos-table-expand-en')
    h.click('i18n-names-delete-en-US')
    expect(deleteInputs(h).target()).toMatchObject({
      kind: 'override',
      expectedName: '',
    })
    h.click('i18n-language-name-delete-confirm')
    expect(h.calls[0]).toMatchObject({
      action: 'hilos_i18n_language_name_override_delete',
      payload: { localeCode: 'en-US', expectedName: '' },
    })
    await h.settle(() => h.succeed())
    expect(h.el('modal')).toBeNull()
    expect(h.focuses.at(-1)).toBe('')
  })

  it('shares one row in focus: each window lets the other go before it takes its own', () => {
    const h = setup()
    h.click('i18n-names-delete-en')
    expect(deleteInputs(h).open()).toBe(true)
    h.click('i18n-language-name-edit-es')
    expect(deleteInputs(h).open()).toBe(false)
    expect(h.el('i18n-language-name-window')).not.toBeNull()
    expect(h.focuses.slice(-2)).toEqual(['', 'es'])
    h.click('i18n-names-delete-en')
    expect(h.el('i18n-language-name-window')).toBeNull()
    expect(deleteInputs(h).open()).toBe(true)
    expect(h.focuses.at(-1)).toBe('en')
  })

  it('closes both windows on a route change and lets their row go', () => {
    const h = setup()
    h.click('i18n-names-delete-en')
    h.act(() =>
      h.route.set({ ...h.route.get(), params: { languageCode: 'ru' } }),
    )
    expect(h.el('modal')).toBeNull()
    expect(h.focuses.at(-1)).toBe('')
    h.act(() =>
      h.route.set({ ...h.route.get(), params: { languageCode: 'uk' } }),
    )
    h.click('i18n-language-name-edit-en')
    h.type('i18n-language-name-input', 'Draft')
    h.act(() =>
      h.route.set({ ...h.route.get(), params: { languageCode: 'ru' } }),
    )
    expect(h.el('modal')).toBeNull()
    expect(h.focuses.at(-1)).toBe('')
  })

  it('keeps the confirmation open with its explanation when the card disappears', () => {
    const h = setup()
    h.click('i18n-names-delete-en')
    h.act(() => h.card([]))
    expect(document.querySelector('table')).toBeNull()
    expect(h.el('modal')).not.toBeNull()
    expect(h.el('i18n-language-name-delete-notice')?.textContent).toContain(
      'The name no longer exists.',
    )
    expect(
      h.el<HTMLButtonElement>('i18n-language-name-delete-confirm')?.disabled,
    ).toBe(true)
  })

  it('locks every trash for a viewer while the name windows open and read', () => {
    const h = setup(true)
    h.click('hilos-table-expand-en')
    const trashes = [
      ...document.querySelectorAll<HTMLButtonElement>(
        '[data-id^="i18n-names-delete-"]',
      ),
    ]
    expect(trashes.length).toBeGreaterThan(0)
    for (const trash of trashes) {
      expect(trash.disabled).toBe(true)
      expect(trash.getAttribute('aria-describedby')).toBeTruthy()
    }
    for (const id of [
      'i18n-language-name-add-fr',
      'i18n-language-name-locale-edit-en-US',
    ]) {
      expect(h.el<HTMLButtonElement>(id)?.disabled).toBe(false)
      h.click(id)
      expect(h.el('i18n-language-name-window')).not.toBeNull()
      expect(
        h.el<HTMLButtonElement>('i18n-language-name-submit')?.disabled,
      ).toBe(true)
      h.submit()
      h.click('i18n-language-name-cancel')
    }
    expect(h.calls).toHaveLength(0)
  })

  it('binds a new context once and lets the table go when the page ends', () => {
    const h = setup()
    const bound = h.listenerCount()
    h.click('i18n-language-name-edit-en')
    h.act(() => h.fixture.componentRef.setInput('context', { ...h.context }))
    expect(h.el('modal')).toBeNull()
    expect(h.listenerCount()).toBe(bound)
    h.fixture.destroy()
    expect(h.focuses.at(-1)).toBe('')
    expect(h.unregister).toHaveBeenCalled()
    expect(h.listenerCount()).toBe(h.unmounted)
  })
})
