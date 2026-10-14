import { act, fireEvent } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { createSignal, type HilosI18nCountryCard } from '@hilos/core'
import { HilosI18nCountrySwitchOn } from '../src/admin/i18n/details/HilosI18nCountrySwitchOn.js'
import { HilosAdminViewModeContext } from '../src/hilosLookOnly.js'
import { useSignal } from '../src/useSignal.js'
import { harness, countryCard, el, click, settle } from './hilosI18nHarness.js'

function setup(
  initial: HilosI18nCountryCard | null = { ...countryCard, enabled: false },
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
        <HilosI18nCountrySwitchOn context={h.context} card={value} />
      </HilosAdminViewModeContext.Provider>
    )
  }
  const view = h.mount(<Surface />)
  return { ...h, card, locked, view }
}

describe('React country switch-on', () => {
  it.each(['missing', 'off', 'on'] as const)(
    'keeps %s default locale as a live note without blocking switching on',
    (defaultLocale) => {
      const h = setup({
        ...countryCard,
        summary: {
          ...countryCard.summary,
          defaultLocale,
          defaultLocaleCode: defaultLocale === 'missing' ? null : 'en-GG',
        },
      })
      click('country-card-switch-on')
      expect(el('country-card-switch-on-note') !== null).toBe(
        defaultLocale !== 'on',
      )
      if (defaultLocale !== 'on')
        expect(el('country-card-switch-on-note').textContent).toContain(
          defaultLocale === 'off' ? 'en-GG' : 'allowed',
        )
      expect(
        el<HTMLButtonElement>('country-card-switch-on-confirm').disabled,
      ).toBe(false)
      act(() =>
        h.card.set({
          ...countryCard,
          summary: { ...countryCard.summary, defaultLocale: 'on' },
        }),
      )
      expect(el('country-card-switch-on-note')).toBeNull()
    },
  )
  it('waits through its own live echo, guards a double confirm and closes only on ack', async () => {
    const h = setup()
    click('country-card-switch-on')
    expect(document.activeElement?.getAttribute('role')).toBe('dialog')
    click('country-card-switch-on-confirm')
    click('country-card-switch-on-confirm')
    expect(h.calls).toHaveLength(1)
    expect(h.calls[0]).toMatchObject({
      action: 'hilos_i18n_country_switch_on',
      payload: { countryCode: 'gg' },
    })
    expect(
      el<HTMLButtonElement>('country-card-switch-on-cancel').disabled,
    ).toBe(true)
    click('modal-close')
    fireEvent.keyDown(el('modal'), { key: 'Escape' })
    act(() => h.card.set({ ...countryCard, enabled: true }))
    expect(el('modal')).not.toBeNull()
    expect(
      el('country-card-switch-on-notice')?.textContent ?? '',
    ).not.toContain('elsewhere')
    await settle(() => h.succeed())
    expect(el('modal')).toBeNull()
  })
  it('keeps a server refusal and clears it on reopening', async () => {
    const h = setup()
    click('country-card-switch-on')
    click('country-card-switch-on-confirm')
    await settle(() => h.refuse())
    expect(el('modal')).not.toBeNull()
    expect(el('country-card-switch-on-error').textContent).toContain(
      'Server refused',
    )
    click('country-card-switch-on-cancel')
    click('country-card-switch-on')
    expect(el('country-card-switch-on-error').textContent).not.toContain(
      'Server refused',
    )
  })
  it('reports an external switch and closes on a disappeared card', () => {
    const h = setup()
    click('country-card-switch-on')
    act(() => h.card.set({ ...countryCard, enabled: true }))
    expect(el('country-card-switch-on-notice').textContent).toContain(
      'elsewhere just now',
    )
    expect(
      el<HTMLButtonElement>('country-card-switch-on-confirm').disabled,
    ).toBe(true)
    click('country-card-switch-on-confirm')
    expect(h.calls).toEqual([])
    act(() => h.card.set(null))
    expect(el('modal')).toBeNull()
    expect(el('country-card-switch-on')).toBeNull()
  })
  it('keeps the opener usable for a viewer and follows a live mode lock', () => {
    const h = setup(undefined, true)
    expect(el<HTMLButtonElement>('country-card-switch-on').disabled).toBe(false)
    click('country-card-switch-on')
    expect(
      el<HTMLButtonElement>('country-card-switch-on-confirm').disabled,
    ).toBe(true)
    click('country-card-switch-on-confirm')
    expect(h.calls).toEqual([])
    act(() => h.locked.set(false))
    expect(
      el<HTMLButtonElement>('country-card-switch-on-confirm').disabled,
    ).toBe(false)
    act(() => h.locked.set(true))
    expect(
      el<HTMLButtonElement>('country-card-switch-on-confirm').disabled,
    ).toBe(true)
    click('country-card-switch-on-cancel')
    expect(el('modal')).toBeNull()
  })
  it('ignores an ack after unmount', async () => {
    const h = setup()
    click('country-card-switch-on')
    click('country-card-switch-on-confirm')
    h.view.unmount()
    await settle(() => h.succeed())
    expect(el('modal')).toBeNull()
  })
  it('uses uppercase code without a name and rejects a former subject', () => {
    const h = setup({
      ...countryCard,
      enabled: false,
      summary: { ...countryCard.summary, name: null },
    })
    click('country-card-switch-on')
    expect(el('modal').textContent).toContain('GG')
    act(() => h.card.set({ ...countryCard, code: 'us' }))
    expect(el('modal')).toBeNull()
  })
})
