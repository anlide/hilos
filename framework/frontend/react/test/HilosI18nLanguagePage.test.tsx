import { act, fireEvent } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { HilosPages, HILOS_I18N_LANGUAGE_DELETE_COPY } from '@hilos/core'
import { HilosI18nLanguagePage } from '../src/admin/i18n/details/HilosI18nLanguagePage.js'
import { HilosRouterContext } from '../src/hilosRouterContext.js'
import {
  harness,
  languageCard,
  el,
  click,
  change,
  expectLanguagePageHead,
  settle,
} from './hilosI18nHarness.js'

function setup(viewer = false) {
  const h = harness(HilosPages.I18N_LANGUAGE, [], viewer)
  const view = h.mount(<HilosI18nLanguagePage context={h.context} />)
  return { ...h, view }
}

describe('React language card', () => {
  it('preserves a draft without retargeting a same-code replacement', () => {
    const h = setup()
    act(() => h.card())
    click('language-card-edit')
    change('i18n-language-edit-native-name', 'Unsaved draft')
    act(() =>
      h.card({ ...languageCard, languageId: languageCard.languageId + 1 }),
    )
    expect(el<HTMLInputElement>('i18n-language-edit-native-name').value).toBe(
      'Unsaved draft',
    )
    expect(el<HTMLButtonElement>('i18n-language-edit-submit').disabled).toBe(
      true,
    )
    expect(el('i18n-language-edit-submit').textContent).toContain('Deleted')
    fireEvent.submit(el('i18n-language-edit-window').querySelector('form')!)
    expect(h.calls).toHaveLength(0)
  })

  it('distinguishes loading from a deleted card and renders live fields and the shared head', () => {
    const h = setup()
    expect(
      document.querySelector('[aria-label="Loading language"]'),
    ).not.toBeNull()
    expect(el('hilos-page-head-skeleton')).not.toBeNull()
    act(() => h.card())
    expectLanguagePageHead(HilosPages.I18N_LANGUAGE)
    expect(el('language-head-tile').textContent).toBe('uk')
    expect(el('language-head-tile').getAttribute('aria-hidden')).toBe('true')
    expect(el('language-head-direction').textContent).toBe('Left to right')
    expect(el('language-card-code').textContent).toBe('uk')
    expect(el('language-card-direction').textContent).toBe('Left to right')
    expect(el('language-card-editable').textContent).toContain(
      'Language switched off — edit freely',
    )
    expect(el('language-card-enabled').textContent).toBe(
      'Language switched off',
    )
    expect(
      el('hilos-page-tab-hilos_i18n_language_names').getAttribute('href'),
    ).toBe('/hilos/i18n/languages/uk/names')
    act(() =>
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
    expectLanguagePageHead(HilosPages.I18N_LANGUAGE, 'Changed')
    expect(el('language-card-name').textContent).toBe('Changed')
    expect(el('language-card-direction').textContent).toBe('Right to left')
    expect(el('language-head-direction').textContent).toBe('Right to left')
    expect(el('language-head-default').textContent).toBe('Default language')
    expect(el('language-head-default').getAttribute('title')).toBe(
      'Default language',
    )
    expect(
      el('language-head-default')
        .querySelector('i')
        ?.getAttribute('aria-hidden'),
    ).toBe('true')
    expect(el('language-head-own').textContent).toBe('Custom language')
    expect(el('language-head-own').getAttribute('title')).toBe(
      'Custom language',
    )
    expect(el('language-card-frozen')).not.toBeNull()
    expect(el('language-card-editable')).toBeNull()
    expect(el('language-card-edit')).toBeNull()
    expect(el('language-card-enabled').textContent).toBe('Language switched on')
    act(() => h.card({ ...languageCard, nativeName: '' }))
    expectLanguagePageHead(HilosPages.I18N_LANGUAGE, '')
    act(() => h.card([]))
    expectLanguagePageHead(HilosPages.I18N_LANGUAGE, 'Language')
    expect(el('language-card-unavailable').textContent).toContain(
      'no longer available',
    )
    expect(document.querySelector('[aria-label="Loading language"]')).toBeNull()
  })
  it('renders live summary values, direction semantics, and routed tile links', () => {
    const h = setup()
    act(() =>
      h.card({
        ...languageCard,
        summary: {
          ...languageCard.summary,
          nameCount: 0,
          localeCount: 0,
          enabledLocaleCount: 0,
          countrylessLocale: 'missing',
        },
      }),
    )
    const mainSection = el('language-card-main')
    expect(el('language-overview-name-count').textContent).toBe('0')
    expect(el('language-overview-locale-count').textContent).toBe('0')
    expect(el('language-overview-enabled-locale-count').textContent).toBe('0')
    expect(el('language-overview-locales').textContent).toContain(
      'None created.',
    )
    expect(el('language-overview-countryless-state').textContent).toBe(
      'Not created',
    )
    expect(el('language-overview-name-count').getAttribute('href')).toBe(
      '/hilos/i18n/languages/uk/names',
    )
    expect(el('language-overview-locale-count').getAttribute('href')).toBe(
      '/hilos/i18n/languages/uk/locales',
    )
    expect(el('language-overview-countryless-state').getAttribute('href')).toBe(
      '/hilos/i18n/languages/uk/locales',
    )

    const rtl = document.getElementById('language-card-rtl') as HTMLInputElement
    expect(rtl.checked).toBe(false)
    expect(rtl.disabled).toBe(true)
    expect(rtl.getAttribute('aria-label')).toBe('Right to left')
    expect(rtl.getAttribute('aria-describedby')).toBe('language-card-rtl-hint')
    expect(document.getElementById('language-card-rtl-hint')?.textContent).toBe(
      'Right-to-left writing reverses the product layout.',
    )

    act(() =>
      h.card({
        ...languageCard,
        rtl: true,
        summary: {
          ...languageCard.summary,
          nameCount: 5,
          localeCount: 4,
          enabledLocaleCount: 2,
          countrylessLocale: 'on',
        },
      }),
    )
    expect(el('language-card-main')).toBe(mainSection)
    expect(el('language-overview-name-count').textContent).toBe('5')
    expect(el('language-overview-locale-count').textContent).toBe('4')
    expect(el('language-overview-enabled-locale-count').textContent).toBe('2')
    expect(el('language-overview-countryless-state').textContent).toBe(
      'Switched on',
    )
    expect(rtl.checked).toBe(true)
  })
  it.each([
    ['missing', 'Not created'],
    ['off', 'Switched off'],
    ['on', 'Switched on'],
  ] as const)(
    'maps countryless locale state %s to %s',
    (countrylessLocale, expected) => {
      const h = setup()
      act(() =>
        h.card({
          ...languageCard,
          summary: { ...languageCard.summary, countrylessLocale },
        }),
      )
      expect(el('language-overview-countryless-state').textContent).toBe(
        expected,
      )
    },
  )
  it.each(['default', 'locales', 'names'] as const)(
    'shows the live %s deletion hint with its disabled control',
    (deleteReason) => {
      const h = setup()
      act(() =>
        h.card({
          ...languageCard,
          summary: { ...languageCard.summary, canDelete: false, deleteReason },
        }),
      )
      expect(el<HTMLButtonElement>('language-card-delete').disabled).toBe(true)
      expect(el('language-card-delete').getAttribute('aria-describedby')).toBe(
        'language-card-delete-reason',
      )
      expect(el('language-card-delete-reason').textContent).toBe(
        HILOS_I18N_LANGUAGE_DELETE_COPY.reasons[deleteReason],
      )
      expect(el('language-card-delete').getAttribute('aria-label')).toBe(
        HILOS_I18N_LANGUAGE_DELETE_COPY.open,
      )
      expect(el('language-card-delete').getAttribute('title')).toBe(
        HILOS_I18N_LANGUAGE_DELETE_COPY.open,
      )
      expect(
        el('language-card-delete')
          .querySelector('i')
          ?.getAttribute('aria-hidden'),
      ).toBe('true')
    },
  )
  it('sends the card edit action and keeps a deleted draft until the window closes', () => {
    const h = setup()
    act(() => h.card())
    click('language-card-edit')
    change('i18n-language-edit-native-name', 'Mine')
    act(() => h.card([]))
    expect(el<HTMLInputElement>('i18n-language-edit-native-name').value).toBe(
      'Mine',
    )
    expect(el('i18n-language-edit-submit').textContent).toBe('Deleted')
    fireEvent.submit(document.querySelector('form')!)
    expect(h.calls).toEqual([])
  })
  it('saves through the card action with its captured language ID', async () => {
    const h = setup()
    act(() => h.card())
    click('language-card-edit')
    change('i18n-language-edit-native-name', 'Mine')
    click('i18n-language-edit-submit')
    expect(h.calls[0]).toMatchObject({
      action: 'hilos_i18n_language_update',
      payload: { languageId: 19, nativeName: 'Mine', rtl: false },
    })
    act(() => h.card({ ...languageCard, nativeName: 'Mine' }))
    expect(el('modal')).not.toBeNull()
    await settle(() => h.succeed())
    expect(el('modal')).toBeNull()
  })
  it('waits for successful card deletion before returning to the list', async () => {
    const h = setup()
    act(() => h.card())
    click('language-card-delete')
    click('i18n-language-delete-confirm')
    act(() => h.card([]))
    expect(el('modal')).not.toBeNull()
    expect(el('language-card-unavailable')).toBeNull()
    expect(h.navigation.navigate).not.toHaveBeenCalled()
    await settle(() => h.succeed())
    expect(h.navigation.navigate).toHaveBeenCalledWith('/hilos/i18n/languages')
  })
  it('keeps a viewer delete window open on external deletion without dispatching', () => {
    const h = setup(true)
    act(() => h.card())
    click('language-card-delete')
    expect(el<HTMLButtonElement>('i18n-language-delete-confirm').disabled).toBe(
      true,
    )
    act(() => h.card([]))
    expect(el('i18n-language-delete-notice').textContent).toContain(
      'Deleted elsewhere.',
    )
    click('i18n-language-delete-confirm')
    expect(h.calls).toEqual([])
  })
  it('releases old edit windows on a subject change and ignores an old delete ack', async () => {
    const h = setup()
    act(() => h.card())
    click('language-card-edit')
    change('i18n-language-edit-native-name', 'Old draft')
    act(() => {
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
    expect(el('i18n-language-edit-window')).toBeNull()
    expectLanguagePageHead(HilosPages.I18N_LANGUAGE, 'Français')
    expect(el('language-card-code').textContent).toBe('fr')
    expect(el('language-overview-name-count').getAttribute('href')).toBe(
      '/hilos/i18n/languages/fr/names',
    )
    expect(el('language-overview-locale-count').getAttribute('href')).toBe(
      '/hilos/i18n/languages/fr/locales',
    )
    click('language-card-delete')
    click('i18n-language-delete-confirm')
    act(() => {
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
    expectLanguagePageHead(HilosPages.I18N_LANGUAGE, 'العربية')
    click('language-card-delete')
    await settle(() => h.succeed())
    expect(h.navigation.navigate).not.toHaveBeenCalled()
    expect(el('modal').textContent).toContain('Delete language · العربية')
  })
  it('renders unavailable tab paths as text and keeps the card frozen in view', () => {
    const h = setup()
    h.navigation.resolvePath = () => undefined
    act(() => h.card({ ...languageCard, enabled: true }))
    expect(el('hilos-page-tabs')).toBeNull()
    expect(el('language-overview-name-count').tagName).toBe('SPAN')
    expect(el('language-overview-locale-count').tagName).toBe('SPAN')
    expect(el('language-overview-countryless-state').tagName).toBe('SPAN')
    click('language-card-view')
    expect(
      el<HTMLInputElement>('i18n-language-edit-native-name').disabled,
    ).toBe(true)
    expect(el('i18n-language-edit-submit')).toBeNull()
    click('i18n-language-edit-close')
    expect(el('modal')).toBeNull()
  })
  it('keeps the count readable when the page identity has no names tab', () => {
    const h = setup()
    act(() => h.card())
    act(() =>
      h.scope.data.set('pageTabs', [
        { page: HilosPages.I18N_LANGUAGE, label: 'Overview' },
        { page: HilosPages.I18N_LANGUAGE_LOCALES, label: 'Locales' },
      ]),
    )
    expect(el('language-overview-name-count').tagName).toBe('SPAN')
    expect(el('language-overview-name-count').textContent).toBe('3')
    expect(el('language-overview-locale-count').tagName).toBe('A')
  })
  it('disposes an edit session when the project context changes', () => {
    const h = setup()
    act(() => h.card())
    click('language-card-edit')
    const next = harness()
    next.route.set({
      page: HilosPages.I18N_LANGUAGE,
      params: { languageCode: 'fr' },
      admin: true,
    })
    next.card({
      ...languageCard,
      languageId: 13,
      code: 'fr',
      nativeName: 'Français',
    })
    h.view.rerender(
      <HilosRouterContext.Provider value={next.navigation}>
        <HilosI18nLanguagePage context={next.context} />
      </HilosRouterContext.Provider>,
    )
    expect(el('i18n-language-edit-window')).toBeNull()
    expectLanguagePageHead(HilosPages.I18N_LANGUAGE, 'Français')
  })
  it('releases a pending old-context deletion before a new confirmation can open', async () => {
    const h = setup()
    act(() => h.card())
    click('language-card-delete')
    click('i18n-language-delete-confirm')
    const next = harness()
    next.route.set({
      page: HilosPages.I18N_LANGUAGE,
      params: { languageCode: 'fr' },
      admin: true,
    })
    next.card({
      ...languageCard,
      languageId: 13,
      code: 'fr',
      nativeName: 'Français',
    })
    h.view.rerender(
      <HilosRouterContext.Provider value={next.navigation}>
        <HilosI18nLanguagePage context={next.context} />
      </HilosRouterContext.Provider>,
    )
    expectLanguagePageHead(HilosPages.I18N_LANGUAGE, 'Français')
    expect(el('modal')).toBeNull()
    click('language-card-delete')
    await settle(() => h.succeed())
    expect(next.navigation.navigate).not.toHaveBeenCalled()
    expect(el('modal').textContent).toContain('Delete language · Français')
    expect(el<HTMLButtonElement>('i18n-language-delete-confirm').disabled).toBe(
      false,
    )
  })

  it('binds the live name before the first paint and releases it on unmount', () => {
    const h = harness(HilosPages.I18N_LANGUAGE)
    h.card()
    const view = h.mount(<HilosI18nLanguagePage context={h.context} />)
    expectLanguagePageHead(HilosPages.I18N_LANGUAGE)
    view.unmount()
    expect(h.navigation.pageIdentity.get()?.pageHeadOwner?.label).toBe(
      'Language',
    )
  })

  it('uses the catalog name when the current page scope is outside the owner', () => {
    const h = setup()
    act(() => h.card())
    act(() => {
      const outside = h.scopes.openPage(HilosPages.I18N)
      const shell = {
        pageLabel: 'Language',
        pageLead: '',
        pageHeadOwner: {
          page: HilosPages.I18N_LANGUAGE,
          label: 'Language',
          lead: '',
        },
        pageBreadcrumb: [
          { page: HilosPages.DASHBOARD, label: 'Hilos' },
          { page: HilosPages.I18N, label: 'Internationalization' },
          { page: HilosPages.I18N_LANGUAGES, label: 'Languages' },
          { page: HilosPages.I18N_LANGUAGE, label: 'Language' },
        ],
        pageTabs: [
          { page: HilosPages.I18N_LANGUAGE, label: 'Overview' },
          { page: HilosPages.I18N_LANGUAGE_NAMES, label: 'Names' },
          { page: HilosPages.I18N_LANGUAGE_LOCALES, label: 'Locales' },
        ],
        pageChildren: [],
      }
      for (const [key, value] of Object.entries(shell))
        outside.data.set(key, value)
      outside.data.set('languageCard', languageCard)
    })
    expectLanguagePageHead(HilosPages.I18N_LANGUAGE, 'Language')
  })
})
