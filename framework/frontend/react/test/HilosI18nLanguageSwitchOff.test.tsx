import { act, fireEvent } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { createSignal, type HilosI18nLanguageCard } from '@hilos/core'
import { HilosI18nLanguageSwitchOff } from '../src/admin/i18n/details/HilosI18nLanguageSwitchOff.js'
import { HilosAdminViewModeContext } from '../src/hilosLookOnly.js'
import { useSignal } from '../src/useSignal.js'
import { harness, languageCard, el, click, settle } from './hilosI18nHarness.js'

function setup(
  initial: HilosI18nLanguageCard | null = { ...languageCard, enabled: true },
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
        <HilosI18nLanguageSwitchOff context={h.context} card={value} />
      </HilosAdminViewModeContext.Provider>
    )
  }
  const view = h.mount(<Surface />)
  return { ...h, card, locked, view }
}

describe('React language switch-off', () => {
  it('refuses the default before opening, with a visible accessible reason', () => {
    setup({
      ...languageCard,
      enabled: true,
      summary: { ...languageCard.summary, isDefault: true },
    })
    expect(el<HTMLButtonElement>('language-card-switch-off').disabled).toBe(
      true,
    )
    expect(el('language-card-switch-off-reason').textContent).toContain(
      'set in env',
    )
    expect(
      el('language-card-switch-off').getAttribute('aria-describedby'),
    ).toBe('language-card-switch-off-reason')
    click('language-card-switch-off')
    expect(el('modal')).toBeNull()
  })
  it('waits through its own live echo, guards a double confirm and closes only on ack', async () => {
    const h = setup()
    click('language-card-switch-off')
    expect(document.activeElement?.getAttribute('role')).toBe('dialog')
    click('language-card-switch-off-confirm')
    click('language-card-switch-off-confirm')
    expect(h.calls).toHaveLength(1)
    expect(h.calls[0]).toMatchObject({
      action: 'hilos_i18n_language_switch_off',
      payload: { languageId: 19 },
    })
    expect(
      el<HTMLButtonElement>('language-card-switch-off-cancel').disabled,
    ).toBe(true)
    click('modal-close')
    fireEvent.keyDown(el('modal'), { key: 'Escape' })
    act(() => h.card.set({ ...languageCard, enabled: false }))
    expect(el('modal')).not.toBeNull()
    expect(
      el('language-card-switch-off-notice')?.textContent ?? '',
    ).not.toContain('elsewhere')
    await settle(() => h.succeed())
    expect(el('modal')).toBeNull()
  })
  it('keeps a server refusal and clears it on reopening', async () => {
    const h = setup()
    click('language-card-switch-off')
    click('language-card-switch-off-confirm')
    await settle(() => h.refuse())
    expect(el('modal')).not.toBeNull()
    expect(el('language-card-switch-off-error').textContent).toContain(
      'Server refused',
    )
    click('language-card-switch-off-cancel')
    click('language-card-switch-off')
    expect(el('language-card-switch-off-error').textContent).not.toContain(
      'Server refused',
    )
  })
  it('reports an external switch and closes on a disappeared card', () => {
    const h = setup()
    click('language-card-switch-off')
    act(() => h.card.set({ ...languageCard, enabled: false }))
    expect(el('language-card-switch-off-notice').textContent).toContain(
      'elsewhere just now',
    )
    expect(
      el<HTMLButtonElement>('language-card-switch-off-confirm').disabled,
    ).toBe(true)
    click('language-card-switch-off-confirm')
    expect(h.calls).toEqual([])
    act(() => h.card.set(null))
    expect(el('modal')).toBeNull()
    expect(el('language-card-switch-off')).toBeNull()
  })
  it('keeps the opener usable for a viewer and follows a live mode lock', () => {
    const h = setup(undefined, true)
    expect(el<HTMLButtonElement>('language-card-switch-off').disabled).toBe(
      false,
    )
    click('language-card-switch-off')
    expect(
      el<HTMLButtonElement>('language-card-switch-off-confirm').disabled,
    ).toBe(true)
    click('language-card-switch-off-confirm')
    expect(h.calls).toEqual([])
    act(() => h.locked.set(false))
    expect(
      el<HTMLButtonElement>('language-card-switch-off-confirm').disabled,
    ).toBe(false)
    act(() => h.locked.set(true))
    expect(
      el<HTMLButtonElement>('language-card-switch-off-confirm').disabled,
    ).toBe(true)
    click('language-card-switch-off-cancel')
    expect(el('modal')).toBeNull()
  })
  it('ignores an ack after unmount', async () => {
    const h = setup()
    click('language-card-switch-off')
    click('language-card-switch-off-confirm')
    h.view.unmount()
    await settle(() => h.succeed())
    expect(el('modal')).toBeNull()
  })
})
