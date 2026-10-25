// The Angular languages list over the real table binder and action lifecycle:
// the declared projections and marks, the catalog note and legend, creation from
// the table's main action, the delete confirmation over the row in focus through
// its own echo, the row windows, a viewer of the admin view mode, and a context
// replaced under open windows. The rules themselves are the core's and are
// tested there; this file covers the Angular wiring.
import {
  HILOS_I18N_LANGUAGE_DELETE_COPY,
  HilosPages,
  hilosToasts,
} from '@hilos/core'
import { describe, expect, it, vi } from 'vitest'

import { HilosI18nLanguagesPage } from '../src/admin/i18n/lists/HilosI18nLanguagesPage.js'
import { i18nBench, language, mountOver } from './helpers/hilosI18n.js'

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
  const bench = i18nBench(HilosPages.I18N_LANGUAGES, rows, viewer)
  const unmounted = bench.listenerCount()
  const view = mountOver(HilosI18nLanguagesPage, bench, {
    context: bench.context,
  })

  return { ...bench, ...view, unmounted }
}

describe('HilosI18nLanguagesPage', () => {
  it('draws the declared projections: linked codes, marks with words, read-only ticks and both openers', () => {
    const h = setup()
    expect(h.fixture.nativeElement.querySelector('table')).not.toBeNull()
    expect(h.el('i18n-languages-link-en')?.getAttribute('href')).toBe(
      '/hilos/i18n/languages/en',
    )
    expect(h.el('i18n-languages-default-en')?.textContent).toContain(
      'Default language',
    )
    expect(h.el('i18n-languages-own-qx')?.textContent).toContain(
      'Custom language',
    )
    expect(h.el<HTMLInputElement>('i18n-languages-rtl-qx')?.checked).toBe(true)
    expect(h.el<HTMLInputElement>('i18n-languages-enabled-qx')?.checked).toBe(
      false,
    )
    expect(h.el<HTMLInputElement>('i18n-languages-enabled-qx')?.disabled).toBe(
      true,
    )
    expect(h.el('i18n-languages-link-qx')?.className).toContain(
      'link-secondary',
    )
    expect(h.all('i18n-languages-link-qx')).toHaveLength(2)
    expect(h.el('i18n-languages-view-en')).not.toBeNull()
    expect(h.el('i18n-languages-edit-qx')).not.toBeNull()
    expect(h.fixture.nativeElement.textContent).not.toMatch(
      /Preview|Synchronize|Translate/,
    )
  })

  it('draws a code without an address as plain text', () => {
    const h = setup([own])
    h.paths.resolvePath = () => undefined
    h.act(() => h.pushWindow([{ ...own, nativeName: 'Renamed' }]))
    expect(h.el('i18n-languages-link-qx')?.tagName).toBe('SPAN')
  })

  it.each(['default', 'locales', 'names'] as const)(
    'refuses a %s deletion with distinct descriptions and native reason buttons in both projections',
    (deleteReason) => {
      const h = setup([{ ...own, canDelete: false, deleteReason }])
      const controls = h.all('i18n-languages-delete-qx') as HTMLButtonElement[]
      const ids = controls.map((control) =>
        control.getAttribute('aria-describedby'),
      )
      expect(controls).toHaveLength(2)
      expect(new Set(ids).size).toBe(2)
      for (const control of controls) {
        expect(control.disabled).toBe(true)
        expect(control.hasAttribute('title')).toBe(false)
        expect(
          document
            .getElementById(control.getAttribute('aria-describedby')!)
            ?.textContent?.trim(),
        ).toBe(HILOS_I18N_LANGUAGE_DELETE_COPY.reasons[deleteReason])
      }
      const descriptions = h.all('i18n-languages-delete-reason-qx')
      expect(descriptions).toHaveLength(2)
      descriptions.forEach((description, index) => {
        expect(description.id).toBe(ids[index])
        expect(description.className).toBe('visually-hidden')
        expect(description.hasAttribute('hidden')).toBe(false)
        expect(description.hasAttribute('aria-hidden')).toBe(false)
      })
      const whyButtons = h.all('hilos-action-reason') as HTMLButtonElement[]
      expect(whyButtons).toHaveLength(2)
      const groups = h.fixture.nativeElement.querySelectorAll(
        '.hilos-button-group',
      ) as NodeListOf<HTMLElement>
      expect(groups).toHaveLength(2)
      for (const group of groups) {
        expect(group.tagName).toBe('HILOS-I18N-LANGUAGE-ROW-ACTIONS')
        expect(group.className).toBe(
          'hilos-button-group d-md-inline-flex gap-1',
        )
        expect(group.querySelector('.hilos-button-group')).toBeNull()
        expect(
          [...group.children].map((child) => child.getAttribute('data-id')),
        ).toEqual([
          'i18n-languages-edit-qx',
          'hilos-action-reason',
          'i18n-languages-delete-qx',
          'i18n-languages-delete-reason-qx',
        ])
        expect(
          [...group.querySelectorAll('button')].every(
            (button) => button.parentElement === group,
          ),
        ).toBe(true)
      }
      const cardRows = h.fixture.nativeElement.querySelectorAll(
        '.hilos-button-row',
      ) as NodeListOf<HTMLElement>
      expect(cardRows).toHaveLength(1)
      expect(cardRows[0]!.firstElementChild).toBe(groups[1])
      expect(cardRows[0]!.children).toHaveLength(1)
      h.click('i18n-languages-delete-qx')
      expect(h.focuses).toEqual([])
      expect(h.el('modal')).toBeNull()
      for (const why of whyButtons) {
        expect(why.type).toBe('button')
        expect(why.hidden).toBe(false)
        expect(why.getAttribute('aria-label')).toBe(
          'Why this action is unavailable',
        )
        expect(why.title).toBe(
          HILOS_I18N_LANGUAGE_DELETE_COPY.reasons[deleteReason],
        )
        expect(why.querySelector('i')?.getAttribute('aria-hidden')).toBe('true')
        why.focus()
        why.click()
        h.detect()
        const dialog = h.el('modal')!
        expect(why.contains(dialog)).toBe(false)
        expect(why.parentElement!.contains(dialog)).toBe(false)
        expect(document.activeElement).toBe(dialog)
        expect(h.el('hilos-action-reason-body')?.textContent?.trim()).toBe(
          HILOS_I18N_LANGUAGE_DELETE_COPY.reasons[deleteReason],
        )
        h.click('hilos-action-reason-close')
        expect(h.el('modal')).toBeNull()
        expect(document.activeElement).toBe(why)
      }
      expect(h.focuses).toEqual([])
      expect(h.calls).toEqual([])
      expect(hilosToasts.toasts.get()).toEqual([])
    },
  )

  it('lets a viewer read a refusal without sending an action or holding row focus', () => {
    const h = setup([english], true)
    h.click('hilos-action-reason')
    expect(h.el('hilos-action-reason-body')?.textContent?.trim()).toBe(
      HILOS_I18N_LANGUAGE_DELETE_COPY.reasons.default,
    )
    expect(h.calls).toEqual([])
    expect(h.focuses).toEqual([])
    h.escape()
    expect(h.el('modal')).toBeNull()
    expect(h.el<HTMLButtonElement>('i18n-languages-delete-en')?.disabled).toBe(
      true,
    )
  })

  it('keeps a live row reason open after delete becomes available and returns to the table fallback', () => {
    const h = setup([{ ...own, canDelete: false, deleteReason: 'locales' }])
    // jsdom has no layout; the standard table fallback requires visible boxes.
    const rootBox = vi
      .spyOn(h.el('hilos-viewport-table')!, 'getBoundingClientRect')
      .mockReturnValue(new DOMRect(0, 0, 800, 300))
    const searchBox = vi
      .spyOn(h.el('hilos-table-search')!, 'getBoundingClientRect')
      .mockReturnValue(new DOMRect(0, 0, 200, 30))
    h.el('hilos-action-reason')!.focus()
    h.click('hilos-action-reason')
    const dialog = h.el('modal')!
    h.act(() => h.delta(own))
    expect(h.el('modal')).toBe(dialog)
    expect(document.activeElement).toBe(dialog)
    expect(h.el('hilos-action-reason-body')?.textContent?.trim()).toBe(
      HILOS_I18N_LANGUAGE_DELETE_COPY.reasons.locales,
    )
    expect(h.all('hilos-action-reason').every((button) => button.hidden)).toBe(
      true,
    )
    expect(h.all('i18n-languages-delete-reason-qx')).toHaveLength(0)
    expect(h.el<HTMLButtonElement>('i18n-languages-delete-qx')?.disabled).toBe(
      false,
    )
    h.click('hilos-action-reason-close')
    expect(h.el('modal')).toBeNull()
    expect(document.activeElement).toBe(h.el('hilos-table-search'))
    expect(h.focuses).toEqual([])
    expect(h.calls).toEqual([])
    rootBox.mockRestore()
    searchBox.mockRestore()
  })

  it('shows the live catalog and legend, and adds a catalog language from the main action without another request', async () => {
    const h = setup([])
    expect(h.el('i18n-languages-catalog')).toBeNull()
    expect(h.el('i18n-languages-legend-default')).not.toBeNull()
    expect(h.el('i18n-languages-legend-own')).not.toBeNull()
    h.act(() => {
      h.scope.data.set('builtInCatalog', {
        languageCount: 50,
        countryCount: 51,
      })
      h.scope.data.set('builtInLanguages', [
        { code: 'fr', nativeName: 'Français', rtl: false },
        { code: 'ar', nativeName: 'العربية', rtl: true },
      ])
    })
    expect(h.el('i18n-languages-catalog')?.textContent).toContain(
      '50 languages and 51 countries',
    )
    expect(h.el('i18n-languages-catalog')?.textContent).toContain('holds 2 ISO')
    h.click('hilos-table-main-action')
    expect(document.activeElement).toBe(h.el('i18n-language-add-code'))
    expect(h.el<HTMLButtonElement>('i18n-language-add-submit')?.disabled).toBe(
      true,
    )
    h.type('i18n-language-add-code', 'AR')
    expect(h.el<HTMLInputElement>('i18n-language-add-native-name')?.value).toBe(
      'العربية',
    )
    expect(h.el<HTMLInputElement>('i18n-language-add-rtl')?.checked).toBe(true)
    expect(h.el('i18n-language-add-plate')?.getAttribute('data-kind')).toBe(
      'known',
    )
    h.type('i18n-language-add-code', 'fr')
    h.type('i18n-language-add-native-name', '  French  ')
    h.submit()
    h.submit()
    expect(h.calls).toHaveLength(1)
    expect(h.calls[0]).toMatchObject({
      action: 'hilos_i18n_language_add',
      payload: { languageCode: 'fr', nativeName: 'French', rtl: false },
    })
    h.escape()
    expect(h.el('modal')).not.toBeNull()
    expect(h.el<HTMLButtonElement>('i18n-language-add-cancel')?.disabled).toBe(
      true,
    )
    await h.settle(() => h.refuse())
    expect(h.el('i18n-language-add-error')?.textContent).toContain(
      'Server refused the language',
    )
    expect(h.el<HTMLInputElement>('i18n-language-add-native-name')?.value).toBe(
      '  French  ',
    )
    h.click('i18n-language-add-submit')
    await h.settle(() => h.succeed(1, 'Language added.'))
    expect(h.el('modal')).toBeNull()
    expect(
      hilosToasts.toasts
        .get()
        .some((toast) => toast.message === 'Language added.'),
    ).toBe(true)
    expect(h.calls).toHaveLength(2)
  })

  it('asks to confirm an own code and sends it only from the confirmation', async () => {
    const h = setup([])
    h.act(() =>
      h.scope.data.set('builtInLanguages', [
        { code: 'fr', nativeName: 'Français', rtl: false },
      ]),
    )
    h.click('hilos-table-main-action')
    h.type('i18n-language-add-code', 'cy')
    expect(h.el<HTMLInputElement>('i18n-language-add-native-name')?.value).toBe(
      '',
    )
    expect(h.el('i18n-language-add-plate')?.getAttribute('data-kind')).toBe(
      'own',
    )
    h.type('i18n-language-add-native-name', 'Cymraeg')
    h.toggle('i18n-language-add-rtl')
    h.click('i18n-language-add-submit')
    expect(h.calls).toEqual([])
    expect(h.el('i18n-language-confirm')?.textContent).toContain(
      'catalog of 1 languages',
    )
    expect(document.activeElement?.getAttribute('role')).toBe('dialog')
    h.click('i18n-language-confirm-submit')
    h.click('i18n-language-confirm-submit')
    expect(h.calls).toHaveLength(1)
    expect(h.calls[0]).toMatchObject({
      action: 'hilos_i18n_language_add',
      payload: { languageCode: 'cy', nativeName: 'Cymraeg', rtl: true },
    })
    await h.settle(() => h.refuse())
    expect(h.el('i18n-language-confirm-error')?.textContent).toContain(
      'Server refused the language',
    )
    h.click('i18n-language-confirm-cancel')
    expect(h.el('modal')).toBeNull()
    h.click('hilos-table-main-action')
    expect(h.el<HTMLInputElement>('i18n-language-add-code')?.value).toBe('')
    expect(h.el('i18n-language-add-error')?.textContent).not.toContain(
      'Server refused',
    )
  })

  it('opens deletion over the fresh row in focus, holds its own echo until the ack and keeps the list address', async () => {
    const h = setup([own])
    h.act(() => h.delta({ ...own, nativeName: 'Fresh name' }))
    h.click('i18n-languages-delete-qx')
    expect(h.el('modal')?.textContent).toContain('Delete language · Fresh name')
    expect(h.focuses).toEqual(['46'])
    h.click('i18n-language-delete-confirm')
    h.click('i18n-language-delete-confirm')
    expect(h.calls).toHaveLength(1)
    expect(h.calls[0]).toMatchObject({
      action: 'hilos_i18n_language_row_delete',
      payload: { languageId: 46 },
    })
    expect(
      h.el<HTMLButtonElement>('i18n-language-delete-cancel')?.disabled,
    ).toBe(true)
    h.escape()
    h.act(() => h.delta(own, 'row_removed'))
    expect(h.el('modal')).not.toBeNull()
    expect(h.el('i18n-language-delete-notice')).toBeNull()
    expect(h.el('hilos-table-row-46')).not.toBeNull()
    await h.settle(() => h.succeed())
    expect(h.el('modal')).toBeNull()
    expect(h.focuses.at(-1)).toBe('')
    expect(h.navigate).not.toHaveBeenCalled()
    h.click('hilos-table-apply')
    expect(h.el('hilos-table-placeholder')?.textContent).toContain('Removed')
    h.fixture.destroy()
    expect(h.unregister).toHaveBeenCalled()
    expect(h.listenerCount()).toBe(h.unmounted)
  })

  it('keeps a refused deletion open with the reason and says when the language left elsewhere', async () => {
    const h = setup([own])
    h.click('i18n-languages-delete-qx')
    h.click('i18n-language-delete-confirm')
    await h.settle(() => h.refuse())
    expect(h.el('modal')).not.toBeNull()
    expect(h.el('i18n-language-delete-error')?.textContent).toContain(
      'Server refused the language',
    )
    h.act(() => h.delta(own, 'row_removed'))
    expect(h.el('i18n-language-delete-notice')?.textContent).toContain(
      'Deleted elsewhere.',
    )
    expect(
      h.el<HTMLButtonElement>('i18n-language-delete-confirm')?.disabled,
    ).toBe(true)
    h.click('i18n-language-delete-cancel')
    expect(h.el('modal')).toBeNull()
    expect(h.focuses.at(-1)).toBe('')
  })

  it('declines a stale delete click on a removed row and holds no focus', () => {
    const h = setup([own])
    h.act(() => h.delta(own, 'row_removed'))
    h.click('i18n-languages-delete-qx')
    expect(h.el('modal')).toBeNull()
    expect(h.focuses).toEqual([])
  })

  it('opens the row window, releases its focus on cancel and on a new context', () => {
    const h = setup([own])
    h.click('i18n-languages-edit-qx')
    expect(h.focuses).toEqual(['46'])
    expect(h.el<HTMLInputElement>('i18n-language-edit-code')?.value).toBe('qx')
    expect(h.el<HTMLInputElement>('i18n-language-edit-code')?.disabled).toBe(
      true,
    )
    h.click('i18n-language-edit-cancel')
    expect(h.el('modal')).toBeNull()
    expect(h.focuses.at(-1)).toBe('')
    h.click('i18n-languages-edit-qx')
    const next = i18nBench(HilosPages.I18N_LANGUAGES, [english])
    h.fixture.componentRef.setInput('context', next.context)
    h.detect()
    expect(h.el('i18n-language-edit-window')).toBeNull()
    expect(h.focuses.at(-1)).toBe('')
    expect(h.unregister).toHaveBeenCalled()
    expect(h.el('i18n-languages-view-en')).not.toBeNull()
  })

  it('lets a viewer open and read every window while nothing is sent', () => {
    const h = setup([own], true)
    h.click('i18n-languages-edit-qx')
    h.type('i18n-language-edit-native-name', 'Draft')
    expect(
      h.el<HTMLInputElement>('i18n-language-edit-native-name')?.disabled,
    ).toBe(false)
    expect(h.el<HTMLButtonElement>('i18n-language-edit-submit')?.disabled).toBe(
      true,
    )
    h.submit()
    expect(h.calls).toEqual([])
    h.escape()
    h.click('modal-confirm-discard')
    h.click('i18n-languages-delete-qx')
    expect(
      h.el<HTMLButtonElement>('i18n-language-delete-confirm')?.disabled,
    ).toBe(true)
    h.click('i18n-language-delete-confirm')
    expect(h.calls).toEqual([])
    h.click('i18n-language-delete-cancel')
    expect(h.focuses.at(-1)).toBe('')
    h.click('hilos-table-main-action')
    h.type('i18n-language-add-code', 'cy')
    h.type('i18n-language-add-native-name', 'Cymraeg')
    expect(h.el<HTMLButtonElement>('i18n-language-add-submit')?.disabled).toBe(
      true,
    )
    h.submit()
    expect(h.calls).toEqual([])
    h.click('i18n-language-add-cancel')
    expect(h.el('modal')).toBeNull()
  })

  it('lets a pending deletion of a former context reach nothing of the new one', async () => {
    const h = setup([own])
    h.click('i18n-languages-delete-qx')
    h.click('i18n-language-delete-confirm')
    const next = i18nBench(HilosPages.I18N_LANGUAGES, [language])
    h.fixture.componentRef.setInput('context', next.context)
    h.detect()
    expect(h.focuses.at(-1)).toBe('')
    expect(h.el('modal')).toBeNull()
    h.click('i18n-languages-delete-uk')
    await h.settle(() => h.succeed())
    expect(h.el('modal')?.textContent).toContain('Delete language · Українська')
    expect(
      h.el<HTMLButtonElement>('i18n-language-delete-confirm')?.disabled,
    ).toBe(false)
    h.fixture.destroy()
    expect(next.focuses.at(-1)).toBe('')
  })
})
