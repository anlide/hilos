import { act, fireEvent } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { createSignal, type HilosI18nCountryCard } from '@hilos/core'
import { HilosI18nCountrySwitchOff } from '../src/admin/i18n/details/HilosI18nCountrySwitchOff.js'
import { HilosAdminViewModeContext } from '../src/hilosLookOnly.js'
import { useSignal } from '../src/useSignal.js'
import { harness, countryCard, el, click, settle } from './hilosI18nHarness.js'

function setup(
  initial: HilosI18nCountryCard | null = { ...countryCard, enabled: true },
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
        <HilosI18nCountrySwitchOff context={h.context} card={value} />
      </HilosAdminViewModeContext.Provider>
    )
  }
  const view = h.mount(<Surface />)
  return { ...h, card, locked, view }
}

describe('React country switch-off', () => {
  it('reads live counts while retaining the opening country label', () => {
    const h = setup()
    click('country-card-switch-off')
    expect(el('country-card-switch-off-locale-count').textContent).toBe('2')
    expect(el('country-card-switch-off-name-count').textContent).toBe('3')
    act(() =>
      h.card.set({
        ...countryCard,
        enabled: true,
        summary: {
          ...countryCard.summary,
          name: 'New',
          localeCount: 7,
          nameCount: 8,
        },
      }),
    )
    expect(el('country-card-switch-off-locale-count').textContent).toBe('7')
    expect(el('country-card-switch-off-name-count').textContent).toBe('8')
    expect(el('modal').textContent).toContain('Switch off country · Guernsey')
  })
  it('waits through its own live echo, guards a double confirm and closes only on ack', async () => {
    const h = setup()
    click('country-card-switch-off')
    expect(document.activeElement?.getAttribute('role')).toBe('dialog')
    click('country-card-switch-off-confirm')
    click('country-card-switch-off-confirm')
    expect(h.calls).toHaveLength(1)
    expect(h.calls[0]).toMatchObject({
      action: 'hilos_i18n_country_switch_off',
      payload: { countryCode: 'gg' },
    })
    expect(
      el<HTMLButtonElement>('country-card-switch-off-cancel').disabled,
    ).toBe(true)
    click('modal-close')
    fireEvent.keyDown(el('modal'), { key: 'Escape' })
    act(() => h.card.set({ ...countryCard, enabled: false }))
    expect(el('modal')).not.toBeNull()
    expect(
      el('country-card-switch-off-notice')?.textContent ?? '',
    ).not.toContain('elsewhere')
    await settle(() => h.succeed())
    expect(el('modal')).toBeNull()
  })
  it('keeps a server refusal and clears it on reopening', async () => {
    const h = setup()
    click('country-card-switch-off')
    click('country-card-switch-off-confirm')
    await settle(() => h.refuse())
    expect(el('modal')).not.toBeNull()
    expect(el('country-card-switch-off-error').textContent).toContain(
      'Server refused',
    )
    click('country-card-switch-off-cancel')
    click('country-card-switch-off')
    expect(el('country-card-switch-off-error').textContent).not.toContain(
      'Server refused',
    )
  })
  it('reports an external switch and closes on a disappeared card', () => {
    const h = setup()
    click('country-card-switch-off')
    act(() => h.card.set({ ...countryCard, enabled: false }))
    expect(el('country-card-switch-off-notice').textContent).toContain(
      'elsewhere just now',
    )
    expect(
      el<HTMLButtonElement>('country-card-switch-off-confirm').disabled,
    ).toBe(true)
    click('country-card-switch-off-confirm')
    expect(h.calls).toEqual([])
    act(() => h.card.set(null))
    expect(el('modal')).toBeNull()
    expect(el('country-card-switch-off')).toBeNull()
  })
  it('keeps the opener usable for a viewer and follows a live mode lock', () => {
    const h = setup(undefined, true)
    expect(el<HTMLButtonElement>('country-card-switch-off').disabled).toBe(
      false,
    )
    click('country-card-switch-off')
    expect(
      el<HTMLButtonElement>('country-card-switch-off-confirm').disabled,
    ).toBe(true)
    click('country-card-switch-off-confirm')
    expect(h.calls).toEqual([])
    act(() => h.locked.set(false))
    expect(
      el<HTMLButtonElement>('country-card-switch-off-confirm').disabled,
    ).toBe(false)
    act(() => h.locked.set(true))
    expect(
      el<HTMLButtonElement>('country-card-switch-off-confirm').disabled,
    ).toBe(true)
    click('country-card-switch-off-cancel')
    expect(el('modal')).toBeNull()
  })
  it('ignores an ack after unmount', async () => {
    const h = setup()
    click('country-card-switch-off')
    click('country-card-switch-off-confirm')
    h.view.unmount()
    await settle(() => h.succeed())
    expect(el('modal')).toBeNull()
  })
  it('uses uppercase code without a name and rejects a former subject', () => {
    const h = setup({
      ...countryCard,
      enabled: true,
      summary: { ...countryCard.summary, name: null },
    })
    click('country-card-switch-off')
    expect(el('modal').textContent).toContain('GG')
    act(() => h.card.set({ ...countryCard, code: 'us' }))
    expect(el('modal')).toBeNull()
  })
})
