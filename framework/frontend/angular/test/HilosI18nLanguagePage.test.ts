// The Angular language card over the real action lifecycle and page scope: the
// shared entity head, loading against a card that is gone, the deletion hints, the
// card window, the two switches with their live verdicts and their own echo,
// the deletion that returns to the list only after its ack, a viewer of the
// admin view mode, and a new language or context under open windows. The
// verdicts themselves are the core's and are tested there.
import {
  HILOS_I18N_LANGUAGE_DELETE_COPY,
  HILOS_I18N_LANGUAGE_MARKS,
  HILOS_I18N_LANGUAGE_SWITCH_ON_COPY,
  HILOS_I18N_LANGUAGE_WINDOW_COPY,
  HilosPages,
} from '@hilos/core'
import { describe, expect, it } from 'vitest'

import { HilosI18nLanguagePage } from '../src/admin/i18n/details/HilosI18nLanguagePage.js'
import {
  i18nBench,
  languageCard,
  languageName,
  lastCrumb,
  mountOver,
} from './helpers/hilosI18n.js'

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
  it.each(['missing', 'off', 'on'] as const)(
    'reads zero and live nonzero summary values with countryless=%s',
    (countrylessLocale) => {
      const h = setup()
      h.act(() =>
        h.card({
          ...languageCard,
          summary: {
            ...languageCard.summary,
            nameCount: 0,
            localeCount: 0,
            enabledLocaleCount: 0,
            countrylessLocale,
          },
        }),
      )
      expect(h.el('language-overview-name-count')?.textContent?.trim()).toBe(
        '0',
      )
      expect(h.el('language-overview-locale-count')?.textContent?.trim()).toBe(
        '0',
      )
      expect(
        h.el('language-overview-enabled-locale-count')?.textContent?.trim(),
      ).toBe('0')
      expect(h.el('language-overview-locales')?.textContent).toContain(
        'None created.',
      )
      expect(
        h.el('language-overview-countryless-state')?.textContent?.trim(),
      ).toBe(
        { missing: 'Not created', off: 'Switched off', on: 'Switched on' }[
          countrylessLocale
        ],
      )
      h.act(() =>
        h.card({
          ...languageCard,
          summary: {
            ...languageCard.summary,
            nameCount: 7,
            localeCount: 4,
            enabledLocaleCount: 3,
            countrylessLocale,
          },
        }),
      )
      expect(h.el('language-overview-name-count')?.textContent?.trim()).toBe(
        '7',
      )
      expect(h.el('language-overview-locale-count')?.textContent?.trim()).toBe(
        '4',
      )
      expect(
        h.el('language-overview-enabled-locale-count')?.textContent?.trim(),
      ).toBe('3')
      expect(h.el('language-overview-locales')?.textContent).not.toContain(
        'None created.',
      )
      expect(h.calls).toEqual([])
    },
  )

  it('updates the countryless state from the live summary without remounting', () => {
    const h = setup()
    h.act(() => h.card())
    for (const [countrylessLocale, label] of [
      ['missing', 'Not created'],
      ['off', 'Switched off'],
      ['on', 'Switched on'],
    ] as const) {
      h.act(() =>
        h.card({
          ...languageCard,
          summary: { ...languageCard.summary, countrylessLocale },
        }),
      )
      const value = h.el('language-overview-countryless-state')!
      expect(value.textContent?.trim()).toBe(label)
      expect(value.getAttribute('aria-label')).toBe(
        `Locale without a country: ${label}`,
      )
    }
  })

  it('offers native summary links and hands primary clicks to the router', () => {
    const h = setup()
    h.act(() => h.card())
    for (const [id, label, path] of [
      ['name-count', 'Names: 3', '/hilos/i18n/languages/uk/names'],
      ['locale-count', 'Locales: 2', '/hilos/i18n/languages/uk/locales'],
      [
        'countryless-state',
        'Locale without a country: Not created',
        '/hilos/i18n/languages/uk/locales',
      ],
    ]) {
      const value = h.el(`language-overview-${id}`)!
      expect(value.tagName).toBe('A')
      expect(value.getAttribute('href')).toBe(path)
      expect(value.getAttribute('aria-label')).toBe(label)
      h.click(`language-overview-${id}`)
      expect(h.navigate).toHaveBeenLastCalledWith(path)
    }
    expect(h.calls).toEqual([])
  })

  it.each(['catalog', 'route'] as const)(
    'keeps a summary value as text when the %s does not permit its target',
    (source) => {
      const h = setup()
      h.act(() => h.card())
      h.act(() => {
        if (source === 'catalog') {
          h.scope.data.set('pageTabs', [
            { page: HilosPages.I18N_LANGUAGE, label: 'Overview' },
            { page: HilosPages.I18N_LANGUAGE_NAMES, label: 'Names' },
          ])
        } else {
          const resolve = h.paths.resolvePath
          h.paths.resolvePath = (key, params) =>
            key === HilosPages.I18N_LANGUAGE_LOCALES
              ? undefined
              : resolve(key, params)
          h.scope.data.set('pageLead', 'Routes unavailable')
        }
      })
      expect(h.el('language-overview-name-count')?.getAttribute('href')).toBe(
        '/hilos/i18n/languages/uk/names',
      )
      for (const id of ['locale-count', 'countryless-state']) {
        const value = h.el(`language-overview-${id}`)!
        expect(value.tagName).toBe('SPAN')
        expect(value.getAttribute('href')).toBeNull()
      }
      expect(h.el('language-overview-name-count')?.textContent?.trim()).toBe(
        '3',
      )
      expect(h.el('language-overview-locale-count')?.textContent?.trim()).toBe(
        '2',
      )
      expect(
        h.el('language-overview-countryless-state')?.textContent?.trim(),
      ).toBe('Not created')
      expect(h.navigate).not.toHaveBeenCalled()
    },
  )

  it('names frozen and editable controls and exposes the disabled writing direction', () => {
    const h = setup()
    h.act(() => h.card())
    expect(h.el('language-card-editable')?.textContent?.trim()).toBe(
      'Language switched off — edit freely',
    )
    expect(
      h
        .el('language-card-editable')
        ?.querySelector('i')
        ?.getAttribute('aria-hidden'),
    ).toBe('true')
    expect(h.el('language-card-enabled')?.textContent?.trim()).toBe(
      'Language switched off',
    )
    const edit = h.el('language-card-edit')!
    expect(edit.getAttribute('aria-label')).toBe(
      HILOS_I18N_LANGUAGE_WINDOW_COPY.open.edit,
    )
    expect(edit.getAttribute('title')).toBe(
      HILOS_I18N_LANGUAGE_WINDOW_COPY.open.edit,
    )
    expect(edit.querySelector('i')?.getAttribute('aria-hidden')).toBe('true')
    const rtl = h.el<HTMLInputElement>('language-card-rtl')!
    expect(rtl.type).toBe('checkbox')
    expect(rtl.disabled).toBe(true)
    expect(rtl.checked).toBe(false)
    expect(rtl.getAttribute('aria-label')).toBe('Right to left')
    expect(rtl.labels?.[0]?.textContent?.trim()).toBe('Writing direction')
    expect(
      document
        .getElementById(rtl.getAttribute('aria-describedby')!)
        ?.textContent?.trim(),
    ).toBe('Right-to-left writing reverses the product layout.')
    expect(h.el('language-card-direction')?.textContent?.trim()).toBe(
      'Left to right',
    )
    h.act(() => h.card({ ...enabledCard, rtl: true }))
    expect(h.el('language-card-editable')).toBeNull()
    expect(h.el('language-card-frozen')?.textContent?.trim()).toBe(
      'Language enabled — frozen',
    )
    expect(
      h
        .el('language-card-frozen')
        ?.querySelector('i')
        ?.getAttribute('aria-hidden'),
    ).toBe('true')
    expect(h.el('language-card-enabled')?.textContent?.trim()).toBe(
      'Language switched on',
    )
    const view = h.el('language-card-view')!
    expect(view.getAttribute('aria-label')).toBe(
      HILOS_I18N_LANGUAGE_WINDOW_COPY.open.view,
    )
    expect(view.getAttribute('title')).toBe(
      HILOS_I18N_LANGUAGE_WINDOW_COPY.open.view,
    )
    expect(view.querySelector('i')?.getAttribute('aria-hidden')).toBe('true')
    expect(h.el<HTMLInputElement>('language-card-rtl')?.checked).toBe(true)
    expect(h.el<HTMLInputElement>('language-card-rtl')?.disabled).toBe(true)
    expect(h.el('language-card-direction')?.textContent?.trim()).toBe(
      'Right to left',
    )
    expect(document.querySelectorAll('h1')).toHaveLength(1)
    expect(
      [...document.querySelectorAll('h2')].map((heading) =>
        heading.textContent?.trim(),
      ),
    ).toEqual(expect.arrayContaining(['Main', 'State']))
  })

  it('names the delete icon and explains an available deletion', () => {
    const h = setup()
    h.act(() => h.card())
    const control = h.el<HTMLButtonElement>('language-card-delete')!
    expect(control.tagName).toBe('BUTTON')
    expect(control.disabled).toBe(false)
    expect(control.getAttribute('aria-label')).toBe('Delete language')
    expect(control.getAttribute('title')).toBe('Delete language')
    expect(control.querySelector('i')?.getAttribute('aria-hidden')).toBe('true')
    expect(h.el('language-card-delete-verdict')?.textContent).toContain(
      HILOS_I18N_LANGUAGE_DELETE_COPY.body,
    )
    expect(h.el('language-card-delete-verdict')?.textContent).toContain(
      HILOS_I18N_LANGUAGE_DELETE_COPY.note,
    )
    expect(h.el('language-card-delete-reason')).toBeNull()
  })

  it('preserves a draft without retargeting a same-code replacement', () => {
    const h = setup()
    h.act(() => h.card())
    h.click('language-card-edit')
    h.type('i18n-language-edit-native-name', 'Unsaved draft')
    h.act(() =>
      h.card({ ...languageCard, languageId: languageCard.languageId + 1 }),
    )
    expect(
      h.el<HTMLInputElement>('i18n-language-edit-native-name')!.value,
    ).toBe('Unsaved draft')
    expect(h.el<HTMLButtonElement>('i18n-language-edit-submit')!.disabled).toBe(
      true,
    )
    expect(h.el('i18n-language-edit-submit')!.textContent).toContain('Deleted')
    h.submit()
    expect(h.calls).toHaveLength(0)
  })

  it('tells loading from a card that is gone and draws the live fields under the shared head', () => {
    const h = setup()
    expect(
      document.querySelector('[aria-label="Loading language"]'),
    ).not.toBeNull()
    expect(h.el('hilos-page-head-skeleton')).not.toBeNull()
    h.act(() => h.card())
    expect(document.querySelectorAll('h1')).toHaveLength(1)
    expect(h.el('hilos-page-head-title')?.textContent?.trim()).toBe(
      'Українська',
    )
    expect(lastCrumb()).toBe('Українська')
    expect(languageName(h)).toEqual(['Українська', 'Українська'])
    expect(h.el('language-head-tile')?.textContent?.trim()).toBe('uk')
    expect(h.el('language-head-default')).toBeNull()
    expect(h.el('language-head-own')).toBeNull()
    expect(h.el('language-head-direction')?.textContent?.trim()).toBe(
      'Left to right',
    )
    expect(h.el('language-card-header')).toBeNull()
    expect(h.el('language-card-tabs')).toBeNull()
    expect(h.el('language-card-counts')).toBeNull()
    expect(h.el('language-card-code')?.textContent?.trim()).toBe('uk')
    expect(h.el('language-card-direction')?.textContent?.trim()).toBe(
      'Left to right',
    )
    expect(
      h
        .el(`hilos-page-tab-${HilosPages.I18N_LANGUAGE}`)
        ?.getAttribute('aria-current'),
    ).toBe('page')
    expect(
      h
        .el(`hilos-page-tab-${HilosPages.I18N_LANGUAGE_NAMES}`)
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
    expect(h.el('hilos-page-head-title')?.textContent?.trim()).toBe('Changed')
    expect(lastCrumb()).toBe('Changed')
    expect(languageName(h)).toEqual(['Changed', 'Changed'])
    expect(h.el('language-card-name')?.textContent?.trim()).toBe('Changed')
    expect(h.el('language-card-direction')?.textContent?.trim()).toBe(
      'Right to left',
    )
    expect(h.el('language-head-direction')?.textContent?.trim()).toBe(
      'Right to left',
    )
    const defaultMark = h.el('language-head-default')!
    const ownMark = h.el('language-head-own')!
    expect(defaultMark.getAttribute('title')).toBe(
      HILOS_I18N_LANGUAGE_MARKS.default.label,
    )
    expect(defaultMark.querySelector('.visually-hidden')?.textContent).toBe(
      HILOS_I18N_LANGUAGE_MARKS.default.label,
    )
    expect(defaultMark.querySelector('i')?.getAttribute('aria-hidden')).toBe(
      'true',
    )
    expect(ownMark.getAttribute('title')).toBe(
      HILOS_I18N_LANGUAGE_MARKS.own.label,
    )
    expect(ownMark.querySelector('.visually-hidden')?.textContent).toBe(
      HILOS_I18N_LANGUAGE_MARKS.own.label,
    )
    expect(ownMark.querySelector('i')?.getAttribute('aria-hidden')).toBe('true')
    expect(h.el('language-card-frozen')).not.toBeNull()
    expect(h.el('language-card-edit')).toBeNull()
    expect(h.el('language-card-view')).not.toBeNull()
    h.act(() => h.card([]))
    expect(h.el('language-card-unavailable')?.textContent).toContain(
      'no longer available',
    )
    expect(document.querySelector('[aria-label="Loading language"]')).toBeNull()
    expect(h.el('language-head-tile')).toBeNull()
    expect(h.el('hilos-page-head-title')?.textContent?.trim()).toBe('Language')
    expect(languageName(h)).toEqual(['Language', 'Language'])
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
      expect(control.getAttribute('aria-label')).toBe('Delete language')
      expect(control.getAttribute('title')).toBe('Delete language')
      expect(
        document.getElementById(control.getAttribute('aria-describedby')!),
      ).toBe(h.el('language-card-delete-reason'))
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
      payload: { languageId: 19, nativeName: 'Mine', rtl: false },
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
    expect(h.el('hilos-page-tabs')).toBeNull()
    expect(document.querySelector('[data-id^="hilos-page-tab-"]')).toBeNull()
    for (const id of ['name-count', 'locale-count', 'countryless-state']) {
      expect(h.el(`language-overview-${id}`)?.tagName).toBe('SPAN')
    }
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
      payload: { languageId: 19 },
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
      payload: { languageId: 19 },
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
      payload: { languageId: 19 },
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
    expect(languageName(h)).toEqual(['Українська', 'Українська'])
    h.click('language-card-edit')
    h.type('i18n-language-edit-native-name', 'Old draft')
    h.act(() => {
      h.route.set({
        page: HilosPages.I18N_LANGUAGE,
        params: { languageCode: 'fr' },
        admin: true,
      })
      h.card({
        ...languageCard,
        languageId: 13,
        code: 'fr',
        nativeName: 'Français',
      })
    })
    expect(h.el('i18n-language-edit-window')).toBeNull()
    expect(h.el('hilos-page-head-title')?.textContent?.trim()).toBe('Français')
    expect(lastCrumb()).toBe('Français')
    expect(languageName(h)).toEqual(['Français', 'Français'])
    expect(h.el('language-card-code')?.textContent?.trim()).toBe('fr')
    expect(h.el('language-overview-name-count')?.getAttribute('href')).toBe(
      '/hilos/i18n/languages/fr/names',
    )
    expect(h.el('language-overview-locale-count')?.getAttribute('href')).toBe(
      '/hilos/i18n/languages/fr/locales',
    )
    expect(
      h.el('language-overview-countryless-state')?.getAttribute('href'),
    ).toBe('/hilos/i18n/languages/fr/locales')
    h.click('language-card-delete')
    h.click('i18n-language-delete-confirm')
    h.act(() => {
      h.route.set({
        page: HilosPages.I18N_LANGUAGE,
        params: { languageCode: 'ar' },
        admin: true,
      })
      h.card({
        ...languageCard,
        languageId: 16,
        code: 'ar',
        nativeName: 'العربية',
      })
    })
    expect(h.el('hilos-page-head-title')?.textContent?.trim()).toBe('العربية')
    expect(lastCrumb()).toBe('العربية')
    expect(languageName(h)).toEqual(['العربية', 'العربية'])
    expect(h.el('modal')).toBeNull()
    expect(h.el('language-overview-name-count')?.getAttribute('href')).toBe(
      '/hilos/i18n/languages/ar/names',
    )
    expect(h.el('language-overview-locale-count')?.getAttribute('href')).toBe(
      '/hilos/i18n/languages/ar/locales',
    )
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
    expect(languageName(h)).toEqual(['Українська', 'Українська'])
    h.click('language-card-edit')
    const next = i18nBench()
    next.card({
      ...languageCard,
      languageId: 13,
      code: 'fr',
      nativeName: 'Français',
      summary: { ...languageCard.summary, nameCount: 8 },
    })
    h.fixture.componentRef.setInput('context', next.context)
    h.detect()
    expect(h.el('i18n-language-edit-window')).toBeNull()
    expect(languageName(h)).toEqual(['Language', 'Language'])
    expect(h.el('language-card-code')?.textContent?.trim()).toBe('fr')
    expect(h.el('language-overview-name-count')?.getAttribute('href')).toBe(
      '/hilos/i18n/languages/fr/names',
    )
    expect(h.el('language-overview-name-count')?.textContent?.trim()).toBe('8')
    h.act(() =>
      h.card({
        ...languageCard,
        summary: { ...languageCard.summary, nameCount: 99 },
      }),
    )
    expect(h.el('language-overview-name-count')?.textContent?.trim()).toBe('8')
    expect(
      h.el('language-overview-countryless-state')?.getAttribute('href'),
    ).toBe('/hilos/i18n/languages/fr/locales')
    h.fixture.destroy()
    expect(h.listenerCount()).toBe(h.unmounted)
    expect(languageName(h)).toEqual(['Language', 'Language'])
  })
})
