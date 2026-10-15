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

  it('distinguishes loading from a deleted card and renders live fields, marks, counts and code-addressed tabs', () => {
    const h = setup()
    expect(
      document.querySelector('[aria-label="Loading language"]'),
    ).not.toBeNull()
    expect(el('language-card-header')).toBeNull()
    act(() => h.card())
    expect(el('language-card-counts').textContent).toBe('Locales: 2 · Names: 3')
    expect(el('language-card-code').textContent).toBe('uk')
    expect(el('language-card-direction').textContent).toBe('Left to right')
    const main = el('language-card-tab-hilos_i18n_language')
    expect(main.getAttribute('aria-current')).toBe('page')
    expect(
      el('language-card-tab-hilos_i18n_language_names').getAttribute('href'),
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
    expect(el('language-card-name').textContent).toBe('Changed')
    expect(el('language-card-direction').textContent).toBe('Right to left')
    expect(el('language-card-counts').textContent).toBe('Locales: 4 · Names: 5')
    expect(el('language-card-default')).not.toBeNull()
    expect(el('language-card-own')).not.toBeNull()
    expect(el('language-card-frozen')).not.toBeNull()
    expect(el('language-card-edit')).toBeNull()
    act(() => h.card([]))
    expect(el('language-card-unavailable').textContent).toContain(
      'no longer available',
    )
    expect(document.querySelector('[aria-label="Loading language"]')).toBeNull()
  })
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
    expect(el('language-card-code').textContent).toBe('fr')
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
    click('language-card-delete')
    await settle(() => h.succeed())
    expect(h.navigation.navigate).not.toHaveBeenCalled()
    expect(el('modal').textContent).toContain('Delete language · العربية')
  })
  it('renders unavailable tab paths as text and keeps the card frozen in view', () => {
    const h = setup()
    h.navigation.resolvePath = () => undefined
    act(() => h.card({ ...languageCard, enabled: true }))
    expect(el('language-card-tab-hilos_i18n_language_names').tagName).toBe(
      'SPAN',
    )
    click('language-card-view')
    expect(
      el<HTMLInputElement>('i18n-language-edit-native-name').disabled,
    ).toBe(true)
    expect(el('i18n-language-edit-submit')).toBeNull()
    click('i18n-language-edit-close')
    expect(el('modal')).toBeNull()
  })
  it('disposes an edit session when the project context changes', () => {
    const h = setup()
    act(() => h.card())
    click('language-card-edit')
    const next = harness()
    next.card({ ...languageCard, languageId: 13, code: 'fr' })
    h.view.rerender(
      <HilosRouterContext.Provider value={h.navigation}>
        <HilosI18nLanguagePage context={next.context} />
      </HilosRouterContext.Provider>,
    )
    expect(el('i18n-language-edit-window')).toBeNull()
  })
  it('releases a pending old-context deletion before a new confirmation can open', async () => {
    const h = setup()
    act(() => h.card())
    click('language-card-delete')
    click('i18n-language-delete-confirm')
    const next = harness()
    next.card({
      ...languageCard,
      languageId: 13,
      code: 'fr',
      nativeName: 'Français',
    })
    h.view.rerender(
      <HilosRouterContext.Provider value={h.navigation}>
        <HilosI18nLanguagePage context={next.context} />
      </HilosRouterContext.Provider>,
    )
    expect(el('modal')).toBeNull()
    click('language-card-delete')
    await settle(() => h.succeed())
    expect(h.navigation.navigate).not.toHaveBeenCalled()
    expect(el('modal').textContent).toContain('Delete language · Français')
    expect(el<HTMLButtonElement>('i18n-language-delete-confirm').disabled).toBe(
      false,
    )
  })
})
