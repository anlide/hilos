import { act, fireEvent } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import {
  createHilosI18nBuiltInLanguages,
  createHilosI18nLanguageAdd,
  HilosPages,
  hilosToasts,
} from '@hilos/core'
import { HilosI18nLanguageAdd } from '../src/admin/i18n/lists/HilosI18nLanguageAdd.js'
import { HilosAdminViewModeContext } from '../src/hilosLookOnly.js'
import { harness, el, click, change, settle } from './hilosI18nHarness.js'

function setup(viewer = false) {
  const h = harness(HilosPages.I18N_LANGUAGES)
  h.scope.data.set('builtInLanguages', [
    { code: 'fr', nativeName: 'Français', rtl: false },
    { code: 'ar', nativeName: 'العربية', rtl: true },
  ])
  const add = createHilosI18nLanguageAdd(
    h.context,
    createHilosI18nBuiltInLanguages(h.context),
  )
  const view = h.mount(
    <HilosAdminViewModeContext.Provider value={viewer}>
      <HilosI18nLanguageAdd add={add} context={h.context} />
    </HilosAdminViewModeContext.Provider>,
  )
  act(() => add.open())
  return { ...h, add, view }
}

describe('React language creation', () => {
  it('focuses code, fills known values, preserves same-code edits and refreshes a changed code', () => {
    setup()
    expect(document.activeElement).toBe(el('i18n-language-add-code'))
    expect(el<HTMLButtonElement>('i18n-language-add-submit').disabled).toBe(
      true,
    )
    change('i18n-language-add-code', 'f')
    expect(el('i18n-language-add-plate')).toBeNull()
    change('i18n-language-add-code', 'FR')
    expect(el<HTMLInputElement>('i18n-language-add-native-name').value).toBe(
      'Français',
    )
    expect(el('i18n-language-add-plate').getAttribute('data-kind')).toBe(
      'known',
    )
    change('i18n-language-add-native-name', 'My name')
    change('i18n-language-add-code', 'fr')
    expect(el<HTMLInputElement>('i18n-language-add-native-name').value).toBe(
      'My name',
    )
    change('i18n-language-add-code', 'ar')
    expect(el<HTMLInputElement>('i18n-language-add-rtl').checked).toBe(true)
    change('i18n-language-add-code', 'cy')
    expect(el<HTMLInputElement>('i18n-language-add-native-name').value).toBe('')
    expect(el('i18n-language-add-plate').getAttribute('data-kind')).toBe('own')
  })
  it('guards empty and double Enter, keeps refusal and closes only on the known language ack', async () => {
    const h = setup()
    fireEvent.submit(document.querySelector('form')!)
    expect(h.calls).toEqual([])
    change('i18n-language-add-code', 'fr')
    change('i18n-language-add-native-name', '  French  ')
    fireEvent.submit(document.querySelector('form')!)
    fireEvent.submit(document.querySelector('form')!)
    expect(h.calls).toHaveLength(1)
    expect(h.calls[0]).toMatchObject({
      action: 'hilos_i18n_language_add',
      payload: { languageCode: 'fr', nativeName: 'French', rtl: false },
    })
    click('modal-close')
    fireEvent.keyDown(el('modal'), { key: 'Escape' })
    expect(h.add.step.get()).toBe('form')
    expect(el<HTMLButtonElement>('i18n-language-add-cancel').disabled).toBe(
      true,
    )
    await settle(() => h.refuse())
    expect(el('i18n-language-add-error').textContent).toContain(
      'Server refused',
    )
    expect(el<HTMLInputElement>('i18n-language-add-native-name').value).toBe(
      '  French  ',
    )
    click('i18n-language-add-submit')
    await settle(() => h.succeed(1, 'Language added.'))
    expect(el('modal')).toBeNull()
    expect(
      hilosToasts.toasts
        .get()
        .some((toast) => toast.message === 'Language added.'),
    ).toBe(true)
  })
  it('requires own-code confirmation and clears the draft on cancel', async () => {
    const h = setup()
    change('i18n-language-add-code', 'cy')
    change('i18n-language-add-native-name', 'Cymraeg')
    fireEvent.click(el('i18n-language-add-rtl'))
    click('i18n-language-add-submit')
    expect(h.calls).toHaveLength(0)
    expect(el('i18n-language-confirm').textContent).toContain(
      'catalog of 2 languages',
    )
    expect(document.activeElement?.getAttribute('role')).toBe('dialog')
    click('i18n-language-confirm-submit')
    click('i18n-language-confirm-submit')
    expect(h.calls[0]).toMatchObject({
      action: 'hilos_i18n_language_add',
      payload: { languageCode: 'cy', nativeName: 'Cymraeg', rtl: true },
    })
    await settle(() => h.refuse())
    expect(el('i18n-language-confirm-error').textContent).toContain(
      'Server refused',
    )
    click('i18n-language-confirm-cancel')
    act(() => h.add.open())
    expect(el<HTMLInputElement>('i18n-language-add-code').value).toBe('')
    expect(el('i18n-language-add-error').textContent).not.toContain(
      'Server refused',
    )
  })
  it('allows a viewer to read and fill but never dispatches on Enter', () => {
    const h = setup(true)
    change('i18n-language-add-code', 'fr')
    expect(el<HTMLInputElement>('i18n-language-add-native-name').disabled).toBe(
      false,
    )
    expect(el<HTMLButtonElement>('i18n-language-add-submit').disabled).toBe(
      true,
    )
    fireEvent.submit(document.querySelector('form')!)
    expect(h.calls).toHaveLength(0)
    click('i18n-language-add-cancel')
    expect(el('modal')).toBeNull()
  })
  it('adds an own language only after confirmation and the server acknowledgement', async () => {
    const h = setup()
    change('i18n-language-add-code', 'cy')
    change('i18n-language-add-native-name', 'Cymraeg')
    click('i18n-language-add-submit')
    click('i18n-language-confirm-submit')
    expect(el('modal')).not.toBeNull()
    await settle(() => h.succeed())
    expect(el('modal')).toBeNull()
    expect(h.add.step.get()).toBe('closed')
  })
})
