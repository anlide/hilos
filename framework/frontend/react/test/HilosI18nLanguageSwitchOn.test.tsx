import { act, fireEvent } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { createSignal, type HilosI18nLanguageCard } from '@hilos/core'
import { HilosI18nLanguageSwitchOn } from '../src/admin/i18n/details/HilosI18nLanguageSwitchOn.js'
import { HilosAdminViewModeContext } from '../src/hilosLookOnly.js'
import { useSignal } from '../src/useSignal.js'
import { harness, languageCard, el, click, settle } from './hilosI18nHarness.js'

function setup(
  initial: HilosI18nLanguageCard | null = { ...languageCard, enabled: false },
  viewer = false,
) {
  const h = harness()
  const card = createSignal(initial),
    locked = createSignal(viewer)
  function Surface() {
    const value = useSignal(card),
      viewOnly = useSignal(locked)
    return (
      <HilosAdminViewModeContext.Provider value={viewOnly}>
        <HilosI18nLanguageSwitchOn context={h.context} card={value} />
      </HilosAdminViewModeContext.Provider>
    )
  }
  const view = h.mount(<Surface />)
  return { ...h, card, locked, view }
}

describe('React language switch-on', () => {
  it.each(['missing', 'off', 'on'] as const)(
    'reads live %s locale facts and N names without a denominator',
    (locale) => {
      const h = setup({
        ...languageCard,
        summary: { ...languageCard.summary, countrylessLocale: locale },
      })
      click('language-card-switch-on')
      expect(el('language-card-switch-on-names').textContent).toBe('3')
      expect(el('language-card-switch-on-locale').textContent).toContain(
        locale === 'missing'
          ? 'not created'
          : locale === 'off'
            ? 'switched off'
            : 'switched on',
      )
      expect(el('language-card-switch-on-warning') !== null).toBe(
        locale !== 'on',
      )
      expect(
        el<HTMLButtonElement>('language-card-switch-on-confirm').disabled,
      ).toBe(false)
      act(() =>
        h.card.set({
          ...languageCard,
          summary: {
            ...languageCard.summary,
            nameCount: 7,
            countrylessLocale: 'on',
          },
        }),
      )
      expect(el('language-card-switch-on-names').textContent).toBe('7')
      expect(el('language-card-switch-on-warning')).toBeNull()
    },
  )
  it('waits through its own live echo, guards a double confirm and closes only on ack', async () => {
    const h = setup()
    click('language-card-switch-on')
    expect(document.activeElement?.getAttribute('role')).toBe('dialog')
    click('language-card-switch-on-confirm')
    click('language-card-switch-on-confirm')
    expect(h.calls).toHaveLength(1)
    expect(h.calls[0]).toMatchObject({
      action: 'hilos_i18n_language_switch_on',
      payload: { languageId: 19 },
    })
    expect(
      el<HTMLButtonElement>('language-card-switch-on-cancel').disabled,
    ).toBe(true)
    click('modal-close')
    fireEvent.keyDown(el('modal'), { key: 'Escape' })
    act(() => h.card.set({ ...languageCard, enabled: true }))
    expect(el('modal')).not.toBeNull()
    expect(
      el('language-card-switch-on-notice')?.textContent ?? '',
    ).not.toContain('elsewhere')
    await settle(() => h.succeed())
    expect(el('modal')).toBeNull()
  })
  it('keeps a server refusal and clears it on reopening', async () => {
    const h = setup()
    click('language-card-switch-on')
    click('language-card-switch-on-confirm')
    await settle(() => h.refuse())
    expect(el('modal')).not.toBeNull()
    expect(el('language-card-switch-on-error').textContent).toContain(
      'Server refused',
    )
    click('language-card-switch-on-cancel')
    click('language-card-switch-on')
    expect(el('language-card-switch-on-error').textContent).not.toContain(
      'Server refused',
    )
  })
  it('reports an external switch and closes on a disappeared card', () => {
    const h = setup()
    click('language-card-switch-on')
    act(() => h.card.set({ ...languageCard, enabled: true }))
    expect(el('language-card-switch-on-notice').textContent).toContain(
      'elsewhere just now',
    )
    expect(
      el<HTMLButtonElement>('language-card-switch-on-confirm').disabled,
    ).toBe(true)
    click('language-card-switch-on-confirm')
    expect(h.calls).toEqual([])
    act(() => h.card.set(null))
    expect(el('modal')).toBeNull()
    expect(el('language-card-switch-on')).toBeNull()
  })
  it('keeps the opener usable for a viewer and follows a live mode lock', () => {
    const h = setup(undefined, true)
    expect(el<HTMLButtonElement>('language-card-switch-on').disabled).toBe(
      false,
    )
    click('language-card-switch-on')
    expect(
      el<HTMLButtonElement>('language-card-switch-on-confirm').disabled,
    ).toBe(true)
    click('language-card-switch-on-confirm')
    expect(h.calls).toEqual([])
    act(() => h.locked.set(false))
    expect(
      el<HTMLButtonElement>('language-card-switch-on-confirm').disabled,
    ).toBe(false)
    act(() => h.locked.set(true))
    expect(
      el<HTMLButtonElement>('language-card-switch-on-confirm').disabled,
    ).toBe(true)
    click('language-card-switch-on-cancel')
    expect(el('modal')).toBeNull()
  })
  it('ignores an ack after unmount', async () => {
    const h = setup()
    click('language-card-switch-on')
    click('language-card-switch-on-confirm')
    h.view.unmount()
    await settle(() => h.succeed())
    expect(el('modal')).toBeNull()
  })
})
