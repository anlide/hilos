// The Angular language card over the real action lifecycle and page scope: the
// header and tabs, loading against a card that is gone, the deletion hints, the
// card window, the two switches with their live verdicts and their own echo,
// the deletion that returns to the list only after its ack, a viewer of the
// admin view mode, and a new language or context under open windows. The
// verdicts themselves are the core's and are tested there.
import {
  HILOS_I18N_LANGUAGE_DELETE_COPY,
  HILOS_I18N_LANGUAGE_SWITCH_ON_COPY,
  HilosPages,
} from '@hilos/core'
import { describe, expect, it } from 'vitest'

import { HilosI18nLanguagePage } from '../src/admin/i18n/details/HilosI18nLanguagePage.js'
import { i18nBench, languageCard, mountOver } from './helpers/hilosI18n.js'

function setup(viewer = false) {
  const bench = i18nBench(HilosPages.I18N_LANGUAGE, [], viewer)
  const unmounted = bench.listenerCount()
  const view = mountOver(HilosI18nLanguagePage, bench, {
    context: bench.context,
  })

  return { ...bench, ...view, unmounted }
}

const enabledCard = { ...languageCard, enabled: true }

describe('HilosI18nLanguagePage', () => {
  it('tells loading from a card that is gone and draws the live fields, marks, counts and tabs', () => {
    const h = setup()
    expect(
      document.querySelector('[aria-label="Loading language"]'),
    ).not.toBeNull()
    expect(h.el('language-card-header')).toBeNull()
    h.act(() => h.card())
    expect(document.querySelectorAll('h1')).toHaveLength(1)
    expect(h.el('language-card-counts')?.textContent?.trim()).toBe(
      'Locales: 2 · Names: 3',
    )
    expect(h.el('language-card-code')?.textContent?.trim()).toBe('uk')
    expect(h.el('language-card-direction')?.textContent?.trim()).toBe(
      'Left to right',
    )
    expect(
      h
        .el(`language-card-tab-${HilosPages.I18N_LANGUAGE}`)
        ?.getAttribute('aria-current'),
    ).toBe('page')
    expect(
      h
        .el(`language-card-tab-${HilosPages.I18N_LANGUAGE_NAMES}`)
        ?.getAttribute('href'),
    ).toBe('/hilos/i18n/languages/uk/names')
    expect(h.el('language-card-edit')).not.toBeNull()
    h.act(() =>
      h.card({
        ...languageCard,
        nativeName: 'Changed',
        rtl: true,
        enabled: true,
        summary: {
          ...languageCard.summary,
          isDefault: true,
          isOwn: true,
          localeCount: 4,
          nameCount: 5,
        },
      }),
    )
    expect(h.el('language-card-name')?.textContent?.trim()).toBe('Changed')
    expect(h.el('language-card-direction')?.textContent?.trim()).toBe(
      'Right to left',
    )
    expect(h.el('language-card-counts')?.textContent?.trim()).toBe(
      'Locales: 4 · Names: 5',
    )
    expect(h.el('language-card-default')).not.toBeNull()
    expect(h.el('language-card-own')).not.toBeNull()
    expect(h.el('language-card-frozen')).not.toBeNull()
    expect(h.el('language-card-edit')).toBeNull()
    expect(h.el('language-card-view')).not.toBeNull()
    h.act(() => h.card([]))
    expect(h.el('language-card-unavailable')?.textContent).toContain(
      'no longer available',
    )
    expect(document.querySelector('[aria-label="Loading language"]')).toBeNull()
  })

  it.each(['default', 'locales', 'names'] as const)(
    'shows the live %s deletion hint beside its disabled control',
    (deleteReason) => {
      const h = setup()
      h.act(() =>
        h.card({
          ...languageCard,
          summary: { ...languageCard.summary, canDelete: false, deleteReason },
        }),
      )
      const control = h.el<HTMLButtonElement>('language-card-delete')!
      expect(control.disabled).toBe(true)
      expect(control.getAttribute('aria-describedby')).toBe(
        'language-card-delete-reason',
      )
      expect(h.el('language-card-delete-reason')?.textContent?.trim()).toBe(
        HILOS_I18N_LANGUAGE_DELETE_COPY.reasons[deleteReason],
      )
    },
  )

  it('saves through the card action and closes only on the ack after its own echo', async () => {
    const h = setup()
    h.act(() => h.card())
    h.click('language-card-edit')
    h.type('i18n-language-edit-native-name', 'Mine')
    h.click('i18n-language-edit-submit')
    expect(h.calls[0]).toMatchObject({
      action: 'hilos_i18n_language_update',
      payload: { languageCode: 'uk', nativeName: 'Mine', rtl: false },
    })
    h.act(() => h.card({ ...languageCard, nativeName: 'Mine' }))
    expect(h.el('modal')).not.toBeNull()
    await h.settle(() => h.succeed())
    expect(h.el('modal')).toBeNull()
  })

  it('keeps the draft of a deleted card and sends nothing from it', () => {
    const h = setup()
    h.act(() => h.card())
    h.click('language-card-edit')
    h.type('i18n-language-edit-native-name', 'Mine')
    h.act(() => h.card([]))
    expect(
      h.el<HTMLInputElement>('i18n-language-edit-native-name')?.value,
    ).toBe('Mine')
    expect(h.el('i18n-language-edit-submit')?.textContent?.trim()).toBe(
      'Deleted',
    )
    h.submit()
    expect(h.calls).toEqual([])
  })

  it('opens a switched-on card in view and draws an unaddressed tab as text', () => {
    const h = setup()
    h.paths.resolvePath = () => undefined
    h.act(() => h.card(enabledCard))
    expect(
      h.el(`language-card-tab-${HilosPages.I18N_LANGUAGE_NAMES}`)?.tagName,
    ).toBe('SPAN')
    h.click('language-card-view')
    expect(
      h.el<HTMLInputElement>('i18n-language-edit-native-name')?.disabled,
    ).toBe(true)
    expect(h.el('i18n-language-edit-submit')).toBeNull()
    h.click('i18n-language-edit-close')
    expect(h.el('modal')).toBeNull()
  })

  it('refuses to switch off the default language with a visible reason', () => {
    const h = setup()
    h.act(() =>
      h.card({
        ...enabledCard,
        summary: { ...languageCard.summary, isDefault: true },
      }),
    )
    const control = h.el<HTMLButtonElement>('language-card-switch-off')!
    expect(control.disabled).toBe(true)
    expect(control.getAttribute('aria-describedby')).toBe(
      'language-card-switch-off-reason',
    )
    expect(h.el('language-card-switch-off-reason')?.textContent).toContain(
      'set in env',
    )
    expect(h.el('language-card-switch-on')).toBeNull()
    h.click('language-card-switch-off')
    expect(h.el('modal')).toBeNull()
  })

  it('switches off through its own echo, guarding a double confirm, and closes only on the ack', async () => {
    const h = setup()
    h.act(() => h.card(enabledCard))
    h.el('language-card-switch-off')!.focus()
    h.click('language-card-switch-off')
    expect(document.activeElement?.getAttribute('role')).toBe('dialog')
    h.click('language-card-switch-off-confirm')
    h.click('language-card-switch-off-confirm')
    expect(h.calls).toHaveLength(1)
    expect(h.calls[0]).toMatchObject({
      action: 'hilos_i18n_language_switch_off',
      payload: { languageCode: 'uk' },
    })
    expect(
      h.el<HTMLButtonElement>('language-card-switch-off-cancel')?.disabled,
    ).toBe(true)
    expect(h.el<HTMLButtonElement>('modal-close')?.disabled).toBe(true)
    h.escape()
    h.act(() => h.card(languageCard))
    expect(h.el('modal')).not.toBeNull()
    expect(h.el('language-card-switch-off-notice')).toBeNull()
    await h.settle(() => h.succeed())
    expect(h.el('modal')).toBeNull()
  })

  it('keeps a refused switch-off open and clears the refusal on reopening', async () => {
    const h = setup()
    h.act(() => h.card(enabledCard))
    h.click('language-card-switch-off')
    h.click('language-card-switch-off-confirm')
    await h.settle(() => h.refuse())
    expect(h.el('language-card-switch-off-error')?.textContent).toContain(
      'Server refused the language',
    )
    h.click('language-card-switch-off-cancel')
    h.click('language-card-switch-off')
    expect(h.el('language-card-switch-off-error')?.textContent).not.toContain(
      'Server refused',
    )
  })

  it('reads the live switch-on summary, says a switch made elsewhere and closes when the card leaves', () => {
    const h = setup()
    h.act(() => h.card())
    h.click('language-card-switch-on')
    expect(h.el('language-card-switch-on-locale')?.textContent).toContain(
      'not created',
    )
    expect(h.el('language-card-switch-on-names')?.textContent?.trim()).toBe('3')
    expect(h.el('modal')?.textContent).not.toMatch(/\bof \d/)
    expect(h.el('language-card-switch-on-warning')?.textContent?.trim()).toBe(
      HILOS_I18N_LANGUAGE_SWITCH_ON_COPY.warning.missing,
    )
    h.act(() =>
      h.card({
        ...languageCard,
        summary: {
          ...languageCard.summary,
          countrylessLocale: 'on',
          nameCount: 7,
        },
      }),
    )
    expect(h.el('language-card-switch-on-locale')?.textContent).toContain(
      'switched on',
    )
    expect(h.el('language-card-switch-on-names')?.textContent?.trim()).toBe('7')
    expect(h.el('language-card-switch-on-warning')).toBeNull()
    h.act(() => h.card(enabledCard))
    expect(h.el('language-card-switch-on-notice')?.textContent).toContain(
      'Switched on elsewhere',
    )
    expect(
      h.el<HTMLButtonElement>('language-card-switch-on-confirm')?.disabled,
    ).toBe(true)
    h.click('language-card-switch-on-confirm')
    expect(h.calls).toEqual([])
    h.act(() => h.card([]))
    expect(h.el('modal')).toBeNull()
  })

  it('switches on with a missing countryless locale and closes on the ack', async () => {
    const h = setup()
    h.act(() => h.card())
    h.click('language-card-switch-on')
    h.click('language-card-switch-on-confirm')
    expect(h.calls[0]).toMatchObject({
      action: 'hilos_i18n_language_switch_on',
      payload: { languageCode: 'uk' },
    })
    h.act(() => h.card(enabledCard))
    expect(h.el('modal')).not.toBeNull()
    await h.settle(() => h.succeed())
    expect(h.el('modal')).toBeNull()
  })

  it('waits for the deletion ack before returning to the list', async () => {
    const h = setup()
    h.act(() => h.card())
    h.click('language-card-delete')
    h.click('i18n-language-delete-confirm')
    expect(h.calls[0]).toMatchObject({
      action: 'hilos_i18n_language_delete',
      payload: { languageCode: 'uk' },
    })
    h.act(() => h.card([]))
    expect(h.el('modal')).not.toBeNull()
    expect(h.el('language-card-unavailable')).toBeNull()
    expect(h.navigate).not.toHaveBeenCalled()
    await h.settle(() => h.succeed())
    expect(h.navigate).toHaveBeenCalledWith('/hilos/i18n/languages')
  })

  it('lets a viewer read every window, keeps an externally deleted one open and sends nothing', () => {
    const h = setup(true)
    h.act(() => h.card())
    h.click('language-card-edit')
    h.type('i18n-language-edit-native-name', 'Mine')
    expect(h.el<HTMLButtonElement>('i18n-language-edit-submit')?.disabled).toBe(
      true,
    )
    h.submit()
    h.escape()
    h.click('modal-confirm-discard')
    h.click('language-card-switch-on')
    expect(
      h.el<HTMLButtonElement>('language-card-switch-on-confirm')?.disabled,
    ).toBe(true)
    h.click('language-card-switch-on-cancel')
    h.click('language-card-delete')
    expect(
      h.el<HTMLButtonElement>('i18n-language-delete-confirm')?.disabled,
    ).toBe(true)
    h.act(() => h.card([]))
    expect(h.el('i18n-language-delete-notice')?.textContent).toContain(
      'Deleted elsewhere.',
    )
    h.click('i18n-language-delete-confirm')
    expect(h.calls).toEqual([])
    h.click('i18n-language-delete-cancel')
    expect(h.el('modal')).toBeNull()
  })

  it('releases the windows of a former language and lets its deletion ack reach nothing', async () => {
    const h = setup()
    h.act(() => h.card())
    h.click('language-card-edit')
    h.type('i18n-language-edit-native-name', 'Old draft')
    h.act(() => {
      h.route.set({
        page: HilosPages.I18N_LANGUAGE,
        params: { languageCode: 'fr' },
        admin: true,
      })
      h.card({ ...languageCard, code: 'fr', nativeName: 'Français' })
    })
    expect(h.el('i18n-language-edit-window')).toBeNull()
    expect(h.el('language-card-code')?.textContent?.trim()).toBe('fr')
    h.click('language-card-delete')
    h.click('i18n-language-delete-confirm')
    h.act(() => {
      h.route.set({
        page: HilosPages.I18N_LANGUAGE,
        params: { languageCode: 'ar' },
        admin: true,
      })
      h.card({ ...languageCard, code: 'ar', nativeName: 'العربية' })
    })
    expect(h.el('modal')).toBeNull()
    h.click('language-card-delete')
    await h.settle(() => h.succeed())
    expect(h.navigate).not.toHaveBeenCalled()
    expect(h.el('modal')?.textContent).toContain('Delete language · العربية')
    expect(
      h.el<HTMLButtonElement>('i18n-language-delete-confirm')?.disabled,
    ).toBe(false)
  })

  it('closes the windows of a former context and releases every subscription on destroy', () => {
    const h = setup()
    h.act(() => h.card())
    h.click('language-card-edit')
    const next = i18nBench()
    next.card({ ...languageCard, code: 'fr', nativeName: 'Français' })
    h.fixture.componentRef.setInput('context', next.context)
    h.detect()
    expect(h.el('i18n-language-edit-window')).toBeNull()
    expect(h.el('language-card-code')?.textContent?.trim()).toBe('fr')
    h.fixture.destroy()
    expect(h.listenerCount()).toBe(h.unmounted)
  })
})
